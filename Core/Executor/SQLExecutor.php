<?php

namespace Kaliop\eZMigrationBundle\Core\Executor;

use Doctrine\DBAL\Connection;
use Kaliop\eZMigrationBundle\API\EmbeddedReferenceResolverBagInterface;
use Kaliop\eZMigrationBundle\API\Exception\InvalidMatchResultsNumberException;
use Kaliop\eZMigrationBundle\API\Exception\InvalidStepDefinitionException;
use Kaliop\eZMigrationBundle\API\Exception\MigrationBundleException;
use Kaliop\eZMigrationBundle\API\Value\MigrationStep;
use ProxyManager\Proxy\ValueHolderInterface;

/**
 * @property EmbeddedReferenceResolverBagInterface $referenceResolver
 */
class SQLExecutor extends AbstractExecutor
{
    use IgnorableStepExecutorTrait;
    use ReferenceSetterTrait;
    use NonScalarReferenceSetterTrait;

    protected $scalarReferences = array('count');

    /**
     * @var Connection $connection
     */
    protected $connection;

    protected $supportedStepTypes = array('sql');
    protected $supportedActions = array('exec', 'query');

    protected $queryRequiresFetching = false;

    /**
     * @param Connection $connection
     * @param EmbeddedReferenceResolverBagInterface $referenceResolver
     */
    public function __construct(Connection $connection, EmbeddedReferenceResolverBagInterface $referenceResolver)
    {
        $this->connection = $connection;
        $this->referenceResolver = $referenceResolver;
    }

    /**
     * @param MigrationStep $step
     * @return integer
     * @throws \Exception if migration step is not for this type of db
     */
    public function execute(MigrationStep $step)
    {
        parent::execute($step);

        // BC
        if (!isset($step->dsl['mode'])) {
            $action = 'exec';
        } else {
            $action = $step->dsl['mode'];
        }

        if (!in_array($action, $this->supportedActions)) {
            throw new InvalidStepDefinitionException("Invalid step definition: value '$action' is not allowed for 'mode'");
        }

        $this->skipStepIfNeeded($step);

        return $this->$action($step);
    }

    protected function exec($step)
    {
        $conn = $this->connection;
        // @see http://doctrine-orm.readthedocs.io/projects/doctrine-dbal/en/latest/reference/platforms.html
        $dbType = strtolower(preg_replace('/([0-9]+|Platform)/', '', $conn->getDatabasePlatform()->getName()));

        if (!isset($step->dsl[$dbType])) {
            throw new MigrationBundleException("Current database type '$dbType' is not supported by the SQL migration");
        }
        $sql = $step->dsl[$dbType];

        if (isset($step->dsl['resolve_references']) && $step->dsl['resolve_references']) {
            $sql = $this->referenceResolver->resolveEmbeddedReferences($sql);
        }

        $result = $conn->exec($sql);

        $this->setExecReferences($result, $step);

        return $result;
    }

    protected function query($step)
    {
        $conn = $this->connection;
        // @see http://doctrine-orm.readthedocs.io/projects/doctrine-dbal/en/latest/reference/platforms.html
        $dbType = strtolower(preg_replace('/([0-9]+|Platform)/', '', $conn->getDatabasePlatform()->getName()));

        if (!isset($step->dsl[$dbType])) {
            throw new MigrationBundleException("Current database type '$dbType' is not supported by the SQL migration");
        }
        $sql = $step->dsl[$dbType];

        if (isset($step->dsl['resolve_references']) && $step->dsl['resolve_references']) {
            $sql = $this->referenceResolver->resolveEmbeddedReferences($sql);
        }

        $singleResult = ($this->expectedResultsType($step) == self::$RESULT_TYPE_SINGLE);

        /** @var \Doctrine\DBAL\Driver\Statement $stmt */
        // NB: we can't use `query()` because of https://jira.ez.no/browse/EZEE-3345
        $stmt = $conn->executeQuery($sql);
        if ($singleResult) {
            // fetch only twice, to insure that we get only 1 row. This can save ram compared to fetching all rows
            $result = $stmt->fetch();
            if ($result === false) {
                throw new InvalidMatchResultsNumberException('Found no results but expect one');
            }
            if ($stmt->fetch() !== false) {
                $stmt->closeCursor();
                throw new InvalidMatchResultsNumberException('Found two (or more) results but expect only one');
            }
            $stmt->closeCursor();
            $result = array($result);
        } else {
            // fetch all rows
            $result = $stmt->fetchAll();
            $stmt->closeCursor();

            $this->validateResultsCount($result, $step);
        }

        $this->setQueryReferences($result, $step, $singleResult);

        return $result;
    }

    protected function setExecReferences($result, $step)
    {
        if (!array_key_exists('references', $step->dsl) || !count($step->dsl['references'])) {
            return false;
        }

        foreach ($step->dsl['references'] as $key => $reference) {
            $reference = $this->parseReferenceDefinition($key, $reference);
            switch ($reference['attribute']) {
                case 'affected_rows':
                    $value = $result;
                    break;
                default:
                    throw new InvalidStepDefinitionException('Sql Executor does not support setting references for attribute ' . $reference['attribute']);
            }

            $overwrite = false;
            if (isset($reference['overwrite'])) {
                $overwrite = $reference['overwrite'];
            }
            $this->addReference($reference['identifier'], $value, $overwrite);
        }

        return true;
    }

    protected function setQueryReferences($result, $step, $singleResult)
    {
        if (!array_key_exists('references', $step->dsl) || !count($step->dsl['references'])) {
            return false;
        }

        foreach ($step->dsl['references'] as $key => $reference) {
            $reference = $this->parseReferenceDefinition($key, $reference);
            switch ($reference['attribute']) {
                case 'count':
                    $value = count($result);
                    break;
                default:
                    if (strpos($reference['attribute'], 'results.') !== 0) {
                        throw new InvalidStepDefinitionException('Sql Executor does not support setting references for attribute ' . $reference['attribute']);
                    }
                    if (count($result)) {
                        $colName = substr($reference['attribute'], 8);
                        if (!isset($result[0][$colName])) {
                            /// @todo use a MigrationBundleException ?
                            throw new \InvalidArgumentException('Sql Executor does not support setting references for attribute ' . $reference['attribute']);
                        }
                        $value = array_column($result, $colName);
                        if ($singleResult) {
                            $value = reset($value);
                        }
                    } else {
                        // we should validate the requested column name, but we can't...
                        $value = array();
                    }
            }

            $overwrite = false;
            if (isset($reference['overwrite'])) {
                $overwrite = $reference['overwrite'];
            }
            $this->addReference($reference['identifier'], $value, $overwrite);
        }

        return true;
    }

    /**
     * @param array $referenceDefinition
     * @return bool
     */
    protected function isScalarReference($referenceDefinition)
    {
        return in_array($referenceDefinition['attribute'], $this->scalarReferences);
    }

    /**
     * Resets the transaction counter and all other transaction-related info for the current db connection
     * @internal
     * @return void
     */
    public function resetTransaction()
    {
        /** @var Connection $connection */
        $connection = ($this->connection instanceof ValueHolderInterface) ? $this->connection->getWrappedValueHolderValue() : $this->connection;

        $cl = \Closure::bind(function () {
               $this->transactionNestingLevel = 0;
            },
            $connection,
            $connection
        );
        $cl();
    }
}
