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
 * Privacy provider for the Grade Tracker report.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gradereport_coifish\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider — declares stored personal data and implements export/delete.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\user_preference_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the personal data stored by this plugin.
     *
     * @param collection $collection The collection to add metadata to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('gradereport_coifish_intv', [
            'courseid' => 'privacy:metadata:intv:courseid',
            'teacherid' => 'privacy:metadata:intv:teacherid',
            'diagnostictype' => 'privacy:metadata:intv:diagnostictype',
            'scope' => 'privacy:metadata:intv:scope',
            'actiontype' => 'privacy:metadata:intv:actiontype',
            'customaction' => 'privacy:metadata:intv:customaction',
            'notes' => 'privacy:metadata:intv:notes',
            'timecreated' => 'privacy:metadata:intv:timecreated',
        ], 'privacy:metadata:intv');

        $collection->add_database_table('gradereport_coifish_intv_stu', [
            'interventionid' => 'privacy:metadata:intv_stu:interventionid',
            'studentid' => 'privacy:metadata:intv_stu:studentid',
            'snap_grade' => 'privacy:metadata:intv_stu:snap_grade',
            'snap_engagement' => 'privacy:metadata:intv_stu:snap_engagement',
            'snap_social' => 'privacy:metadata:intv_stu:snap_social',
            'snap_feedbackpct' => 'privacy:metadata:intv_stu:snap_feedbackpct',
            'snap_daysinactive' => 'privacy:metadata:intv_stu:snap_daysinactive',
        ], 'privacy:metadata:intv_stu');

        $collection->add_database_table('gradereport_coifish_intv_out', [
            'intvstudentid' => 'privacy:metadata:intv_out:intvstudentid',
            'checkdays' => 'privacy:metadata:intv_out:checkdays',
            'timechecked' => 'privacy:metadata:intv_out:timechecked',
            'grade' => 'privacy:metadata:intv_out:grade',
            'engagement' => 'privacy:metadata:intv_out:engagement',
            'social' => 'privacy:metadata:intv_out:social',
            'feedbackpct' => 'privacy:metadata:intv_out:feedbackpct',
            'daysinactive' => 'privacy:metadata:intv_out:daysinactive',
            'outcome' => 'privacy:metadata:intv_out:outcome',
        ], 'privacy:metadata:intv_out');

        $collection->add_database_table('gradereport_coifish_feedback', [
            'courseid' => 'privacy:metadata:feedback:courseid',
            'userid' => 'privacy:metadata:feedback:userid',
            'coverage' => 'privacy:metadata:feedback:coverage',
            'depth' => 'privacy:metadata:feedback:depth',
            'personalisation' => 'privacy:metadata:feedback:personalisation',
            'structured' => 'privacy:metadata:feedback:structured',
            'composite' => 'privacy:metadata:feedback:composite',
            'totalgraded' => 'privacy:metadata:feedback:totalgraded',
            'timemodified' => 'privacy:metadata:feedback:timemodified',
        ], 'privacy:metadata:feedback');

        $collection->add_database_table('gradereport_coifish_student_pulse', [
            'userid' => 'privacy:metadata:pulse:userid',
            'courseid' => 'privacy:metadata:pulse:courseid',
            'periodstart' => 'privacy:metadata:pulse:periodstart',
            'grade' => 'privacy:metadata:pulse:grade',
            'engagement' => 'privacy:metadata:pulse:engagement',
            'social' => 'privacy:metadata:pulse:social',
            'selfregulation' => 'privacy:metadata:pulse:selfregulation',
            'feedbackpct' => 'privacy:metadata:pulse:feedbackpct',
            'daysoffline' => 'privacy:metadata:pulse:daysoffline',
            'timecomputed' => 'privacy:metadata:pulse:timecomputed',
        ], 'privacy:metadata:pulse');

        $collection->add_user_preference(
            'gradereport_coifish_pulse_lastshown',
            'privacy:metadata:preference:pulse_lastshown'
        );
        $collection->add_user_preference(
            'gradereport_coifish_pulse_muted',
            'privacy:metadata:preference:pulse_muted'
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist The contextlist containing the list of contexts.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Teacher who logged interventions.
        $contextlist->add_from_sql(
            "SELECT DISTINCT ctx.id
               FROM {context} ctx
               JOIN {gradereport_coifish_intv} i ON i.courseid = ctx.instanceid
              WHERE ctx.contextlevel = :contextlevel AND i.teacherid = :userid",
            ['contextlevel' => CONTEXT_COURSE, 'userid' => $userid]
        );

        // Student who was the subject of interventions.
        $contextlist->add_from_sql(
            "SELECT DISTINCT ctx.id
               FROM {context} ctx
               JOIN {gradereport_coifish_intv} i ON i.courseid = ctx.instanceid
               JOIN {gradereport_coifish_intv_stu} s ON s.interventionid = i.id
              WHERE ctx.contextlevel = :contextlevel AND s.studentid = :userid",
            ['contextlevel' => CONTEXT_COURSE, 'userid' => $userid]
        );

        // Teacher feedback metrics.
        $contextlist->add_from_sql(
            "SELECT DISTINCT ctx.id
               FROM {context} ctx
               JOIN {gradereport_coifish_feedback} f ON f.courseid = ctx.instanceid
              WHERE ctx.contextlevel = :contextlevel AND f.userid = :userid",
            ['contextlevel' => CONTEXT_COURSE, 'userid' => $userid]
        );

        // Student pulse snapshots.
        $contextlist->add_from_sql(
            "SELECT DISTINCT ctx.id
               FROM {context} ctx
               JOIN {gradereport_coifish_student_pulse} p ON p.courseid = ctx.instanceid
              WHERE ctx.contextlevel = :contextlevel AND p.userid = :userid",
            ['contextlevel' => CONTEXT_COURSE, 'userid' => $userid]
        );

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist to add users to.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }
        $courseid = $context->instanceid;

        // Teachers who logged interventions.
        $userlist->add_from_sql(
            'teacherid',
            "SELECT DISTINCT teacherid FROM {gradereport_coifish_intv} WHERE courseid = :courseid",
            ['courseid' => $courseid]
        );

        // Students who were subjects of interventions.
        $userlist->add_from_sql(
            'studentid',
            "SELECT DISTINCT s.studentid
               FROM {gradereport_coifish_intv_stu} s
               JOIN {gradereport_coifish_intv} i ON i.id = s.interventionid
              WHERE i.courseid = :courseid",
            ['courseid' => $courseid]
        );

        // Teachers with feedback metrics.
        $userlist->add_from_sql(
            'userid',
            "SELECT DISTINCT userid FROM {gradereport_coifish_feedback} WHERE courseid = :courseid",
            ['courseid' => $courseid]
        );

        // Students with pulse snapshots.
        $userlist->add_from_sql(
            'userid',
            "SELECT DISTINCT userid FROM {gradereport_coifish_student_pulse} WHERE courseid = :courseid",
            ['courseid' => $courseid]
        );
    }

    /**
     * Export all user data for the specified approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export for.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_COURSE) {
                continue;
            }
            $courseid = $context->instanceid;
            $subcontext = [get_string('pluginname', 'gradereport_coifish')];

            // Export interventions logged by this teacher.
            $interventions = $DB->get_records('gradereport_coifish_intv', [
                'courseid' => $courseid,
                'teacherid' => $userid,
            ]);
            if ($interventions) {
                $exportdata = [];
                foreach ($interventions as $intv) {
                    $students = $DB->get_records('gradereport_coifish_intv_stu', [
                        'interventionid' => $intv->id,
                    ]);
                    $outcomes = [];
                    foreach ($students as $stu) {
                        $outs = $DB->get_records('gradereport_coifish_intv_out', [
                            'intvstudentid' => $stu->id,
                        ]);
                        $outcomes[$stu->id] = array_values($outs);
                    }
                    $exportdata[] = [
                        'diagnostictype' => $intv->diagnostictype,
                        'scope' => $intv->scope,
                        'actiontype' => $intv->actiontype,
                        'customaction' => $intv->customaction,
                        'notes' => $intv->notes,
                        'timecreated' => \core_privacy\local\request\transform::datetime($intv->timecreated),
                        'students' => array_values($students),
                        'outcomes' => $outcomes,
                    ];
                }
                writer::with_context($context)->export_data(
                    array_merge($subcontext, ['interventions_logged']),
                    (object)['interventions' => $exportdata]
                );
            }

            // Export intervention records where this user was the student.
            $studentrecords = $DB->get_records_sql(
                "SELECT s.*, i.diagnostictype, i.scope, i.actiontype, i.timecreated AS intv_time
                   FROM {gradereport_coifish_intv_stu} s
                   JOIN {gradereport_coifish_intv} i ON i.id = s.interventionid
                  WHERE i.courseid = :courseid AND s.studentid = :userid",
                ['courseid' => $courseid, 'userid' => $userid]
            );
            if ($studentrecords) {
                $exportstu = [];
                foreach ($studentrecords as $rec) {
                    $outs = $DB->get_records('gradereport_coifish_intv_out', [
                        'intvstudentid' => $rec->id,
                    ]);
                    $exportstu[] = [
                        'diagnostictype' => $rec->diagnostictype,
                        'actiontype' => $rec->actiontype,
                        'snap_grade' => $rec->snap_grade,
                        'snap_engagement' => $rec->snap_engagement,
                        'snap_social' => $rec->snap_social,
                        'snap_feedbackpct' => $rec->snap_feedbackpct,
                        'snap_daysinactive' => $rec->snap_daysinactive,
                        'timecreated' => \core_privacy\local\request\transform::datetime($rec->intv_time),
                        'outcomes' => array_values($outs),
                    ];
                }
                writer::with_context($context)->export_data(
                    array_merge($subcontext, ['interventions_received']),
                    (object)['interventions' => $exportstu]
                );
            }

            // Export feedback quality metrics for this teacher.
            $feedback = $DB->get_records('gradereport_coifish_feedback', [
                'courseid' => $courseid,
                'userid' => $userid,
            ]);
            if ($feedback) {
                writer::with_context($context)->export_data(
                    array_merge($subcontext, ['feedback_metrics']),
                    (object)['feedback' => array_values($feedback)]
                );
            }

            // Export this student's pulse-dashboard snapshots.
            $pulse = $DB->get_records('gradereport_coifish_student_pulse', [
                'courseid' => $courseid,
                'userid' => $userid,
            ], 'periodstart');
            if ($pulse) {
                writer::with_context($context)->export_data(
                    array_merge($subcontext, ['pulse_dashboard']),
                    (object)['snapshots' => array_values($pulse)]
                );
            }
        }
    }

    /**
     * Export stored user preferences for a user.
     *
     * The pulse "last shown" and "muted" preferences are per-course, so their
     * names carry the course ID as a suffix; scan and export each.
     *
     * @param int $userid The user to export preferences for.
     */
    public static function export_user_preferences(int $userid): void {
        $prefs = get_user_preferences(null, null, $userid);
        if (!is_array($prefs)) {
            return;
        }
        foreach ($prefs as $name => $value) {
            if (strpos($name, \gradereport_coifish\pulse::PREF_LASTSHOWN) === 0) {
                writer::export_user_preference(
                    'gradereport_coifish',
                    $name,
                    $value,
                    get_string('privacy:metadata:preference:pulse_lastshown', 'gradereport_coifish')
                );
            } else if (strpos($name, \gradereport_coifish\pulse::PREF_MUTED) === 0) {
                writer::export_user_preference(
                    'gradereport_coifish',
                    $name,
                    $value,
                    get_string('privacy:metadata:preference:pulse_muted', 'gradereport_coifish')
                );
            }
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context The specific context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }
        $courseid = $context->instanceid;

        // Delete outcome records, then student records, then interventions.
        $interventionids = $DB->get_fieldset_select('gradereport_coifish_intv', 'id', 'courseid = ?', [$courseid]);
        if ($interventionids) {
            [$insql, $inparams] = $DB->get_in_or_equal($interventionids);
            $studentids = $DB->get_fieldset_select('gradereport_coifish_intv_stu', 'id', "interventionid $insql", $inparams);
            if ($studentids) {
                [$insql2, $inparams2] = $DB->get_in_or_equal($studentids);
                $DB->delete_records_select('gradereport_coifish_intv_out', "intvstudentid $insql2", $inparams2);
            }
            $DB->delete_records_select('gradereport_coifish_intv_stu', "interventionid $insql", $inparams);
        }
        $DB->delete_records('gradereport_coifish_intv', ['courseid' => $courseid]);
        $DB->delete_records('gradereport_coifish_feedback', ['courseid' => $courseid]);
        $DB->delete_records('gradereport_coifish_student_pulse', ['courseid' => $courseid]);
    }

    /**
     * Delete all user data for the specified user in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_COURSE) {
                continue;
            }
            $courseid = $context->instanceid;

            // Delete intervention student records where this user is the student.
            $studentrecordids = $DB->get_fieldset_sql(
                "SELECT s.id FROM {gradereport_coifish_intv_stu} s
                   JOIN {gradereport_coifish_intv} i ON i.id = s.interventionid
                  WHERE i.courseid = :courseid AND s.studentid = :userid",
                ['courseid' => $courseid, 'userid' => $userid]
            );
            if ($studentrecordids) {
                [$insql, $inparams] = $DB->get_in_or_equal($studentrecordids);
                $DB->delete_records_select('gradereport_coifish_intv_out', "intvstudentid $insql", $inparams);
                $DB->delete_records_select('gradereport_coifish_intv_stu', "id $insql", $inparams);
            }

            // Delete interventions logged by this teacher.
            $teacherinterventionids = $DB->get_fieldset_select(
                'gradereport_coifish_intv',
                'id',
                'courseid = ? AND teacherid = ?',
                [$courseid, $userid]
            );
            if ($teacherinterventionids) {
                [$insql, $inparams] = $DB->get_in_or_equal($teacherinterventionids);
                $stuids = $DB->get_fieldset_select('gradereport_coifish_intv_stu', 'id', "interventionid $insql", $inparams);
                if ($stuids) {
                    [$insql2, $inparams2] = $DB->get_in_or_equal($stuids);
                    $DB->delete_records_select('gradereport_coifish_intv_out', "intvstudentid $insql2", $inparams2);
                }
                $DB->delete_records_select('gradereport_coifish_intv_stu', "interventionid $insql", $inparams);
                $DB->delete_records_select('gradereport_coifish_intv', "id $insql", $inparams);
            }

            // Delete feedback metrics.
            $DB->delete_records('gradereport_coifish_feedback', [
                'courseid' => $courseid,
                'userid' => $userid,
            ]);

            // Delete pulse snapshots and the per-course pulse preferences.
            $DB->delete_records('gradereport_coifish_student_pulse', [
                'courseid' => $courseid,
                'userid' => $userid,
            ]);
            unset_user_preference(\gradereport_coifish\pulse::PREF_LASTSHOWN . $courseid, $userid);
            unset_user_preference(\gradereport_coifish\pulse::PREF_MUTED . $courseid, $userid);
        }
    }

    /**
     * Delete multiple users' data within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }
        $courseid = $context->instanceid;
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        // Delete student intervention records and outcomes.
        $studentrecordids = $DB->get_fieldset_sql(
            "SELECT s.id FROM {gradereport_coifish_intv_stu} s
               JOIN {gradereport_coifish_intv} i ON i.id = s.interventionid
              WHERE i.courseid = :courseid AND s.studentid $usersql",
            array_merge(['courseid' => $courseid], $userparams)
        );
        if ($studentrecordids) {
            [$insql, $inparams] = $DB->get_in_or_equal($studentrecordids);
            $DB->delete_records_select('gradereport_coifish_intv_out', "intvstudentid $insql", $inparams);
            $DB->delete_records_select('gradereport_coifish_intv_stu', "id $insql", $inparams);
        }

        // Delete interventions logged by these teachers.
        $teacherinterventionids = $DB->get_fieldset_sql(
            "SELECT id FROM {gradereport_coifish_intv}
              WHERE courseid = :courseid AND teacherid $usersql",
            array_merge(['courseid' => $courseid], $userparams)
        );
        if ($teacherinterventionids) {
            [$insql, $inparams] = $DB->get_in_or_equal($teacherinterventionids);
            $stuids = $DB->get_fieldset_select('gradereport_coifish_intv_stu', 'id', "interventionid $insql", $inparams);
            if ($stuids) {
                [$insql2, $inparams2] = $DB->get_in_or_equal($stuids);
                $DB->delete_records_select('gradereport_coifish_intv_out', "intvstudentid $insql2", $inparams2);
            }
            $DB->delete_records_select('gradereport_coifish_intv_stu', "interventionid $insql", $inparams);
            $DB->delete_records_select('gradereport_coifish_intv', "id $insql", $inparams);
        }

        // Delete feedback metrics.
        $DB->delete_records_select(
            'gradereport_coifish_feedback',
            "courseid = :courseid AND userid $usersql",
            array_merge(['courseid' => $courseid], $userparams)
        );

        // Delete pulse snapshots and the per-course pulse preferences.
        $DB->delete_records_select(
            'gradereport_coifish_student_pulse',
            "courseid = :courseid AND userid $usersql",
            array_merge(['courseid' => $courseid], $userparams)
        );
        foreach ($userids as $uid) {
            unset_user_preference(\gradereport_coifish\pulse::PREF_LASTSHOWN . $courseid, $uid);
            unset_user_preference(\gradereport_coifish\pulse::PREF_MUTED . $courseid, $uid);
        }
    }
}
