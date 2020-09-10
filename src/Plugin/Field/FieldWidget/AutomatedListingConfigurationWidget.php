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
      '#title' => $this->t('Display options'),
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

    $element['tabs']['display']['min_not_met'] = [
      '#type' => 'radios',
      '#title' => $this->t('If minimum count is not met'),
      '#options' => [
        'hide' => $this->t('Hide component'),
        'no_results_message' => $this->t("Show 'no results' message"),
      ],
      '#default_value' => $configuration['display']['min_not_met'] ?? 'hide',
    ];

    $no_result_message = $this->t('There are currently no results');

    if (!empty($configuration['display']['no_results_message'])) {
      $no_result_message = $configuration['display']['no_results_message'];
    }

    $element['tabs']['display']['no_results_message'] = [
      '#type' => 'textfield',
      '#title' => $this->t("'No results' message"),
      '#default_value' => $no_result_message,
      '#states' => [
        'invisible' => [
          ':input[name="' . $this->getFormStatesElementName('tabs|display|min_not_met', $items, $delta, $element) . '"]' => ['value' => 'hide'],
        ],
      ],
    ];

    $element['tabs']['display']['items_per_page'] = [
      '#type' => 'number',
      '#title' => $this->t('Cards shown per page'),
      '#default_value' => $configuration['display']['items_per_page'] ?? 0,
      '#min' => 0,
      '#description' => $this->t('Enter \'0\' to show all results on one page'),
    ];

    $default_sort_by = '';
    $date_fields = $this->indexHelper->getIndexDateFields($this->index);
    if (!empty($configuration['display']['sort_by']) && !empty($date_fields[$configuration['display']['sort_by']])) {
      $default_sort_by = $configuration['display']['sort_by'];
    }

    $element['tabs']['display']['sort_by'] = [
      '#type' => 'select',
      '#title' => $this->t('Sort by'),
      '#default_value' => $default_sort_by,
      '#options' => ['' => $this->t('- No sort -')] + $date_fields,
    ];
    $element['tabs']['display']['sort_direction'] = [
      '#type' => 'select',
      '#title' => $this->t('Sort order'),
      '#default_value' => $configuration['display']['sort_direction'] ?? 'desc',
      '#options' => [
        'asc' => $this->t('Ascending'),
        'desc' => $this->t('Descending'),
      ],
    ];

    if ($this->indexHelper->isNodeStickyIndexedAsInteger($this->index)) {
      $element['tabs']['display']['sort_with_sticky'] = [
        '#type' => 'checkbox',
        '#title' => $this->t("Consider 'Sticky at top of lists' option"),
        '#default_value' => $configuration['display']['sort_with_sticky'] ?? FALSE,
        '#description' => $this->t('If this option is selected, all other sorting criteria will be secondary to the stickied content.'),
      ];
    }

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
  protected function buildResultsSettingsTab(FieldItemListInterface $items, $delta, array &$element, array &$form, FormStateInterface $form_state, array $configuration = NULL) {
    $element['tabs']['results'] = [
      '#type' => 'details',
      '#title' => $this->t('Listing results'),
      '#open' => TRUE,
      '#collapsible' => TRUE,
      '#group_name' => 'results',
    ];
    $element['tabs']['results']['operator'] = $this->buildFilterOperatorSelect($configuration['filter_operator'] ?? 'AND', $this->t('This operator is used to combined the filters together.'));

    // Content type filter.
    if ($this->indexHelper->isNodeTypeIndexed($this->index)) {
      $element['tabs']['results']['type_wrapper'] = [
        '#type' => 'details',
        '#title' => $this->t('Content type'),
        '#open' => TRUE,
        '#collapsible' => TRUE,
        '#group_name' => 'filters_type_wrapper',
      ];
      $element['tabs']['results']['type_wrapper']['type'] = [
        '#type' => 'select',
        '#title' => $this->t('Select Content type'),
        '#options' => $this->indexHelper->getNodeTypes(),
        '#default_value' => $configuration['results']['type']['values'] ?? [],
      ];
      if (isset($configuration['results']['type']['values']) && empty($configuration['results']['type']['values'])) {
        $element['tabs']['results']['type_wrapper']['#open'] = FALSE;
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
        if ($field_id === 'field_topic' || $field_id === 'field_tags') {
          $default_values = $configuration['results'][$field_id]['values'] ?? [];
          $field_filter = $this->indexHelper->buildEntityReferenceFieldFilter($this->index, $field_id, $default_values);
          if ($field_filter) {
            $element['tabs']['results'][$field_id . '_wrapper'] = [
              '#type' => 'details',
              '#title' => $field_label,
              '#open' => FALSE,
              '#collapsible' => TRUE,
              '#group_name' => 'filters_' . $field_id . '_wrapper',
            ];
            $element['tabs']['results'][$field_id . '_wrapper'][$field_id] = $field_filter;
            $element['tabs']['results'][$field_id . '_wrapper']['operator'] = $this->buildFilterOperatorSelect($configuration['results'][$field_id]['operator'] ?? 'OR', $this->t('This filter operator is used to combined all the selected values together.'));
            if (isset($configuration['results'][$field_id])) {
              $element['tabs']['results'][$field_id . '_wrapper']['#open'] = TRUE;
            }

            $element['tabs']['results'][$field_id . '_wrapper']['#title'] = ($field_id == 'field_topic') ? $this->t('Topic') : $this->t('Tags');
            $element['tabs']['results'][$field_id . '_wrapper'][$field_id]['#title'] = ($field_id == 'field_topic') ? $this->t('Select topics') : $this->t('Select tags');
            $element['tabs']['results'][$field_id . '_wrapper'][$field_id]['#description'] = ($field_id == 'field_topic') ? $this->t('Separate multiple topics with comma') : $this->t('Separate multiple tags with comma');
            if (isset($configuration['results'][$field_id]['values']) && empty($configuration['results'][$field_id]['values'])) {
              $element['tabs']['results'][$field_id . '_wrapper']['#open'] = FALSE;
            }
            else {
              $element['tabs']['results'][$field_id . '_wrapper']['#open'] = TRUE;
            }
          }
        }
      }

      $element['tabs']['results']['advanced_taxonomy_wrapper'] = [
        '#type' => 'details',
        '#title' => $this->t('Advanced Taxonomies'),
        '#open' => TRUE,
        '#collapsible' => TRUE,
        '#group_name' => 'result_advanced_taxonomy_wrapper',
      ];

      foreach ($entity_reference_fields as $field_id => $field_label) {
        if ($field_id !== 'field_topic' && $field_id !== 'field_tags') {
          $default_values = $configuration['results']['advanced_taxonomy_wrapper'][$field_id]['values'] ?? [];
          $field_filter = $this->indexHelper->buildEntityReferenceFieldFilter($this->index, $field_id, $default_values);
          if ($field_filter) {
            $element['tabs']['results']['advanced_taxonomy_wrapper'][$field_id . '_wrapper'] = [
              '#type' => 'details',
              '#title' => $field_label,
              '#open' => FALSE,
              '#collapsible' => TRUE,
              '#group_name' => 'filters_' . $field_id . '_wrapper',
            ];
            $element['tabs']['results']['advanced_taxonomy_wrapper'][$field_id . '_wrapper'][$field_id] = $field_filter;
            $element['tabs']['results']['advanced_taxonomy_wrapper'][$field_id . '_wrapper']['operator'] = $this->buildFilterOperatorSelect($configuration['results']['advanced_taxonomy_wrapper'][$field_id]['operator'] ?? 'OR', $this->t('This filter operator is used to combined all the selected values together.'));
            if (isset($configuration['results']['advanced_taxonomy_wrapper'][$field_id])) {
              $element['tabs']['results']['advanced_taxonomy_wrapper'][$field_id . '_wrapper']['#open'] = TRUE;
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
      $configuration['results'],
    ]);
    $context = [
      'index' => clone $items,
      'delta' => $delta,
      'filters' => $configuration['results'],
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
          $element['tabs']['results'][$field_id . '_wrapper'] = [
            '#type' => 'details',
            '#title' => $index_field->getLabel(),
            '#open' => FALSE,
            '#collapsible' => TRUE,
            '#group_name' => 'results' . $field_id . '_wrapper',
          ];
          $element['tabs']['results'][$field_id . '_wrapper'][$field_id] = $field_filter;
          if (empty($field_filter['#disable_filter_operator'])) {
            $element['tabs']['results'][$field_id . '_wrapper']['operator'] = $this->buildFilterOperatorSelect($configuration['results'][$field_id]['operator'] ?? 'OR');
          }
          unset($field_filter['#disable_filter_operator']);
          if (isset($configuration['results'][$field_id]['values'])) {
            $element['tabs']['results'][$field_id . '_wrapper']['#open'] = TRUE;
          }
        }
      }
    }

    // Today filter.
    $date_fields = $this->indexHelper->getIndexDateFields($this->index);
    $element['tabs']['results']['today'] = [
      '#type' => 'details',
      '#title' => $this->t('Filter from today for Event-like content types'),
      '#description' => $this->t('This filter is only enabled when there is a valid field mapping for both Start Date and End Date. Both Start Date and End Date can be mapped to the same date field.'),
      '#open' => TRUE,
      '#collapsible' => TRUE,
      '#group_name' => 'filters_today',
    ];
    $element['tabs']['results']['today']['status'] = [
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
      $element['tabs']['results']['today']['#open'] = FALSE;
    }
    $element['tabs']['results']['today']['start_date'] = [
      '#type' => 'select',
      '#title' => $this->t('Start Date'),
      '#default_value' => $default_filter_today_start_date,
      '#options' => ['' => $this->t('- No mapping -')] + $date_fields,
    ];
    $element['tabs']['results']['today']['end_date'] = [
      '#type' => 'select',
      '#title' => $this->t('End Date'),
      '#default_value' => $default_filter_today_end_date,
      '#options' => ['' => $this->t('- No mapping -')] + $date_fields,
    ];
    $element['tabs']['results']['today']['criteria'] = [
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
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $values = parent::massageFormValues($values, $form, $form_state);
    foreach ($values as $delta => &$value) {
      $config = [];
      $config['index'] = $this->fieldDefinition->getFieldStorageDefinition()->getSetting('index');
      $config['display']['min_not_met'] = $value['tabs']['display']['min_not_met'] ?? 'hide';
      $config['display']['no_results_message'] = $value['tabs']['display']['no_results_message'] ?? $this->t('There are currently now results');
      $config['display']['type'] = $value['tabs']['display']['type'] ?? 'carousel';
      $config['display']['items_per_page'] = (int) $value['tabs']['display']['items_per_page'] ?? 0;
      $config['card_display']['date'] = $value['tabs']['display']['card_date'] ?? '';
      $card_fields = ['image', 'title', 'summary', 'topic', 'location'];
      foreach ($card_fields as $card_field) {
        $config['card_display']['hide'][$card_field] = !empty($value['tabs']['display']['card_display_hide'][$card_field]) ? TRUE : FALSE;
      }

      $config['filter_operator'] = $value['tabs']['results']['operator'] ?? 'AND';
      $config['filter_today']['status'] = (bool) $value['tabs']['results']['today']['status'] ?? FALSE;
      $config['filter_today']['start_date'] = $value['tabs']['results']['today']['start_date'] ?? '';
      $config['filter_today']['end_date'] = $value['tabs']['results']['today']['end_date'] ?? '';
      $config['filter_today']['criteria'] = $value['tabs']['results']['today']['criteria'] ?? 'upcoming';

      $config['results']['type']['values'] = $value['tabs']['results']['type_wrapper']['type'] ? array_values(array_filter($value['tabs']['results']['type_wrapper']['type'])) : [];
      $config['results']['type']['operator'] = 'OR';

      foreach ($value['tabs']['results']['advanced_taxonomy_wrapper'] as $wrapper_id => $wrapper) {
        $field_id = str_replace('_wrapper', '', $wrapper_id);

        $config['results']['advanced_taxonomy_wrapper'][$field_id]['values'] = $this->saveFilterValues($field_id, $wrapper);

        if (!empty($wrapper['operator'])) {
          $config['results']['advanced_taxonomy_wrapper'][$field_id]['operator'] = $wrapper['operator'];
        }

        if (empty($config['results']['advanced_taxonomy_wrapper'][$field_id]['values'])) {
          unset($config['results']['advanced_taxonomy_wrapper'][$field_id]);
        }
      }

      foreach (['field_topic_wrapper', 'field_tags_wrapper'] as $wrapper_id) {
        if (isset($value['tabs']['results'][$wrapper_id])) {
          $wrapper = $value['tabs']['results'][$wrapper_id];
          $field_id = str_replace('_wrapper', '', $wrapper_id);

          $config['results'][$field_id]['values'] = $this->saveFilterValues($field_id, $wrapper);

          if (!empty($wrapper['operator'])) {
            $config['results'][$field_id]['operator'] = $wrapper['operator'];
          }
        }

        if (empty($config['results'][$field_id]) && empty($config['results'][$field_id]['values'])) {
          $config['results'][$field_id] = [];
        }
      }

      $config['display']['sort_by'] = $value['tabs']['display']['sort_by'] ?? '';
      $config['display']['sort_direction'] = $value['tabs']['display']['sort_direction'] ?? 'desc';

      if (isset($value['tabs']['display']['sort_with_sticky'])) {
        $config['display']['sort_with_sticky'] = (boolean) $value['tabs']['display']['sort_with_sticky'] ?? FALSE;
      }
      $value['value'] = Yaml::encode($config);
    }

    return $values;
  }

  protected function saveFilterValues($field_id, $wrapper) {
    $entity_reference_fields = $this->getEntityReferenceFields();
    $values = [];

    if (isset($wrapper[$field_id])) {
      switch ($field_id) {
        case 'type':
        case 'today':
        case 'operator':
          return $values;

        default:
          // Entity reference fields.
          if (isset($entity_reference_fields[$field_id])) {
            foreach ($wrapper[$field_id] as $index => $reference) {
              if (!empty($reference['target_id'])) {
                $values[] = (int) $reference['target_id'];
              }
            }
          }
          // Extra fields.
          else {
            $values = array_filter(is_array($wrapper[$field_id]) ? array_values(array_filter($wrapper[$field_id])) : [$wrapper[$field_id]]);
          }

          return $values;
      }
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
    $configuration['display']['min_not_met'] = $configuration['display']['min_not_met'] ?? 'hide';
    $configuration['display']['no_results_message'] = $configuration['display']['no_results_message'] ?? '';

    $configuration['display']['type'] = $configuration['display']['type'] ?? 'carousel';
    $configuration['display']['items_per_page'] = $configuration['display']['items_per_page'] ?? 0;

    $configuration['filter_operator'] = $configuration['filter_operator'] ?? 'AND';

    $configuration['results'] = $configuration['results'] ?? [];

    return $configuration;
  }

}
