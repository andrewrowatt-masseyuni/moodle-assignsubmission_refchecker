@assignsubmission @assignsubmission_refchecker
Feature: Configuring what students are told about their references
  In order to control how much students are told about their references
  As a teacher
  I need the display settings to appear only when reference checking is switched on

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
    And I change the window size to "large"

  @javascript
  Scenario: The display settings appear only once reference checking is enabled
    And I am on the "Course 1" course page logged in as teacher1
    And I turn editing mode on
    When I add a "assign" activity to course "Course 1" section "1"
    And I expand all fieldsets
    Then I should not see "Show students"
    And I should not see "Check references"
    When I set the field "Reference Checker" to "1"
    Then I should see "Show students"
    And I should see "Check references"

  @javascript
  Scenario: The chosen display level is kept
    Given the following "activities" exist:
      | activity | course | name  | assignsubmission_file_enabled | assignsubmission_file_maxfiles | assignsubmission_file_maxsizebytes | assignsubmission_refchecker_enabled |
      | assign   | C1     | Essay | 1                             | 1                              | 1048576                            | 1                                   |
    And I am on the "Essay" Activity page logged in as teacher1
    When I navigate to "Settings" in current page administration
    And I expand all fieldsets
    And I set the field "Show students" to "Summary"
    And I press "Save and display"
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    Then the field "Show students" matches value "Summary"

  Scenario: Students are told what the check does before they submit
    Given the following "activities" exist:
      | activity | course | name  | assignsubmission_file_enabled | assignsubmission_file_maxfiles | assignsubmission_file_maxsizebytes | assignsubmission_refchecker_enabled |
      | assign   | C1     | Essay | 1                             | 1                              | 1048576                            | 1                                   |
    When I am on the "Essay" Activity page logged in as student1
    Then I should see "Your reference list will be checked automatically"
    And I should see "It is not a plagiarism check"

  @javascript
  Scenario: No information hides everything the plugin would tell a student
    Given the following "activities" exist:
      | activity | course | name  | assignsubmission_file_enabled | assignsubmission_file_maxfiles | assignsubmission_file_maxsizebytes | assignsubmission_refchecker_enabled |
      | assign   | C1     | Essay | 1                             | 1                              | 1048576                            | 1                                   |
    And I am on the "Essay" Activity page logged in as teacher1
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    And I set the field "Show students" to "No information"
    And I press "Save and display"
    Then I should see "Students on this assignment see: No information"
    When I am on the "Essay" Activity page logged in as student1
    Then I should not see "Your reference list will be checked automatically"
    And I should not see "It is not a plagiarism check"

  Scenario: Nothing is shown when reference checking is switched off
    Given the following "activities" exist:
      | activity | course | name  | assignsubmission_file_enabled | assignsubmission_file_maxfiles | assignsubmission_file_maxsizebytes | assignsubmission_refchecker_enabled |
      | assign   | C1     | Essay | 1                             | 1                              | 1048576                            | 0                                   |
    When I am on the "Essay" Activity page logged in as student1
    Then I should not see "Your reference list will be checked automatically"

  @javascript
  Scenario: A teacher is warned when File submissions is not enabled
    Given the following "activities" exist:
      | activity | course | name  | assignsubmission_file_enabled | assignsubmission_refchecker_enabled |
      | assign   | C1     | Essay | 0                             | 1                                    |
    When I am on the "Essay" Activity page logged in as teacher1
    Then I should see "File submissions is not"
