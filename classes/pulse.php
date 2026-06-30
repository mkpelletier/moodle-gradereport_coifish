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

/**
 * Shared logic for the student-facing fortnightly pulse dashboard.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gradereport_coifish;

/**
 * Captures and reads per-student pulse snapshots, and resolves per-course
 * pulse configuration. Used by the build_student_pulse task, the course-entry
 * hook, the student_pulse renderable and the unit tests so the period maths and
 * capture rules live in exactly one place.
 */
class pulse {
    /** @var int Default period length, in days. */
    public const DEFAULT_INTERVAL_DAYS = 14;

    /** @var string User-preference prefix: timestamp of the last period shown, per course. */
    public const PREF_LASTSHOWN = 'gradereport_coifish_pulse_lastshown_';

    /** @var string User-preference prefix: 1 if the student has muted the pulse for a course. */
    public const PREF_MUTED = 'gradereport_coifish_pulse_muted_';

    /**
     * Resolve a course's pulse settings from the course_<id> JSON blob.
     *
     * @param int $courseid The course ID.
     * @return array Keys: 'enabled' (bool) and 'interval' (int days).
     */
    public static function course_config(int $courseid): array {
        $raw = get_config('gradereport_coifish', 'course_' . $courseid);
        $settings = $raw ? (json_decode($raw, true) ?: []) : [];

        // Enabled: a course override ('1' / '0', or a legacy bool) wins; '' or
        // unset falls back to the site default.
        $override = $settings['student_dashboard_enabled'] ?? '';
        if ($override === '' || $override === null) {
            $enabled = !empty(get_config('gradereport_coifish', 'student_dashboard_enabled'));
        } else {
            $enabled = (bool)$override;
        }

        // Interval: a positive course override wins; otherwise the site default,
        // then a sane fallback.
        $courseinterval = (int)($settings['student_dashboard_interval_days'] ?? 0);
        if ($courseinterval > 0) {
            $interval = $courseinterval;
        } else {
            $siteinterval = (int)get_config('gradereport_coifish', 'student_dashboard_interval');
            $interval = $siteinterval > 0 ? $siteinterval : self::DEFAULT_INTERVAL_DAYS;
        }

        return ['enabled' => $enabled, 'interval' => $interval];
    }

    /**
     * Course IDs with the pulse dashboard enabled.
     *
     * @return int[] Enabled course IDs.
     */
    public static function enabled_courseids(): array {
        $all = (array)get_config('gradereport_coifish');
        $ids = [];
        foreach ($all as $key => $value) {
            if (strpos($key, 'course_') !== 0) {
                continue;
            }
            $courseid = (int)substr($key, 7);
            if ($courseid <= 0) {
                continue;
            }
            $settings = json_decode($value, true);
            if (!empty($settings['student_dashboard_enabled'])) {
                $ids[] = $courseid;
            }
        }
        return $ids;
    }

    /**
     * Start timestamp of the period containing $now, anchored to the course
     * start date when available (so periods line up with the course calendar),
     * otherwise to fixed interval buckets from the Unix epoch.
     *
     * @param int $coursestart Course start date (0 if unset).
     * @param int $intervaldays Period length, in days.
     * @param int $now Reference time.
     * @return int Period start timestamp, or 0 if the course has not started yet.
     */
    public static function period_start(int $coursestart, int $intervaldays, int $now): int {
        $intervalsecs = ($intervaldays > 0 ? $intervaldays : self::DEFAULT_INTERVAL_DAYS) * DAYSECS;
        if ($coursestart > 0) {
            if ($now < $coursestart) {
                return 0;
            }
            return $coursestart + intdiv($now - $coursestart, $intervalsecs) * $intervalsecs;
        }
        return $now - ($now % $intervalsecs);
    }

    /**
     * Capture pulse rows for the current period of a course, if not already
     * captured. In-progress metrics are read from {local_coifish_active_snapshot}
     * (the daily-refreshed analytics snapshot); course-access recency is computed
     * natively. Writes at most one row per student per period and is a no-op when
     * the period is already captured or the analytics snapshot is unavailable.
     *
     * @param int $courseid The course ID.
     * @param int|null $now Reference time (defaults to now); injectable for tests.
     * @return int Number of rows written.
     */
    public static function capture_course(int $courseid, ?int $now = null): int {
        global $DB;
        $now = $now ?? time();

        $config = self::course_config($courseid);
        if (!$config['enabled']) {
            return 0;
        }

        $course = get_course($courseid);
        $periodstart = self::period_start((int)$course->startdate, $config['interval'], $now);
        if ($periodstart <= 0) {
            return 0;
        }
        if ($DB->record_exists('gradereport_coifish_student_pulse', [
            'courseid' => $courseid,
            'periodstart' => $periodstart,
        ])) {
            return 0;
        }

        // The analytics snapshot is the metric source; without it there is
        // nothing meaningful to show, so the feature stays dormant.
        if (!$DB->get_manager()->table_exists('local_coifish_active_snapshot')) {
            return 0;
        }
        $snaps = $DB->get_records('local_coifish_active_snapshot', ['courseid' => $courseid]);
        if (!$snaps) {
            return 0;
        }

        $userids = array_map(function ($s) {
            return (int)$s->userid;
        }, $snaps);
        $lastaccess = [];
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'la');
        $records = $DB->get_records_sql(
            "SELECT userid, timeaccess
               FROM {user_lastaccess}
              WHERE courseid = :courseid AND userid $insql",
            array_merge(['courseid' => $courseid], $params)
        );
        foreach ($records as $rec) {
            $lastaccess[(int)$rec->userid] = (int)$rec->timeaccess;
        }

        $written = 0;
        foreach ($snaps as $snap) {
            $uid = (int)$snap->userid;
            $la = $lastaccess[$uid] ?? 0;
            $DB->insert_record('gradereport_coifish_student_pulse', (object)[
                'courseid' => $courseid,
                'userid' => $uid,
                'periodstart' => $periodstart,
                'grade' => $snap->currentgrade,
                'engagement' => $snap->engagement,
                'social' => $snap->social,
                'selfregulation' => $snap->selfregulation,
                'feedbackpct' => $snap->feedbackpct,
                'daysoffline' => $la > 0 ? (int)floor(($now - $la) / DAYSECS) : null,
                'timecomputed' => $now,
            ]);
            $written++;
        }
        return $written;
    }

    /**
     * The most recent pulse rows for a student in a course, newest first.
     *
     * @param int $courseid The course ID.
     * @param int $userid The student's user ID.
     * @param int $limit Maximum rows to return.
     * @return array Row objects ordered newest period first.
     */
    public static function recent_rows(int $courseid, int $userid, int $limit = 2): array {
        global $DB;
        return array_values($DB->get_records(
            'gradereport_coifish_student_pulse',
            ['courseid' => $courseid, 'userid' => $userid],
            'periodstart DESC',
            '*',
            0,
            $limit
        ));
    }
}
