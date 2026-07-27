<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace assignsubmission_refchecker;

use assign;
use assignsubmission_refchecker\local\job_manager;
use assignsubmission_refchecker\local\match_status;
use assignsubmission_refchecker\local\text_mode;
use assignsubmission_refchecker\local\text_submission;
use backup;
use backup_controller;
use restore_controller;
use restore_dbops;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/assign/tests/generator.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Tests that submitted reference lists and check results survive a course backup and restore.
 *
 * @package    assignsubmission_refchecker
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_assignsubmission_refchecker_subplugin
 * @covers     \restore_assignsubmission_refchecker_subplugin
 */
final class backup_restore_test extends \advanced_testcase {
    use \mod_assign_test_generator;

    /**
     * Duplicating a course carries the pasted list, the job and its references across, re-parented.
     */
    public function test_results_survive_a_duplication(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $assign = $this->create_instance($course, [
            'assignsubmission_file_enabled' => 1,
            // save_settings() on the file plugin reads both of these straight off the form
            // data, so enabling it without them is an undefined property warning, which
            // PHPUnit turns into a failure before the test body ever runs.
            'assignsubmission_file_maxfiles' => 1,
            'assignsubmission_file_maxsizebytes' => 1024 * 1024,
            'assignsubmission_refchecker_enabled' => 1,
            'assignsubmission_refchecker_requiretext' => text_mode::REQUIRED,
        ]);
        $submission = $assign->get_user_submission($student->id, true);

        $generator = $this->getDataGenerator()->get_plugin_generator('assignsubmission_refchecker');
        $generator->create_text_submission($submission, 'BACKUPPASTEDREFERENCELIST');
        $job = $generator->create_job([
            'assignment' => $assign->get_instance()->id,
            'submission' => $submission->id,
            'totalrefs' => 2,
            'checkedrefs' => 2,
            'verifiedrefs' => 1,
            'notfoundrefs' => 1,
            'sectionheading' => 'Bibliography',
        ]);
        $generator->create_reference($job, ['sortorder' => 0, 'rawref' => 'BACKUPREFERENCEONE']);
        $generator->create_reference($job, [
            'sortorder' => 1,
            'rawref' => 'BACKUPREFERENCETWO',
            'matchstatus' => match_status::NOTFOUND,
        ]);

        $newcourseid = $this->duplicate_course($course, $USER->id);

        // Find the assignment in the restored course.
        $modules = get_coursemodules_in_course('assign', $newcourseid);
        $this->assertCount(1, $modules);
        $newcm = reset($modules);

        $newjobs = $DB->get_records(job_manager::TABLE_JOB, ['assignment' => $newcm->instance]);
        $this->assertCount(1, $newjobs);

        $newjob = reset($newjobs);
        $this->assertSame('Bibliography', $newjob->sectionheading);
        $this->assertSame(2, (int) $newjob->totalrefs);

        // The job must point at the restored submission, not the original one.
        $this->assertNotEquals($submission->id, $newjob->submission);
        $restoredsubmission = $DB->get_record('assign_submission', ['id' => $newjob->submission]);
        $this->assertNotFalse($restoredsubmission);
        $this->assertEquals($newcm->instance, $restoredsubmission->assignment);

        $newrefs = $DB->get_records(job_manager::TABLE_REFS, ['jobid' => $newjob->id], 'sortorder ASC');
        $this->assertCount(2, $newrefs);

        $rawrefs = array_values(array_map(static fn($ref) => $ref->rawref, $newrefs));
        $this->assertSame(['BACKUPREFERENCEONE', 'BACKUPREFERENCETWO'], $rawrefs);

        // The denormalised submission id on each reference must have been remapped too.
        foreach ($newrefs as $ref) {
            $this->assertEquals($newjob->submission, $ref->submission);
        }

        // The student's own work matters more than any of the derived results above.
        $newtext = $DB->get_record(text_submission::TABLE, ['submission' => $newjob->submission]);
        $this->assertNotFalse($newtext);
        $this->assertSame('BACKUPPASTEDREFERENCELIST', $newtext->referencetext);
        $this->assertEquals($newcm->instance, $newtext->assignment);
    }

    /**
     * Back a course up and restore it into a new one.
     *
     * @param stdClass $course
     * @param int $userid
     * @return int The new course id.
     */
    private function duplicate_course(stdClass $course, int $userid): int {
        $backupid = 'refchecker-backup-test';

        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $course->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $userid,
        );
        $bc->execute_plan();
        $results = $bc->get_results();
        $file = $results['backup_destination'];

        $path = make_backup_temp_directory($backupid);
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $path);
        $bc->destroy();

        $newcourseid = restore_dbops::create_new_course(
            $course->fullname . ' copy',
            $course->shortname . '_copy',
            $course->category,
        );

        $rc = new restore_controller(
            $backupid,
            $newcourseid,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $userid,
            backup::TARGET_NEW_COURSE,
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        return $newcourseid;
    }
}
