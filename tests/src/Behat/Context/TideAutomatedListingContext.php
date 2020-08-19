<?php

namespace Drupal\Tests\tide_automated_listing\Behat\Context;

/**
 * @file
 * Feature context for Behat testing.
 *
 * @codingStandardsIgnoreFile
 */

use Drupal\DrupalExtension\Context\RawDrupalContext;

/**
 * Defines application features from the specific context.
 */
class TideAutomatedListingContext extends RawDrupalContext {

  /**
   * @Then /^I click on link with href "([^"]*)"$/
   * @param string $href
   */
  public function clickOnLinkWithHref(string $href) {
    $page = $this->getSession()->getPage();
    $link = $page->find('xpath', '//a[@href="' . $href . '"]');
    if ($link === NULL) {
      throw new \Exception('Link with href "' . $href . '" not found.');
    }
    $link->click();
  }

  /**
   * @Then /^I click on the horizontal tab "([^"]*)"$/
   * @param string $text
   */
  public function clickOnHorzTab(string $text) {
    $page = $this->getSession()->getPage();
    $link = $page->find('xpath', '//ul[contains(@class, "horizontal-tabs-list")]/li[contains(@class, "horizontal-tab-button")]/a/strong[text()="' . $text . '"]');
    if ($link === NULL) {
      throw new \Exception('The horizontal tab with text "' . $text . '" not found.');
    }
    $link->click();
  }

  /**
   * @Then /^I click on the detail "([^"]*)"$/
   * @param string $text
   */
  public function clickOnDetail(string $text) {
    $page = $this->getSession()->getPage();
    $link = $page->find('xpath', '//div[contains(@class, "details-wrapper")]/details/summary[text()="' . $text . '"]');
    if ($link === NULL) {
      throw new \Exception('The detail with text "' . $text . '" not found.');
    }
    $link->click();
  }

}
