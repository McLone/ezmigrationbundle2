<?php

namespace Kaliop\eZMigrationBundle\Core\Executor;

use eZ\Publish\API\Repository\ContentTypeService;
use eZ\Publish\API\Repository\Values\ContentType\ContentType;
use eZ\Publish\API\Repository\Values\ContentType\FieldDefinition;
use eZ\Publish\API\Repository\Exceptions\NotFoundException;
use Kaliop\eZMigrationBundle\API\Collection\ContentTypeCollection;
use Kaliop\eZMigrationBundle\API\Exception\InvalidStepDefinitionException;
use Kaliop\eZMigrationBundle\API\Exception\MigrationBundleException;
use Kaliop\eZMigrationBundle\API\MigrationGeneratorInterface;
use Kaliop\eZMigrationBundle\API\EnumerableMatcherInterface;
use Kaliop\eZMigrationBundle\API\ReferenceResolverInterface;
use Kaliop\eZMigrationBundle\Core\Helper\SortConverter;
use Kaliop\eZMigrationBundle\Core\Matcher\ContentTypeMatcher;
use Kaliop\eZMigrationBundle\Core\Matcher\ContentTypeGroupMatcher;
use Kaliop\eZMigrationBundle\Core\FieldHandlerManager;
use JmesPath\Env as JmesPath;

/**
 * Handles content type migrations.
 */
class ContentTypeManager extends RepositoryExecutor implements MigrationGeneratorInterface, EnumerableMatcherInterface
{
    protected $supportedActions = array('create', 'load', 'update', 'delete');
    protected $supportedStepTypes = array('content_type');

    protected $contentTypeMatcher;
    protected $contentTypeGroupMatcher;
    // This resolver is used to resolve references in content-type settings definitions
    protected $extendedReferenceResolver;
    protected $fieldHandlerManager;
    protected $sortConverter;

    public function __construct(ContentTypeMatcher $matcher, ContentTypeGroupMatcher $contentTypeGroupMatcher,
                                ReferenceResolverInterface $extendedReferenceResolver, FieldHandlerManager $fieldHandlerManager,
                                SortConverter $sortConverter)
    {
        $this->contentTypeMatcher = $matcher;
        $this->contentTypeGroupMatcher = $contentTypeGroupMatcher;
        $this->extendedReferenceResolver = $extendedReferenceResolver;
        $this->fieldHandlerManager = $fieldHandlerManager;
        $this->sortConverter = $sortConverter;
    }

