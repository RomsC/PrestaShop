<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

namespace PrestaShopBundle\Command;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Exception;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateSchemaCommand extends ContainerAwareCommand
{
    /**
     * @var EntityManagerInterface
     */
    private $em;

    private $metadata;

    private $dbName;

    private $dbPrefix;

    public function configure()
    {
        $this
            ->setName('prestashop:schema:update-without-foreign')
            ->setDescription('Update the database');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $container = $this->getContainer();

        $this->dbName = $container->getParameter('database_name');
        $this->dbPrefix = $container->getParameter('database_prefix');

        $this->em = $container->get('doctrine')->getManager();
        $this->metadata = $this->em->getMetadataFactory()->getAllMetadata();

        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        $output->writeln('Updating database schema...');
        $affectedRows = 0;

        $affectedRows = $this->dropExistingForeignKeys($conn);

        $schemaTool = new SchemaTool($this->em);
        $updateSchemaSql = $schemaTool->getUpdateSchemaSql($this->metadata, false);

        $removedTables = $this->removeDropTables($updateSchemaSql);
        $this->removeAlterTables($updateSchemaSql, $removedTables);
        $this->removeDuplicateDropForeignKeys($updateSchemaSql);
        $this->removeAddConstraints($updateSchemaSql);

        $constraints = $this->moveConstraints($updateSchemaSql);

        $this->clearQueries($conn, $updateSchemaSql);

        $affectedRows += count($updateSchemaSql);
        // Now execute the queries!
        foreach ($updateSchemaSql as $sql) {
            try {
                $output->writeln('Executing: ' . $sql);
                $conn->executeQuery($sql);
            } catch (Exception $e) {
                $conn->rollBack();

                throw ($e);
            }
        }
        $conn->commit();

        $pluralization = (1 > $affectedRows) ? 'query was' : 'queries were';
        $output->writeln(sprintf('Database schema updated successfully! "<info>%s</info>" %s executed', $affectedRows, $pluralization));

        return 0;
    }

    /**
     * Drop foreign keys from the database
     *
     * @param Connection $connection Database connection to use to clear foreign keys
     *
     * @return int The number of affected rows
     */
    public function dropExistingForeignKeys(Connection $connection): int
    {
        // First drop any existing FK
        $query = $connection->query(
            'SELECT CONSTRAINT_NAME, TABLE_NAME ' .
            'FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS ' .
            'WHERE CONSTRAINT_TYPE = "FOREIGN KEY" ' .
            'AND TABLE_SCHEMA = "' . $this->dbName . '" ' .
            'AND TABLE_NAME LIKE "' . $this->dbPrefix . '%"'
        );

        $results = $query->fetchAll();
        $nbQueries = 0;

        foreach ($results as $result) {
            $drop = 'ALTER TABLE ' . $result['TABLE_NAME'] . ' DROP FOREIGN KEY ' . $result['CONSTRAINT_NAME'];
            $output->writeln('Executing: ' . $drop);

            $nbQueries += $connection->executeQuery($drop);
        }

        return $nbQueries;
    }

    /**
     * Remove DROP TABLE queries
     *
     * @param array $queries List of SQL queries to parse
     *
     * @return array Queries that have been removed
     */
    public function removeDropTables(array &$queries): array
    {
        $removedTables = [];
        foreach ($queries as $key => $sql) {
            $matches = [];
            if (preg_match('/DROP TABLE (.+?)$/', $sql, $matches)) {
                unset($queries[$key]);
                $removedTables[] = $matches[1];
            }
        }

        return $removedTables;
    }

    /**
     * Remove ALTER TABLE queries
     *
     * @param array $queries List of SQL queries to parse
     * @param array $removedTables Tables removed by previous methods
     *
     * @return void
     */
    public function removeAlterTables(array &$queries, array $removedTables): void
    {
        foreach ($queries as $key => $sql) {
            $matches = [];
            if (preg_match('/ALTER TABLE (.+?) /', $sql, $matches)) {
                $alteredTables = $matches[1];
                if (in_array($alteredTables, $removedTables)) {
                    unset($queries[$key]);
                }
            }
        }
    }

