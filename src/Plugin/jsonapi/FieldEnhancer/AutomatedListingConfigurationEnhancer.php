<?php

namespace Drupal\tide_automated_listing\Plugin\jsonapi\FieldEnhancer;

use Drupal\Core\Serialization\Yaml;
use Drupal\jsonapi_extras\Plugin\ResourceFieldEnhancerBase;
use Shaper\Util\Context;

/**
 * Decode Automated Listing Configuration.
 *
 * @ResourceFieldEnhancer(
 *   id = "automated_listing_configuration",
 *   label = @Translation("Automated Listing Configuration"),
 *   description = @Translation("Decode Automated Listing Configuration.")
 * )
 */
class AutomatedListingConfigurationEnhancer extends ResourceFieldEnhancerBase {

  /**
   * Gets the S-API Index Helper service.
   *
   * @return \Drupal\tide_automated_listing\SearchApiIndexHelper
   *   The helper.
   */
  protected static function getIndexHelper() {
    return \Drupal::service('tide_automated_listing.sapi_index_helper');
  }

  protected function setDefaultKeys() {
    $configuration = [];
    $configuration['content_type'] = '';
    $configuration['display']['items'] = 1;
    $configuration['sort']['field'] = '';
    $configuration['sort']['direction'] = '';
    $configuration['results']['min_not_met'] = 'hide';
    $configuration['results']['no_results_message'] = '';
    $configuration['filters'] = [];

    return $configuration;
  }

  /**
   * {@inheritdoc}
   */
  protected function doUndoTransform($data, Context $context) {
    $configuration = Yaml::decode($data);
    if (!empty($configuration['index'])) {
      $configuration['server_index'] = static::getIndexHelper()->getServerIndexId($configuration['index']);
    }

    $configuration = array_merge($this->setDefaultKeys(), $configuration);

    if (isset($configuration['results']['type'])) {
      $configuration['filters']['type'] = $configuration['results']['type'];

      if (isset($configuration['results']['type']['values'])) {
        $configuration['content_type'] = $configuration['results']['type']['values'];
      }

      unset($configuration['results']['type']);
    }

    if (isset($configuration['results']['field_topic'])) {
      $configuration['filters']['field_topic'] = $configuration['results']['field_topic'];
      unset($configuration['results']['field_topic']);
    }

    if (isset($configuration['results']['field_tags'])) {
      $configuration['filters']['field_tags'] = $configuration['results']['field_tags'];
      unset($configuration['results']['field_tags']);
    }

    if (isset($configuration['results']['advanced_taxonomy_wrapper'])) {
      $advanced_taxonomy_wrapper = array_merge($configuration['filters'], $configuration['results']['advanced_taxonomy_wrapper']);
      $configuration['filters'] = $advanced_taxonomy_wrapper;
      unset($configuration['results']['advanced_taxonomy_wrapper']);
    }

    if (isset($configuration['display']['items_per_page'])) {
      $configuration['display']['items'] = $configuration['display']['items_per_page'];
      unset($configuration['display']['items_per_page']);
    }

    if (isset($configuration['display']['sort_by'])) {
      $configuration['sort']['field'] = $configuration['display']['sort_by'];
      unset($configuration['display']['sort_by']);
    }

    if (isset($configuration['display']['sort_direction'])) {
      $configuration['sort']['direction'] = $configuration['display']['sort_direction'];
      unset($configuration['display']['sort_direction']);
    }

    if (isset($configuration['display']['min_not_met'])) {
      $configuration['results']['min_not_met'] = $configuration['display']['min_not_met'];
      unset($configuration['display']['min_not_met']);
    }

    if (isset($configuration['display']['no_results_message'])) {
      $configuration['results']['no_results_message'] = $configuration['display']['no_results_message'];
      unset($configuration['display']['no_results_message']);
    }

    return $configuration;
  }

  /**
   * {@inheritdoc}
   */
  protected function doTransform($data, Context $context) {
    unset($data['server_index']);
    return Yaml::encode($data);
  }

  /**
   * {@inheritdoc}
   */
  public function getOutputJsonSchema() {
    return [
      'anyOf' => [
        ['type' => 'array'],
        ['type' => 'boolean'],
        ['type' => 'null'],
        ['type' => 'number'],
        ['type' => 'object'],
        ['type' => 'string'],
      ],
    ];
  }

}
