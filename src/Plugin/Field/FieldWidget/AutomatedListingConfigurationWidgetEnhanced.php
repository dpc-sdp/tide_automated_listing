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
 * Plugin implementation of the 'automated_listing_configuration_enhanced' widget.
 *
 * @FieldWidget(
 *   id = "automated_listing_configuration_enhanced",
 *   label = @Translation("Automated Card Listing Configuration Enhanced"),
 *   field_types = {
 *     "automated_listing_configuration"
 *   }
 * )
 */
class AutomatedListingConfigurationWidgetEnhanced extends StringTextareaWidget implements ContainerFactoryPluginInterface {

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
  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    array $third_party_settings,
    SearchApiIndexHelper $index_helper,
    ModuleHandlerInterface $module_handler
  ) {
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
  public static function defaultSettings() {
    return [
        'expose_filter_operator' => 0,
        'default_filter_operator' => 'OR',
        'excluded_fields' => "uid",
      ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element['expose_filter_operator'] = [
      '#type' => 'radios',
      '#title' => t('Expose Filter Operator'),
      '#options' => [0 => 'Hide', 1 => 'Show'],
      '#default_value' => $this->getSetting('expose_filter_operator'),
      '#required' => TRUE,
    ];

    $element['default_filter_operator'] = [
      '#type' => 'radios',
      '#title' => t('Default Filter Operator'),
      '#options' => ['AND' => 'AND', 'OR' => 'OR'],
      '#default_value' => $this->getSetting('default_filter_operator'),
      '#required' => TRUE,
    ];

    $element['excluded_fields'] = [
      '#type' => 'textfield',
      '#title' => t('Fields to exclude'),
      '#description' => t('Enter comma separated fields to exclude. Example: nid,uid'),
      '#default_value' => $this->getSetting('excluded_fields'),
      '#required' => TRUE,
    ];

    return $element;
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
    $default_sort_by = 'changed';
    $date_fields = $this->indexHelper->getIndexDateFields($this->index);
    if (!empty($configuration['sort']['field']) && !empty($date_fields[$configuration['sort']['field']])) {
      $default_sort_by = $configuration['sort']['field'];
    }

    $element['sort_by'] = [
      '#type' => 'select',
      '#title' => $this->t('Sort by a date filter'),
      '#default_value' => $default_sort_by,
      '#options' => ['' => $this->t('- No sort -')] + $date_fields,
    ];
    $element['sort_direction'] = [
      '#type' => 'select',
      '#title' => $this->t('Sort order'),
      '#default_value' => $configuration['sort']['direction'] ?? 'desc',
      '#options' => [
        'asc' => $this->t('Ascending'),
        'desc' => $this->t('Descending'),
      ],
    ];

    if ($this->indexHelper->isNodeStickyIndexedAsInteger($this->index)) {
      $element['sort_with_sticky'] = [
        '#type' => 'checkbox',
        '#title' => $this->t("Consider 'Sticky at top of lists' option"),
        '#default_value' => $configuration['sort_with_sticky'] ?? FALSE,
        '#description' => $this->t('If this option is selected, all other sorting criteria will be secondary to the stickied content.'),
      ];
    }

    $date_fields = $this->indexHelper->getIndexDateFields($this->index);
    $default_card_date = '';
    if (!empty($configuration['card_display']['date']) && !empty($date_fields[$configuration['card_display']['date']])) {
      $default_card_date = $configuration['card_display']['date'];
    }
    $element['card_date'] = [
      '#type' => 'select',
      '#title' => $this->t('Card Date field mapping'),
      '#default_value' => $default_card_date,
      '#options' => ['' => $this->t('- Does not show -')] + $date_fields,
    ];

    if ($this->settings['expose_filter_operator'] == FALSE) {
      $element['card_date']['#access'] = FALSE;
    }

    $element['card_display_hide'] = [
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


    if ($this->settings['expose_filter_operator'] == FALSE) {
      $element['card_display_hide']['#access'] = FALSE;
    }

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
    $element['operator'] = $this->buildFilterOperatorSelect($configuration['filter_operator'] ?? $this->settings['default_filter_operator'], $this->t('This operator is used to combined the filters together.'));

    // Content type filter.
    if ($this->indexHelper->isNodeTypeIndexed($this->index)) {
      $element['type'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Select the content type(s) you\'d like to display'),
        '#options' => $this->indexHelper->getNodeTypes(),
        '#default_value' => $configuration['filters']['type']['values'] ?? [],
        '#required' => FALSE,
        '#description' => $this->t('You can select more than 1 content type to display in your list of cards.')
      ];
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

    $contentTypesDefinitions = [];

    foreach ($this->indexHelper->getNodeTypes() as $key => $value) {
      $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', $key);
      $contentTypesDefinitions[$key] = array_keys($definitions);
    }

    if (!empty($entity_reference_fields)) {
      foreach ($entity_reference_fields as $field_id => $field_settings) {
        if ($field_id === 'field_topic' || $field_id === 'field_tags') {
          $default_values = $configuration['filters'][$field_id]['values'] ?? [];
          $field_filter = $this->indexHelper->buildEntityReferenceFieldFilter($this->index, $field_id, $default_values);
          if ($field_filter) {
            $element[$field_id . '_wrapper'] = [
              '#type' => 'details',
              '#title' => $field_settings['label'],
              '#open' => FALSE,
              '#collapsible' => TRUE,
              '#group_name' => 'filters_' . $field_id . '_wrapper',
            ];
            $element[$field_id . '_wrapper'][$field_id] = $field_filter;

            $element[$field_id . '_wrapper']['operator'] = $this->buildFilterOperatorSelect($configuration['filters'][$field_id]['operator'] ?? $this->settings['default_filter_operator'], $this->t('This filter operator is used to combined all the selected values together.'));

            if (isset($configuration['filters'][$field_id])) {
              $element[$field_id . '_wrapper']['#open'] = TRUE;
            }

            $element[$field_id . '_wrapper']['#title'] = ($field_id == 'field_topic') ? $this->t('Topic') : $this->t('Tags');
            $element[$field_id . '_wrapper'][$field_id]['#title'] = ($field_id == 'field_topic') ? $this->t('Select topics to show') : $this->t('Select tags to show ');
            $element[$field_id . '_wrapper'][$field_id]['#description'] = ($field_id == 'field_topic') ? $this->t('Start typing a topic from our list of topics, for example Business (https://www.vic.gov.au/create-new-content-page#mandatory-fields-landing-page).') : $this->t('Start typing tags for audience groups. Find a list of tags (https://www.singledigitalpresence.vic.gov.au/create-new-content-page#mandatory-fields-landing-page)');
            if (isset($configuration['filters'][$field_id]['values']) && empty($configuration['filters'][$field_id]['values'])) {
              $element[$field_id . '_wrapper']['#open'] = FALSE;
            }
            else {
              $element[$field_id . '_wrapper']['#open'] = TRUE;
            }

            $visible_content_types = [];
            foreach ($contentTypesDefinitions as $key => $value) {
              if (in_array($field_id, $value)) {
                $visible_content_types[] = $key;
              }
            }

            $element[$field_id . '_wrapper']['visible_types'] = $this->buildHiddenValueStates($visible_content_types);
          }
        }
      }

      $element['advanced_taxonomy_wrapper'] = [
        '#type' => 'details',
        '#title' => $this->t('Advanced Taxonomies'),
        '#open' => TRUE,
        '#collapsible' => TRUE,
        '#group_name' => 'result_advanced_taxonomy_wrapper',
      ];

      foreach ($entity_reference_fields as $field_id => $field_settings) {
        if ($field_id !== 'field_topic' && $field_id !== 'field_tags') {
          $default_values = $configuration['filters'][$field_id]['values'] ?? [];
          $field_filter = $this->indexHelper->buildEntityReferenceFieldFilter($this->index, $field_id, $default_values);
          if ($field_filter) {
            $element['advanced_taxonomy_wrapper'][$field_id . '_wrapper'] = [
              '#type' => 'details',
              '#title' => $field_settings['label'],
              '#open' => FALSE,
              '#collapsible' => TRUE,
              '#group_name' => 'filters_' . $field_id . '_wrapper',
            ];
            $element['advanced_taxonomy_wrapper'][$field_id . '_wrapper'][$field_id] = $field_filter;

            $element['advanced_taxonomy_wrapper'][$field_id . '_wrapper']['operator'] = $this->buildFilterOperatorSelect($configuration['filters'][$field_id]['operator'] ?? $this->settings['default_filter_operator'], $this->t('This filter operator is used to combined all the selected values together.'));

            if (isset($configuration['filters'][$field_id])) {
              $element['advanced_taxonomy_wrapper'][$field_id . '_wrapper']['#open'] = TRUE;
            }

            $visible = [];
            $visible_content_types = [];

            foreach ($contentTypesDefinitions as $key => $value) {
              foreach ($value as $item) {
                if (strpos($field_settings['path'], $item) !== FALSE) {
                  $visible[] = [
                    ':input[name="' . $this->getFormStatesElementName('type', $items, $delta, $element) . '[' . $key . ']' . '"]' => ['checked' => TRUE],
                  ];
                  $visible_content_types[] = $key;
                  continue;
                }
              }
            }

            $element['advanced_taxonomy_wrapper'][$field_id . '_wrapper']['visible_types'] = $this->buildHiddenValueStates($visible_content_types);

            if (!empty($visible)) {
              $element['advanced_taxonomy_wrapper'][$field_id . '_wrapper']['#states']['visible'] = $visible;
            } else {
              $element['advanced_taxonomy_wrapper'][$field_id . '_wrapper']['#access'] = FALSE;
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
          $element[$field_id . '_wrapper'] = [
            '#type' => 'details',
            '#title' => $index_field->getLabel(),
            '#open' => FALSE,
            '#collapsible' => TRUE,
            '#group_name' => 'results' . $field_id . '_wrapper',
          ];
          $element[$field_id . '_wrapper'][$field_id] = $field_filter;

          if (empty($field_filter['#disable_filter_operator'])) {
            $element[$field_id . '_wrapper']['operator'] = $this->buildFilterOperatorSelect($configuration['filters'][$field_id]['operator'] ?? $this->settings['default_filter_operator']);
          }

          unset($field_filter['#disable_filter_operator']);
          if (isset($configuration['filters'][$field_id]['values'])) {
            $element[$field_id . '_wrapper']['#open'] = TRUE;
          }
        }
      }
    }

    // Today filter.
    $date_fields = $this->indexHelper->getIndexDateFields($this->index);
    $element['today'] = [
      '#type' => 'details',
      '#title' => $this->t('Filters for content types that include dates'),
      '#description' => $this->t('You can apply more filters to Event, Grant, Family violence recommendations and Publications content, if a Start date and End date has been included.'),
      '#open' => TRUE,
      '#collapsible' => TRUE,
      '#group_name' => 'filters_today',
      '#states' => [
        'invisible' => [
          ':input[name="' . $this->getFormStatesElementName('type', $items, $delta, $element) . '"]' => ['value' => '']
        ]
      ],
    ];

    $element['today']['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable date filter'),
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
      $element['today']['#open'] = FALSE;
    }

    $element['today']['start_date'] = [
      '#type' => 'select',
      '#title' => $this->t('Start Date'),
      '#default_value' => $default_filter_today_start_date,
      '#options' => ['' => $this->t('- No mapping -')] + $date_fields,
    ];

    $element['today']['end_date'] = [
      '#type' => 'select',
      '#title' => $this->t('End Date'),
      '#default_value' => $default_filter_today_end_date,
      '#options' => ['' => $this->t('- No mapping -')] + $date_fields,
    ];
    $element['today']['criteria'] = [
      '#type' => 'select',
      '#title' => $this->t('Select the date filter for your collection'),
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

      // Deprecated Fields Start
      $config['results']['min'] = 1;
      $config['results']['max'] = 0;
      $config['results']['min_not_met'] = 'hide';
      $config['results']['no_results_message'] = 'There are currently no results';
      $config['display']['type'] = 'grid';
      $config['display']['items_per_page'] = 0;
      // Deprecated Fields End

      $config['card_display']['date'] = $value['card_date'] ?? '';
      $card_fields = ['image', 'title', 'summary', 'topic', 'location'];
      foreach ($card_fields as $card_field) {
        $config['card_display']['hide'][$card_field] = !empty($value['card_display_hide'][$card_field]) ? TRUE : FALSE;
      }

      $config['filter_operator'] = $value['operator'] ?? $this->settings['default_filter_operator'];
      $config['filter_today']['status'] = (bool) $value['today']['status'] ?? FALSE;
      $config['filter_today']['start_date'] = $value['today']['start_date'] ?? '';
      $config['filter_today']['end_date'] = $value['today']['end_date'] ?? '';
      $config['filter_today']['criteria'] = $value['today']['criteria'] ?? 'upcoming';

      $config['filters']['type']['values'] = $value['type'] ? array_values(array_filter($value['type'])) : [];
      $config['filters']['type']['operator'] = 'OR';

      foreach ($value['advanced_taxonomy_wrapper'] as $wrapper_id => $wrapper) {
        $field_id = str_replace('_wrapper', '', $wrapper_id);
        $content_type_selected = array_values($value['type']);
        $visible_content_types = $value['advanced_taxonomy_wrapper'][$wrapper_id]['visible_types'];
        $visible_content_type_collection = explode(",", $visible_content_types);

        $visible = FALSE;

        if (is_array($visible_content_type_collection)) {
          foreach ($visible_content_type_collection as $visible_type) {
            if (in_array($visible_type, $content_type_selected, TRUE)) {
              $visible = TRUE;
              continue;
            }
          }
        } else {
          if (in_array($visible_content_type_collection, $content_type_selected, TRUE)) {
            $visible = TRUE;
          }
        }

        if ($visible === TRUE) {
          $config['filters'][$field_id]['values'] = $this->saveFilterValues($field_id, $wrapper);

          if (!empty($wrapper['operator'])) {
            $config['filters'][$field_id]['operator'] = $wrapper['operator'];
          }
        } else {
          $config['filters'][$field_id]['values'] = [];
        }

        if (empty($config['filters'][$field_id]['values'])) {
          unset($config['filters'][$field_id]);
        }
      }

      foreach (['field_topic_wrapper', 'field_tags_wrapper'] as $wrapper_id) {
        if (isset($value[$wrapper_id])) {
          $wrapper = $value[$wrapper_id];
          $field_id = str_replace('_wrapper', '', $wrapper_id);
          $content_type_selected = array_values($value['type']);
          $visible_content_types = $value[$wrapper_id]['visible_types'];
          $visible_content_type_collection = explode(",", $visible_content_types);

          $visible = FALSE;

          if (is_array($visible_content_type_collection)) {
            foreach ($visible_content_type_collection as $visible_type) {
              if (in_array($visible_type, $content_type_selected, TRUE)) {
                $visible = TRUE;
                continue;
              }
            }
          } else {
            if (in_array($visible_content_type_collection, $content_type_selected, TRUE)) {
              $visible = TRUE;
            }
          }

          if ($visible === TRUE) {
            $config['filters'][$field_id]['values'] = $this->saveFilterValues($field_id, $wrapper);

            if (!empty($wrapper['operator'])) {
              $config['filters'][$field_id]['operator'] = $wrapper['operator'];
            }
          } else {
            $config['filters'][$field_id]['values'] = [];
          }

          if (empty($config['filters'][$field_id]['values'])) {
            unset($config['filters'][$field_id]);
          }
        }
      }

      $config['sort']['field'] = $value['sort_by'] ?? '';
      $config['sort']['direction'] = $value['sort_direction'] ?? 'desc';

      if (isset($value['sort_with_sticky'])) {
        $config['sort']['sort_with_sticky'] = (boolean) $value['sort_with_sticky'] ?? FALSE;
      }

      if (isset($config['type'])) {
        unset($config['type']);
      }

      $value['value'] = Yaml::encode($config);
    }

    return $values;
  }

  /**
   * Save filter values.
   */
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
   * @param $value
   * @return array
   */
  protected function buildHiddenValueStates($value) {
    return [
      '#type' => 'hidden',
      '#value' => implode(",", $value),
    ];
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
    $element = [
      '#type' => 'select',
      '#title' => $this->t('Filter operator'),
      '#description' => $description,
      '#default_value' => $default_value ?? 'AND',
      '#options' => [
        'AND' => $this->t('AND'),
        'OR' => $this->t('OR'),
      ]
    ];


    if ($this->settings['expose_filter_operator'] == FALSE) {
      $element['#access'] = FALSE;
    }

    return $element;
  }

  /**
   * Get all entity reference fields.
   *
   * @return array
   *   The reference fields.
   */
  protected function getEntityReferenceFields() {
    $excluded_fields = ['nid'];
    if (isset($this->settings['excluded_fields']) && !empty($this->settings['excluded_fields'])) {
      if (strpos($this->settings['excluded_fields'], ',') !== FALSE) {
        $excluded_fields = array_merge($excluded_fields, explode(',', $this->settings['excluded_fields']));
      } else {
        $excluded_fields = array_merge($excluded_fields, [$this->settings['excluded_fields']]);
      }
    }

    $reference_fields = $this->indexHelper->getIndexEntityReferenceFields($this->index, $excluded_fields);
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
    $configuration['min'] = $configuration['min'] ?? 1;
    $configuration['max'] = 0;
    $configuration['min_not_met'] = $configuration['min_not_met'] ?? 'hide';
    $configuration['no_results_message'] = $configuration['no_results_message'] ?? '';

    $configuration['type'] = $configuration['type'] ?? 'grid';
    $configuration['items_per_page'] = $configuration['items_per_page'] ?? 9;

    $configuration['filter_operator'] = $configuration['filter_operator'] ?? $this->settings['default_filter_operator'];

    $configuration['sort'] = $configuration['sort'] ?? [];

    return $configuration;
  }

}
