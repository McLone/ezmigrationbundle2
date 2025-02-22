<?php

namespace Kaliop\eZMigrationBundle\Core\Executor;

use eZ\Publish\API\Repository\RoleService;
use eZ\Publish\API\Repository\UserService;
use eZ\Publish\API\Repository\Values\User\Limitation;
use eZ\Publish\API\Repository\Values\User\Role;
use eZ\Publish\API\Repository\Values\User\RoleDraft;
use Kaliop\eZMigrationBundle\API\Collection\RoleCollection;
use Kaliop\eZMigrationBundle\API\EnumerableMatcherInterface;
use Kaliop\eZMigrationBundle\API\Exception\InvalidStepDefinitionException;
use Kaliop\eZMigrationBundle\API\Exception\MigrationBundleException;
use Kaliop\eZMigrationBundle\API\MigrationGeneratorInterface;
use Kaliop\eZMigrationBundle\Core\Helper\LimitationConverter;
use Kaliop\eZMigrationBundle\Core\Matcher\RoleMatcher;

/**
 * Handles role migrations.
 */
class RoleManager extends RepositoryExecutor implements MigrationGeneratorInterface, EnumerableMatcherInterface
{
    protected $supportedStepTypes = array('role');
    protected $supportedActions = array('create', 'load', 'update', 'delete');

    protected $limitationConverter;
    protected $roleMatcher;

    public function __construct(RoleMatcher $roleMatcher, LimitationConverter $limitationConverter)
    {
        $this->roleMatcher = $roleMatcher;
        $this->limitationConverter = $limitationConverter;
    }

    /**
     * Method to handle the create operation of the migration instructions
     */
    protected function create($step)
    {
        $roleService = $this->repository->getRoleService();
        $userService = $this->repository->getUserService();

        $roleName = $this->resolveReference($step->dsl['name']);
        $roleCreateStruct = $roleService->newRoleCreateStruct($roleName);

        // Publish new role
        $roleDraft = $roleService->createRole($roleCreateStruct);

        if (isset($step->dsl['policies'])) {
            foreach ($step->dsl['policies'] as $key => $ymlPolicy) {
                $this->addPolicy($roleDraft, $roleService, $ymlPolicy);
            }
        }

        $roleService->publishRoleDraft($roleDraft);

        $role = $roleDraft;

        if (isset($step->dsl['assign'])) {
            $this->assignRole($roleDraft, $roleService, $userService, $step->dsl['assign']);
        }

        $this->setReferences($role, $step);

        return $role;
    }

    protected function load($step)
    {
        $roleCollection = $this->matchRoles('load', $step);

        $this->validateResultsCount($roleCollection, $step);

        $this->setReferences($roleCollection, $step);

        return $roleCollection;
    }

    /**
     * Method to handle the update operation of the migration instructions
     */
    protected function update($step)
    {
        $roleCollection = $this->matchRoles('update', $step);

        $this->validateResultsCount($roleCollection, $step);

        if (count($roleCollection) > 1 && isset($step->dsl['new_name'])) {
            throw new MigrationBundleException("Can not execute Role update because multiple roles match, and a new_name is specified in the dsl.");
        }

        $roleService = $this->repository->getRoleService();
        $userService = $this->repository->getUserService();

        /** @var \eZ\Publish\API\Repository\Values\User\Role $role */
        foreach ($roleCollection as $key => $role) {

            $roleDraft = $roleService->createRoleDraft($role);

            // Updating role name
            if (isset($step->dsl['new_name'])) {
                $update = $roleService->newRoleUpdateStruct();
                $newRoleName = $this->resolveReference($step->dsl['new_name']);
                $update->identifier = $this->resolveReference($newRoleName);

                $roleDraft = $roleService->updateRoleDraft($roleDraft, $update);
            }

            if (isset($step->dsl['policies'])) {
                $ymlPolicies = $step->dsl['policies'];

                // Removing all policies so we can add them back.
                // TODO: Check and update policies instead of remove and add.
                $policies = $roleDraft->getPolicies();
                foreach ($policies as $policyDraft) {
                    $roleDraft = $roleService->removePolicyByRoleDraft($roleDraft, $policyDraft);
                }

                foreach ($ymlPolicies as $ymlPolicy) {
                    $this->addPolicy($roleDraft, $roleService, $ymlPolicy);
                }
            }

            $roleService->publishRoleDraft($roleDraft);
            // q: is this necessary? Sadly, we don't get back a role from publishRoleDraft...
            $role = $roleService->loadRole($role->id);

            if (isset($step->dsl['assign'])) {
                $this->assignRole($role, $roleService, $userService, $step->dsl['assign']);
            }

            if (isset($step->dsl['unassign'])) {
                $this->unassignRole($role, $roleService, $userService, $step->dsl['unassign']);
            }

            $roleCollection[$key] = $role;
        }

        $this->setReferences($roleCollection, $step);

        return $roleCollection;
    }

