@assignsubmission @assignsubmission_refchecker
Feature: Limiting who can add reference checking to an assignment
  In order to roll reference checking out to a few staff at a time
  As an administrator
  I need the submission type to be offered only to holders of a capability

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
      | Course 2 | C2        | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Tara      | Teacher  | teacher1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C2     | editingteacher |
    And I change the window size to "large"

  @javascript
  Scenario: A teacher without the capability is not offered the submission type
    Given I am on the "Course 1" course page logged in as teacher1
    And I turn editing mode on
    When I add a "assign" activity to course "Course 1" section "1"
    And I expand all fieldsets
    Then I should see "Submission types"
    And I should not see "Reference Checker"

  @javascript
  Scenario: A teacher granted the capability in one course is offered it only there
    Given the following "permission overrides" exist:
      | capability                            | permission | role           | contextlevel | reference |
      | assignsubmission/refchecker:configure | Allow      | editingteacher | Course       | C1        |
    And I am on the "Course 1" course page logged in as teacher1
    And I turn editing mode on
    When I add a "assign" activity to course "Course 1" section "1"
    And I expand all fieldsets
    Then I should see "Reference Checker"
    And I am on the "Course 2" course page
    And I turn editing mode on
    When I add a "assign" activity to course "Course 2" section "1"
    And I expand all fieldsets
    Then I should not see "Reference Checker"

  @javascript
  Scenario: A teacher without the capability cannot change the settings of an assignment that has it on
    Given the following "activities" exist:
      | activity | course | name  | assignsubmission_file_enabled | assignsubmission_file_maxfiles | assignsubmission_file_maxsizebytes | assignsubmission_refchecker_enabled | assignsubmission_refchecker_studentdisplay |
      | assign   | C1     | Essay | 1                             | 1                              | 1048576                            | 1                                   | 2                                          |
    And I am on the "Essay" Activity page logged in as teacher1
    When I navigate to "Settings" in current page administration
    And I expand all fieldsets
    Then I should see "You do not have permission to change its settings"
    And I should not see "Show students"
    # Saving the form must leave the setting the person who set the assignment up chose.
    And I press "Save and display"
    And I log out
    And the following "permission overrides" exist:
      | capability                            | permission | role           | contextlevel | reference |
      | assignsubmission/refchecker:configure | Allow      | editingteacher | Course       | C1        |
    And I am on the "Essay" Activity page logged in as teacher1
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    Then the field "Show students" matches value "Summary"
