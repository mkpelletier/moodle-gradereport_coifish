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
 * Unit tests for the gamification widget methods.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \gradereport_coifish\report
 */
final class gamification_test extends \advanced_testcase {
    /**
     * Create a course with 12 enrolled students and 4 graded assignments.
     *
     * Student 1 (the "target" student) has scores: 40, 55, 60, 75 (improving trend).
     * Other students have varied scores to populate competitive widgets.
     *
     * @return array Array with course, target student, grade items, and all students.
     */
    protected function create_gamification_data(): array {
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();

        // Create 12 students.
        $students = [];
        for ($i = 0; $i < 12; $i++) {
            $student = $generator->create_user();
            $generator->enrol_user($student->id, $course->id, 'student');
            $students[] = $student;
        }

        // Create a teacher.
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');

        // Create 4 assignments.
        $assigns = [];
        $gradeitems = [];
        for ($i = 1; $i <= 4; $i++) {
            $assign = $generator->create_module('assign', [
                'course' => $course->id,
                'name' => 'Assessment ' . $i,
            ]);
            $assigns[] = $assign;
            $gradeitems[] = \grade_item::fetch([
                'itemtype' => 'mod',
                'itemmodule' => 'assign',
                'iteminstance' => $assign->id,
                'courseid' => $course->id,
            ]);
        }

        // Grade the target student (student[0]) — improving trend: 40, 55, 60, 75.
        $targetscores = [40, 55, 60, 75];
        for ($i = 0; $i < 4; $i++) {
            $gradeitems[$i]->update_final_grade($students[0]->id, $targetscores[$i], 'test');
        }

        // Grade other students with various scores for competitive widgets.
        $otherscores = [
            // Student[1]..student[11] — first and last assignments at least.
            [90, 88, 85, 82], // High performer.
            [80, 78, 75, 70],
            [70, 72, 74, 76], // Improving.
            [65, 60, 62, 61],
            [60, 55, 58, 57],
            [55, 50, 52, 53],
            [50, 48, 45, 42], // Declining.
            [45, 40, 38, 35],
            [35, 30, 28, 25],
            [25, 30, 35, 40], // Big improvement.
            [20, 22, 18, 15],
        ];
        for ($s = 0; $s < 11; $s++) {
            for ($i = 0; $i < 4; $i++) {
                $gradeitems[$i]->update_final_grade($students[$s + 1]->id, $otherscores[$s][$i], 'test');
            }
        }

        grade_regrade_final_grades($course->id);

        return [
            'course' => $course,
            'target' => $students[0],
            'students' => $students,
            'teacher' => $teacher,
            'assigns' => $assigns,
            'gradeitems' => $gradeitems,
        ];
    }

    /**
     * Helper to create a report instance.
     *
     * @param object $course The course object.
     * @param int $userid The user ID.
     * @return report The report instance.
     */
    protected function create_report(object $course, int $userid): report {
        $context = \context_course::instance($course->id);
        $gpr = new \grade_plugin_return([
            'type' => 'report',
            'plugin' => 'gradetracker',
            'courseid' => $course->id,
            'userid' => $userid,
        ]);
        return new report($course->id, $gpr, $context, $userid);
    }

    /**
     * Enable all gamification widgets and set minimum enrolment low enough.
     *
     * @param int $courseid Optional course ID to also enable course-level gamification.
     */
    protected function enable_all_widgets(int $courseid = 0): void {
        set_config('widget_overall', '1', 'gradereport_coifish');
        set_config('widget_neighbours', '1', 'gradereport_coifish');
        set_config('widget_improvement', '1', 'gradereport_coifish');
        set_config('widget_trend', '1', 'gradereport_coifish');
        set_config('widget_streak', '1', 'gradereport_coifish');
        set_config('widget_milestones', '1', 'gradereport_coifish');
        set_config('leaderboard_min_enrolment', '5', 'gradereport_coifish');
        set_config('threshold_pass', '50', 'gradereport_coifish');

        if ($courseid > 0) {
            set_config('course_' . $courseid, json_encode([
                'gamification_enabled' => true,
                'widgets' => [
                    'overall' => true,
                    'neighbours' => true,
                    'improvement' => true,
                    'trend' => true,
                    'streak' => true,
                    'milestones' => true,
                ],
            ]), 'gradereport_coifish');
        }
    }