    /**
     * Method to handle the delete operation of the migration instructions
     */
    protected function delete($step)
    {
        $roleCollection = $this->matchRoles('delete', $step);

        $this->validateResultsCount($roleCollection, $step);

        $this->setReferences($roleCollection, $step);

        $roleService = $this->repository->getRoleService();

        foreach ($roleCollection as $role) {
            $roleService->deleteRole($role);
        }

        return $roleCollection;
    }

    /**
     * @param string $action
     * @return RoleCollection
     * @throws \Exception
     */
    protected function matchRoles($action, $step)
    {
        if (!isset($step->dsl['name']) && !isset($step->dsl['match'])) {
            throw new InvalidStepDefinitionException("The name of a role or a match condition is required to $action it");
        }

        // Backwards compat
        if (isset($step->dsl['match'])) {
            $match = $step->dsl['match'];
        } else {
            $match = array('identifier' => $step->dsl['name']);
        }

        // convert the references passed in the match
        $match = $this->resolveReferencesRecursively($match);

        $tolerateMisses = isset($step->dsl['match_tolerate_misses']) ? $this->resolveReference($step->dsl['match_tolerate_misses']) : false;

        return $this->roleMatcher->match($match, $tolerateMisses);
    }

    /**
     * @param Role $role
     * @param array $references the definitions of the references to set
     * @throws InvalidStepDefinitionException
     * @return array key: the reference names, values: the reference values
     */
    protected function getReferencesValues($role, array $references, $step)
    {
        $refs = array();

        foreach ($references as $key => $reference) {
            $reference = $this->parseReferenceDefinition($key, $reference);
            switch ($reference['attribute']) {
                case 'role_id':
                case 'id':
                    $value = $role->id;
                    break;
                case 'identifier':
                case 'role_identifier':
                    $value = $role->identifier;
                    break;
                default:
                    throw new InvalidStepDefinitionException('Role Manager does not support setting references for attribute ' . $reference['attribute']);
            }

            $refs[$reference['identifier']] = $value;
        }

        return $refs;
    }

    /**
     * @param array $matchCondition
     * @param string $mode
     * @param array $context
     * @throws \Exception
     * @return array
     */
    public function generateMigration(array $matchCondition, $mode, array $context = array())
    {
        $currentUser = $this->authenticateUserByContext($context);

        try {
            $roleCollection = $this->roleMatcher->match($matchCondition);
            $data = array();

            /** @var \eZ\Publish\API\Repository\Values\User\Role $role */
            foreach ($roleCollection as $role) {
                $roleData = array(
                    'type' => reset($this->supportedStepTypes),
                    'mode' => $mode
                );

                switch ($mode) {
                    case 'create':
                        $roleData = array_merge(
                            $roleData,
                            array(
                                'name' => $role->identifier
                            )
                        );
                        break;
                    case 'update':
                    case 'delete':
                        $roleData = array_merge(
                            $roleData,
                            array(
                                'match' => array(
                                    RoleMatcher::MATCH_ROLE_IDENTIFIER => $role->identifier
                                )
                            )
                        );
                        break;
                    default:
                        throw new InvalidStepDefinitionException("Executor 'role' doesn't support mode '$mode'");
                }

                if ($mode != 'delete') {
                    $policies = array();
                    foreach ($role->getPolicies() as $policy) {
                        $limitations = array();

                        foreach ($policy->getLimitations() as $limitation) {
                            if (!($limitation instanceof Limitation)) {
                                throw new MigrationBundleException("The role contains an invalid limitation for policy {$policy->module}/{$policy->function}, we can not reliably generate its definition.");
                            }
                            $limitations[] = $this->limitationConverter->getLimitationArrayWithIdentifiers($limitation);
                        }
                        // try to sort predictably to ease diffing
                        $this->sortPolicyLimitationsDefinitions($limitations);

                        $policies[] = array(
                            'module' => $policy->module,
                            'function' => $policy->function,
                            'limitations' => $limitations
                        );
                    }
                    // try to sort predictably to ease diffing
                    $this->sortPolicyDefinitions($policies);

                    $roleData = array_merge(
                        $roleData,
                        array(
                            'policies' => $policies
                        )
                    );
                }

                $data[] = $roleData;
            }

        } finally {
            $this->authenticateUserByReference($currentUser);
        }

        return $data;
    }

