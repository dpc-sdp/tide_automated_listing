<?php

namespace Drupal\tide_automated_listing\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\BasicStringFormatter;

/**
 * Plugin implementation of the 'automated_listing_configuration' formatter.
 *
 * @FieldFormatter(
 *   id = "automated_listing_configuration",
 *   label = @Translation("Automated Card Listing Configuration"),
 *   field_types = {
 *     "automated_listing_configuration",
 *   }
 * )
 */
class AutomatedListingConfigurationFormatter extends BasicStringFormatter {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    // The default widget should not display the listing configuration
    // in Drupal front-end.
    return [];
  }

}