    /**
     * Test that all widgets are returned when enabled.
     */
    public function test_all_widgets_enabled(): void {
        $data = $this->create_gamification_data();
        $this->enable_all_widgets($data['course']->id);
        // Ensure percentile widget is visible to all students regardless of ranking.
        set_config('percentile_threshold', 100, 'gradereport_coifish');

        $report = $this->create_report($data['course'], $data['target']->id);
        $result = $report->get_gamification_data(true);

        $this->assertFalse($result['nograded']);
        $this->assertTrue($result['haswidgets']);

        // Should have 6 widgets.
        $types = array_column($result['widgets'], 'type');
        $this->assertContains('overall', $types);
        $this->assertContains('neighbours', $types);
        $this->assertContains('improvement', $types);
        $this->assertContains('trend', $types);
        $this->assertContains('streak', $types);
        $this->assertContains('milestones', $types);
    }

    /**
     * Test that no widgets are returned when all are disabled.
     */
    public function test_no_widgets_when_disabled(): void {
        $data = $this->create_gamification_data();

        set_config('widget_overall', '0', 'gradereport_coifish');
        set_config('widget_neighbours', '0', 'gradereport_coifish');
        set_config('widget_improvement', '0', 'gradereport_coifish');
        set_config('widget_trend', '0', 'gradereport_coifish');
        set_config('widget_streak', '0', 'gradereport_coifish');
        set_config('widget_milestones', '0', 'gradereport_coifish');

        $report = $this->create_report($data['course'], $data['target']->id);
        $result = $report->get_gamification_data(true);

        $this->assertFalse($result['haswidgets']);
        $this->assertEmpty($result['widgets']);
    }

    /**
     * Test that competitive widgets are hidden when enrolment is too low.
     */
    public function test_competitive_widgets_hidden_low_enrolment(): void {
        $data = $this->create_gamification_data();

        set_config('widget_overall', '1', 'gradereport_coifish');
        set_config('widget_neighbours', '1', 'gradereport_coifish');
        set_config('widget_improvement', '1', 'gradereport_coifish');
        set_config('widget_trend', '1', 'gradereport_coifish');
        set_config('widget_streak', '0', 'gradereport_coifish');
        set_config('widget_milestones', '0', 'gradereport_coifish');
        // Set enrolment threshold higher than our 12 students.
        set_config('leaderboard_min_enrolment', '50', 'gradereport_coifish');

        $report = $this->create_report($data['course'], $data['target']->id);
        $result = $report->get_gamification_data(true);

        $types = array_column($result['widgets'], 'type');
        // Competitive widgets should be absent.
        $this->assertNotContains('overall', $types);
        $this->assertNotContains('neighbours', $types);
        $this->assertNotContains('improvement', $types);
        // Personal widget should still be present.
        $this->assertContains('trend', $types);
    }

    /**
     * Test overall percentile widget values.
     */
    public function test_widget_overall_percentile(): void {
        $data = $this->create_gamification_data();
        $this->enable_all_widgets($data['course']->id);
        set_config('percentile_threshold', 100, 'gradereport_coifish');

        $report = $this->create_report($data['course'], $data['target']->id);
        $result = $report->get_gamification_data(true);

        $overall = null;
        foreach ($result['widgets'] as $w) {
            if ($w['type'] === 'overall') {
                $overall = $w;
                break;
            }
        }

        $this->assertNotNull($overall);
        $this->assertArrayHasKey('toppercent', $overall);
        $this->assertArrayHasKey('toppercentlabel', $overall);
        // Toppercent should be between 1 and 100.
        $this->assertGreaterThanOrEqual(1, $overall['toppercent']);
        $this->assertLessThanOrEqual(100, $overall['toppercent']);
    }

    /**
     * Test nearest neighbours widget returns correct number of rows.
     */
    public function test_widget_neighbours_rows(): void {
        $data = $this->create_gamification_data();
        $this->enable_all_widgets($data['course']->id);

        $report = $this->create_report($data['course'], $data['target']->id);
        $result = $report->get_gamification_data(true);

        $neighbours = null;
        foreach ($result['widgets'] as $w) {
            if ($w['type'] === 'neighbours') {
                $neighbours = $w;
                break;
            }
        }

        $this->assertNotNull($neighbours);
        $this->assertArrayHasKey('rows', $neighbours);
        // Should have up to 5 rows (2 above + self + 2 below).
        $this->assertGreaterThanOrEqual(3, count($neighbours['rows']));
        $this->assertLessThanOrEqual(5, count($neighbours['rows']));

        // Exactly one row should be "you".
        $yourows = array_filter($neighbours['rows'], function ($r) {
            return $r['isyou'];
        });
        $this->assertCount(1, $yourows);
    }

