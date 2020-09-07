@tide
Feature: Automated Listing component in Landing Page

  Ensure that Landing Page content has the expected automated listing component.

  @api @nosuggest
  Scenario: The content type has the expected fields (and labels where we can use them).
    Given I am logged in as a user with the "administrator" role
    And save screenshot
    When I visit "admin/structure/paragraphs_type"
    And save screenshot

    And I see the text "Card collection"

    When I visit "admin/structure/paragraphs_type/automated_card_listing/fields"
    And I see the text "Card CTA Text"
    And I see the text "Listing Configuration"
    And I see the text "Listing CTA"
    And I see the text "Listing Title"