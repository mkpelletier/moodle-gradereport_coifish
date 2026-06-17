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

    /**
     * Invoke a protected method on a report instance via reflection.
     *
     * @param report $report The report instance.
     * @param string $method The protected method name.
     * @param array $args Positional arguments.
     * @return mixed The method's return value.
     */
    protected function call_protected(report $report, string $method, array $args = []) {
        $ref = new \ReflectionMethod(report::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($report, $args);
    }

    /**
     * The assign grading turnaround must run the clock to the *first* grade
     * (assign_grades.timecreated), not the last modification (timemodified), so a
     * late edit to a grade does not inflate a lecturer's turnaround; and an
     * academic-integrity referral recorded after submission but before grading
     * must pause the clock at the referral moment.
     */
    public function test_assign_turnaround_uses_timecreated_and_referral_pause(): void {
        global $DB;
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $teacher = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');

        $assign = $generator->create_module('assign', ['course' => $course->id, 'name' => 'Essay']);

        // Timeline: submission at T0; first grade at T0+5d; a late edit bumps
        // timemodified to T0+30d. With timecreated as the base the turnaround is
        // 5 days; with timemodified it would be a wrong 30 days.
        $t0 = 1700000000;
        $day = DAYSECS;

        $DB->insert_record('assign_submission', (object)[
            'assignment' => $assign->id,
            'userid' => $student->id,
            'timecreated' => $t0,
            'timemodified' => $t0,
            'status' => 'submitted',
            'groupid' => 0,
            'attemptnumber' => 0,
            'latest' => 1,
        ]);
        $DB->insert_record('assign_grades', (object)[
            'assignment' => $assign->id,
            'userid' => $student->id,
            'grader' => $teacher->id,
            'grade' => 75.0,
            'timecreated' => $t0 + 5 * $day,
            'timemodified' => $t0 + 30 * $day,
            'attemptnumber' => 0,
        ]);

        $report = $this->create_report($course, 0);

        // Without any referral: clock runs submission (T0) → first grade (T0+5d).
        $secs = $this->call_protected($report, 'get_assign_avg_turnaround_seconds');
        $this->assertEqualsWithDelta(5 * $day, $secs, 1.0, 'assign turnaround should use timecreated, not timemodified');

        // A referral lands at T0+3d — after submission, before the grade was
        // created — so the clock pauses there: turnaround becomes 3 days.
        if ($DB->get_manager()->table_exists('local_unifiedgrader_referral')) {
            $DB->insert_record('local_unifiedgrader_referral', (object)[
                'cmid' => $assign->cmid,
                'userid' => $student->id,
                'authorid' => $teacher->id,
                'reason' => 'plagiarism',
                'note' => '',
                'status' => 'open',
                'outcome' => '',
                'timereferred' => $t0 + 3 * $day,
                'timeresolved' => 0,
                'timemodified' => $t0 + 3 * $day,
            ]);

            $secs = $this->call_protected($report, 'get_assign_avg_turnaround_seconds');
            $this->assertEqualsWithDelta(3 * $day, $secs, 1.0, 'an integrity referral before grading should pause the clock');
        }
    }

    /**
     * Feedback weights default to the documented split and normalise to 1.
     */
    public function test_feedback_weights_default(): void {
        $this->resetAfterTest();
        $w = report::get_feedback_weights();
        $this->assertEqualsWithDelta(1.0, array_sum($w), 0.0001);
        $this->assertEqualsWithDelta(0.30, $w['coverage'], 0.0001);
        $this->assertEqualsWithDelta(0.15, $w['structured'], 0.0001);
    }

    /**
     * Configured weights are honoured and normalised even when they don't sum to 100.
     */
    public function test_feedback_weights_custom_normalise(): void {
        $this->resetAfterTest();
        set_config('feedback_weight_coverage', 50, 'gradereport_coifish');
        set_config('feedback_weight_depth', 50, 'gradereport_coifish');
        set_config('feedback_weight_quality', 0, 'gradereport_coifish');
        set_config('feedback_weight_personalisation', 0, 'gradereport_coifish');
        set_config('feedback_weight_structured', 0, 'gradereport_coifish');
        $w = report::get_feedback_weights();
        $this->assertEqualsWithDelta(0.5, $w['coverage'], 0.0001);
        $this->assertEqualsWithDelta(0.5, $w['depth'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $w['quality'], 0.0001);
        $this->assertEqualsWithDelta(1.0, array_sum($w), 0.0001);
    }

    /**
     * Zeroing every weight falls back to the defaults rather than collapsing to 0.
     */
    public function test_feedback_weights_all_zero_falls_back(): void {
        $this->resetAfterTest();
        foreach (['coverage', 'depth', 'quality', 'personalisation', 'structured'] as $d) {
            set_config('feedback_weight_' . $d, 0, 'gradereport_coifish');
        }
        $w = report::get_feedback_weights();
        $this->assertEqualsWithDelta(1.0, array_sum($w), 0.0001);
        $this->assertEqualsWithDelta(0.30, $w['coverage'], 0.0001);
    }

    /**
     * Recorded-feedback depth credit: floor/cap, size scaling, and embed floor.
     */
    public function test_media_word_equivalent(): void {
        $task = new \gradereport_coifish\task\calculate_feedback_metrics();
        $m = new \ReflectionMethod($task, 'media_word_equivalent');
        $m->setAccessible(true);

        // Unknown size (embedded / S3) -> fixed floor (80), plus any typed words.
        $this->assertEquals(80, $m->invoke($task, null, 0));
        $this->assertEquals(90, $m->invoke($task, null, 10));
        // Tiny file clamps up to the floor; huge file clamps down to the cap.
        $this->assertEquals(30, $m->invoke($task, 1000, 0));
        $this->assertEquals(150, $m->invoke($task, 50000000, 0));
        // A 350KB file at ~7KB/word lands around 50 word-equivalents.
        $this->assertEqualsWithDelta(50, $m->invoke($task, 350000, 0), 1);
    }

    /**
     * The assignment-level breakdown reports per-assign coverage/feedback counts
     * for the grading teacher and composes the row score from the sub-scores and
     * the configured weights and the course-level structured score.
     */
    public function test_assignment_feedback_breakdown(): void {
        global $DB;
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $s1 = $generator->create_user();
        $s2 = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($s1->id, $course->id, 'student');
        $generator->enrol_user($s2->id, $course->id, 'student');

        $assign = $generator->create_module('assign', ['course' => $course->id, 'name' => 'Essay 1']);
        // A second assignment the teacher never grades — must NOT appear.
        $generator->create_module('assign', ['course' => $course->id, 'name' => 'Essay 2']);

        // Two graded items by this teacher; only the first carries a comment.
        $g1 = $DB->insert_record('assign_grades', (object)[
            'assignment' => $assign->id,
            'userid' => $s1->id,
            'grader' => $teacher->id,
            'grade' => 80.0,
            'timecreated' => 1700000000,
            'timemodified' => 1700000000,
            'attemptnumber' => 0,
        ]);
        $DB->insert_record('assign_grades', (object)[
            'assignment' => $assign->id,
            'userid' => $s2->id,
            'grader' => $teacher->id,
            'grade' => 65.0,
            'timecreated' => 1700000000,
            'timemodified' => 1700000000,
            'attemptnumber' => 0,
        ]);
        $DB->insert_record('assignfeedback_comments', (object)[
            'assignment' => $assign->id,
            'grade' => $g1,
            'commenttext' => 'Have you considered revising the introduction to clarify your thesis?',
            'commentformat' => 1,
        ]);

        $rows = report::get_assignment_feedback_breakdown($course->id, $teacher->id);

        // Exactly one row — only the assignment the teacher actually graded.
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame((int)$assign->cmid, $row['cmid']);
        $this->assertSame('Essay 1', $row['name']);

        // Two graded, one with feedback -> coverage = round((1/2)/0.80*100) = 63.
        $this->assertSame(2, $row['ngraded']);
        $this->assertSame(1, $row['nwithfeedback']);
        $this->assertSame(63, $row['coverage']);

        // Composite must be the weighted blend of the four sub-scores plus the
        // course-level structured score — proving the weights are applied.
        $weights = report::get_feedback_weights();
        $scorer = new \gradereport_coifish\feedback_scorer();
        $structured = $scorer->structured_score($course->id);
        $expected = (int)round(
            $row['coverage'] * $weights['coverage'] +
            $row['depth'] * $weights['depth'] +
            $row['quality'] * $weights['quality'] +
            $row['personalisation'] * $weights['personalisation'] +
            $structured * $weights['structured']
        );
        $this->assertSame($expected, $row['composite']);

        // Every advertised key is present with the right type.
        $keys = ['cmid', 'name', 'coverage', 'depth', 'quality', 'personalisation', 'composite', 'ngraded', 'nwithfeedback'];
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $row);
        }
    }

    /**
     * Invoke a protected method on the cohort feedback-metrics task via reflection.
     *
     * @param \gradereport_coifish\task\calculate_feedback_metrics $task The task instance.
     * @param string $method The protected method name.
     * @param array $args Positional arguments.
     * @return mixed The method's return value.
     */
    protected function call_task_protected(
        \gradereport_coifish\task\calculate_feedback_metrics $task,
        string $method,
        array $args = []
    ) {
        $ref = new \ReflectionMethod(\gradereport_coifish\task\calculate_feedback_metrics::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($task, $args);
    }

    /**
     * The cohort coverage denominator must drop assignments the sibling
     * local_coifish plugin marks as "not feedback-relevant" via the shared
     * config key local_coifish/feedback_excluded_cmids. Grading two assignments
     * gives a denominator of 2; excluding one assignment's cmid shrinks it to 1
     * (the remaining graded item) and lifts the coverage percentage.
     */
    public function test_feedback_coverage_excludes_marked_assignments(): void {
        global $DB;
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');

        $assign1 = $generator->create_module('assign', ['course' => $course->id, 'name' => 'Essay 1']);
        $assign2 = $generator->create_module('assign', ['course' => $course->id, 'name' => 'Self-study']);

        // Teacher grades both assignments. Assign 1 carries a comment (covered);
        // assign 2 carries none (the self-study activity that never gets feedback).
        $g1 = $DB->insert_record('assign_grades', (object)[
            'assignment' => $assign1->id,
            'userid' => $student->id,
            'grader' => $teacher->id,
            'grade' => 80.0,
            'timecreated' => 1700000000,
            'timemodified' => 1700000000,
            'attemptnumber' => 0,
        ]);
        $DB->insert_record('assignfeedback_comments', (object)[
            'assignment' => $assign1->id,
            'grade' => $g1,
            'commenttext' => 'Have you considered revising the introduction to clarify your thesis?',
            'commentformat' => 1,
        ]);
        $DB->insert_record('assign_grades', (object)[
            'assignment' => $assign2->id,
            'userid' => $student->id,
            'grader' => $teacher->id,
            'grade' => 100.0,
            'timecreated' => 1700000000,
            'timemodified' => 1700000000,
            'attemptnumber' => 0,
        ]);

        $task = new \gradereport_coifish\task\calculate_feedback_metrics();

        // Baseline: both graded items count, so the denominator is 2.
        set_config('feedback_excluded_cmids', '', 'local_coifish');
        $before = $this->call_task_protected($task, 'get_feedback_coverage', [$course->id, [$teacher->id]]);
        $this->assertSame(2, (int)$before[$teacher->id]['total']);
        $this->assertSame(1, (int)$before[$teacher->id]['withfeedback']);

        // Exclude the self-study assignment's cmid.
        set_config('feedback_excluded_cmids', (string)$assign2->cmid, 'local_coifish');
        $after = $this->call_task_protected($task, 'get_feedback_coverage', [$course->id, [$teacher->id]]);

        // The denominator shrank to the single feedback-relevant graded item, and
        // since it carries feedback the coverage percentage rose to 100%.
        $this->assertSame(1, (int)$after[$teacher->id]['total']);
        $this->assertSame(1, (int)$after[$teacher->id]['withfeedback']);
        $this->assertGreaterThan($before[$teacher->id]['score'], $after[$teacher->id]['score']);

        // A non-numeric / unknown token must be ignored, leaving the list empty.
        set_config('feedback_excluded_cmids', 'not-a-cmid', 'local_coifish');
        $ignored = $this->call_task_protected($task, 'get_feedback_coverage', [$course->id, [$teacher->id]]);
        $this->assertSame(2, (int)$ignored[$teacher->id]['total']);
    }

    /**
     * The per-assignment text source must be consistent with coverage: a Unified
     * Grader submission comment is only analysed for depth/quality when it backs a
     * graded row this teacher graded (matching student + author, grade >= 0). A UG
     * comment about a NON-graded student must not produce depth on an assignment
     * whose coverage is 0 — the old "0% coverage yet non-zero depth" bug.
     */
    public function test_assignment_text_ug_source_matches_coverage(): void {
        global $DB;
        $this->resetAfterTest(true);

        if (!$DB->get_manager()->table_exists('local_unifiedgrader_scomm')) {
            $this->markTestSkipped('Unified Grader not installed.');
        }

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $graded = $generator->create_user();
        $ungraded = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($graded->id, $course->id, 'student');
        $generator->enrol_user($ungraded->id, $course->id, 'student');

        $assign = $generator->create_module('assign', ['course' => $course->id, 'name' => 'Essay 1']);

        // UG must be enabled for assignments for the UG text source to be consulted.
        set_config('enable_assign', 1, 'local_unifiedgrader');

        // One graded row, for $graded, with NO feedback of any kind -> coverage 0.
        $DB->insert_record('assign_grades', (object)[
            'assignment' => $assign->id,
            'userid' => $graded->id,
            'grader' => $teacher->id,
            'grade' => 70.0,
            'timecreated' => 1700000000,
            'timemodified' => 1700000000,
            'attemptnumber' => 0,
        ]);

        // A rich UG submission comment, but authored about $ungraded — who has NO
        // graded row. Under the old by-cmid/by-author gather this would inflate
        // depth; after the fix it is excluded because it backs no graded item.
        $DB->insert_record('local_unifiedgrader_scomm', (object)[
            'cmid' => $assign->cmid,
            'userid' => $ungraded->id,
            'authorid' => $teacher->id,
            'content' => 'This is a long, detailed and dialogic comment. Have you considered '
                . 'revising the structure and expanding the analysis to strengthen your argument?',
            'timecreated' => 1700000000,
            'timemodified' => 1700000000,
        ]);

        $rows = report::get_assignment_feedback_breakdown($course->id, $teacher->id);

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame((int)$assign->cmid, $row['cmid']);

        // One graded, none with feedback -> coverage 0; and because the only UG
        // comment does not back a graded row, depth/quality stay 0 too.
        $this->assertSame(1, $row['ngraded']);
        $this->assertSame(0, $row['nwithfeedback']);
        $this->assertSame(0, $row['coverage']);
        $this->assertSame(0, $row['depth']);
        $this->assertSame(0, $row['quality']);

        // Sanity: a UG comment that DOES back the graded row is still analysed.
        $DB->insert_record('local_unifiedgrader_scomm', (object)[
            'cmid' => $assign->cmid,
            'userid' => $graded->id,
            'authorid' => $teacher->id,
            'content' => 'This is a long, detailed and dialogic comment. Have you considered '
                . 'revising the structure and expanding the analysis to strengthen your argument?',
            'timecreated' => 1700000000,
            'timemodified' => 1700000000,
        ]);
        $rows2 = report::get_assignment_feedback_breakdown($course->id, $teacher->id);
        $this->assertGreaterThan(0, $rows2[0]['depth']);
    }
}
