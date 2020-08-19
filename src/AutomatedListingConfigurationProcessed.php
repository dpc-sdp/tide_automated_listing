<?php

namespace Drupal\tide_automated_listing;

use Drupal\Core\Serialization\Yaml;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\TypedData;
use Drupal\Core\TypedData\TypedDataInterface;

/**
 * A computed property for Automated Listing Configuration.
 *
 * Required settings (below the definition's 'settings' key) are:
 *  - text source: The text property containing the YAML configuration.
 */
class AutomatedListingConfigurationProcessed extends TypedData {

  /**
   * The configuration.
   *
   * @var array
   */
  protected $configuration = NULL;

  /**
   * {@inheritdoc}
   */
  public function __construct(DataDefinitionInterface $definition, $name = NULL, TypedDataInterface $parent = NULL) {
    parent::__construct($definition, $name, $parent);
    if (!$definition->getSetting('text source')) {
      throw new \InvalidArgumentException("The definition's 'text source' key has to specify the name of the text property holding the YAML configuration.");
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getValue() {
    if ($this->configuration !== NULL) {
      return $this->configuration;
    }

    /** @var \Drupal\Core\Field\FieldItemInterface $item */
    $item = $this->getParent();
    $text = $item->{($this->definition->getSetting('text source'))};

    // Avoid doing unnecessary work on empty strings.
    if (!isset($text) || empty($text) || trim($text) === '') {
      $this->configuration = [];
    }

    $this->configuration = Yaml::decode($text);
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($value, $notify = TRUE) {
    $this->configuration = $value;
    // Notify the parent of any changes.
    if ($notify && isset($this->parent)) {
      $this->parent->onChange($this->name);
    }
  }

}
