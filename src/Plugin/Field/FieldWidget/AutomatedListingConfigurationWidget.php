<?php

namespace Drupal\tide_automated_listing\Plugin\Field\FieldWidget;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\StringTextareaWidget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Serialization\Yaml;
use Drupal\tide_automated_listing\SearchApiIndexHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'automated_listing_configuration' widget.
 *
 * @FieldWidget(
 *   id = "automated_listing_configuration",
 *   label = @Translation("Automated Card Listing Configuration"),
 *   field_types = {
 *     "automated_listing_configuration"
 *   }
 * )
 */
class AutomatedListingConfigurationWidget extends StringTextareaWidget implements ContainerFactoryPluginInterface {

  /**
   * The Search API Index helper.
   *
   * @var \Drupal\tide_automated_listing\SearchApiIndexHelper
   */
  protected $indexHelper;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The search API index.
   *
   * @var \Drupal\search_api\IndexInterface
   */
  protected $index;

  /**
   * {@inheritdoc}
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, SearchApiIndexHelper $index_helper, ModuleHandlerInterface $module_handler) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
    $this->indexHelper = $index_helper;
    $this->moduleHandler = $module_handler;
    $this->getIndex();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('tide_automated_listing.sapi_index_helper'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    return [];
  }

  /**
   * Get search API index.
   *
   * @return \Drupal\search_api\IndexInterface|null|false
   *   The index, NULL upon failure, FALSE when no index is selected.
   */
  protected function getIndex() {
    if (!$this->index) {
      // Load and verify the index.
      /** @var \Drupal\search_api\IndexInterface $index */
      $index = NULL;
      $index_id = $this->fieldDefinition->getFieldStorageDefinition()
        ->getSetting('index');
      if ($index_id) {
        $index = $this->indexHelper->loadSearchApiIndex($index_id);
        if ($index && $this->indexHelper->isValidNodeIndex($index)) {
          $this->index = $index;
        }
      }
      else {
        return FALSE;
      }
    }

    return $this->index;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);

    // Added theme wrapper to combine all the fields.
    $element['#theme_wrappers'] = [
      'details' => [
        '#title' => $element['#title'],
        '#attributes' => [
          'open' => 'open',
        ],
        '#summary_attributes' => [],
        '#required' => TRUE,
      ],
    ];
    // Hide the YAML configuration field.
    $element['value']['#access'] = FALSE;

    // Load and verify the index.
    /** @var \Drupal\search_api\IndexInterface $index */
    $index = $this->getIndex();
    $index_error = '';
    if ($index === NULL) {
      $index_error = $this->t('Invalid Search API Index.');
    }
    elseif ($index === FALSE) {
      $index_error = $this->t('No Search API Index has been selected for this field.');
    }

    if (!$index) {
      $element['error'] = [
        '#type' => 'markup',
        '#markup' => $index_error,
        '#prefix' => '<div class="form-item--error-message">',
        '#suffix' => '</div>',
        '#allowed_tags' => ['div'],
      ];
      return $element;
    }

    $configuration = $items[$delta]->configuration ?? [];
    $configuration = $this->getDefaultConfiguration($configuration);

    $element['tabs'] = [
      '#type' => 'horizontal_tabs',
      '#group_name' => 'tabs',
    ];
    $element['#attached']['library'][] = 'field_group/formatter.horizontal_tabs';

    $this->buildResultsSettingsTab($items, $delta, $element, $form, $form_state, $configuration);
    $this->buildDisplaySettingsTab($items, $delta, $element, $form, $form_state, $configuration);
    $this->buildFilterSettingsTab($items, $delta, $element, $form, $form_state, $configuration);
    $this->buildSortSettingsTab($items, $delta, $element, $form, $form_state, $configuration);

