<?php

namespace Drupal\tide_automated_listing;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\elasticsearch_connector\ElasticSearch\Parameters\Factory\IndexFactory;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Utility\FieldsHelper;

/**
 * Class SearchApiIndexHelper.
 *
 * @package Drupal\tide_automated_listing
 */
class SearchApiIndexHelper {
  use StringTranslationTrait;

  /**
   * The Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Search API Field Helper.
   *
   * @var \Drupal\search_api\Utility\FieldsHelper
   */
  protected $sapiFieldsHelper;

  /**
   * The Entity Type Bundle Info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * SearchApiIndexHelper constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The Entity Type Manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The Entity Type Bundle Info service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The string translation service.
   * @param \Drupal\search_api\Utility\FieldsHelper $sapi_fields_helper
   *   The SAPI Fields Helper.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityTypeBundleInfoInterface $entity_type_bundle_info, TranslationInterface $translation, FieldsHelper $sapi_fields_helper) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->stringTranslation = $translation;
    $this->sapiFieldsHelper = $sapi_fields_helper;
  }

  /**
   * Get all enabled Search API indices.
   *
   * @return \Drupal\search_api\IndexInterface[]
   *   List of enabled indices.
   */
  public function getEnabledSearchApiNodeIndices() {
    try {
      $index_storage = $this->entityTypeManager->getStorage('search_api_index');
      /** @var \Drupal\search_api\IndexInterface[] $indices */
      $indices = $index_storage->loadByProperties(['status' => TRUE]);
      return $indices;
    }
    catch (\Exception $exception) {
      watchdog_exception('tide_automated_listing', $exception);
    }
    return [];
  }

  /**
   * Get the options for Index select.
   *
   * @return string[]
   *   List of indices.
   */
  public function getIndexSelectOptions() {
    $indices = $this->getEnabledSearchApiNodeIndices();
    $options = [];
    foreach ($indices as $index) {
      if ($this->isValidNodeIndex($index)) {
        $options[$index->id()] = $index->label();
      }
    }

    return $options;
  }

