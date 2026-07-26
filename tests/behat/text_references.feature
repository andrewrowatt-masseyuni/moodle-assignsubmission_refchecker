@assignsubmission @assignsubmission_refchecker
Feature: Submitting a reference list as plain text
  In order to have references detected reliably
  As a teacher
  I need to be able to ask students to paste their reference list in instead of relying on a file

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

  @javascript
  Scenario: The setting appears only once reference checking is enabled, and defaults to No
    Given I am on the "Course 1" course page logged in as teacher1
    And I turn editing mode on
    When I add a "assign" activity to course "Course 1" section "1"
    And I expand all fieldsets
    Then I should not see "Require text references"
    When I set the field "Reference Checker" to "1"
    Then I should see "Require text references"
    And the field "Require text references" matches value "No"

  @javascript
  Scenario: The chosen setting is kept
    Given the following "activities" exist:
      | activity | course | name  | assignsubmission_file_enabled | assignsubmission_refchecker_enabled |
      | assign   | C1     | Essay | 1                             | 1                                   |
    And I am on the "Essay" Activity page logged in as teacher1
    When I navigate to "Settings" in current page administration
    And I expand all fieldsets
    And I set the field "Require text references" to "Yes - Required"
    And I press "Save and display"
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    Then the field "Require text references" matches value "Yes - Required"

  Scenario: No References box is offered by default
    Given the following "activities" exist:
      | activity | course | name  | assignsubmission_file_enabled | assignsubmission_refchecker_enabled |
      | assign   | C1     | Essay | 1                             | 1                                   |
    And I am on the "Essay" Activity page logged in as student1
    When I press "Add submission"
    Then I should not see "Paste in your reference list here."

  Scenario: A student pastes their reference list in with no file to upload
    Given the following "activities" exist:
      | activity | course | name  | submissiondrafts | assignsubmission_file_enabled | assignsubmission_refchecker_enabled | assignsubmission_refchecker_requiretext |
      | assign   | C1     | Essay | 0                | 0                             | 1                                   | required                                |
    And I am on the "Essay" Activity page logged in as student1
    When I press "Add submission"
    Then I should see "Paste in your reference list here."
    When I set the field "References" to multiline:
      """
      Braun, V., & Clarke, V. (2006). Using thematic analysis in psychology. Qualitative Research in Psychology, 3(2), 77-101.
      Creswell, J. W. (2014). Research design: Qualitative and quantitative approaches. SAGE Publications.
      """
    And I press "Save changes"
    Then I should see "Submitted for grading"

  Scenario: A required reference list cannot be left empty
    Given the following "activities" exist:
      | activity | course | name  | assignsubmission_file_enabled | assignsubmission_refchecker_enabled | assignsubmission_refchecker_requiretext |
      | assign   | C1     | Essay | 1                             | 1                                   | required                                |
    And I am on the "Essay" Activity page logged in as student1
    When I press "Add submission"
    And I press "Save changes"
    Then I should see "Paste in your reference list."

  Scenario: An optional reference list may be left empty
    Given the following "activities" exist:
      | activity | course | name  | submissiondrafts | assignsubmission_onlinetext_enabled | assignsubmission_refchecker_enabled | assignsubmission_refchecker_requiretext |
      | assign   | C1     | Essay | 0                | 1                                   | 1                                   | optional                                |
    And I am on the "Essay" Activity page logged in as student1
    When I press "Add submission"
    Then I should see "Paste in your reference list here."
    When I set the field "Online text" to "My essay."
    And I press "Save changes"
    Then I should see "Submitted for grading"

  Scenario: A teacher is not warned about File submissions when text references are asked for
    Given the following "activities" exist:
      | activity | course | name  | assignsubmission_file_enabled | assignsubmission_refchecker_enabled | assignsubmission_refchecker_requiretext |
      | assign   | C1     | Essay | 0                             | 1                                   | required                                |
    When I am on the "Essay" Activity page logged in as teacher1
    Then I should not see "File submissions is not"