    return $element;
  }

  /**
   * Build Display settings.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   Field items.
   * @param int $delta
   *   The current delta.
   * @param array $element
   *   The element.
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $configuration
   *   The YAML configuration of the listing.
   */
  protected function buildDisplaySettingsTab(FieldItemListInterface $items, $delta, array &$element, array &$form, FormStateInterface $form_state, array $configuration = NULL) {
    $element['tabs']['display'] = [
      '#type' => 'details',
      '#title' => $this->t('Display settings'),
      '#open' => TRUE,
      '#collapsible' => TRUE,
      '#group_name' => 'display',
    ];
    $element['tabs']['display']['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Display as'),
      '#options' => [
        'carousel' => $this->t('Carousel'),
        'grid' => $this->t('Grid'),
      ],
      '#default_value' => $configuration['display']['type'] ?? 'carousel',
    ];
    $element['tabs']['display']['items_per_page'] = [
      '#type' => 'number',
      '#title' => $this->t('Items per page'),
      '#default_value' => $configuration['display']['items_per_page'] ?? 0,
      '#min' => 0,
      '#description' => $this->t('If 0 is entered here, all items will show on the same page without pagination. Otherwise pagination will apply once the entered number is exceeded.'),
      '#states' => [
        'visible' => [
          ':input[name="' . $this->getFormStatesElementName('tabs|display|type', $items, $delta, $element) . '"]' => ['value' => 'grid'],
        ],
      ],
    ];

    $date_fields = $this->indexHelper->getIndexDateFields($this->index);
    $default_card_date = '';
    if (!empty($configuration['card_display']['date']) && !empty($date_fields[$configuration['card_display']['date']])) {
      $default_card_date = $configuration['card_display']['date'];
    }
    $element['tabs']['display']['card_date'] = [
      '#type' => 'select',
      '#title' => $this->t('Card Date field mapping'),
      '#default_value' => $default_card_date,
      '#options' => ['' => $this->t('- Does not show -')] + $date_fields,

    ];
    $element['tabs']['display']['card_display_hide'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Hide the following fields on the card'),
      '#default_value' => !empty($configuration['card_display']['hide']) ? array_keys(array_filter($configuration['card_display']['hide'])) : [],
      '#options' => [
        'image' => $this->t('Image'),
        'title' => $this->t('Title'),
        'summary' => $this->t('Summary'),
        'topic' => $this->t('Topic'),
        'location' => $this->t('Location'),
      ],
    ];
  }

  /**
   * Build Display settings.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   Field items.
   * @param int $delta
   *   The current delta.
   * @param array $element
   *   The element.
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $configuration
   *   The YAML configuration of the listing.
   */
  protected function buildResultsSettingsTab(FieldItemListInterface $items, $delta, array &$element, array &$form, FormStateInterface $form_state, array $configuration = NULL) {
    $element['tabs']['results'] = [
      '#type' => 'details',
      '#title' => $this->t('Result settings'),
      '#open' => TRUE,
      '#collapsible' => TRUE,
      '#tree' => TRUE,
      '#group_name' => 'results',
    ];
    $element['tabs']['results']['min'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum results to show'),
      '#default_value' => $configuration['results']['min'] ?? 0,
      '#min' => 0,
    ];
    $element['tabs']['results']['max'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum results to show'),
      '#default_value' => $configuration['results']['max'] ?? 0,
      '#min' => 0,
    ];

    $element['tabs']['results']['min_not_met'] = [
      '#type' => 'radios',
      '#title' => $this->t('If minimum count is not met'),
      '#options' => [
        'hide' => $this->t('Hide component'),
        'no_results_message' => $this->t("Show 'no results' message"),
      ],
      '#default_value' => $configuration['results']['min_not_met'] ?? 'hide',
    ];
    $element['tabs']['results']['no_results_message'] = [
      '#type' => 'textfield',
      '#title' => $this->t("'No results' message"),
      '#default_value' => $configuration['results']['no_results_message'] ?? $this->t('There are currently no results'),
      '#states' => [
        'invisible' => [
          ':input[name="' . $this->getFormStatesElementName('tabs|results|min_not_met', $items, $delta, $element) . '"]' => ['value' => 'hide'],
        ],
      ],
    ];
  }

  /**
   * Build Filters.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   Field items.
   * @param int $delta
   *   The current delta.
   * @param array $element
   *   The element.
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $configuration
   *   The YAML configuration of the listing.
   */
  protected function buildFilterSettingsTab(FieldItemListInterface $items, $delta, array &$element, array &$form, FormStateInterface $form_state, array $configuration = NULL) {
    $element['tabs']['filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Filter criteria'),
      '#open' => TRUE,
      '#collapsible' => TRUE,
      '#group_name' => 'filters',
    ];
    $element['tabs']['filters']['operator'] = $this->buildFilterOperatorSelect($configuration['filter_operator'] ?? 'AND', $this->t('This operator is used to combined the filters together.'));

    // Content type filter.
    if ($this->indexHelper->isNodeTypeIndexed($this->index)) {
      $element['tabs']['filters']['type_wrapper'] = [
        '#type' => 'details',
        '#title' => $this->t('Content type'),
        '#open' => TRUE,
        '#collapsible' => TRUE,
        '#group_name' => 'filters_type_wrapper',
      ];
      $element['tabs']['filters']['type_wrapper']['type'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Select Content types'),
        '#options' => $this->indexHelper->getNodeTypes(),
        '#default_value' => $configuration['filters']['type']['values'] ?? [],
      ];
      if (isset($configuration['filters']['type']['values']) && empty($configuration['filters']['type']['values'])) {
        $element['tabs']['filters']['type_wrapper']['#open'] = FALSE;
      }
    }

    // Generate all entity reference filters.
    $entity_reference_fields = $this->getEntityReferenceFields();
    // Allow other modules to remove entity reference filters.
    $excludes = $this->moduleHandler->invokeAll('tide_automated_listing_entity_reference_fields_exclude', [
      $this->index,
      $entity_reference_fields,
      clone $items,
      $delta,
    ]);
    if (!empty($excludes) && is_array($excludes)) {
      $entity_reference_fields = $this->indexHelper::excludeArrayKey($entity_reference_fields, $excludes);
    }

    if (!empty($entity_reference_fields)) {
      foreach ($entity_reference_fields as $field_id => $field_label) {
        $default_values = $configuration['filters'][$field_id]['values'] ?? [];
        $field_filter = $this->indexHelper->buildEntityReferenceFieldFilter($this->index, $field_id, $default_values);
        if ($field_filter) {
          $element['tabs']['filters'][$field_id . '_wrapper'] = [
            '#type' => 'details',
            '#title' => $field_label,
            '#open' => FALSE,
            '#collapsible' => TRUE,
            '#group_name' => 'filters_' . $field_id . '_wrapper',
          ];
          $element['tabs']['filters'][$field_id . '_wrapper'][$field_id] = $field_filter;
          $element['tabs']['filters'][$field_id . '_wrapper']['operator'] = $this->buildFilterOperatorSelect($configuration['filters'][$field_id]['operator'] ?? 'OR', $this->t('This filter operator is used to combined all the selected values together.'));
          if (isset($configuration['filters'][$field_id])) {
            $element['tabs']['filters'][$field_id . '_wrapper']['#open'] = TRUE;
          }

          // Special treatment for topic and tags.
          if ($field_id == 'field_topic' || $field_id == 'field_tags') {
            $element['tabs']['filters'][$field_id . '_wrapper']['#title'] = ($field_id == 'field_topic') ? $this->t('Topic') : $this->t('Tags');
            $element['tabs']['filters'][$field_id . '_wrapper'][$field_id]['#title'] = ($field_id == 'field_topic') ? $this->t('Select topics') : $this->t('Select tags');
            if (isset($configuration['filters'][$field_id]['values']) && empty($configuration['filters'][$field_id]['values'])) {
              $element['tabs']['filters'][$field_id . '_wrapper']['#open'] = FALSE;
            }
            else {
              $element['tabs']['filters'][$field_id . '_wrapper']['#open'] = TRUE;
            }
          }
        }
      }
    }

    // Build extra filters.
    $extra_filters = $this->moduleHandler->invokeAll('tide_automated_listing_extra_filters_build', [
      $this->index,
      clone $items,
      $delta,
      $configuration['filters'],
    ]);
    $context = [
      'index' => clone $items,
      'delta' => $delta,
      'filters' => $configuration['filters'],
    ];
    $this->moduleHandler->alter('tide_automated_listing_extra_filters_build', $extra_filters, $this->index, $context);
    if (!empty($extra_filters) && is_array($extra_filters)) {
      foreach ($extra_filters as $field_id => $field_filter) {
        // Skip entity reference fields in extra filters.
        if (isset($entity_reference_fields[$field_id])) {
          continue;
        }
        $index_field = $this->index->getField($field_id);
        if ($index_field) {
          $element['tabs']['filters'][$field_id . '_wrapper'] = [
            '#type' => 'details',
            '#title' => $index_field->getLabel(),
            '#open' => FALSE,
            '#collapsible' => TRUE,
            '#group_name' => 'filters' . $field_id . '_wrapper',
          ];
          $element['tabs']['filters'][$field_id . '_wrapper'][$field_id] = $field_filter;
          if (empty($field_filter['#disable_filter_operator'])) {
            $element['tabs']['filters'][$field_id . '_wrapper']['operator'] = $this->buildFilterOperatorSelect($configuration['filters'][$field_id]['operator'] ?? 'OR');
          }
          unset($field_filter['#disable_filter_operator']);
          if (isset($configuration['filters'][$field_id]['values'])) {
            $element['tabs']['filters'][$field_id . '_wrapper']['#open'] = TRUE;
          }
        }
      }
    }

    // Today filter.
    $date_fields = $this->indexHelper->getIndexDateFields($this->index);
    $element['tabs']['filters']['today'] = [
      '#type' => 'details',
      '#title' => $this->t('Filter from today for Event-like content types'),
      '#description' => $this->t('This filter is only enabled when there is a valid field mapping for both Start Date and End Date. Both Start Date and End Date can be mapped to the same date field.'),
      '#open' => TRUE,
      '#collapsible' => TRUE,
      '#group_name' => 'filters_today',
    ];
    $element['tabs']['filters']['today']['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable this filter'),
      '#default_value' => $configuration['filter_today']['status'] ?? FALSE,
    ];
    $default_filter_today_start_date = $configuration['filter_today']['start_date'] ?? 'field_event_date_start_value';
    if (!isset($date_fields[$default_filter_today_start_date])) {
      $default_filter_today_start_date = '';
    }
    $default_filter_today_end_date = $configuration['filter_today']['end_date'] ?? 'field_event_date_end_value';
    if (!isset($date_fields[$default_filter_today_end_date])) {
      $default_filter_today_end_date = '';
    }
    if (isset($configuration['filter_today']['status']) && empty($configuration['filter_today']['status'])) {
      $element['tabs']['filters']['today']['#open'] = FALSE;
    }
    $element['tabs']['filters']['today']['start_date'] = [
      '#type' => 'select',
      '#title' => $this->t('Start Date'),
      '#default_value' => $default_filter_today_start_date,
      '#options' => ['' => $this->t('- No mapping -')] + $date_fields,
    ];
    $element['tabs']['filters']['today']['end_date'] = [
      '#type' => 'select',
      '#title' => $this->t('End Date'),
      '#default_value' => $default_filter_today_end_date,
      '#options' => ['' => $this->t('- No mapping -')] + $date_fields,
    ];
    $element['tabs']['filters']['today']['criteria'] = [
      '#type' => 'select',
      '#title' => $this->t('Criteria'),
      '#default_value' => $configuration['filter_today']['criteria'] ?? 'upcoming',
      '#options' => [
        'all' => $this->t('All dates'),
        'upcoming' => $this->t('Upcoming dates'),
        'from_current' => $this->t('Upcoming and current dates'),
        'past' => $this->t('Past dates'),
      ],
    ];
  }

  /**
   * Build Sort settings.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   Field items.
   * @param int $delta
   *   The current delta.
   * @param array $element
   *   The element.
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $configuration
   *   The YAML configuration of the listing.
   */
  protected function buildSortSettingsTab(FieldItemListInterface $items, $delta, array &$element, array &$form, FormStateInterface $form_state, array $configuration = NULL) {
    $element['tabs']['sort'] = [
      '#type' => 'details',
      '#title' => $this->t('Sort criteria'),
      '#open' => TRUE,
      '#collapsible' => TRUE,
      '#group_name' => 'sort',
    ];
    $default_sort_by = '';
    $date_fields = $this->indexHelper->getIndexDateFields($this->index);
    if (!empty($configuration['sort']['field']) && !empty($date_fields[$configuration['sort']['field']])) {
      $default_sort_by = $configuration['sort']['field'];
    }
    $element['tabs']['sort']['field'] = [
      '#type' => 'select',
      '#title' => $this->t('Sort by'),
      '#default_value' => $default_sort_by,
      '#options' => ['' => $this->t('- No sort -')] + $date_fields,
    ];
    $element['tabs']['sort']['direction'] = [
      '#type' => 'select',
      '#title' => $this->t('Sort order'),
      '#default_value' => $configuration['sort']['direction'] ?? 'desc',
      '#options' => [
        'asc' => $this->t('Ascending'),
        'desc' => $this->t('Descending'),
      ],
    ];

    if ($this->indexHelper->isNodeStickyIndexedAsInteger($this->index)) {
      $element['tabs']['sort']['with_sticky'] = [
        '#type' => 'checkbox',
        '#title' => $this->t("Consider 'Sticky at top of lists' option"),
        '#default_value' => $configuration['sort']['with_sticky'] ?? FALSE,
        '#description' => $this->t('If this option is selected, all other sorting criteria will be secondary to the stickied content.'),
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $values = parent::massageFormValues($values, $form, $form_state);
    foreach ($values as $delta => &$value) {
      $config = [];
      $config['index'] = $this->fieldDefinition->getFieldStorageDefinition()->getSetting('index');
      $config['results']['min'] = (int) $value['tabs']['results']['min'] ?? 0;
      $config['results']['max'] = (int) $value['tabs']['results']['max'] ?? 0;
      $config['results']['min_not_met'] = $value['tabs']['results']['min_not_met'] ?? 'hide';
      $config['results']['no_results_message'] = $value['tabs']['results']['no_results_message'] ?? $this->t('There are currently now results');
      $config['display']['type'] = $value['tabs']['display']['type'] ?? 'carousel';
      $config['display']['items_per_page'] = (int) $value['tabs']['display']['items_per_page'] ?? 0;
      $config['card_display']['date'] = $value['tabs']['display']['card_date'] ?? '';

      $card_fields = ['image', 'title', 'summary', 'topic', 'location'];
      foreach ($card_fields as $card_field) {
        $config['card_display']['hide'][$card_field] = !empty($value['tabs']['display']['card_display_hide'][$card_field]) ? TRUE : FALSE;
      }

      $config['filter_operator'] = $value['tabs']['filters']['operator'] ?? 'AND';
      $config['filter_today']['status'] = (bool) $value['tabs']['filters']['today']['status'] ?? FALSE;
      $config['filter_today']['start_date'] = $value['tabs']['filters']['today']['start_date'] ?? '';
      $config['filter_today']['end_date'] = $value['tabs']['filters']['today']['end_date'] ?? '';
      $config['filter_today']['criteria'] = $value['tabs']['filters']['today']['criteria'] ?? 'upcoming';

      $config['filters']['type']['values'] = $value['tabs']['filters']['type_wrapper']['type'] ? array_values(array_filter($value['tabs']['filters']['type_wrapper']['type'])) : [];
      $config['filters']['type']['operator'] = 'OR';

      $entity_reference_fields = $this->getEntityReferenceFields();
      foreach ($value['tabs']['filters'] as $wrapper_id => $wrapper) {
        $field_id = str_replace('_wrapper', '', $wrapper_id);
        if (isset($wrapper[$field_id])) {
          switch ($field_id) {
            case 'type':
            case 'today':
            case 'operator':
              break;

            default:
              // Entity reference fields.
              if (isset($entity_reference_fields[$field_id])) {
                foreach ($wrapper[$field_id] as $index => $reference) {
                  if (!empty($reference['target_id'])) {
                    $config['filters'][$field_id]['values'][] = (int) $reference['target_id'];
                  }
                }
              }
              // Extra fields.
              else {
                $config['filters'][$field_id]['values'] = is_array($wrapper[$field_id]) ? array_values(array_filter($wrapper[$field_id])) : [$wrapper[$field_id]];
                $config['filters'][$field_id]['values'] = array_filter($config['filters'][$field_id]['values']);
              }

              if (!empty($wrapper['operator'])) {
                $config['filters'][$field_id]['operator'] = $wrapper['operator'];
              }

              if (empty($config['filters'][$field_id]['values'])) {
                unset($config['filters'][$field_id]);
              }
              break;
          }
        }
      }

      if (!isset($config['filters']['field_topic'])) {
        $config['filters']['field_topic'] = [];
      }
      if (!isset($config['filters']['field_tags'])) {
        $config['filters']['field_tags'] = [];
      }

      $config['sort']['field'] = $value['tabs']['sort']['field'] ?? '';
      $config['sort']['direction'] = $value['tabs']['sort']['direction'] ?? 'desc';

      if (isset($value['tabs']['sort']['with_sticky'])) {
        $config['sort']['with_sticky'] = (boolean) $value['tabs']['sort']['with_sticky'] ?? FALSE;
      }
      $value['value'] = Yaml::encode($config);
    }

    return $values;
  }

  /**
   * Build a filter operator select element.
   *
   * @param string $default_value
   *   The default operator.
   * @param string $description
   *   The description of the operator.
   *
   * @return string[]
   *   The form element.
   */
  protected function buildFilterOperatorSelect($default_value = 'AND', $description = NULL) {
    return [
      '#type' => 'select',
      '#title' => $this->t('Filter operator'),
      '#description' => $description,
      '#default_value' => $default_value ?? 'AND',
      '#options' => [
        'AND' => $this->t('AND'),
        'OR' => $this->t('OR'),
      ],
    ];
  }

  /**
   * Get all entity reference fields.
   *
   * @return array
   *   The reference fields.
   */
  protected function getEntityReferenceFields() {
    $reference_fields = $this->indexHelper->getIndexEntityReferenceFields($this->index, ['nid']);
    $fields = [];
    $top_fields = ['field_topic', 'field_tags'];
    foreach ($top_fields as $field_id) {
      if (isset($reference_fields[$field_id])) {
        $fields[$field_id] = $reference_fields[$field_id];
        unset($reference_fields[$field_id]);
      }
    }
    $fields += $reference_fields;

    return $fields;
  }

  /**
   * Get the element name for Form States API.
   *
   * @param string $element_name
   *   The name of the element.
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   Field items.
   * @param int $delta
   *   Delta.
   * @param array $element
   *   The element.
   *
   * @return string
   *   The final element name.
   */
  protected function getFormStatesElementName($element_name, FieldItemListInterface $items, $delta, array $element) {
    $name = '';
    foreach ($element['#field_parents'] as $index => $parent) {
      $name .= $index ? ('[' . $parent . ']') : $parent;
    }
    $name .= '[' . $items->getName() . ']';
    $name .= '[' . $delta . ']';
    foreach (explode('|', $element_name) as $path) {
      $name .= '[' . $path . ']';
    }
    return $name;
  }

  /**
   * Get the default configuration.
   *
   * @param array $configuration
   *   The configuration.
   *
   * @return array
   *   The configuration.
   */
  protected function getDefaultConfiguration($configuration) {
    $configuration['results']['min'] = $configuration['results']['min'] ?? 0;
    $configuration['results']['max'] = $configuration['results']['max'] ?? 0;
    $configuration['results']['min_not_met'] = $configuration['results']['min_not_met'] ?? 'hide';
    $configuration['results']['no_results_message'] = $configuration['results']['no_results_message'] ?? '';

    $configuration['display']['type'] = $configuration['display']['type'] ?? 'carousel';
    $configuration['display']['items_per_page'] = $configuration['display']['items_per_page'] ?? 0;

    $configuration['filter_operator'] = $configuration['filter_operator'] ?? 'AND';
    $configuration['filters'] = $configuration['filters'] ?? [];

    $configuration['sort'] = $configuration['sort'] ?? [];

    return $configuration;
  }

}
