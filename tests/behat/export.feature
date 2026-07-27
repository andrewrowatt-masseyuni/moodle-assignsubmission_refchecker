@assignsubmission @assignsubmission_refchecker
Feature: Downloading the reference check report
  In order to keep a record of a reference check
  As a teacher, or as a student who is shown the full report
  I need to download the report as PDF, Excel or CSV

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | Student  | student1@example.com |
      | teacher1 | Tara      | Teacher  | teacher1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity | course | name  | assignsubmission_file_enabled | assignsubmission_refchecker_enabled |
      | assign   | C1     | Essay | 1                             | 1                                   |
    And the following "assignsubmission_refchecker > jobs" exist:
      | assign | user     |
      | Essay  | student1 |
    And the following "assignsubmission_refchecker > references" exist:
      | assign | user     | rawref                                                          | matchstatus |
      | Essay  | student1 | Smith, J. (2019). Learning at scale. Journal of Things, 1, 1-15. | verified    |
      | Essay  | student1 | Jones, P. (2021). A fabricated title. Nowhere Journal, 2, 1-9.   | notfound    |

  @javascript
  Scenario: A teacher can download the report in each format
    Given I am on the "Essay" Activity page logged in as teacher1
    And I follow "View all submissions"
    When I click on "Expand" "link" in the "Sam Student" "table_row"
    Then I should see "Download"
    And following "PDF" should download between "1000" and "5000000" bytes
    And following "Excel" should download between "100" and "5000000" bytes
    And following "CSV" should download between "50" and "5000000" bytes

  @javascript
  Scenario: A student who is shown the full report can download it
    Given I am on the "Essay" Activity page logged in as teacher1
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    And I set the field "Show students" to "Full report"
    And I press "Save and display"
    When I am on the "Essay" Activity page logged in as student1
    Then I should see "Download"
    And following "CSV" should download between "50" and "5000000" bytes

  Scenario: A student who is shown only the summary is offered no download
    Given I am on the "Essay" Activity page logged in as teacher1
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    And I set the field "Show students" to "Summary"
    And I press "Save and display"
    When I am on the "Essay" Activity page logged in as student1
    Then I should not see "Download"
