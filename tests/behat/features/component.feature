@tide
Feature: Automated Listing component in Landing Page

  Ensure that Landing Page content has the expected automated listing component.

  @api @nosuggest
  Scenario: The content type has the expected component (and labels where we can use them).
    Given I am logged in as a user with the "create landing_page content" permission
    When I visit "node/add/landing_page"

    # And I should see text matching "Body Content"
    And I should see "Automated Card listing" in the "select[name='field_landing_page_component[add_more][add_more_select]']" element

  @api @javascript @jsonapi
  Scenario: Request a landing page with an automated listing component via API
    Given vocabulary "sites" with name "Sites" exists
    And sites terms:
      | name      | parent | tid   |
      | Test Site | 0      | 10001 |

    Given vocabulary "topic" with name "Topic" exists
    And topic terms:
      | name       | parent |
      | Test Topic | 0      |

    Given landing_page content:
      | title                     | path                     | moderation_state | uuid                                | field_topic | field_node_site | field_node_primary_site | field_landing_page_summary | field_landing_page_bg_colour |
      | [TEST] Landing Page title | /test-landing-page-alias | published        | 99999999-aaaa-bbbb-ccc-000000000000 | Test Topic  | Test Site       | Test Site               | Test Summary               | White                       |

    Given I am logged in as a user with the "Administrator" role
    When I edit landing_page "[TEST] Landing Page title"
    And I click on link with href "#edit-group-components"

    And I select "Automated Card listing" from "edit-field-landing-page-component-add-more-add-more-select"
    And I press the "edit-field-landing-page-component-add-more-add-more-button" button
    Then I wait for AJAX to finish

    Then I fill in "Listing Title" with "Test Automated Listing"

    Then I click on the horizontal tab "Result settings"
    And I fill in "Maximum results to show" with "9"
    And I select the radio button "Show 'no results' message"

    Then I click on the horizontal tab "Display settings"
    And I select "Grid" from "Display as"
    And I fill in "Items per page" with "9"
    And I select "Changed" from "Card Date field mapping"
    And I check the box "Topic"
    And I check the box "Location"

    Then I click on the horizontal tab "Filter criteria"
    # And I click on the detail "Content type"
    And I check the box "Landing Page"

    Then I click on the horizontal tab "Sort criteria"
    And I select "Changed" from "Sort by"
    And I select "Ascending" from "Sort order"

    Then I select "Published" from "Change to"
    And I press the "Save" button

    Given I am an anonymous user
    When I send a GET request to "api/v1/node/landing_page/99999999-aaaa-bbbb-ccc-000000000000?site=10001&include=field_landing_page_component"
    Then the rest response status code should be 200
    And the response should be in JSON
    And the JSON node "data" should exist
    And the JSON node "included" should exist
    And the JSON node "included" should have 1 element
    And the JSON node "included[0].type" should be equal to "paragraph--automated_card_listing"
    And the JSON node "included[0].attributes" should exist
    And the JSON node "included[0].attributes.field_paragraph_title" should be equal to "Test Automated Listing"
    And the JSON node "included[0].attributes.field_paragraph_auto_listing" should exist

    And the JSON node "included[0].attributes.field_paragraph_auto_listing.results.min" should be equal to "0"
    And the JSON node "included[0].attributes.field_paragraph_auto_listing.results.max" should be equal to "9"
    And the JSON node "included[0].attributes.field_paragraph_auto_listing.results.min_not_met" should be equal to "no_results_message"
    And the JSON node "included[0].attributes.field_paragraph_auto_listing.results.no_results_message" should be equal to "There are currently no results"

    And the JSON node "included[0].attributes.field_paragraph_auto_listing.display.type" should be equal to "grid"
    And the JSON node "included[0].attributes.field_paragraph_auto_listing.display.items_per_page" should be equal to "9"

    And the JSON node "included[0].attributes.field_paragraph_auto_listing.card_display.date" should be equal to "changed"
    And the JSON node "included[0].attributes.field_paragraph_auto_listing.card_display.hide.image" should be equal to "false"
    And the JSON node "included[0].attributes.field_paragraph_auto_listing.card_display.hide.title" should be equal to "false"
    And the JSON node "included[0].attributes.field_paragraph_auto_listing.card_display.hide.summary" should be equal to "false"
    And the JSON node "included[0].attributes.field_paragraph_auto_listing.card_display.hide.topic" should be equal to "true"
    And the JSON node "included[0].attributes.field_paragraph_auto_listing.card_display.hide.location" should be equal to "true"

    And the JSON node "included[0].attributes.field_paragraph_auto_listing.filter_operator" should be equal to "AND"

    And the JSON node "included[0].attributes.field_paragraph_auto_listing.filter_today.status" should be equal to "false"

    And the JSON array node "included[0].attributes.field_paragraph_auto_listing.filters.type.values" should contain "landing_page" element
    And the JSON node "included[0].attributes.field_paragraph_auto_listing.filters.type.operator" should be equal to "OR"

    And the JSON array node "included[0].attributes.field_paragraph_auto_listing.filters.field_topic" should have 0 elements
    And the JSON array node "included[0].attributes.field_paragraph_auto_listing.filters.field_tags" should have 0 elements

    And the JSON node "included[0].attributes.field_paragraph_auto_listing.sort.field" should be equal to "changed"
    And the JSON node "included[0].attributes.field_paragraph_auto_listing.sort.direction" should be equal to "asc"

    # Then I break

