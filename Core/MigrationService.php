<?php

namespace Kaliop\eZMigrationBundle\Core;

use eZ\Publish\API\Repository\Repository;
use Kaliop\eZMigrationBundle\API\ReferenceBagInterface;
use Kaliop\eZMigrationBundle\API\Value\MigrationStep;
use Kaliop\eZMigrationBundle\API\Collection\MigrationDefinitionCollection;
use Kaliop\eZMigrationBundle\API\StorageHandlerInterface;
use Kaliop\eZMigrationBundle\API\LoaderInterface;
use Kaliop\eZMigrationBundle\API\DefinitionParserInterface;
use Kaliop\eZMigrationBundle\API\ExecutorInterface;
use Kaliop\eZMigrationBundle\API\ContextProviderInterface;
use Kaliop\eZMigrationBundle\API\Value\Migration;
use Kaliop\eZMigrationBundle\API\Value\MigrationDefinition;
use Kaliop\eZMigrationBundle\API\Exception\MigrationStepExecutionException;
use Kaliop\eZMigrationBundle\API\Exception\MigrationAbortedException;
use Kaliop\eZMigrationBundle\API\Exception\MigrationBundleException;
use Kaliop\eZMigrationBundle\API\Exception\MigrationSuspendedException;
use Kaliop\eZMigrationBundle\API\Exception\MigrationStepSkippedException;
use Kaliop\eZMigrationBundle\API\Exception\AfterMigrationExecutionException;
use Kaliop\eZMigrationBundle\API\Event\BeforeStepExecutionEvent;
use Kaliop\eZMigrationBundle\API\Event\StepExecutedEvent;
use Kaliop\eZMigrationBundle\API\Event\MigrationAbortedEvent;
use Kaliop\eZMigrationBundle\API\Event\MigrationSuspendedEvent;
use Kaliop\eZMigrationBundle\Core\Executor\SQLExecutor;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class MigrationService implements ContextProviderInterface
{
    use AuthenticatedUserSetterTrait;

    /**
     * The default Admin user Id, used when no Admin user is specified
     * @deprecated kept around for BC. Use ADMIN_USER_LOGIN instead
     */
    const ADMIN_USER_ID = 14;

    /**
     * The default Admin user login, used when no Admin user is specified
     */
    const ADMIN_USER_LOGIN = 'admin';

    /**
     * @var LoaderInterface $loader
     */
    protected $loader;

    /**
     * @var StorageHandlerInterface $storageHandler
     */
    protected $storageHandler;

    /** @var DefinitionParserInterface[] $DefinitionParsers */
    protected $DefinitionParsers = array();

    /** @var ExecutorInterface[] $executors */
    protected $executors = array();

    /** @var Repository $repository */
    protected $repository;

    protected $dispatcher;

    /**
     * @var ContextHandler $contextHandler
     */
    protected $contextHandler;

    protected $eventPrefix = 'ez_migration.';

    protected $eventEntity = 'migration';

    protected $migrationContext = array();

    /** @var  OutputInterface $output */
    protected $output;

    /** @var ReferenceBagInterface */
    protected $referenceResolver;

    public function __construct(LoaderInterface $loader, StorageHandlerInterface $storageHandler, Repository $repository,
        EventDispatcherInterface $eventDispatcher, $contextHandler, $referenceResolver)
    {
        $this->loader = $loader;
        $this->storageHandler = $storageHandler;
        $this->repository = $repository;
        $this->dispatcher = $eventDispatcher;
        $this->contextHandler = $contextHandler;
        $this->referenceResolver = $referenceResolver;
    }

    public function addDefinitionParser(DefinitionParserInterface $DefinitionParser)
    {
        $this->DefinitionParsers[] = $DefinitionParser;
    }

    public function addExecutor(ExecutorInterface $executor)
    {
        foreach ($executor->supportedTypes() as $type) {
            $this->executors[$type] = $executor;
        }
    }

    /**
     * @param string $type
     * @return ExecutorInterface
     * @throws \InvalidArgumentException If executor doesn't exist
     */
    public function getExecutor($type)
    {
        if (!isset($this->executors[$type])) {
            throw new \InvalidArgumentException("Executor with type '$type' doesn't exist");
        }

        return $this->executors[$type];
    }

    /**
     * @return string[]
     */
    public function listExecutors()
    {
        return array_keys($this->executors);
    }

    public function setLoader(LoaderInterface $loader)
    {
        $this->loader = $loader;
    }

    /**
     * @todo we could get rid of this by getting $output passed as argument to self::executeMigration.
     */
    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
    }

    /**
     * NB: returns UNPARSED definitions
     *
     * @param string[] $paths
     * @return MigrationDefinitionCollection key: migration name, value: migration definition as binary string
     * @throws \Exception
     */
    public function getMigrationsDefinitions(array $paths = array())
    {
        // we try to be flexible in file types we support, and the same time avoid loading all files in a directory
        $handledDefinitions = array();
        foreach ($this->loader->listAvailableDefinitions($paths) as $migrationName => $definitionPath) {
            foreach ($this->DefinitionParsers as $definitionParser) {
                if ($definitionParser->supports($migrationName)) {
                    $handledDefinitions[] = $definitionPath;
                }
            }
        }

        // we can not call loadDefinitions with an empty array using the Filesystem loader, or it will start looking in bundles...
        if (empty($handledDefinitions) && !empty($paths)) {
            return new MigrationDefinitionCollection();
        }

        return $this->loader->loadDefinitions($handledDefinitions);
    }

    /**
     * Returns the list of all the migrations which where executed or attempted so far
     *
     * @param int $limit 0 or below will be treated as 'no limit'
     * @param int $offset
     * @return \Kaliop\eZMigrationBundle\API\Collection\MigrationCollection
     */
    public function getMigrations($limit = null, $offset = null)
    {
        return $this->storageHandler->loadMigrations($limit, $offset);
    }

    /**
     * Returns the list of all the migrations in a given status which where executed or attempted so far
     *
     * @param int $status
     * @param int $limit 0 or below will be treated as 'no limit'
     * @param int $offset
     * @return \Kaliop\eZMigrationBundle\API\Collection\MigrationCollection
     */
    public function getMigrationsByStatus($status, $limit = null, $offset = null)
    {
        return $this->storageHandler->loadMigrationsByStatus($status, $limit, $offset);
    }

    public function getMigrationsByPaths(array $paths, $limit = null, $offset = null)
    {
        return $this->storageHandler->loadMigrationsByPaths($paths, $limit, $offset);
    }

    /**
     * @param string $migrationName
     * @return Migration|null
     */
    public function getMigration($migrationName)
    {
        return $this->storageHandler->loadMigration($migrationName);
    }

    /**
     * @param MigrationDefinition $migrationDefinition
     * @return Migration
     */
    public function addMigration(MigrationDefinition $migrationDefinition)
    {
        return $this->storageHandler->addMigration($migrationDefinition);
    }

    /**
     * @param Migration $migration
     */
    public function deleteMigration(Migration $migration)
    {
        return $this->storageHandler->deleteMigration($migration);
    }

    /**
     * @param MigrationDefinition $migrationDefinition
     * @return Migration
     */
    public function skipMigration(MigrationDefinition $migrationDefinition)
    {
        return $this->storageHandler->skipMigration($migrationDefinition);
    }

    /**
     * Not to be called by external users for normal use cases, you should use executeMigration() instead
     *
     * @param Migration $migration
     */
    public function endMigration(Migration $migration)
    {
        return $this->storageHandler->endMigration($migration);
    }

    /**
     * Not to be called by external users for normal use cases, you should use executeMigration() instead.
     * NB: will act regardless of current migration status.
     *
     * @param Migration $migration
     */
    public function failMigration(Migration $migration, $errorMessage)
    {
        return $this->storageHandler->endMigration(
            new Migration(
                $migration->name,
                $migration->md5,
                $migration->path,
                $migration->executionDate,
                Migration::STATUS_FAILED,
                $errorMessage
            ),
            true
        );
    }

    /**
     * Parses a migration definition, return a parsed definition.
     * If there is a parsing error, the definition status will be updated accordingly
     *
     * @param MigrationDefinition $migrationDefinition
     * @return MigrationDefinition
     * @throws \Exception if the migrationDefinition has no suitable parser for its source format
     */
    public function parseMigrationDefinition(MigrationDefinition $migrationDefinition)
    {
        foreach ($this->DefinitionParsers as $definitionParser) {
            if ($definitionParser->supports($migrationDefinition->name)) {
                // parse the source file
                $migrationDefinition = $definitionParser->parseMigrationDefinition($migrationDefinition);

                // and make sure we know how to handle all steps
                foreach ($migrationDefinition->steps as $step) {
                    if (!isset($this->executors[$step->type])) {
                        return new MigrationDefinition(
                            $migrationDefinition->name,
                            $migrationDefinition->path,
                            $migrationDefinition->rawDefinition,
                            MigrationDefinition::STATUS_INVALID,
                            array(),
                            "Can not handle migration step of type '{$step->type}'"
                        );
                    }
                }

                return $migrationDefinition;
            }
        }

        throw new MigrationBundleException("No parser available to parse migration definition '{$migrationDefinition->name}'");
    }

    /**
     * @param MigrationDefinition $migrationDefinition
     * @param array $migrationContext Supported array keys are: adminUserLogin, defaultLanguageCode,
     *                                forcedReferences, forceExecution, forceSigchildEnabled, userContentType, userGroupContentType,
     *                                useTransaction.
     * @throws \Exception
     *
     * @todo add support for setting in $migrationContext a userContentType, userGroupContentType ?
     * @todo treating a null and false $adminLogin values differently is prone to hard-to-track errors.
     *       Shall we use instead -1 to indicate the desire to not-login-as-admin-user-at-all ?
     */
    public function executeMigration(MigrationDefinition $migrationDefinition, $migrationContext = array())
    {
        if ($migrationDefinition->status == MigrationDefinition::STATUS_TO_PARSE) {
            $migrationDefinition = $this->parseMigrationDefinition($migrationDefinition);
        }

        if ($migrationDefinition->status == MigrationDefinition::STATUS_INVALID) {
            throw new MigrationBundleException("Can not execute " . $this->getEntityName($migrationDefinition). " '{$migrationDefinition->name}': {$migrationDefinition->parsingError}");
        }

        if ($this->output) {
            $migrationContext['output'] = $this->output;
        }
        $forceExecution = array_key_exists('forceExecution', $migrationContext) ? $migrationContext['forceExecution'] : false;

        // set migration as begun - has to be in own db transaction
        $migration = $this->storageHandler->startMigration($migrationDefinition, $forceExecution);

        $this->executeMigrationInner($migration, $migrationDefinition, $migrationContext);
    }

    /**
     * @param Migration $migration
     * @param MigrationDefinition $migrationDefinition
     * @param array $migrationContext
     * @param int $stepOffset
     * @throws \Exception
     */
    protected function executeMigrationInner(Migration $migration, MigrationDefinition $migrationDefinition,
        $migrationContext, $stepOffset = 0)
    {
        $useTransaction = array_key_exists('useTransaction', $migrationContext) ? $migrationContext['useTransaction'] : true;
        $adminLogin = array_key_exists('adminLogin', $migrationContext) ? $migrationContext['adminLogin'] : null;

        /// @todo can we make this validation smarter / move it somewhere else?
        if (array_key_exists('path', $migrationContext) || array_key_exists('contentTypeIdentifier', $migrationContext) ||
            array_key_exists('fieldIdentifier', $migrationContext)) {
            throw new MigrationBundleException("Invalid call to executeMigrationInner: forbidden elements in migrationContext");
        }

        $messageSuffix = '';
        if (isset($migrationContext['forcedReferences']) && count($migrationContext['forcedReferences'])) {
            $messageSuffix = array();
            foreach ($migrationContext['forcedReferences'] as $name => $value) {
                $this->referenceResolver->addReference($name, $value, true);
                $messageSuffix[] = "$name: $value";
            }
            $messageSuffix = 'Injected references: ' . implode(', ', $messageSuffix);
        }

        if ($useTransaction) {
            $this->repository->beginTransaction();
        }

        $this->migrationContext[$migration->name] = array('context' => $migrationContext);
        $usedSqlExecutor = false;
        $steps = array_slice($migrationDefinition->steps->getArrayCopy(), $stepOffset);

        try {

            $i = $stepOffset+1;
            $finalStatus = Migration::STATUS_DONE;
            $finalMessage = '';

            try {

                foreach ($steps as $step) {
                    // save enough data in the context to be able to successfully suspend/resume
                    $this->migrationContext[$migration->name]['step'] = $i;

                    $step = $this->injectContextIntoStep($step, array_merge($migrationContext, array('step' => $i)));

                    // we validated the fact that we have a good executor at parsing time
                    $executor = $this->executors[$step->type];

                    $beforeStepExecutionEvent = new BeforeStepExecutionEvent($step, $executor);
                    $this->dispatcher->dispatch($beforeStepExecutionEvent, $this->eventPrefix . 'before_execution');
                    // allow some sneaky trickery here: event listeners can manipulate 'live' the step definition and the executor
                    $executor = $beforeStepExecutionEvent->getExecutor();
                    if ($executor instanceof SQLExecutor) {
                        $usedSqlExecutor = $executor;
                    }
                    $step = $beforeStepExecutionEvent->getStep();

                    try {
                        $result = $executor->execute($step);

                        $this->dispatcher->dispatch(new StepExecutedEvent($step, $result), $this->eventPrefix . 'step_executed');
                    } catch (MigrationStepSkippedException $e) {
                        continue;
                    }

                    $i++;
                }

            } catch (MigrationAbortedException $e) {
                // allow a migration step (or events) to abort the migration via a specific exception

                $this->dispatcher->dispatch(new MigrationAbortedEvent($step, $e), $this->eventPrefix . $this->eventEntity . '_aborted');

                $finalStatus = $e->getCode();
                $finalMessage = "Abort in execution of step $i: " . $e->getMessage();
            } catch (MigrationSuspendedException $e) {
                // allow a migration step (or events) to suspend the migration via a specific exception

                $this->dispatcher->dispatch(new MigrationSuspendedEvent($step, $e), $this->eventPrefix . $this->eventEntity . '_suspended');

                // let the context handler store our context, along with context data from any other (tagged) service which has some
                $this->contextHandler->storeCurrentContext($migration->name);

                $finalStatus = Migration::STATUS_SUSPENDED;
                $finalMessage = "Suspended in execution of step $i: " . $e->getMessage();
            }

            $finalMessage = ($finalMessage != '' && $messageSuffix != '') ? $finalMessage . '. '. $messageSuffix : $finalMessage . $messageSuffix;

            // in case we have an exception thrown in the commit phase after the last step, make sure we report the correct step
            $i--;

            // set migration as done
            $this->storageHandler->endMigration(new Migration(
                $migration->name,
                $migration->md5,
                $migration->path,
                $migration->executionDate,
                $finalStatus,
                $finalMessage
            ));

            if ($useTransaction) {
                $isCommitting = false;

                // there might be workflows or other actions happening at commit time that fail if we are not admin
                /// @todo optimization - check the id of the admin user, and if it matches the current user, avoid setting it
                $currentUser = $this->getCurrentUser();
                $this->authenticateUserByLogin($adminLogin ?? self::ADMIN_USER_LOGIN);
                $previousUser = $currentUser;

                try {
                    $isCommitting = true;
                    $this->repository->commit();
                    $isCommitting = false;
                } catch (\RuntimeException $e) {
                    // When running some DDL queries, some databases (eg. mysql, oracle) do commit any pending transaction.
                    // Since php 8.0, the PDO driver does properly take that into account, and throws an exception
                    // when we try to commit here. Short of analyzing any executed migration step checking for execution
                    // of DDL, all we can do is swallow the error.
                    // NB: what we get is a chain: RuntimeException/RuntimeException/PDOException. Should we validate it fully?
                    if ($usedSqlExecutor && $e->getMessage() == 'There is no active transaction') {
                        /// @todo either output a warning, or save it in the migration status
                        $isCommitting = false;
                        $usedSqlExecutor->resetTransaction();
                    } else {
                        throw $e;
                    }
                } finally {
                    if ($previousUser) {
                        $this->authenticateUserByReference($previousUser);
                        $previousUser = null;
                    }
                }
            }

        } catch (\Throwable $e) {
            /// @todo shall we emit a signal as well?

            $errorMessage = $this->getFullExceptionMessage($e) . ' in file ' . $e->getFile() . ' line ' . $e->getLine();
            $finalStatus = Migration::STATUS_FAILED;
            $exception = null;

            if ($useTransaction) {
                try {

                    // there is no need to be admin here, at least in theory
                    $this->repository->rollBack();

                } catch (\Throwable $e2) {
                    // This check is not rock-solid, but at the moment is the best we can do to tell apart 2 cases of
                    // exceptions originating above: the case where the commit was successful but handling of a commit-queue
                    // signal failed, from the case where something failed beforehand.
                    // Known cases for signals failing at commit time include fe. https://jira.ez.no/browse/EZP-29333
                    if ($isCommitting && $e2 instanceof \RuntimeException && $e2->getMessage() == 'There is no active transaction') {
                        // since all the migration steps succeeded and it was committed (because there was nothing to roll back), no use to mark it as failed...
                        $finalStatus = Migration::STATUS_DONE;
                        $errorMessage = 'An exception was thrown after committing, in file ' .
                            $e->getFile() . ' line ' . $e->getLine() . ': ' . $this->getFullExceptionMessage($e);
                        $exception = new AfterMigrationExecutionException($errorMessage, $i, $e);
                    } else {

                        $errorMessage .= '. In addition, an exception was thrown while rolling back, in file ' .
                            $e2->getFile() . ' line ' . $e2->getLine() . ': ' . $this->getFullExceptionMessage($e2);
                    }
                }
            }

            $errorMessage = ($errorMessage != '' && $messageSuffix != '') ? $errorMessage . '. '. $messageSuffix : $errorMessage . $messageSuffix;

            // set migration as failed
            // NB: we use the 'force' flag here because we might be catching an exception happened during the call to
            // $this->repository->commit() above, in which case the Migration might already be in the DB with a status 'done'
            $this->storageHandler->endMigration(
                new Migration(
                    $migration->name,
                    $migration->md5,
                    $migration->path,
                    $migration->executionDate,
                    $finalStatus,
                    $errorMessage
                ),
                true
            );

            throw ($exception ? $exception : new MigrationStepExecutionException($errorMessage, $i, $e));
        }
    }

    /**
     * @param Migration $migration
     * @param array $migrationContext see executeMigration
     * @throws \Exception
     *
     * @todo add support for adminLogin ?
     */
    public function resumeMigration(Migration $migration, $migrationContext = array())
    {
        if ($migration->status != Migration::STATUS_SUSPENDED) {
            throw new MigrationBundleException("Can not resume ".$this->getEntityName($migration)." '{$migration->name}': it is not in suspended status");
        }

        $migrationDefinitions = $this->getMigrationsDefinitions(array($migration->path));
        if (!count($migrationDefinitions)) {
            throw new MigrationBundleException("Can not resume ".$this->getEntityName($migration)." '{$migration->name}': its definition is missing");
        }

        $defs = $migrationDefinitions->getArrayCopy();
        $migrationDefinition = reset($defs);

        $migrationDefinition = $this->parseMigrationDefinition($migrationDefinition);
        if ($migrationDefinition->status == MigrationDefinition::STATUS_INVALID) {
            throw new MigrationBundleException("Can not resume ".$this->getEntityName($migration)." '{$migration->name}': {$migrationDefinition->parsingError}");
        }

        // restore context
        $this->contextHandler->restoreCurrentContext($migration->name);
        if (!isset($this->migrationContext[$migration->name])) {
            throw new MigrationBundleException("Can not resume ".$this->getEntityName($migration)." '{$migration->name}': the stored context is missing");
        }
        $restoredContext = $this->migrationContext[$migration->name];
        if (!is_array($restoredContext) || !isset($restoredContext['context']) || !isset($restoredContext['step'])) {
            throw new MigrationBundleException("Can not resume ".$this->getEntityName($migration)." '{$migration->name}': the stored context is invalid");
        }

        // update migration status
        $migration = $this->storageHandler->resumeMigration($migration);

        // clean up restored context - ideally it should be in the same db transaction as the line above
        $this->contextHandler->deleteContext($migration->name);

        // and go
        // note: we store the current step counting starting at 1, but use offset starting at 0, hence the -1 here
        $this->executeMigrationInner($migration, $migrationDefinition, array_merge($restoredContext['context'], $migrationContext),
            $restoredContext['step'] - 1);
    }

    protected function injectContextIntoStep(MigrationStep $step, array $context)
    {
        return new MigrationStep(
            $step->type,
            $step->dsl,
            array_merge($step->context, $context)
        );
    }

    /**
     * Turns eZPublish cryptic exceptions into something more palatable for random devs
     * @todo should this be moved to a lower layer ?
     *
     * @param \Throwable $e
     * @return string
     */
    protected function getFullExceptionMessage(\Throwable $e)
    {
        $message = $e->getMessage();
        if (is_a($e, '\eZ\Publish\API\Repository\Exceptions\ContentTypeFieldDefinitionValidationException') ||
            is_a($e, '\eZ\Publish\API\Repository\Exceptions\LimitationValidationException') ||
            is_a($e, '\eZ\Publish\Core\Base\Exceptions\ContentFieldValidationException')
        ) {
            if (is_a($e, '\eZ\Publish\API\Repository\Exceptions\LimitationValidationException')) {
                $errorsArray = $e->getLimitationErrors();
                if ($errorsArray == null) {
                    return $message;
                }
            } else if (is_a($e, '\eZ\Publish\Core\Base\Exceptions\ContentFieldValidationException')) {
                $errorsArray = array();
                foreach ($e->getFieldErrors() as $limitationError) {
                    // we get the 1st language
                    $errorsArray[] = reset($limitationError);
                }
            } else {
                $errorsArray = $e->getFieldErrors();
            }

            foreach ($errorsArray as $errors) {
                // sometimes error arrays are 2-level deep, sometimes 1...
                if (!is_array($errors)) {
                    $errors = array($errors);
                }
                foreach ($errors as $error) {
                    /// @todo find out what is the proper eZ way of getting a translated message for these errors
                    $translatableMessage = $error->getTranslatableMessage();
                    if (is_a($translatableMessage, '\eZ\Publish\API\Repository\Values\Translation\Plural')) {
                        $msgText = $translatableMessage->plural;
                    } else {
                        $msgText = $translatableMessage->message;
                    }

                    $message .= "\n" . $msgText . " - " . var_export($translatableMessage->values, true);
                }
            }
        }

        while (($e = $e->getPrevious()) != null) {
            $message .= "\n" . $e->getMessage();
        }

        return $message;
    }

    /**
     * @param string $migrationName
     * @return array
     */
    public function getCurrentContext($migrationName)
    {
        if (!isset($this->migrationContext[$migrationName]))
            return null;
        $context = $this->migrationContext[$migrationName];
        // avoid attempting to store the current outputInterface when saving the context
        if (isset($context['output'])) {
            unset($context['output']);
        }
        return $context;
    }

    /**
     * This gets called when we call $this->contextHandler->restoreCurrentContext().
     * @param string $migrationName
     * @param array $context
     */
    public function restoreContext($migrationName, array $context)
    {
        $this->migrationContext[$migrationName] = $context;
        if ($this->output) {
            $this->migrationContext['output'] = $this->output;
        }
    }

    protected function getEntityName($migration)
    {
        $array = explode('\\', get_class($migration));
        return strtolower(preg_replace('/Definition$/', '', end($array)));
    }
}