    /**
     * Test improvement widget detects positive trend.
     */
    public function test_widget_improvement_positive(): void {
        $data = $this->create_gamification_data();
        $this->enable_all_widgets($data['course']->id);

        // Target student goes from 40 to 75 — 35 point improvement.
        $report = $this->create_report($data['course'], $data['target']->id);
        $result = $report->get_gamification_data(true);

        $improvement = null;
        foreach ($result['widgets'] as $w) {
            if ($w['type'] === 'improvement') {
                $improvement = $w;
                break;
            }
        }

        $this->assertNotNull($improvement);
        $this->assertTrue($improvement['ispositive']);
        $this->assertFalse($improvement['isnegative']);
        $this->assertGreaterThan(0, $improvement['delta']);
    }

    /**
     * Test personal trend widget with improving scores.
     */
    public function test_widget_trend_direction(): void {
        $data = $this->create_gamification_data();
        $this->enable_all_widgets($data['course']->id);

        $report = $this->create_report($data['course'], $data['target']->id);
        $result = $report->get_gamification_data(true);

        $trend = null;
        foreach ($result['widgets'] as $w) {
            if ($w['type'] === 'trend') {
                $trend = $w;
                break;
            }
        }

        $this->assertNotNull($trend);
        // Target scores are 40, 55, 60, 75 — trending up.
        $this->assertEquals('up', $trend['direction']);
        $this->assertArrayHasKey('sparkjson', $trend);
        // Sparkjson should decode to an array of numbers.
        $points = json_decode($trend['sparkjson']);
        $this->assertIsArray($points);
        $this->assertGreaterThanOrEqual(2, count($points));
    }

    /**
     * Test trend widget requires at least 2 scores.
     */
    public function test_widget_trend_requires_two_scores(): void {
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        // Only one graded assignment.
        $assign = $generator->create_module('assign', ['course' => $course->id]);
        $gi = \grade_item::fetch([
            'itemtype' => 'mod', 'itemmodule' => 'assign',
            'iteminstance' => $assign->id, 'courseid' => $course->id,
        ]);
        $gi->update_final_grade($student->id, 70, 'test');

        set_config('widget_trend', '1', 'gradereport_coifish');
        set_config('widget_overall', '0', 'gradereport_coifish');
        set_config('widget_neighbours', '0', 'gradereport_coifish');
        set_config('widget_improvement', '0', 'gradereport_coifish');
        set_config('widget_streak', '0', 'gradereport_coifish');
        set_config('widget_milestones', '0', 'gradereport_coifish');

        grade_regrade_final_grades($course->id);

        $report = $this->create_report($course, $student->id);
        $result = $report->get_gamification_data(true);

        // Trend should not appear with only 1 score.
        $types = array_column($result['widgets'], 'type');
        $this->assertNotContains('trend', $types);
    }

    /**
     * Test streak tracker counts consecutive passes.
     */
    public function test_widget_streak_count(): void {
        $data = $this->create_gamification_data();
        $this->enable_all_widgets($data['course']->id);
        set_config('threshold_pass', '50', 'gradereport_coifish');

        // Target scores: 40, 55, 60, 75. Streak from the end: 75, 60, 55 = 3 consecutive passes.
        $report = $this->create_report($data['course'], $data['target']->id);
        $result = $report->get_gamification_data(true);

        $streak = null;
        foreach ($result['widgets'] as $w) {
            if ($w['type'] === 'streak') {
                $streak = $w;
                break;
            }
        }

        $this->assertNotNull($streak);
        $this->assertTrue($streak['hasstreak']);
        // 55, 60, 75 are >= 50, so current streak should be 3.
        $this->assertEquals(3, $streak['currentstreak']);
        // Best streak is also 3.
        $this->assertGreaterThanOrEqual(3, $streak['beststreak']);
    }

    /**
     * Test streak is zero when the latest score is below the pass threshold.
     */
    public function test_widget_streak_broken(): void {
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        $assigns = [];
        $gis = [];
        for ($i = 0; $i < 3; $i++) {
            $assign = $generator->create_module('assign', ['course' => $course->id]);
            $assigns[] = $assign;
            $gis[] = \grade_item::fetch([
                'itemtype' => 'mod', 'itemmodule' => 'assign',
                'iteminstance' => $assign->id, 'courseid' => $course->id,
            ]);
        }

        // Scores: 70, 80, 30 — last one breaks the streak.
        $gis[0]->update_final_grade($student->id, 70, 'test');
        $gis[1]->update_final_grade($student->id, 80, 'test');
        $gis[2]->update_final_grade($student->id, 30, 'test');

        set_config('widget_streak', '1', 'gradereport_coifish');
        set_config('widget_trend', '0', 'gradereport_coifish');
        set_config('widget_milestones', '0', 'gradereport_coifish');
        set_config('widget_overall', '0', 'gradereport_coifish');
        set_config('widget_neighbours', '0', 'gradereport_coifish');
        set_config('widget_improvement', '0', 'gradereport_coifish');
        set_config('threshold_pass', '50', 'gradereport_coifish');

        grade_regrade_final_grades($course->id);

        $report = $this->create_report($course, $student->id);
        $result = $report->get_gamification_data(true);

        $streak = null;
        foreach ($result['widgets'] as $w) {
            if ($w['type'] === 'streak') {
                $streak = $w;
                break;
            }
        }

        $this->assertNotNull($streak);
        $this->assertFalse($streak['hasstreak']);
        $this->assertEquals(0, $streak['currentstreak']);
        // Best streak should be 2 (70, 80).
        $this->assertEquals(2, $streak['beststreak']);
    }

