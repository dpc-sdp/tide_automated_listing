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

  /**
   * Set default value for keys.
   *
   * @return array
   */
  protected function setDefaultKeys() {
    $configuration = [];
    $configuration['content_type'] = '';

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

    if (isset($configuration['filters']['type']['values'])) {
      $configuration['content_type'] = $configuration['filters']['type']['values'];
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