    /**
     * @return string[]
     */
    public function listAllowedConditions()
    {
        return $this->roleMatcher->listAllowedConditions();
    }

    /**
     * Create a new Limitation object based on the type and value in the $limitation array.
     *
     * <pre>
     * $limitation = array(
     *  'identifier' => Type of the limitation
     *  'values' => array(Values to base the limitation on)
     * )
     * </pre>
     *
     * @param \eZ\Publish\API\Repository\RoleService $roleService
     * @param array $limitation
     * @return Limitation
     */
    protected function createLimitation(RoleService $roleService, array $limitation)
    {
        $limitationType = $roleService->getLimitationType($limitation['identifier']);

        // 1st resolve refs (if we got passed a string)
        $limitationValue = $this->resolveReference($limitation['values']);
        // then, if we have an array, resolve refs recursively
        if (is_array($limitationValue)) {
            foreach ($limitationValue as $id => $value) {
                $limitationValue[$id] = $this->resolveReference($value);
            }
        } else {
            // if still a scalar, make sure we can loop over it
            $limitationValue = array($limitationValue);
        }


        $limitationValue = $this->limitationConverter->resolveLimitationValue($limitation['identifier'], $limitationValue);
        return $limitationType->buildValue($limitationValue);
    }

    /**
     * Assign a role to users and groups in the assignment array.
     *
     * <pre>
     * $assignments = array(
     *      array(
     *          'type' => 'user',
     *          'ids' => array(user ids),
     *          'limitation' => array(limitations)
     *      )
     * )
     * </pre>
     *
     * @param \eZ\Publish\API\Repository\Values\User\Role $role
     * @param \eZ\Publish\API\Repository\RoleService $roleService
     * @param \eZ\Publish\API\Repository\UserService $userService
     * @param array $assignments
     */
    protected function assignRole(Role $role, RoleService $roleService, UserService $userService, array $assignments)
    {
        foreach ($assignments as $assign) {
            switch ($assign['type']) {
                case 'user':
                    foreach ($assign['ids'] as $userId) {
                        $userId = $this->resolveReference($userId);

                        $user = $userService->loadUser($userId);

                        if (!isset($assign['limitations'])) {
                            $roleService->assignRoleToUser($role, $user);
                        } else {
                            foreach ($assign['limitations'] as $limitation) {
                                $limitationObject = $this->createLimitation($roleService, $limitation);
                                $roleService->assignRoleToUser($role, $user, $limitationObject);
                            }
                        }
                    }
                    break;
                case 'group':
                    foreach ($assign['ids'] as $groupId) {
                        $groupId = $this->resolveReference($groupId);

                        $group = $userService->loadUserGroup($groupId);

                        if (!isset($assign['limitations'])) {
                            $roleService->assignRoleToUserGroup($role, $group);
                        } else {
                            foreach ($assign['limitations'] as $limitation) {
                                $limitationObject = $this->createLimitation($roleService, $limitation);
                                $roleService->assignRoleToUserGroup($role, $group, $limitationObject);
                            }
                        }
                    }
                    break;
                default:
                    throw new InvalidStepDefinitionException("Unsupported type '{$assign['type']}'");
            }
        }
    }