    /**
     * Test milestone badges: first grade always earned, others conditional.
     */
    public function test_widget_milestones_badges(): void {
        $data = $this->create_gamification_data();
        $this->enable_all_widgets($data['course']->id);

        $report = $this->create_report($data['course'], $data['target']->id);
        $result = $report->get_gamification_data(true);

        $milestones = null;
        foreach ($result['widgets'] as $w) {
            if ($w['type'] === 'milestones') {
                $milestones = $w;
                break;
            }
        }

        $this->assertNotNull($milestones);
        $this->assertArrayHasKey('badges', $milestones);
        $this->assertNotEmpty($milestones['badges']);
        $this->assertArrayHasKey('earnedcount', $milestones);
        $this->assertArrayHasKey('totalcount', $milestones);
        $this->assertEquals(6, $milestones['totalcount']);

        // The "first grade" badge should always be earned.
        $firstgrade = null;
        foreach ($milestones['badges'] as $badge) {
            if ($badge['key'] === 'first_grade') {
                $firstgrade = $badge;
                break;
            }
        }
        $this->assertNotNull($firstgrade);
        $this->assertTrue($firstgrade['earned']);

        // The "all submitted" badge — all 4 assignments are graded, so this should be earned.
        $allsubmitted = null;
        foreach ($milestones['badges'] as $badge) {
            if ($badge['key'] === 'all_submitted') {
                $allsubmitted = $badge;
                break;
            }
        }
        $this->assertNotNull($allsubmitted);
        $this->assertTrue($allsubmitted['earned']);

        // At least 1 badge should be earned.
        $this->assertGreaterThanOrEqual(1, $milestones['earnedcount']);
    }

    /**
     * Test nograded state when student has no graded items.
     */
    public function test_nograded_state(): void {
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $generator->create_module('assign', ['course' => $course->id]);

        $this->enable_all_widgets();

        $report = $this->create_report($course, $student->id);
        $result = $report->get_gamification_data(true);

        $this->assertTrue($result['nograded']);
        $this->assertFalse($result['haswidgets']);
        $this->assertEmpty($result['widgets']);
    }

    /**
     * Test that course-level gamification disabled hides widgets.
     */
    public function test_course_level_disabled(): void {
        $data = $this->create_gamification_data();
        $this->enable_all_widgets($data['course']->id);

        // Now disable at course level.
        set_config('course_' . $data['course']->id, json_encode([
            'gamification_enabled' => false,
        ]), 'gradereport_coifish');

        $report = $this->create_report($data['course'], $data['target']->id);

        // Without preview — should be empty.
        $result = $report->get_gamification_data(false);
        $this->assertFalse($result['haswidgets']);

        // With preview — should have widgets.
        $resultpreview = $report->get_gamification_data(true);
        $this->assertTrue($resultpreview['haswidgets']);
        $this->assertTrue($resultpreview['ispreview']);
    }

    /**
     * Test that course-level widget overrides suppress specific widgets.
     */
    public function test_course_level_widget_override(): void {
        $data = $this->create_gamification_data();
        $this->enable_all_widgets($data['course']->id);
        set_config('percentile_threshold', 100, 'gradereport_coifish');

        // Override: disable trend and streak at course level.
        set_config('course_' . $data['course']->id, json_encode([
            'gamification_enabled' => true,
            'widgets' => [
                'overall' => true,
                'neighbours' => true,
                'improvement' => true,
                'trend' => false,
                'streak' => false,
                'milestones' => true,
            ],
        ]), 'gradereport_coifish');

        $report = $this->create_report($data['course'], $data['target']->id);
        $result = $report->get_gamification_data(false);

        $types = array_column($result['widgets'], 'type');
        $this->assertContains('overall', $types);
        $this->assertNotContains('trend', $types);
        $this->assertNotContains('streak', $types);
    }
}
