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
 * Unit tests for the student pulse dashboard logic.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \gradereport_coifish\pulse
 * @covers     \gradereport_coifish\output\student_pulse
 */
final class pulse_test extends \advanced_testcase {
    /**
     * Period start is anchored to the course start date.
     */
    public function test_period_start_anchored_to_course_start(): void {
        $start = 1000000;
        $interval = 14;
        $secs = $interval * DAYSECS;
        $now = $start + ($secs * 2) + 500;
        $this->assertEquals($start + $secs * 2, pulse::period_start($start, $interval, $now));
        $this->assertEquals($start + $secs, pulse::period_start($start, $interval, $start + $secs));
        // Before the course starts there is no period to capture.
        $this->assertEquals(0, pulse::period_start($start, $interval, $start - 10));
    }

    /**
     * With no course start date, periods align to interval buckets from the epoch.
     */
    public function test_period_start_epoch_aligned_without_course_start(): void {
        $interval = 7;
        $secs = $interval * DAYSECS;
        $now = (5 * $secs) + 123;
        $this->assertEquals(5 * $secs, pulse::period_start(0, $interval, $now));
    }

    /**
     * Course config resolves enabled state and interval, with defaults.
     */
    public function test_course_config_and_enabled_courseids(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $config = pulse::course_config($course->id);
        $this->assertFalse($config['enabled']);
        $this->assertEquals(pulse::DEFAULT_INTERVAL_DAYS, $config['interval']);
        $this->assertNotContains((int)$course->id, pulse::enabled_courseids());

        set_config('course_' . $course->id, json_encode([
            'student_dashboard_enabled' => true,
            'student_dashboard_interval_days' => 7,
        ]), 'gradereport_coifish');

        $config = pulse::course_config($course->id);
        $this->assertTrue($config['enabled']);
        $this->assertEquals(7, $config['interval']);
        $this->assertContains((int)$course->id, pulse::enabled_courseids());
    }

    /**
     * Capturing a course with the dashboard disabled writes nothing.
     */
    public function test_capture_is_noop_when_disabled(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->assertEquals(0, pulse::capture_course($course->id));
    }

    /**
     * recent_rows returns the newest periods first.
     */
    public function test_recent_rows_orders_newest_first(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        foreach ([100, 300, 200] as $periodstart) {
            $DB->insert_record('gradereport_coifish_student_pulse', (object)[
                'courseid' => $course->id,
                'userid' => $user->id,
                'periodstart' => $periodstart,
                'grade' => 50,
                'engagement' => 50,
                'social' => 50,
                'selfregulation' => 50,
                'feedbackpct' => 50,
                'daysoffline' => 1,
                'timecomputed' => $periodstart,
            ]);
        }
        $rows = pulse::recent_rows($course->id, $user->id, 2);
        $this->assertCount(2, $rows);
        $this->assertEquals(300, (int)$rows[0]->periodstart);
        $this->assertEquals(200, (int)$rows[1]->periodstart);
    }

    /**
     * The renderable computes deltas, personal best and a targeted prescription.
     */
    public function test_renderable_progress_and_prescription(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $DB->insert_record('gradereport_coifish_student_pulse', (object)[
            'courseid' => $course->id, 'userid' => $user->id, 'periodstart' => 1000,
            'grade' => 50, 'engagement' => 50, 'social' => 50,
            'selfregulation' => 50, 'feedbackpct' => 50, 'daysoffline' => 2, 'timecomputed' => 1000,
        ]);
        $DB->insert_record('gradereport_coifish_student_pulse', (object)[
            'courseid' => $course->id, 'userid' => $user->id, 'periodstart' => 2000,
            'grade' => 70, 'engagement' => 40, 'social' => 70,
            'selfregulation' => 70, 'feedbackpct' => 70, 'daysoffline' => 1, 'timecomputed' => 2000,
        ]);

        $renderable = new \gradereport_coifish\output\student_pulse($course->id, $user->id);
        $data = $renderable->export_for_template($PAGE->get_renderer('core'));

        $this->assertTrue($data['hasdata']);
        $this->assertEquals(64, $data['overall']);
        $this->assertTrue($data['ispersonalbest']);
        $this->assertFalse($data['hasstreak']);

        $engagement = null;
        foreach ($data['metrics'] as $metric) {
            if ($metric['key'] === 'engagement') {
                $engagement = $metric;
            }
        }
        $this->assertNotNull($engagement);
        $this->assertTrue($engagement['down']);
        $this->assertEquals(10, $engagement['deltaabs']);

        $texts = array_map(function ($p) {
            return $p['text'];
        }, $data['prescriptions']);
        $this->assertContains(get_string('pulse_rx_engagement', 'gradereport_coifish'), $texts);
    }

    /**
     * The renderable reports no data when there are no snapshots.
     */
    public function test_renderable_reports_no_data(): void {
        global $PAGE;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $renderable = new \gradereport_coifish\output\student_pulse($course->id, $user->id);
        $data = $renderable->export_for_template($PAGE->get_renderer('core'));
        $this->assertFalse($data['hasdata']);
    }

