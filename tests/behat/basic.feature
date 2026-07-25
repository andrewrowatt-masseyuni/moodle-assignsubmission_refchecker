@assignsubmission @assignsubmission_refchecker
Feature: Basic tests for Reference Checker

  @javascript
  Scenario: Plugin assignsubmission_refchecker appears in the list of installed additional plugins
    Given I log in as "admin"
    When I navigate to "Plugins > Plugins overview" in site administration
    And I follow "Additional plugins"
    Then I should see "Reference Checker"
    And I should see "assignsubmission_refchecker"
