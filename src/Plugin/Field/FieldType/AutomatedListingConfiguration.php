<?php

namespace Drupal\tide_automated_listing\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\StringLongItem;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataInterface;

/**
 * Defines the 'automated_listing_configuration' field type.
 *
 * @FieldType(
 *   id = "automated_listing_configuration",
 *   label = @Translation("Automated Card Listing Configuration"),
 *   description = @Translation("A field containing YAML configuration for automated card listing."),
 *   category = @Translation("Automated Card Listing"),
 *   default_widget = "automated_listing_configuration",
 *   default_formatter = "automated_listing_configuration",
 * )
 */
class AutomatedListingConfiguration extends StringLongItem {

  /**
   * The Search API Index helper.
   *
   * @var \Drupal\tide_automated_listing\SearchApiIndexHelper
   */
  protected $indexHelper;

  /**
   * {@inheritdoc}
   */
  public function __construct(DataDefinitionInterface $definition, $name = NULL, TypedDataInterface $parent = NULL) {
    parent::__construct($definition, $name, $parent);
    $this->indexHelper = static::getContainer()->get('tide_automated_listing.sapi_index_helper');
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = parent::propertyDefinitions($field_definition);
    $properties['configuration'] = DataDefinition::create('any')
      ->setLabel(t('Computed configuration'))
      ->setDescription(t('The computed configuration object.'))
      ->setComputed(TRUE)
      ->setClass('\Drupal\tide_automated_listing\AutomatedListingConfigurationProcessed')
      ->setSetting('text source', 'value');

    return $properties;
  }

  /**
   * Return the container.
   *
   * @return \Symfony\Component\DependencyInjection\ContainerInterface
   *   The container.
   */
  protected static function getContainer() {
    return \Drupal::getContainer();
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultStorageSettings() {
    return [
      'index' => 'node',
    ] + parent::defaultStorageSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function storageSettingsForm(array &$form, FormStateInterface $form_state, $has_data) {
    $element = parent::storageSettingsForm($form, $form_state, $has_data);

    $options = $this->indexHelper->getIndexSelectOptions();

    $default_value = $this->getSetting('index');
    if (!isset($options[$default_value])) {
      $default_value = NULL;
    }

    $element['index'] = [
      '#type' => 'select',
      '#title' => t('Search API Index'),
      '#options' => $options,
      '#default_value' => $default_value,
      '#required' => TRUE,
      '#weight' => -10,
      '#disabled' => $has_data,
    ];

    return $element;
  }

}