    /**
     * Insert two pulse rows (previous all 80; latest as given) for a user.
     *
     * @param int $courseid Course ID.
     * @param int $userid User ID.
     * @param array $latest Latest-period metric values, keyed by column.
     */
    protected function seed_two_periods(int $courseid, int $userid, array $latest): void {
        global $DB;
        $DB->insert_record('gradereport_coifish_student_pulse', (object)array_merge([
            'courseid' => $courseid, 'userid' => $userid, 'periodstart' => 1000,
            'grade' => 80, 'engagement' => 80, 'social' => 80,
            'selfregulation' => 80, 'feedbackpct' => 80, 'daysoffline' => 1, 'timecomputed' => 1000,
        ]));
        $DB->insert_record('gradereport_coifish_student_pulse', (object)array_merge([
            'courseid' => $courseid, 'userid' => $userid, 'periodstart' => 2000,
            'grade' => 80, 'engagement' => 80, 'social' => 80,
            'selfregulation' => 80, 'feedbackpct' => 80, 'daysoffline' => 1, 'timecomputed' => 2000,
        ], $latest));
    }

    /**
     * The discussion suggestion is suppressed when the course has no participatory forum.
     */
    public function test_social_suggestion_requires_forum(): void {
        global $PAGE;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        // Social is the only low metric; only the (excluded) news forum exists.
        $this->seed_two_periods($course->id, $user->id, ['social' => 30]);
        $data = (new \gradereport_coifish\output\student_pulse($course->id, $user->id))
            ->export_for_template($PAGE->get_renderer('core'));
        $texts = array_map(function ($p) {
            return $p['text'];
        }, $data['prescriptions']);
        $this->assertNotContains(get_string('pulse_rx_social', 'gradereport_coifish'), $texts);
    }

    /**
     * The discussion suggestion appears when the course has a participatory forum.
     */
    public function test_social_suggestion_with_forum(): void {
        global $PAGE;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $this->seed_two_periods($course->id, $user->id, ['social' => 30]);
        $data = (new \gradereport_coifish\output\student_pulse($course->id, $user->id))
            ->export_for_template($PAGE->get_renderer('core'));
        $texts = array_map(function ($p) {
            return $p['text'];
        }, $data['prescriptions']);
        $this->assertContains(get_string('pulse_rx_social', 'gradereport_coifish'), $texts);
    }

    /**
     * When no metric-based suggestion is actionable, upcoming due dates show.
     */
    public function test_due_dates_fallback(): void {
        global $PAGE;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id, 'name' => 'Essay 2', 'duedate' => time() + 5 * DAYSECS,
        ]);
        $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id, 'name' => 'Midterm Exam', 'timeclose' => time() + 2 * DAYSECS,
        ]);
        // All metrics healthy -> no metric-based suggestion -> due dates.
        $this->seed_two_periods($course->id, $user->id, []);
        $data = (new \gradereport_coifish\output\student_pulse($course->id, $user->id))
            ->export_for_template($PAGE->get_renderer('core'));
        $this->assertFalse($data['hasprescriptions']);
        $this->assertTrue($data['hasduedates']);
        $names = array_map(function ($d) {
            return $d['name'];
        }, $data['duedates']);
        $this->assertContains('Essay 2', $names);
        $this->assertContains('Midterm Exam', $names);
        // The quiz closes sooner, so it sorts first across both activity types.
        $this->assertEquals('Midterm Exam', $data['duedates'][0]['name']);
    }

    /**
     * Deadlines beyond the relevance horizon are not shown.
     */
    public function test_due_dates_beyond_horizon_excluded(): void {
        global $PAGE;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        // Default interval is 14 days, so the horizon is 21 days; this is well beyond.
        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id, 'name' => 'Final Project', 'duedate' => time() + 60 * DAYSECS,
        ]);
        $this->seed_two_periods($course->id, $user->id, []);
        $data = (new \gradereport_coifish\output\student_pulse($course->id, $user->id))
            ->export_for_template($PAGE->get_renderer('core'));
        $this->assertFalse($data['hasduedates']);
    }

    /**
     * Config resolves the site default, which a course-level value overrides.
     */
    public function test_course_config_site_default_and_override(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        // No course override; site default off, custom site interval.
        set_config('student_dashboard_enabled', 0, 'gradereport_coifish');
        set_config('student_dashboard_interval', 21, 'gradereport_coifish');
        $config = pulse::course_config($course->id);
        $this->assertFalse($config['enabled']);
        $this->assertEquals(21, $config['interval']);

        // Site default on -> the course inherits it.
        set_config('student_dashboard_enabled', 1, 'gradereport_coifish');
        $this->assertTrue(pulse::course_config($course->id)['enabled']);

        // A course override beats the site default (enabled and interval).
        set_config('course_' . $course->id, json_encode([
            'student_dashboard_enabled' => '0',
            'student_dashboard_interval_days' => 10,
        ]), 'gradereport_coifish');
        $config = pulse::course_config($course->id);
        $this->assertFalse($config['enabled']);
        $this->assertEquals(10, $config['interval']);
    }
}