    /**
     * Method to handle the create operation of the migration instructions
     */
    protected function create($step)
    {
        foreach (array('identifier', 'content_type_group', 'name_pattern', 'name', 'attributes') as $key) {
            if (!isset($step->dsl[$key])) {
                throw new InvalidStepDefinitionException("The '$key' key is missing in a content type creation definition");
            }
        }

        $contentTypeService = $this->repository->getContentTypeService();
        $lang = $this->getLanguageCode($step);

        $contentTypeGroupId = $step->dsl['content_type_group'];
        $contentTypeGroupId = $this->resolveReference($contentTypeGroupId);
        $contentTypeGroup = $this->contentTypeGroupMatcher->matchOneByKey($contentTypeGroupId);

        $contentTypeIdentifier = $this->resolveReference($step->dsl['identifier']);
        $contentTypeCreateStruct = $contentTypeService->newContentTypeCreateStruct($contentTypeIdentifier);
        $contentTypeCreateStruct->mainLanguageCode = $lang;

        // Object Name pattern
        $contentTypeCreateStruct->nameSchema = $step->dsl['name_pattern'];

        // set names for the content type
        $contentTypeCreateStruct->names = $this->getMultilingualValue($step->dsl['name'], $lang);

        if (isset($step->dsl['description'])) {
            // set description for the content type
            $contentTypeCreateStruct->descriptions = $this->getMultilingualValue($step->dsl['description'], $lang);
        }

        if (isset($step->dsl['url_name_pattern'])) {
            $contentTypeCreateStruct->urlAliasSchema = $step->dsl['url_name_pattern'];
        }

        if (isset($step->dsl['is_container'])) {
            $contentTypeCreateStruct->isContainer = $step->dsl['is_container'];
        }

        if (isset($step->dsl['default_always_available'])) {
            $contentTypeCreateStruct->defaultAlwaysAvailable = $step->dsl['default_always_available'];
        }

        if (isset($step->dsl['default_sort_field'])) {
            $contentTypeCreateStruct->defaultSortField = $this->sortConverter->hash2SortField($step->dsl['default_sort_field']);
        }

        if (isset($step->dsl['default_sort_order'])) {
            $contentTypeCreateStruct->defaultSortOrder = $this->sortConverter->hash2SortOrder($step->dsl['default_sort_order']);
        }

        // Add attributes
        // NB: seems like eZ gets mixed up if we pass some attributes with a position and some without...
        // We go out of our way to avoid collisions and preserve an order: fields without position go *last*
        $maxFieldDefinitionPos = 0;
        $fieldDefinitions = array();
        foreach ($step->dsl['attributes'] as $position => $attribute) {
            // allow easy reuse of attribute defs by storing them in references
            if (is_string($attribute)) {
                $attribute = $this->resolveReference($attribute);
            }
            $fieldDefinition = $this->createFieldDefinition($contentTypeService, $attribute, $contentTypeIdentifier, $lang);
            $maxFieldDefinitionPos = $fieldDefinition->position > $maxFieldDefinitionPos ? $fieldDefinition->position : $maxFieldDefinitionPos;
            $fieldDefinitions[] = $fieldDefinition;
        }
        foreach ($fieldDefinitions as $fieldDefinition) {
            if ($fieldDefinition->position == 0) {
                $fieldDefinition->position = ++$maxFieldDefinitionPos;
            }
            $contentTypeCreateStruct->addFieldDefinition($fieldDefinition);
        }

        // Publish new class
        $contentTypeDraft = $contentTypeService->createContentType($contentTypeCreateStruct, array($contentTypeGroup));
        $contentTypeService->publishContentTypeDraft($contentTypeDraft);

        // Set references
        $contentType = $contentTypeService->loadContentTypeByIdentifier($contentTypeDraft->identifier);
        $this->setReferences($contentType, $step);

        return $contentType;
    }

    protected function load($step)
    {
        $contentTypeCollection = $this->matchContentTypes('load', $step);

        $this->validateResultsCount($contentTypeCollection, $step);

        $this->setReferences($contentTypeCollection, $step);

        return $contentTypeCollection;
    }