  /**
   * Check if the Search API Index is a node-only index.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The Search API Index to check.
   *
   * @return bool
   *   TRUE if the index is a valid node index.
   */
  public function isValidNodeIndex(IndexInterface $index) {
    if (!$index->status()) {
      return FALSE;
    }
    $entity_types = $index->getEntityTypes();
    if (count($entity_types) > 1) {
      return FALSE;
    }

    $entity_type = reset($entity_types);
    if ($entity_type !== 'node') {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Load a Search API Index.
   *
   * @param string $id
   *   The ID of the search index to load.
   *
   * @return bool|\Drupal\search_api\IndexInterface
   *   The Search API index, FALSE upon failure.
   */
  public function loadSearchApiIndex($id) {
    try {
      $index_storage = $this->entityTypeManager->getStorage('search_api_index');
      /** @var \Drupal\search_api\IndexInterface $index */
      $index = $index_storage->load($id);
      return $index;
    }
    catch (\Exception $exception) {
      watchdog_exception('tide_automated_listing', $exception);
    }
    return FALSE;
  }

  /**
   * Check if an Search API index has the Sticky field.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The index to check.
   *
   * @return bool
   *   TRUE if the node index has the Sticky field.
   */
  public function isNodeStickyIndexedAsInteger(IndexInterface $index) {
    $sticky = $this->getIndexedNodeField($index, 'sticky');
    return $sticky ? $this->isIntegerField($index, $sticky) : FALSE;
  }

  /**
   * Check if an Search API index has the content type field.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The index to check.
   *
   * @return bool
   *   TRUE if the node index has the Content type field.
   */
  public function isNodeTypeIndexed(IndexInterface $index) {
    return (bool) $this->getIndexedNodeField($index, 'type');
  }

  /**
   * Get list of node types.
   *
   * @return string[]
   *   Node types.
   */
  public function getNodeTypes() {
    try {
      /** @var \Drupal\node\NodeTypeInterface[] $types */
      $types = $this->entityTypeManager->getStorage('node_type')
        ->loadMultiple();
      $node_types = [];
      foreach ($types as $type) {
        $node_types[$type->id()] = $type->label();
      }
      asort($node_types);
      return $node_types;
    }
    catch (\Exception $exception) {
      watchdog_exception('tide_automated_listing', $exception);
    }
    return [];
  }

  /**
   * Build an Entity autocomplete form element to select taxonomy terms.
   *
   * @param string $vid
   *   The vocabulary machine name.
   * @param int[] $default_values
   *   The default values as a list of tid.
   *
   * @return array|bool
   *   The form array, or FALSE if the filter can't be built.
   */
  public function buildTaxonomyTermSelector($vid, array $default_values = []) {
    try {
      $vocabulary = $this->entityTypeManager->getStorage('taxonomy_vocabulary')
        ->load($vid);
      if (!$vocabulary) {
        return FALSE;
      }
    }
    catch (\Exception $exception) {
      watchdog_exception('tide_automated_listing', $exception);
      return FALSE;
    }

    $element = [
      '#title' => $this->t('Select terms'),
    ] + $this->buildEntityReferenceSelector('taxonomy_term', [$vid], $default_values);

    return $element;
  }

  /**
   * Build an Entity autocomplete form element to select entities.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string[] $bundles
   *   The target bundles.
   * @param int[] $default_values
   *   The default values as a list of tid.
   *
   * @return array|bool
   *   The form array, or FALSE if the filter can't be built.
   */
  public function buildEntityReferenceSelector($entity_type_id, array $bundles, array $default_values = []) {
    try {
      $plural_label = $this->entityTypeManager->getDefinition($entity_type_id)->getPluralLabel();

      $entity_bundles = $this->entityTypeBundleInfo->getBundleInfo($entity_type_id);
      foreach ($bundles as $delta => $bundle) {
        if (!isset($entity_bundles[$bundle])) {
          unset($bundles[$delta]);
        }
      }
    }
    catch (\Exception $exception) {
      watchdog_exception('tide_automated_listing', $exception);
      return FALSE;
    }

    $element = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Select @plural_label', ['@plural_label' => $plural_label]),
      '#target_type' => $entity_type_id,
      '#tags' => TRUE,
      '#autocreate' => FALSE,
      '#default_value' => [],
    ];
    if (!empty($bundles)) {
      $element['#selection_settings']['target_bundles'] = $bundles;
    }

    if (!empty($default_values)) {
      try {
        $entities = $this->entityTypeManager->getStorage($entity_type_id)
          ->loadMultiple($default_values);
        if ($entities) {
          /** @var \Drupal\taxonomy\TermInterface[] $entities */
          foreach ($entities as $entity) {
            if (in_array($entity->bundle(), $bundles)) {
              $element['#default_value'][] = $entity;
            }
          }
        }
      }
      catch (\Exception $exception) {
        watchdog_exception('tide_automated_listing', $exception);
      }
    }

    return $element;
  }

  /**
   * Check if an Search API index has the Topic field.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The index to check.
   *
   * @return bool
   *   TRUE if the node index has the Topic field.
   */
  public function isFieldTopicIndexed(IndexInterface $index) {
    return (bool) $this->getIndexedNodeField($index, 'field_topic');
  }

  /**
   * Build the Topic filter.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search api index.
   * @param int[] $default_values
   *   The default values as a list of tid.
   *
   * @return array|bool
   *   The form array, or FALSE if the filter can't be built.
   */
  public function buildTopicFilter(IndexInterface $index, array $default_values = []) {
    if (!$this->isFieldTopicIndexed($index)) {
      return FALSE;
    }

    $element = $this->buildTaxonomyTermSelector('topic', $default_values);
    if ($element) {
      $element['#title'] = $this->t('Select Topics');
    }
    return $element;
  }

  /**
   * Check if an Search API index has the Tags field.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The index to check.
   *
   * @return bool
   *   TRUE if the node index has the Tags field.
   */
  public function isFieldTagsIndexed(IndexInterface $index) {
    return (bool) $this->getIndexedNodeField($index, 'field_tags');
  }

  /**
   * Build the Tags filter.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search api index.
   * @param int[] $default_values
   *   The default values as a list of tid.
   *
   * @return array|bool
   *   The form array, or FALSE if the filter can't be built.
   */
  public function buildTagsFilter(IndexInterface $index, array $default_values = []) {
    if (!$this->isFieldTagsIndexed($index)) {
      return FALSE;
    }

    $element = $this->buildTaxonomyTermSelector('tags', $default_values);
    if ($element) {
      $element['#title'] = $this->t('Select Tags');
    }
    return $element;
  }

  /**
   * Build the filter for an entity reference field.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search api index.
   * @param string $field_id
   *   The index field id.
   * @param array $default_values
   *   The default values as a list of entity ID.
   *
   * @return array|bool
   *   The form array, or FALSE if the filter can't be built.
   */
  public function buildEntityReferenceFieldFilter(IndexInterface $index, $field_id, array $default_values = []) {
    $reference_fields = $this->extractIndexEntityReferenceFields($index);
    if (!isset($reference_fields[$field_id])) {
      return FALSE;
    }

    try {
      $field = $reference_fields[$field_id];
      return $this->buildEntityReferenceSelector($field['target_type'], $field['target_bundles'] ?? [], $default_values);
    }
    catch (\Exception $exception) {
      watchdog_exception('tide_automated_listing', $exception);
      return FALSE;
    }
  }

  /**
   * Get the SAPI Index field ID of a node field.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The index to check.
   * @param string $node_field_name
   *   The Node field name to check.
   *
   * @return string|false
   *   The index field ID if the node index has the given field.
   */
  public function getIndexedNodeField(IndexInterface $index, $node_field_name) {
    $index_fields = &drupal_static(__CLASS__ . '::' . __METHOD__);
    if (isset($index_fields[$node_field_name])) {
      return $index_fields[$node_field_name];
    }

    $fields = $index->getFields();
    foreach ($fields as $field) {
      if ($field->getPropertyPath() == $node_field_name && $field->getDatasourceId() == 'entity:node') {
        $index_fields[$node_field_name] = $field->getFieldIdentifier();
        return $index_fields[$node_field_name];
      }
    }

    $index_fields[$node_field_name] = FALSE;
    return FALSE;
  }

  /**
   * Check if the search index has a field.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The index.
   * @param string $field_id
   *   The field ID.
   *
   * @return bool
   *   TRUE if valid.
   */
  public function isValidIndexField(IndexInterface $index, $field_id) {
    return $index->getField($field_id) ? TRUE : FALSE;
  }

  /**
   * Check if an index field is integer.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The index.
   * @param string $field_id
   *   The index field ID.
   *
   * @return bool
   *   Result.
   */
  public function isIntegerField(IndexInterface $index, $field_id) {
    $field = $index->getField($field_id);
    if ($field) {
      return $field->getType() == 'integer';
    }
    return FALSE;
  }

  /**
   * Get all index integer fields.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   * @param string[] $excludes
   *   The field IDs to exclude.
   *
   * @return array
   *   The list of integer fields.
   */
  public function getIndexIntegerFields(IndexInterface $index, array $excludes = []) {
    $fields = [];
    foreach ($index->getFields() as $field_id => $field) {
      if (!in_array($field_id, $excludes) && $field->getType() == 'integer') {
        $fields[$field_id] = $field->getLabel();
      }
    }

    return $fields;
  }

  /**
   * Extract all index entity reference fields.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   * @param string[] $excludes
   *   The field IDs to exclude.
   *
   * @return array
   *   The list of entity reference fields.
   */
  protected function extractIndexEntityReferenceFields(IndexInterface $index, array $excludes = []) {
    $reference_fields = &drupal_static(__CLASS__ . '::' . __METHOD__);
    if (isset($reference_fields)) {
      return static::excludeArrayKey($reference_fields, $excludes);
    }

    $reference_fields = [];
    $fields = $index->getFields();
    foreach ($fields as $field_id => $field) {
      if ($field->getType() != 'integer') {
        continue;
      }
      try {
        $definition = $this->sapiFieldsHelper->retrieveNestedProperty($index->getPropertyDefinitions('entity:node'), $field->getPropertyPath());
        /** @var \Drupal\field\FieldConfigInterface $field_config */
        $field_config = $definition->getFieldDefinition();
        if (!in_array($field_config->getType(), ['entity_reference', 'entity_reference_revisions'])) {
          continue;
        }
        $settings = $definition->getSettings();
        $target_type = $settings['target_type'];
        if (empty($target_type)) {
          continue;
        }

        $reference_fields[$field_id] = [
          'label' => $field->getLabel(),
          'target_type' => $target_type,
          'target_bundles' => $settings['handler_settings']['target_bundles'] ?? [],
        ];
      }
      catch (\Exception $exception) {
        watchdog_exception('tide_automated_listing', $exception);
        continue;
      }
    }

    return static::excludeArrayKey($reference_fields, $excludes);
  }

  /**
   * Returns an array without the excluded keys.
   *
   * @param array $array
   *   The original array.
   * @param array $excluded_keys
   *   The excluded keys.
   *
   * @return array
   *   The filtered array.
   */
  public static function excludeArrayKey(array $array, array $excluded_keys = []) {
    return array_diff_key($array, array_combine($excluded_keys, $excluded_keys));
  }

  /**
   * Get all index entity reference fields.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   * @param string[] $excludes
   *   The field IDs to exclude.
   *
   * @return array
   *   The list of entity reference fields.
   */
  public function getIndexEntityReferenceFields(IndexInterface $index, array $excludes = []) {
    $reference_fields = $this->extractIndexEntityReferenceFields($index, $excludes);
    $fields = [];
    foreach ($reference_fields as $field_id => $field_info) {
      $fields[$field_id] = $field_info['label'];
    }

    return $fields;
  }

  /**
   * Get all date fields of an index.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The index.
   * @param string[] $excludes
   *   Field IDs to exclude.
   *
   * @return \Drupal\search_api\Item\FieldInterface[]
   *   Date fields.
   */
  public function getIndexDateFields(IndexInterface $index, array $excludes = []) {
    $date_fields = &drupal_static(__CLASS__ . '::' . __METHOD__);
    if (isset($date_fields)) {
      return static::excludeArrayKey($date_fields, $excludes);
    }

    /** @var \Drupal\search_api\Item\FieldInterface[] $date_fields */
    $date_fields = [];
    $fields = $index->getFields();
    foreach ($fields as $field_id => $field) {
      if ($field->getType() == 'date') {
        $date_fields[$field_id] = $field->getLabel();
      }
    }

    return static::excludeArrayKey($date_fields, $excludes);
  }

  /**
   * Retrieve the index ID on the server.
   *
   * @param \Drupal\search_api\IndexInterface|string $index
   *   The index ID, or the fully load search ID index.
   *
   * @return bool|string
   *   The index ID on the server, FALSE upon failure.
   */
  public function getServerIndexId($index) {
    try {
      if (!($index instanceof IndexInterface)) {
        $index = $this->loadSearchApiIndex($index);
        if (!$index) {
          return FALSE;
        }
      }
      $server = $index->getServerInstance();
      if ($server && $server->getBackendId() === 'elasticsearch') {
        return IndexFactory::getIndexName($index);
      }
    }
    catch (\Exception $exception) {
      watchdog_exception('tide_automated_listing', $exception);
    }

    return $index->id();
  }

}
