<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Setup\Declaration\Schema\Db\MySQL;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\ConnectionException;
use Magento\Framework\DB\Adapter\SqlVersionProvider;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\Declaration\Schema\Db\DbSchemaWriterInterface;
use Magento\Framework\Setup\Declaration\Schema\Db\Statement;
use Magento\Framework\Setup\Declaration\Schema\Db\StatementAggregator;
use Magento\Framework\Setup\Declaration\Schema\Db\StatementFactory;
use Magento\Framework\Setup\Declaration\Schema\Dto\Column;
use Magento\Framework\Setup\Declaration\Schema\Dto\Constraint;
use Magento\Framework\Setup\Declaration\Schema\Dto\Constraints\Reference;
use Magento\Framework\Setup\Declaration\Schema\DryRunLogger;

/**
 * @inheritdoc
 */
class DbSchemaWriter implements DbSchemaWriterInterface
{
    /**
     * Statement directives with which we will decide what to do with tables.
     *
     * @var array
     */
    private $statementDirectives = [
        self::ALTER_TYPE => 'ALTER TABLE %s %s',
        self::CREATE_TYPE => 'CREATE TABLE %s %s',
        self::DROP_TYPE => 'DROP TABLE %s'
    ];

    /**
     * Table options mapping
     *
     * @var array
     */
    private $tableOptions = [
        'charset' => 'DEFAULT CHARSET',
        'collation' => 'DEFAULT COLLATE',
        'engine' => 'ENGINE',
        'comment' => 'COMMENT'
    ];

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var StatementFactory
     */
    private $statementFactory;

    /**
     * @var DryRunLogger
     */
    private $dryRunLogger;

    /**
     * @var SqlVersionProvider
     */
    private $sqlVersionProvider;

    /**
     * @param ResourceConnection $resourceConnection
     * @param StatementFactory $statementFactory
     * @param DryRunLogger $dryRunLogger
     * @param SqlVersionProvider $sqlVersionProvider
     * @param array $tableOptions
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        StatementFactory $statementFactory,
        DryRunLogger $dryRunLogger,
        SqlVersionProvider $sqlVersionProvider,
        array $tableOptions = []
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->statementFactory = $statementFactory;
        $this->dryRunLogger = $dryRunLogger;
        $this->tableOptions = array_replace($this->tableOptions, $tableOptions);
        $this->sqlVersionProvider = $sqlVersionProvider;
    }

    /**
     * @inheritdoc
     */
    public function createTable($tableName, $resource, array $definition, array $options)
    {
        $sql = sprintf(
            "(\n%s\n) ENGINE=%s DEFAULT CHARSET=%s DEFAULT COLLATE=%s %s",
            implode(", \n", $definition),
            $options['engine'],
            $options['charset'],
            $options['collation'],
            isset($options['comment']) ? sprintf('COMMENT="%s"', $options['comment']) : ''
        );

        return $this->statementFactory->create(
            $tableName,
            $tableName,
            self::CREATE_TYPE,
            $sql,
            $resource
        );
    }

    /**
     * @inheritdoc
     */
    public function dropTable($tableName, $resource)
    {
        return $this->statementFactory->create(
            $tableName,
            $tableName,
            self::DROP_TYPE,
            '',
            $resource
        );
    }

    /**
     * No names specified for Primary Keys.
     *
     * As MySQL do not have DROP CONSTRAINT syntax, different DROP statements for different operations are required.
     *
     * @param  string $type
     * @param  string $name
     * @return string
     */
    private function getDropElementSQL($type, $name)
    {
        $result = sprintf('DROP COLUMN %s', $name);
        switch ($type) {
            case Constraint::PRIMARY_TYPE:
                $result = 'DROP PRIMARY KEY';
                break;
            case Constraint::UNIQUE_TYPE:
                $result = sprintf('DROP KEY %s', $name);
                break;
            case \Magento\Framework\Setup\Declaration\Schema\Dto\Index::TYPE:
                $result = sprintf('DROP INDEX %s', $name);
                break;
            case Reference::TYPE:
                $result = sprintf('DROP FOREIGN KEY %s', $name);
                break;
        }

        return $result;
    }

    /**
     * @inheritdoc
     *
     * @param string $elementName
     * @param string $resource
     * @param string $tableName
     * @param string $elementDefinition , for example: like CHAR(200) NOT NULL
     * @param string $elementType
     * @return Statement
     */
    public function addElement($elementName, $resource, $tableName, $elementDefinition, $elementType)
    {
        $addElementSyntax = $elementType === Column::TYPE ? 'ADD COLUMN %s' : 'ADD %s';
        $sql = sprintf(
            $addElementSyntax,
            $elementDefinition
        );
        return $this->statementFactory->create(
            $elementName,
            $tableName,
            self::ALTER_TYPE,
            $sql,
            $resource,
            $elementType
        );
    }

    /**
     * @inheritdoc
     *
     * @param string $tableName
     * @param string $resource
     * @param string $optionName
     * @param string $optionValue
     * @return Statement
     */
    public function modifyTableOption($tableName, $resource, $optionName, $optionValue)
    {
        return $this->statementFactory->create(
            $tableName,
            $tableName,
            self::ALTER_TYPE,
            sprintf("%s='%s'", $this->tableOptions[$optionName], $optionValue),
            $resource
        );
    }