    /**
     * Method to handle the update operation of the migration instructions
     */
    protected function update($step)
    {
        $contentTypeCollection = $this->matchContentTypes('update', $step);

        $this->validateResultsCount($contentTypeCollection, $step);

        if (count($contentTypeCollection) > 1 && array_key_exists('new_identifier', $step->dsl)) {
            throw new MigrationBundleException("Can not execute Content Type update because multiple Content Types match, and a new_identifier is specified in the dsl.");
        }

        $contentTypeService = $this->repository->getContentTypeService();
        $lang = $this->getLanguageCode($step);
        foreach ($contentTypeCollection as $key => $contentType) {

            if (isset($step->dsl['remove_drafts']) && $step->dsl['remove_drafts']) {
                try {
                    $draft = $contentTypeService->loadContentTypeDraft($contentType->id);
                    $contentTypeService->deleteContentType($draft);
                } catch (NotFoundException $e) {
                }
            }

            $contentTypeDraft = $contentTypeService->createContentTypeDraft($contentType);

            $contentTypeUpdateStruct = $contentTypeService->newContentTypeUpdateStruct();
            $contentTypeUpdateStruct->mainLanguageCode = $lang;

            $newIdentifier = null;
            if (isset($step->dsl['new_identifier'])) {
                $newIdentifier = $this->resolveReference($step->dsl['new_identifier']);
                $contentTypeUpdateStruct->identifier = $newIdentifier;
            }

            if (isset($step->dsl['name'])) {
                $contentTypeUpdateStruct->names = $this->getMultilingualValue($step->dsl['name'], $lang, $contentTypeDraft->getNames());
            }

            if (isset($step->dsl['description'])) {
                $contentTypeUpdateStruct->descriptions = $this->getMultilingualValue($step->dsl['description'], $lang, $contentTypeDraft->getDescriptions());
            }

            if (isset($step->dsl['name_pattern'])) {
                $contentTypeUpdateStruct->nameSchema = $step->dsl['name_pattern'];
            }

            if (isset($step->dsl['url_name_pattern'])) {
                $contentTypeUpdateStruct->urlAliasSchema = $step->dsl['url_name_pattern'];
            }

            if (isset($step->dsl['is_container'])) {
                $contentTypeUpdateStruct->isContainer = $step->dsl['is_container'];
            }

            if (isset($step->dsl['default_always_available'])) {
                $contentTypeUpdateStruct->defaultAlwaysAvailable = $step->dsl['default_always_available'];
            }

            if (isset($step->dsl['default_sort_field'])) {
                $contentTypeUpdateStruct->defaultSortField = $this->sortConverter->hash2SortField($step->dsl['default_sort_field']);
            }

            if (isset($step->dsl['default_sort_order'])) {
                $contentTypeUpdateStruct->defaultSortOrder = $this->sortConverter->hash2SortOrder($step->dsl['default_sort_order']);
            }

            // Add/edit attributes
            if (isset($step->dsl['attributes'])) {
                // NB: seems like eZ gets mixed up if we pass some attributes with a position and some without...
                // We go out of our way to avoid collisions and preserve order
                $maxFieldDefinitionPos = count($contentType->fieldDefinitions);
                $newFieldDefinitions = array();
                foreach ($step->dsl['attributes'] as $attribute) {

                    // allow easy reuse of attribute defs by storing them in references
                    if (is_string($attribute)) {
                        $attribute = $this->resolveReference($attribute);
                    }

                    $existingFieldDefinition = $this->contentTypeHasFieldDefinition($contentType, $attribute['identifier']);

                    if ($existingFieldDefinition) {
                        // Edit existing attribute
                        $fieldDefinitionUpdateStruct = $this->updateFieldDefinition(
                            $contentTypeService, $attribute, $attribute['identifier'], $contentType->identifier, $lang, $existingFieldDefinition
                        );
                        $contentTypeService->updateFieldDefinition(
                            $contentTypeDraft,
                            $existingFieldDefinition,
                            $fieldDefinitionUpdateStruct
                        );
                        if ($fieldDefinitionUpdateStruct->position > 0) {
                            $maxFieldDefinitionPos = $fieldDefinitionUpdateStruct->position > $maxFieldDefinitionPos ? $fieldDefinitionUpdateStruct->position : $maxFieldDefinitionPos;
                        } else {
                            $maxFieldDefinitionPos = $existingFieldDefinition->position > $maxFieldDefinitionPos ? $existingFieldDefinition->position : $maxFieldDefinitionPos;
                        }

                    } else {
                        // Create new attributes, keep them in temp array
                        $newFieldDefinition = $this->createFieldDefinition($contentTypeService, $attribute, $contentType->identifier, $lang);
                        $maxFieldDefinitionPos = $newFieldDefinition->position > $maxFieldDefinitionPos ? $newFieldDefinition->position : $maxFieldDefinitionPos;
                        $newFieldDefinitions[] = $newFieldDefinition;
                    }
                }

                // Add new attributes
                foreach ($newFieldDefinitions as $newFieldDefinition) {
                    if ($newFieldDefinition->position == 0) {
                        $newFieldDefinition->position = ++$maxFieldDefinitionPos;
                    }
                    $contentTypeService->addFieldDefinition($contentTypeDraft, $newFieldDefinition);
                }
            }

            // Remove attributes
            if (isset($step->dsl['remove_attributes'])) {

                // allow the '*' string to mean 'all attributes excepts the ones specified in this step'
                if ($step->dsl['remove_attributes'] === '*') {
                    $modifiedAttributeIdentifiers = array();
                    foreach ($step->dsl['attributes'] as $attribute) {
                        $modifiedAttributeIdentifiers[] = $attribute['identifier'];
                    }

                    foreach ($contentType->getFieldDefinitions() as $existingFieldDefinition) {
                        if (!in_array($existingFieldDefinition->identifier, $modifiedAttributeIdentifiers)) {
                            $contentTypeService->removeFieldDefinition($contentTypeDraft, $existingFieldDefinition);
                        }
                    }
                } else {
                    // we assume that $step->dsl['remove_attributes'] is an array of field identifiers
                    foreach ($step->dsl['remove_attributes'] as $attribute) {
                        $existingFieldDefinition = $this->contentTypeHasFieldDefinition($contentType, $attribute);
                        if ($existingFieldDefinition) {
                            $contentTypeService->removeFieldDefinition($contentTypeDraft, $existingFieldDefinition);
                        }
                        /// @todo log a warning if the specified field is not present
                    }
                }
            }

            $contentTypeService->updateContentTypeDraft($contentTypeDraft, $contentTypeUpdateStruct);
            $contentTypeService->publishContentTypeDraft($contentTypeDraft);

            if (isset($step->dsl['content_type_group'])) {
                $this->setContentTypeGroup($contentType, $step->dsl['content_type_group']);
            }

            if (isset($step->dsl['remove_content_type_group'])) {
                $this->unsetContentTypeGroup($contentType, $step->dsl['remove_content_type_group']);
            }

            // Set references
            if ($newIdentifier !== null) {
                $contentType = $contentTypeService->loadContentTypeByIdentifier($newIdentifier);
            } else {
                $contentType = $contentTypeService->loadContentTypeByIdentifier($contentTypeDraft->identifier);
            }

            $contentTypeCollection[$key] = $contentType;
        }

        $this->setReferences($contentTypeCollection, $step);

        return $contentTypeCollection;
    }