    /**
     * Remove duplicated DROP FOREIGN KEY queries
     *
     * @param array $queries List of SQL queries to parse
     *
     * @return void
     */
    public function removeDuplicateDropForeignKeys(array &$queries): void
    {
        $dropForeignKeyQueries = [];
        foreach ($queries as $key => $sql) {
            if (preg_match('/ DROP FOREIGN KEY /', $sql)) {
                if (in_array($sql, $dropForeignKeyQueries)) {
                    unset($queries[$key]);
                } else {
                    $dropForeignKeyQueries[] = $sql;
                }
            }
        }
    }

    /**
     * Remove ADD CONSTRAINT queries
     *
     * @param array $queries List of SQL queries to parse
     *
     * @return void
     */
    public function removeAddConstraints(array &$queries): void
    {
        foreach ($queries as $key => $sql) {
            if (preg_match('/ ADD CONSTRAINT /', $sql)) {
                unset($queries[$key]);
            }
        }
    }

    /**
     * Move constraints to the top of the list
     *
     * @param array $queries List of SQL queries to parse
     *
     * @return array The new order
     */
    public function moveConstraints(array &$queries): array
    {
        $constraints = [];

        foreach ($queries as $key => $sql) {
            if (preg_match('/ DROP FOREIGN KEY /', $sql)) {
                $constraints[] = $sql;
                unset($queries[$key]);
            }
        }

        foreach ($constraints as $constraint) {
            array_unshift($queries, $constraint);
        }

        return $constraints;
    }

    /**
     * Put back DEFAULT fields, since it cannot be described in the ORM model
     *
     * @param Connection $connection Database connection to use
     * @param array $queries List of SQL queries to parse
     *
     * return void
     */
    public function clearQueries(Connection $conn, array &$queries): void
    {
        foreach ($queries as $key => $sql) {
            $matches = [];
            if (!preg_match('/ALTER TABLE (.+?) /', $sql, $matches)) {
                continue;
            }

            $tableName = $matches[1];
            $matches = [];
            preg_match_all('/([^\s,]*?) CHANGE (.+?) (.+?)(, CHANGE |$)/', $sql, $matches);
            if (!isset($matches[2]) || !is_array($matches[2])) {
                continue;
            }

            foreach ($matches[2] as $matchKey => $fieldName) {
                $findChange = strpos($matches[0][$matchKey], ', CHANGE ');
                // remove table name
                $matches[0][$matchKey] = preg_replace(
                    '/(.+?) CHANGE/',
                    ' CHANGE',
                    rtrim($matches[0][$matchKey], ', CHANGE ')
                );
                $matches[0][$matchKey] .= $findChange !== false ? ', CHANGE ' : '';
                // remove quote
                $originalFieldName = $fieldName;
                $fieldName = str_replace('`', '', $fieldName);
                // get old default value
                $query = $conn->query('SHOW FULL COLUMNS FROM ' . $tableName . ' WHERE Field="' . $fieldName . '"');
                $results = $query->fetchAll();
                $oldDefaultValue = $results[0]['Default'];
                $extra = $results[0]['Extra'];

                if ($oldDefaultValue !== null
                    && strpos($oldDefaultValue, 'CURRENT_TIMESTAMP') === false) {
                    $oldDefaultValue = "'" . $oldDefaultValue . "'";
                }

                if ($oldDefaultValue === null) {
                    $oldDefaultValue = 'NULL';
                }

                // set the old default value
                if (!($results[0]['Null'] == 'NO' && $results[0]['Default'] === null)
                    && !($oldDefaultValue === 'NULL'
                         && strpos($matches[0][$matchKey], 'NOT NULL') !== false)
                    && (strpos($matches[0][$matchKey], 'BLOB') === false)
                    && (strpos($matches[0][$matchKey], 'TEXT') === false)
                ) {
                    if (preg_match('/DEFAULT/', $matches[0][$matchKey])) {
                        $matches[0][$matchKey] =
                                               preg_replace('/DEFAULT (.+?)(, CHANGE |$)/', 'DEFAULT ' .
                                                            $oldDefaultValue . '$2' . ' ' . $extra, $matches[0][$matchKey]);
                    } else {
                        $matches[0][$matchKey] =
                                               preg_replace('/(.+?)(, CHANGE |$)/uis', '$1 DEFAULT ' .
                                                            $oldDefaultValue . ' ' . $extra . '$2', $matches[0][$matchKey]);
                    }
                }

                $queries[$key] = preg_replace(
                    '/ CHANGE ' . $originalFieldName . ' (.+?)(, CHANGE |$)/uis',
                    $matches[0][$matchKey],
                    $queries[$key]
                );
            }
        }
    }
}