    /**
     * @inheritdoc
     *
     * @param  string $columnName
     * @param  string $resource
     * @param  string $tableName
     * @param  string $columnDefinition
     * @return Statement
     */
    public function modifyColumn($columnName, $resource, $tableName, $columnDefinition)
    {
        $sql = sprintf(
            'MODIFY COLUMN %s',
            $columnDefinition
        );
        return $this->statementFactory->create(
            $columnName,
            $tableName,
            self::ALTER_TYPE,
            $sql,
            $resource
        );
    }

    /**
     * @inheritdoc
     *
     * @param string $resource
     * @param string $elementName
     * @param string $tableName
     * @param string $type
     * @return Statement
     */
    public function dropElement($resource, $elementName, $tableName, $type)
    {
        $adapter = $this->resourceConnection->getConnection($resource);

        $sql = sprintf(
            '%s',
            $this->getDropElementSQL(
                $type,
                $adapter->quoteIdentifier($elementName)
            )
        );
        return $this->statementFactory->create(
            $elementName,
            $tableName,
            self::ALTER_TYPE,
            $sql,
            $resource,
            $type
        );
    }

    /**
     * @inheritdoc
     */
    public function resetAutoIncrement($tableName, $resource)
    {
        $autoIncrementValue = $this->getNextAutoIncrementValue($tableName, $resource);
        $sql = "AUTO_INCREMENT = {$autoIncrementValue}";

        return $this->statementFactory->create(
            sprintf('RESET_AUTOINCREMENT_%s', $tableName),
            $tableName,
            self::ALTER_TYPE,
            $sql,
            $resource
        );
    }

    /**
     * @inheritdoc
     */
    public function compile(StatementAggregator $statementAggregator, $dryRun)
    {
        foreach ($statementAggregator->getStatementsBank() as $statementBank) {
            $statementsSql = [];
            $statement = null;

            /**
             * @var Statement $statement
             */
            foreach ($statementBank as $statement) {
                $statementsSql[] = $statement->getStatement();
            }
            $adapter = $this->resourceConnection->getConnection($statement->getResource());

            if ($dryRun) {
                $this->dryRunLogger->log(
                    sprintf(
                        $this->statementDirectives[$statement->getType()],
                        $adapter->quoteIdentifier($statement->getTableName()),
                        implode(", ", $statementsSql)
                    )
                );
            } else {
                $this->doQuery($adapter, $statementsSql, $statement);
                //Do post update, like SQL DML operations or etc...
                foreach ($statement->getTriggers() as $trigger) {
                    call_user_func($trigger);
                }
            }
        }
    }

    /**
     * Check if we can concatenate sql into one statement
     *
     * Due to issues with some versions of MariaBD such statements
     * may produce errors, e.g. with foreign key definition with column modification
     *
     * @return bool
     * @throws ConnectionException
     */
    private function isNeedToSplitSql() : bool
    {
        return str_contains($this->sqlVersionProvider->getSqlVersion(), SqlVersionProvider::MARIA_DB_10_VERSION);
    }

    /**
     * Perform queries based on statements
     *
     * @param AdapterInterface $adapter
     * @param array $statementsSql
     * @param Statement $statement
     * @return void
     * @throws ConnectionException
     */
    private function doQuery(
        AdapterInterface $adapter,
        array $statementsSql,
        Statement $statement
    ) : void {
        if ($this->isNeedToSplitSql()) {
            $canBeCombinedStatements = [];
            $separatedStatements = [];
            foreach ($statementsSql as $statementSql) {
                if (str_contains($statementSql, 'FOREIGN KEY')) {
                    $separatedStatements[] = $statementSql;
                } else {
                    $canBeCombinedStatements[] = $statementSql;
                }
            }
            if (!empty($canBeCombinedStatements)) {
                $adapter->query(
                    sprintf(
                        $this->statementDirectives[$statement->getType()],
                        $adapter->quoteIdentifier($statement->getTableName()),
                        implode(", ", $canBeCombinedStatements)
                    )
                );
            }
            foreach ($separatedStatements as $separatedStatement) {
                $adapter->query(
                    sprintf(
                        $this->statementDirectives[$statement->getType()],
                        $adapter->quoteIdentifier($statement->getTableName()),
                        $separatedStatement
                    )
                );
            }
        } else {
            $adapter->query(
                sprintf(
                    $this->statementDirectives[$statement->getType()],
                    $adapter->quoteIdentifier($statement->getTableName()),
                    implode(", ", $statementsSql)
                )
            );
        }
    }

    /**
     * Retrieve next value for AUTO_INCREMENT column.
     *
     * @param string $tableName
     * @param string $resource
     * @return int
     * @throws \Zend_Db_Statement_Exception
     */
    private function getNextAutoIncrementValue(string $tableName, string $resource): int
    {
        $adapter = $this->resourceConnection->getConnection($resource);
        $autoIncrementField = $adapter->getAutoIncrementField($tableName);
        if ($autoIncrementField) {
            $sql = sprintf('SELECT MAX(`%s`) + 1 FROM `%s`', $autoIncrementField, $tableName);
            $adapter->resetDdlCache($tableName);
            $stmt = $adapter->query($sql);

            return (int)$stmt->fetchColumn();
        } else {
            return 1;
        }
    }
}