    /**
     * Method to handle the delete operation of the migration instructions
     */
    protected function delete($step)
    {
        $contentTypeCollection = $this->matchContentTypes('delete', $step);

        $this->validateResultsCount($contentTypeCollection, $step);

        $this->setReferences($contentTypeCollection, $step);

        $contentTypeService = $this->repository->getContentTypeService();

        foreach ($contentTypeCollection as $contentType) {
            $contentTypeService->deleteContentType($contentType);
        }

        return $contentTypeCollection;
    }

    /**
     * @param string $action
     * @return ContentTypeCollection
     * @throws \Exception
     */
    protected function matchContentTypes($action, $step)
    {
        if (!isset($step->dsl['identifier']) && !isset($step->dsl['match'])) {
            throw new InvalidStepDefinitionException("The identifier of a content type or a match condition is required to $action it");
        }

        // Backwards compat
        if (isset($step->dsl['match'])) {
            $match = $step->dsl['match'];
        } else {
            $match = array('identifier' => $step->dsl['identifier']);
        }

        // convert the references passed in the match
        $match = $this->resolveReferencesRecursively($match);

        $tolerateMisses = isset($step->dsl['match_tolerate_misses']) ? $this->resolveReference($step->dsl['match_tolerate_misses']) : false;

        return $this->contentTypeMatcher->match($match, $tolerateMisses);
    }

