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

namespace gradereport_coifish;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/grade/report/coifish/lib.php');

/**
 * Unit tests for the grade tracker report class.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \gradereport_coifish\report
 */
final class report_test extends \advanced_testcase {
    /**
     * Create a course with grade categories and items for testing.
     *
     * @return array Array with course, student, teacher, and grade items.
     */
    protected function create_test_data(): array {
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();

        // Create course, student, and teacher.
        $course = $generator->create_course();
        $student = $generator->create_user();
        $teacher = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');

        // Create two assignments in the course.
        $assign1 = $generator->create_module('assign', ['course' => $course->id, 'name' => 'Essay 1']);
        $assign2 = $generator->create_module('assign', ['course' => $course->id, 'name' => 'Essay 2']);

        // Grade the first assignment.
        $gradeitem1 = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign',
            'iteminstance' => $assign1->id, 'courseid' => $course->id]);
        $gradeitem1->update_final_grade($student->id, 75.0, 'test');

        // Leave the second ungraded.
        $gradeitem2 = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign',
            'iteminstance' => $assign2->id, 'courseid' => $course->id]);

        return [
            'course' => $course,
            'student' => $student,
            'teacher' => $teacher,
            'assign1' => $assign1,
            'assign2' => $assign2,
            'gradeitem1' => $gradeitem1,
            'gradeitem2' => $gradeitem2,
        ];
    }

    /**
     * Create a report instance for testing.
     *
     * @param object $course The course object.
     * @param int $userid The user ID to view grades for.
     * @param bool $showhidden Whether to show hidden items.
     * @return report The report instance.
     */
    protected function create_report(object $course, int $userid, bool $showhidden = false): report {
        $context = \context_course::instance($course->id);
        $gpr = new \grade_plugin_return([
            'type' => 'report',
            'plugin' => 'gradetracker',
            'courseid' => $course->id,
            'userid' => $userid,
        ]);

        return new report($course->id, $gpr, $context, $userid, 0, $showhidden);
    }

    /**
     * Test that the report loads grade data for a student.
     */
    public function test_has_grades(): void {
        $data = $this->create_test_data();
        $report = $this->create_report($data['course'], $data['student']->id);

        $this->assertTrue($report->has_grades());
    }

    /**
     * Test that the report returns empty for a user with no grades.
     */
    public function test_no_grades_empty_course(): void {
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        $report = $this->create_report($course, $student->id);

        $this->assertFalse($report->has_grades());
        $this->assertEmpty($report->get_grade_data());
    }

    /**
     * Test that grade data contains expected items.
     */
    public function test_get_grade_data(): void {
        $data = $this->create_test_data();
        $report = $this->create_report($data['course'], $data['student']->id);

        $gradedata = $report->get_grade_data();
        $this->assertNotEmpty($gradedata);

        // Find items across all categories.
        $allitems = [];
        foreach ($gradedata as $cat) {
            if (!empty($cat['items'])) {
                $allitems = array_merge($allitems, $cat['items']);
            }
        }

        // Should have both assignments.
        $this->assertCount(2, $allitems);

        // Find the graded item.
        $gradeditems = array_filter($allitems, function ($item) {
            return $item['graded'];
        });
        $this->assertCount(1, $gradeditems);

        // The graded item should have the correct grade.
        $gradeditem = reset($gradeditems);
        $this->assertEquals(75.0, $gradeditem['grade_raw']);
    }

    /**
     * Test the course total calculation.
     */
    public function test_get_course_total(): void {
        $data = $this->create_test_data();

        // Regrade to ensure course total is calculated.
        grade_regrade_final_grades($data['course']->id);

        $report = $this->create_report($data['course'], $data['student']->id);
        $total = $report->get_course_total();

        $this->assertArrayHasKey('grade', $total);
        $this->assertArrayHasKey('grademax', $total);
        $this->assertArrayHasKey('percentage', $total);
        // Percentage should not be a dash (student has at least one grade).
        $this->assertNotEquals('–', $total['percentage']);
    }

    /**
     * Test the running total calculation.
     */
    public function test_get_running_total(): void {
        $data = $this->create_test_data();

        grade_regrade_final_grades($data['course']->id);

        $report = $this->create_report($data['course'], $data['student']->id);
        $runningtotal = $report->get_running_total();

        $this->assertArrayHasKey('percentage', $runningtotal);
        // Running total should be based on the graded item only (75%).
        $this->assertNotEquals('–', $runningtotal['percentage']);
        $this->assertStringContainsString('75', $runningtotal['percentage']);
    }

    /**
     * Test running total returns dash when nothing is graded.
     */
    public function test_running_total_no_grades(): void {
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $generator->create_module('assign', ['course' => $course->id]);

        $report = $this->create_report($course, $student->id);
        $runningtotal = $report->get_running_total();

        $this->assertEquals('–', $runningtotal['percentage']);
    }

    /**
     * Test that hidden items are excluded by default.
     */
    public function test_hidden_items_excluded(): void {
        $data = $this->create_test_data();

        // Hide the first grade item.
        $data['gradeitem1']->set_hidden(1);

        $report = $this->create_report($data['course'], $data['student']->id);
        $gradedata = $report->get_grade_data();

        // Find items across all categories.
        $allitems = [];
        foreach ($gradedata as $cat) {
            if (!empty($cat['items'])) {
                $allitems = array_merge($allitems, $cat['items']);
            }
        }

        // The hidden item should be marked as hidden.
        $hiddenitems = array_filter($allitems, function ($item) {
            return $item['ishidden'];
        });
        $this->assertCount(1, $hiddenitems);
    }

    /**
     * Test the progress data structure.
     */
    public function test_get_progress_data(): void {
        $data = $this->create_test_data();
        $report = $this->create_report($data['course'], $data['student']->id);

        $progressdata = $report->get_progress_data();

        $this->assertArrayHasKey('categorybars', $progressdata);
        $this->assertArrayHasKey('coursetotalbar', $progressdata);
        $this->assertArrayHasKey('thresholds', $progressdata);

        // Pass threshold should always be present.
        $this->assertNotEmpty($progressdata['thresholds']);
        $passtreshold = $progressdata['thresholds'][0];
        $this->assertArrayHasKey('label', $passtreshold);
        $this->assertArrayHasKey('value', $passtreshold);
    }

    /**
     * Test that thresholds respect site configuration.
     */
    public function test_thresholds_configuration(): void {
        $data = $this->create_test_data();

        // Set custom thresholds.
        set_config('threshold_pass', '40', 'gradereport_coifish');
        set_config('threshold_merit', '', 'gradereport_coifish');
        set_config('threshold_distinction', '80', 'gradereport_coifish');

        $report = $this->create_report($data['course'], $data['student']->id);
        $progressdata = $report->get_progress_data();

        // Should have 2 thresholds (pass + distinction, no merit).
        $this->assertCount(2, $progressdata['thresholds']);
        $this->assertEquals(40, $progressdata['thresholds'][0]['value']);
        $this->assertEquals(80, $progressdata['thresholds'][1]['value']);
    }

    /**
     * Test that optional thresholds can be disabled.
     */
    public function test_thresholds_optional_disabled(): void {
        $data = $this->create_test_data();

        // Disable both merit and distinction.
        set_config('threshold_pass', '50', 'gradereport_coifish');
        set_config('threshold_merit', '', 'gradereport_coifish');
        set_config('threshold_distinction', '', 'gradereport_coifish');

        $report = $this->create_report($data['course'], $data['student']->id);
        $progressdata = $report->get_progress_data();

        // Should have only the pass threshold.
        $this->assertCount(1, $progressdata['thresholds']);
        $this->assertEquals(50, $progressdata['thresholds'][0]['value']);
    }

    /**
     * Test the best possible calculation.
     */
    public function test_best_possible(): void {
        $data = $this->create_test_data();

        grade_regrade_final_grades($data['course']->id);

        $report = $this->create_report($data['course'], $data['student']->id);
        $progressdata = $report->get_progress_data();

        // Best possible should be between the actual percentage and 100%.
        $bestpossible = $progressdata['coursetotalbar']['bestpossible'];
        $this->assertGreaterThan(0, $bestpossible);
        $this->assertLessThanOrEqual(100, $bestpossible);
    }

    /**
     * Test the summary data for teachers.
     */
    public function test_get_summary_data(): void {
        $data = $this->create_test_data();

        $this->setUser($data['teacher']);

        grade_regrade_final_grades($data['course']->id);

        $report = $this->create_report($data['course'], 0);
        $summary = $report->get_summary_data();

        $this->assertNotEmpty($summary);

        // Find the student in the summary.
        $studententry = null;
        foreach ($summary as $entry) {
            if ($entry['userid'] == $data['student']->id) {
                $studententry = $entry;
                break;
            }
        }

        $this->assertNotNull($studententry);
        $this->assertArrayHasKey('fullname', $studententry);
        $this->assertArrayHasKey('grade', $studententry);
        $this->assertArrayHasKey('viewurl', $studententry);
    }

    /**
     * Test the user ID getter.
     */
    public function test_get_userid(): void {
        $data = $this->create_test_data();
        $report = $this->create_report($data['course'], $data['student']->id);

        $this->assertEquals($data['student']->id, $report->get_userid());
    }

    /**
     * Test the has_weights method.
     */
    public function test_has_weights_single_category(): void {
        $data = $this->create_test_data();
        $report = $this->create_report($data['course'], $data['student']->id);

        // With a single category (course default), there should be no weights.
        $this->assertFalse($report->has_weights());
    }

    /**
     * Test with multiple weighted categories.
     */
    public function test_has_weights_multiple_categories(): void {
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        // Create two categories.
        $cat1 = new \grade_category();
        $cat1->courseid = $course->id;
        $cat1->fullname = 'Assignments';
        $cat1->aggregation = GRADE_AGGREGATE_WEIGHTED_MEAN;
        $cat1->insert();

        $cat2 = new \grade_category();
        $cat2->courseid = $course->id;
        $cat2->fullname = 'Quizzes';
        $cat2->aggregation = GRADE_AGGREGATE_WEIGHTED_MEAN;
        $cat2->insert();

        // Set weights.
        $catitem1 = $cat1->get_grade_item();
        $catitem1->aggregationcoef = 60;
        $catitem1->update();

        $catitem2 = $cat2->get_grade_item();
        $catitem2->aggregationcoef = 40;
        $catitem2->update();

        // Set course category to weighted mean.
        $coursecat = \grade_category::fetch_course_category($course->id);
        $coursecat->aggregation = GRADE_AGGREGATE_WEIGHTED_MEAN;
        $coursecat->update();

        // Create assignments in each category.
        $assign1 = $generator->create_module('assign', ['course' => $course->id]);
        $gi1 = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign',
            'iteminstance' => $assign1->id, 'courseid' => $course->id]);
        $gi1->categoryid = $cat1->id;
        $gi1->aggregationcoef = 1;
        $gi1->update();

        $assign2 = $generator->create_module('assign', ['course' => $course->id]);
        $gi2 = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign',
            'iteminstance' => $assign2->id, 'courseid' => $course->id]);
        $gi2->categoryid = $cat2->id;
        $gi2->aggregationcoef = 1;
        $gi2->update();

        $gi1->update_final_grade($student->id, 80.0, 'test');

        $report = $this->create_report($course, $student->id);
        $this->assertTrue($report->has_weights());
    }
}
