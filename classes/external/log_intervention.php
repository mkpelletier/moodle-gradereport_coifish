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
 * External function to log a teacher intervention.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gradereport_coifish\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Log an intervention for one or more students.
 */
class log_intervention extends external_api {
    /**
     * Define parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'studentids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Student user ID')
            ),
            'diagnostictype' => new external_value(PARAM_ALPHANUMEXT, 'Diagnostic card type key'),
            'scope' => new external_value(PARAM_ALPHA, 'individual or cohort'),
            'actiontype' => new external_value(PARAM_ALPHANUMEXT, 'Preset action type key'),
            'customaction' => new external_value(PARAM_TEXT, 'Custom action text', VALUE_DEFAULT, ''),
            'notes' => new external_value(PARAM_TEXT, 'Optional notes', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Execute the function.
     *
     * @param int $courseid
     * @param array $studentids
     * @param string $diagnostictype
     * @param string $scope
     * @param string $actiontype
     * @param string $customaction
     * @param string $notes
     * @return array
     */
    public static function execute(
        int $courseid,
        array $studentids,
        string $diagnostictype,
        string $scope,
        string $actiontype,
        string $customaction = '',
        string $notes = ''
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'studentids' => $studentids,
            'diagnostictype' => $diagnostictype,
            'scope' => $scope,
            'actiontype' => $actiontype,
            'customaction' => $customaction,
            'notes' => $notes,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('gradereport/coifish:intervene', $context);

        $now = time();

        // Create the main intervention record.
        $intervention = (object)[
            'courseid' => $params['courseid'],
            'teacherid' => $USER->id,
            'diagnostictype' => $params['diagnostictype'],
            'scope' => $params['scope'],
            'actiontype' => $params['actiontype'],
            'customaction' => $params['customaction'] ?: null,
            'notes' => $params['notes'] ?: null,
            'timecreated' => $now,
        ];
        $interventionid = $DB->insert_record('gradereport_coifish_intv', $intervention);

        // For cohort-scope interventions with no specific students, use all enrolled students.
        $studentids = $params['studentids'];
        if (empty($studentids) && $params['scope'] === 'cohort') {
            $enrolled = get_enrolled_users($context, 'moodle/course:isincompletionreports', 0, 'u.id');
            $studentids = array_keys($enrolled);
        }

        // Create per-student records with metric snapshots.
        $studentrecords = [];
        foreach ($studentids as $studentid) {
            $snapshot = self::capture_snapshot($params['courseid'], $studentid);
            $record = (object)[
                'interventionid' => $interventionid,
                'studentid' => $studentid,
                'snap_grade' => $snapshot['grade'],
                'snap_engagement' => $snapshot['engagement'],
                'snap_social' => $snapshot['social'],
                'snap_feedbackpct' => $snapshot['feedbackpct'],
                'snap_daysinactive' => $snapshot['daysinactive'],
            ];
            $record->id = $DB->insert_record('gradereport_coifish_intv_stu', $record);
            $studentrecords[] = ['id' => (int)$record->id, 'studentid' => $studentid];
        }

        return [
            'interventionid' => (int)$interventionid,
            'students' => $studentrecords,
        ];
    }

    /**
     * Define return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'interventionid' => new external_value(PARAM_INT, 'The new intervention ID'),
            'students' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Student intervention record ID'),
                    'studentid' => new external_value(PARAM_INT, 'Student user ID'),
                ])
            ),
        ]);
    }

    /**
     * Capture a snapshot of a student's current metrics.
     *
     * @param int $courseid
     * @param int $studentid
     * @return array
     */
    public static function capture_snapshot(int $courseid, int $studentid): array {
        global $DB;

        // Grade percentage.
        $courseitem = $DB->get_record('grade_items', [
            'courseid' => $courseid,
            'itemtype' => 'course',
        ]);
        $grade = null;
        if ($courseitem) {
            $gg = $DB->get_record('grade_grades', [
                'itemid' => $courseitem->id,
                'userid' => $studentid,
            ]);
            if ($gg && $gg->finalgrade !== null && $courseitem->grademax > 0) {
                $grade = round(((float)$gg->finalgrade / (float)$courseitem->grademax) * 100, 2);
            }
        }

        // Engagement: count of distinct activities interacted with.
        $totalactivities = (int)$DB->count_records_sql(
            "SELECT COUNT(cm.id)
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :cid AND cm.deletioninprogress = 0
                AND m.name IN ('assign', 'quiz', 'page', 'book', 'resource', 'url', 'folder')",
            ['cid' => $courseid]
        );
        $engaged = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT l.contextinstanceid)
               FROM {logstore_standard_log} l
              WHERE l.courseid = :cid AND l.userid = :uid
                AND l.action = 'viewed' AND l.target = 'course_module'",
            ['cid' => $courseid, 'uid' => $studentid]
        );
        $engagement = $totalactivities > 0 ? min(100, round(($engaged / $totalactivities) * 100)) : 0;

        // Social presence: forum thread participation rate.
        $totaldiscussions = (int)$DB->count_records_sql(
            "SELECT COUNT(fd.id) FROM {forum_discussions} fd WHERE fd.course = :cid",
            ['cid' => $courseid]
        );
        $threads = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT fd.id)
               FROM {forum_posts} fp
               JOIN {forum_discussions} fd ON fd.id = fp.discussion
              WHERE fd.course = :cid AND fp.userid = :uid",
            ['cid' => $courseid, 'uid' => $studentid]
        );
        $social = $totaldiscussions > 0 ? min(100, round(($threads / $totaldiscussions) * 100)) : 0;

        // Feedback review percentage.
        $totalfeedback = (int)$DB->count_records_sql(
            "SELECT COUNT(ag.id)
               FROM {assign_grades} ag
               JOIN {assign} a ON a.id = ag.assignment
              WHERE a.course = :cid AND ag.userid = :uid AND ag.grade >= 0",
            ['cid' => $courseid, 'uid' => $studentid]
        );
        $viewedfeedback = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT l.contextinstanceid)
               FROM {logstore_standard_log} l
              WHERE l.userid = :uid AND l.courseid = :cid
                AND l.eventname IN (:ev1, :ev2)",
            [
                'uid' => $studentid, 'cid' => $courseid,
                'ev1' => '\\mod_assign\\event\\feedback_viewed',
                'ev2' => '\\mod_assign\\event\\submission_status_viewed',
            ]
        );
        $feedbackpct = $totalfeedback > 0 ? min(100, round(($viewedfeedback / $totalfeedback) * 100)) : null;

        // Days since last activity.
        $lastactive = $DB->get_field_sql(
            "SELECT MAX(timecreated) FROM {logstore_standard_log}
              WHERE courseid = :cid AND userid = :uid",
            ['cid' => $courseid, 'uid' => $studentid]
        );
        $daysinactive = $lastactive ? (int)round((time() - $lastactive) / 86400) : null;

        return [
            'grade' => $grade,
            'engagement' => $engagement,
            'social' => $social,
            'feedbackpct' => $feedbackpct,
            'daysinactive' => $daysinactive,
        ];
    }
}