    /**
     * @param ContentType $contentType
     * @param array $references the definitions of the references to set
     * @throws \InvalidArgumentException When trying to assign a reference to an unsupported attribute
     * @throws InvalidStepDefinitionException
     * @return array key: the reference names, values: the reference values
     */
    protected function getReferencesValues($contentType, array $references, $step)
    {
        $lang = $this->getLanguageCode($step);
        $refs = array();

        foreach ($references as $key => $reference) {

            $reference = $this->parseReferenceDefinition($key, $reference);

            switch ($reference['attribute']) {
                case 'content_type_id':
                case 'id':
                    $value = $contentType->id;
                    break;
                case 'content_type_identifier':
                case 'identifier':
                    $value = $contentType->identifier;
                    break;
                case 'creation_date':
                    $value = $contentType->creationDate->getTimestamp();
                    break;
                case 'content_type_groups_ids':
                    $value = [];
                    foreach ($contentType->contentTypeGroups as $existingGroup) {
                        $value[] = $existingGroup->id;
                    }
                    break;
                case 'default_always_available':
                    $value = $contentType->defaultAlwaysAvailable;
                    break;
                case 'default_sort_field':
                    $value = $this->sortConverter->sortField2Hash($contentType->defaultSortField);
                    break;
                case 'default_sort_order':
                    $value = $this->sortConverter->sortOrder2Hash($contentType->defaultSortOrder);
                    break;
                case 'description':
                    $value = $contentType->getDescription($lang);
                    break;
                case 'is_container':
                    $value = $contentType->isContainer;
                    break;
                case 'modification_date':
                    $value = $contentType->modificationDate->getTimestamp();
                    break;
                case 'name':
                    $value = $contentType->getName($lang);
                    break;
                case 'name_pattern':
                    $value = $contentType->nameSchema;
                    break;
                case 'remote_id':
                    $value = $contentType->remoteId;
                    break;
                case 'status':
                    $value = $contentType->status;
                    break;
                case 'url_name_pattern':
                    $value = $contentType->urlAliasSchema;
                    break;
                default:
                    // allow to get the value of fields as well as their sub-parts
                    if (strpos($reference['attribute'], 'attributes.') === 0) {
                        // BC handling of references to attributes names/descriptions
                        if (preg_match('/^attributes\\.[^.]+\\.(name|description)$/', $reference['attribute'])) {
                            $reference['attribute'] .= '."' . $lang . '"';
                        }
                        $parts = explode('.', $reference['attribute']);
                        // totally not sure if this list of special chars is correct for what could follow a jmespath identifier...
                        // also what about quoted strings?
                        $fieldIdentifier = preg_replace('/[[(|&!{].*$/', '', $parts[1]);
                        $fieldDefinition = $contentType->getFieldDefinition($fieldIdentifier);
                        $hashValue = $this->fieldDefinitionToHash($contentType, $fieldDefinition, $step->context);
                        if (count($parts) == 2 && $fieldIdentifier === $parts[1]) {
                            /// @todo use a MigrationBundleException ?
                            throw new \InvalidArgumentException('Content Type Manager does not support setting references for attribute ' . $reference['attribute'] . ': please specify an attribute definition sub element');
                        }
                        $value = JmesPath::search(implode('.', array_slice($parts, 1)), array($fieldIdentifier => $hashValue));
                        break;
                    }

                    throw new InvalidStepDefinitionException('Content Type Manager does not support setting references for attribute ' . $reference['attribute']);
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
            $contentTypeCollection = $this->contentTypeMatcher->match($matchCondition);
            $data = array();

            /** @var \eZ\Publish\API\Repository\Values\ContentType\ContentType $contentType */
            foreach ($contentTypeCollection as $contentType) {

                $contentTypeData = array(
                    'type' => reset($this->supportedStepTypes),
                    'mode' => $mode
                );

                switch ($mode) {
                    case 'create':
                        $contentTypeGroups = $contentType->getContentTypeGroups();
                        $contentTypeData = array_merge(
                            $contentTypeData,
                            array(
                                'content_type_group' => reset($contentTypeGroups)->identifier,
                                'identifier' => $contentType->identifier
                            )
                        );
                        break;
                    case 'update':
                        $contentTypeData = array_merge(
                            $contentTypeData,
                            // q: are we allowed to change the group in updates ?
                            array(
                                'match' => array(
                                    ContentTypeMatcher::MATCH_CONTENTTYPE_IDENTIFIER => $contentType->identifier
                                ),
                                'new_identifier' => $contentType->identifier,
                            )
                        );
                        break;
                    case 'delete':
                        $contentTypeData = array_merge(
                            $contentTypeData,
                            array(
                                'match' => array(
                                    ContentTypeMatcher::MATCH_CONTENTTYPE_IDENTIFIER => $contentType->identifier
                                )
                            )
                        );
                        break;
                    default:
                        throw new InvalidStepDefinitionException("Executor 'content_type' doesn't support mode '$mode'");
                }

                if ($mode != 'delete') {

                    $attributes = array();
                    foreach ($contentType->getFieldDefinitions() as $i => $fieldDefinition) {
                        $attributes[] = $this->fieldDefinitionToHash($contentType, $fieldDefinition, $context);
                    }

                    $contentTypeData = array_merge(
                        $contentTypeData,
                        array(
                            'name' => $contentType->getNames(),
                            'description' => $contentType->getDescriptions(),
                            'name_pattern' => $contentType->nameSchema,
                            'url_name_pattern' => $contentType->urlAliasSchema,
                            'is_container' => $contentType->isContainer,
                            'default_always_available' => $contentType->defaultAlwaysAvailable,
                            'default_sort_field' => $this->sortConverter->sortField2Hash($contentType->defaultSortField),
                            'default_sort_order' => $this->sortConverter->sortOrder2Hash($contentType->defaultSortOrder),
                            'lang' => $this->getLanguageCodeFromContext($context),
                            'attributes' => $attributes
                        )
                    );
                }

                $data[] = $contentTypeData;
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
        return $this->contentTypeMatcher->listAllowedConditions();
    }

    /**
     * @param ContentType $contentType
     * @param FieldDefinition $fieldDefinition
     * @param array $context
     * @return array
     */
    protected function fieldDefinitionToHash(ContentType $contentType, FieldDefinition $fieldDefinition, $context)
    {
        $fieldTypeService = $this->repository->getFieldTypeService();
        $fieldTypeIdentifier = $fieldDefinition->fieldTypeIdentifier;

        $attribute = array(
            'identifier' => $fieldDefinition->identifier,
            'type' => $fieldTypeIdentifier,
            'name' => $fieldDefinition->getNames(),
            'description' => $fieldDefinition->getDescriptions(),
            'required' => $fieldDefinition->isRequired,
            'searchable' => $fieldDefinition->isSearchable,
            'info-collector' => $fieldDefinition->isInfoCollector,
            'disable-translation' => !$fieldDefinition->isTranslatable,
            'is-thumbnail' => $fieldDefinition->isThumbnail,
            'category' => $fieldDefinition->fieldGroup,
            // Should we cheat and do like the eZ4 Admin Interface and used sequential numbering 1,2,3... ?
            // But what if the end user then edits the 'update' migration and only leaves in it a single
            // field position update? He/she might be surprised when executing it...
            'position' => $fieldDefinition->position
        );

        $fieldType = $fieldTypeService->getFieldType($fieldTypeIdentifier);
        $nullValue = $fieldType->getEmptyValue();
        if ($fieldDefinition->defaultValue != $nullValue) {
            $attribute['default-value'] = $this->fieldHandlerManager->fieldValueToHash(
                $fieldTypeIdentifier, $contentType->identifier, $fieldDefinition->defaultValue
            );
        }

        $attribute['field-settings'] = $this->fieldHandlerManager->fieldSettingsToHash(
            $fieldTypeIdentifier, $contentType->identifier, $fieldDefinition->fieldSettings
        );

        $attribute['validator-configuration'] = $fieldDefinition->validatorConfiguration;

        return $attribute;
    }

    /**
     * Helper function to create field definitions to be added to a new/existing content type.
     *
     * @todo Add translation support if needed
     * @param ContentTypeService $contentTypeService
     * @param array $attribute
     * @param string $contentTypeIdentifier
     * @param string $lang
     * @return \eZ\Publish\API\Repository\Values\ContentType\FieldDefinitionCreateStruct
     * @throws \Exception
     */
    protected function createFieldDefinition(ContentTypeService $contentTypeService, array $attribute, $contentTypeIdentifier, $lang)
    {
        if (!isset($attribute['identifier']) || !isset($attribute['type'])) {
            throw new InvalidStepDefinitionException("Keys 'type' and 'identifier' are mandatory to define a new field in a field type");
        }

        $fieldDefinition = $contentTypeService->newFieldDefinitionCreateStruct(
            $attribute['identifier'],
            $attribute['type']
        );

        foreach ($attribute as $key => $value) {
            switch ($key) {
                case 'name':
                    $fieldDefinition->names = $this->getMultilingualValue($value, $lang);
                    break;
                case 'description':
                    $fieldDefinition->descriptions = $this->getMultilingualValue($value, $lang);
                    break;
                case 'required':
                    $fieldDefinition->isRequired = $value;
                    break;
                case 'searchable':
                    $fieldDefinition->isSearchable = $value;
                    break;
                case 'info-collector':
                    $fieldDefinition->isInfoCollector = $value;
                    break;
                case 'disable-translation':
                    $fieldDefinition->isTranslatable = !$value;
                    break;
                case 'is-thumbnail':
                    $fieldDefinition->isThumbnail = $value;
                    break;
                case 'category':
                    $fieldDefinition->fieldGroup = $value == 'default' ? 'content' : $value;
                    break;
                case 'default-value':
                    /// @todo check that this works for all field types. Maybe we should use fromHash() on the field type,
                    ///       or, better, use the FieldHandlerManager?
                    $fieldDefinition->defaultValue = $value;
                    break;
                case 'field-settings':
                    $fieldDefinition->fieldSettings = $this->getFieldSettings($value, $attribute['type'], $contentTypeIdentifier);
                    break;
                case 'position':
                    $fieldDefinition->position = (int)$value;
                    break;
                case 'validator-configuration':
                    $fieldDefinition->validatorConfiguration = $this->resolveReferencesRecursively($value);
                    break;
            }
        }

        return $fieldDefinition;
    }

    /**
     * Helper function to update field definitions based to be added to a new/existing content type.
     *
     * @todo Add translation support if needed
     * @param ContentTypeService $contentTypeService
     * @param array $attribute
     * @param string $fieldTypeIdentifier
     * @param string $contentTypeIdentifier
     * @param string $lang
     * @param FieldDefinition $existingFieldDefinition
     * @return \eZ\Publish\API\Repository\Values\ContentType\FieldDefinitionUpdateStruct
     * @throws \Exception
     */
    protected function updateFieldDefinition(ContentTypeService $contentTypeService, array $attribute, $fieldTypeIdentifier, $contentTypeIdentifier, $lang, FieldDefinition $existingFieldDefinition)
    {
        if (!isset($attribute['identifier'])) {
            throw new InvalidStepDefinitionException("The 'identifier' of an attribute is missing in the content type update definition.");
        }

        $fieldDefinitionUpdateStruct = $contentTypeService->newFieldDefinitionUpdateStruct();

        foreach ($attribute as $key => $value) {
            switch ($key) {
                case 'new_identifier':
                    $fieldDefinitionUpdateStruct->identifier = $value;
                    break;
                case 'name':
                    $fieldDefinitionUpdateStruct->names = $this->getMultilingualValue($value, $lang, $existingFieldDefinition->getNames());
                    break;
                case 'description':
                    $fieldDefinitionUpdateStruct->descriptions = $this->getMultilingualValue($value, $lang, $existingFieldDefinition->getDescriptions());
                    break;
                case 'required':
                    $fieldDefinitionUpdateStruct->isRequired = $value;
                    break;
                case 'searchable':
                    $fieldDefinitionUpdateStruct->isSearchable = $value;
                    break;
                case 'info-collector':
                    $fieldDefinitionUpdateStruct->isInfoCollector = $value;
                    break;
                case 'disable-translation':
                    $fieldDefinitionUpdateStruct->isTranslatable = !$value;
                    break;
                case 'is-thumbnail':
                    $fieldDefinitionUpdateStruct->isThumbnail = $value;
                    break;
                case 'category':
                    $fieldDefinitionUpdateStruct->fieldGroup = $value == 'default' ? 'content' : $value;
                    break;
                case 'default-value':
                    $fieldDefinitionUpdateStruct->defaultValue = $value;
                    break;
                case 'field-settings':
                    $fieldDefinitionUpdateStruct->fieldSettings = $this->getFieldSettings($value, $fieldTypeIdentifier, $contentTypeIdentifier);
                    break;
                case 'position':
                    $fieldDefinitionUpdateStruct->position = (int)$value;
                    break;
                case 'validator-configuration':
                    $fieldDefinitionUpdateStruct->validatorConfiguration = $this->resolveReferencesRecursively($value);
                    break;
            }
        }

        return $fieldDefinitionUpdateStruct;
    }

    protected function getFieldSettings($value, $fieldTypeIdentifier, $contentTypeIdentifier)
    {
        // 1st update any references in the value array
        // q: shall we delegate this exclusively to the hashToFieldSettings call below ?
        if (is_array($value)) {
            $ret = array();
            foreach ($value as $key => $val)
            {
                $ret[$key] = $val;

                // we do NOT check for refs in field settings which are arrays, even though we could, maybe *should*...
                if (!is_array($val)) {
                    $ret[$key] = $this->extendedReferenceResolver->resolveReference($val);
                }
            }
        } else {
            $ret = $this->extendedReferenceResolver->resolveReference($value);
        }

        // then handle the conversion of the settings from Hash to Repo representation
        if ($this->fieldHandlerManager->managesFieldDefinition($fieldTypeIdentifier, $contentTypeIdentifier)) {
            $ret = $this->fieldHandlerManager->hashToFieldSettings($fieldTypeIdentifier, $contentTypeIdentifier, $ret);
        }

        return $ret;
    }

    /**
     * Helper to find out if a Field is already defined in a ContentType
     *
     * @param ContentType $contentType
     * @param string $fieldIdentifier
     * @return \eZ\Publish\API\Repository\Values\ContentType\FieldDefinition|null
     */
    protected function contentTypeHasFieldDefinition(ContentType $contentType, $fieldIdentifier)
    {
        $existingFieldDefinitions = $contentType->fieldDefinitions;

        foreach ($existingFieldDefinitions as $existingFieldDefinition) {
            if ($fieldIdentifier == $existingFieldDefinition->identifier) {
                return $existingFieldDefinition;
            }
        }

        return null;
    }

    /**
     * Helper for handling multilinugal values - merges user input with existing data
     *
     * @param string|array $newValue if passed a string, we will use $lang as its language. If passed an array, it will be used as is - expected format [ "eng-GB": "Name", "fre-FR": "Nom", ... ]
     * @param string $lang ex: eng-GB. Not used when $newValue is an array
     * @param array $currentValue current set of values in all known languages. Will be merged with $newValue, $newValue taking precedence
     * @return array in the format [ "eng-GB": "Name", "fre-FR": "Nom", ... ]
     */
    protected function getMultilingualValue($newValue, $lang, $currentValue = array())
    {
        $value = is_array($newValue) ? $newValue : array($lang => $newValue);
        $value += $currentValue;

        return $value;
    }

    protected function setContentTypeGroup(ContentType $contentType, $contentTypeGroupId)
    {
        $contentTypeGroupId = $this->resolveReference($contentTypeGroupId);
        $contentTypeGroup = $this->contentTypeGroupMatcher->matchOneByKey($contentTypeGroupId);
        $contentTypeGroupId = $contentTypeGroup->id;

        foreach ($contentType->contentTypeGroups as $existingGroup) {
            if ($existingGroup->id === $contentTypeGroupId) {
                return;
            }
        }

        $contentTypeService = $this->repository->getContentTypeService();
        $contentTypeService->assignContentTypeGroup($contentType, $contentTypeGroup);
    }

    protected function unsetContentTypeGroup(ContentType $contentType, $contentTypeGroupId)
    {
        $contentTypeGroupId = $this->resolveReference($contentTypeGroupId);
        $contentTypeGroup = $this->contentTypeGroupMatcher->matchOneByKey($contentTypeGroupId);
        $contentTypeGroupId = $contentTypeGroup->id;

        foreach ($contentType->contentTypeGroups as $existingGroup) {
            if ($existingGroup->id === $contentTypeGroupId) {
                $contentTypeService = $this->repository->getContentTypeService();
                $contentTypeService->unassignContentTypeGroup($contentType, $contentTypeGroup);
                return;
            }
        }

        /// @todo log a warning if the conteType is not in the specified group
    }
}