    protected function unassignRole(Role $role, RoleService $roleService, UserService $userService, array $assignments)
    {
        foreach ($assignments as $assign) {
            switch ($assign['type']) {
                case 'user':
                    foreach ($assign['ids'] as $userId) {
                        $userId = $this->resolveReference($userId);
                        $user = $userService->loadUser($userId);
                        $userRoleAssignments = $roleService->getRoleAssignmentsForUser($user);
                        foreach ($userRoleAssignments as $assignment) {
                            if ($assignment->role->id == $role->id) {
                                $roleService->removeRoleAssignment($assignment);
                            }
                        }
                    }
                    break;
                case 'group':
                    foreach ($assign['ids'] as $groupId) {
                        $groupId = $this->resolveReference($groupId);
                        $group = $userService->loadUserGroup($groupId);
                        $groupRoleAssignments = $roleService->getRoleAssignmentsForUserGroup($group);
                        foreach ($groupRoleAssignments as $assignment) {
                            if ($assignment->role->id == $role->id) {
                                $roleService->removeRoleAssignment($assignment);
                            }
                        }
                    }
                    break;
                default:
                    throw new InvalidStepDefinitionException("Unsupported type '{$assign['type']}'");
            }
        }
    }

    /**
     * Add new policies to the $role Role.
     *
     * @param \eZ\Publish\API\Repository\Values\User\Role $role
     * @param \eZ\Publish\API\Repository\RoleService $roleService
     * @param array $policy
     */
    protected function addPolicy(RoleDraft $roleDraft, RoleService $roleService, array $policy)
    {
        $policyCreateStruct = $roleService->newPolicyCreateStruct($policy['module'], $policy['function']);

        if (array_key_exists('limitations', $policy)) {
            foreach ($policy['limitations'] as $limitation) {
                $limitationObject = $this->createLimitation($roleService, $limitation);
                $policyCreateStruct->addLimitation($limitationObject);
            }
        }

        $roleService->addPolicyByRoleDraft($roleDraft, $policyCreateStruct);
    }

    protected function sortPolicyLimitationsDefinitions(array &$limitations)
    {
        usort($limitations, function($l1, $l2) {
            if (($iComp = strcmp($l1['identifier'], $l2['identifier'])) != 0 ) {
                return $iComp;
            }
            if (is_int($l1['values']) || is_float($l1['values'])) {
                return $l1['values'] - $l2['values'];
            }
            if (is_string($l1['values'])) {
                return strcmp($l1['values'], $l2['values']);
            }
            if (is_array($l1['values'])) {
                return $this->compareArraysForSorting($l1['values'], $l2['values']);
            }
        });
    }

    protected function sortPolicyDefinitions(array &$policies)
    {
        // try to sort predictably to ease diffing
        usort($policies, function($p1, $p2) {
            if (($mComp = strcmp($p1['module'], $p2['module'])) != 0) {
                return $mComp;
            }
            if (($fComp = strcmp($p1['function'], $p2['function'])) != 0) {
                return $fComp;
            }
            // ugly: sort by comparing limitations identifiers
            /// @todo if limitations identifiers are the same, sort by lim. values...
            $p1LimIds = array();
            $p2LimIds = array();
            foreach ($p1['limitations'] as $lim) {
                $p1LimIds[] = $lim['identifier'];
            }
            foreach ($p2['limitations'] as $lim) {
                $p2LimIds[] = $lim['identifier'];
            }
            return $this->compareArraysForSorting($p1LimIds, $p2LimIds);
        });
    }

    /**
     * Comparison function useful when sorting arrays based only on their values
     * @param string[] $a1
     * @param string[] $a2
     * @return bool|int
     * @todo allow sorting properly int[] and float[] arrays
     */
    protected function compareArraysForSorting($a1, $a2)
    {
        $len = min(count($a1), count($a2));
        for ($i = 0; $i < $len; $i++) {
            if ($cmp = strcmp($a1[$i], $a2[$i]) != 0) {
                return $cmp;
            }
        }
        // the array with fewer elements wins
        return count($a1) - count($a2);
    }
}
