<?php

namespace Drupal\tide_automated_listing\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\BasicStringFormatter;

/**
 * Plugin implementation of the 'automated_listing_configuration' formatter.
 *
 * @FieldFormatter(
 *   id = "automated_listing_configuration_yaml",
 *   label = @Translation("Automated Card Listing Configuration (Raw YAML)"),
 *   field_types = {
 *     "automated_listing_configuration",
 *   }
 * )
 */
class AutomatedListingConfigurationRawFormatter extends BasicStringFormatter {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    foreach ($items as $delta => $item) {
      // The text value has no text format assigned to it, so the user input
      // should equal the output, including newlines.
      $elements[$delta] = [
        '#type' => 'inline_template',
        '#template' => '<pre>{{ value }}</pre>',
        '#context' => ['value' => $item->value],
      ];
    }

    return $elements;
  }

}
