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
 * Library functions for the Grade Tracker report.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Checks if the current user can view the Grade tracker report.
 *
 * @param context_course $context The course context.
 * @return bool True if the user can view the report.
 */
function gradereport_coifish_can_view_report(context_course $context): bool {
    return has_capability('gradereport/coifish:view', $context);
}

/**
 * Whether the current viewer may open one student's individual insights.
 *
 * Encodes the plugin's group-scope rule so every entry point to the individual
 * report (index.php drill-down and the profile-report hook) enforces it
 * identically: viewing yourself is always allowed; holders of
 * gradereport/coifish:viewallgroups see everyone; otherwise the target student
 * must share at least one of the viewer's groups. This mirrors the cohort
 * views' get_scoped_enrolled_users() scoping so the individual and cohort
 * surfaces never disagree about who a teacher may see.
 *
 * @param int $courseid The course ID.
 * @param int $userid The target student's user ID.
 * @param context_course $context The course context.
 * @return bool True if the viewer may see this student's individual report.
 */
function gradereport_coifish_viewer_can_see_user(int $courseid, int $userid, context_course $context): bool {
    global $USER;

    if ((int)$userid === (int)$USER->id) {
        return true;
    }
    if (has_capability('gradereport/coifish:viewallgroups', $context)) {
        return true;
    }
    $viewergroups = array_map('intval', groups_get_user_groups($courseid, $USER->id)[0] ?? []);
    $targetgroups = array_map('intval', groups_get_user_groups($courseid, $userid)[0] ?? []);
    return !empty(array_intersect($viewergroups, $targetgroups));
}

/**
 * Profile report callback. Renders the Grade tracker on the user profile.
 *
 * @param object $course The course object.
 * @param object $user The user object.
 * @param bool $viewasuser True when viewing as the target user.
 */
function grade_report_coifish_profilereport(object $course, object $user, bool $viewasuser = false): void {
    global $OUTPUT;

    if (empty($course->showgrades)) {
        return;
    }

    $context = context_course::instance($course->id);

    // Respect the plugin's group scope: don't render an out-of-group student's
    // insights to a teacher who is restricted to their own groups. (Self-view
    // and viewallgroups holders pass through.)
    if (!gradereport_coifish_viewer_can_see_user((int)$course->id, (int)$user->id, $context)) {
        return;
    }
    $gpr = new grade_plugin_return([
        'type' => 'report',
        'plugin' => 'coifish',
        'courseid' => $course->id,
        'userid' => $user->id,
    ]);

    grade_regrade_final_grades($course->id);

    $report = new \gradereport_coifish\report($course->id, $gpr, $context, $user->id);
    $studentreport = new \gradereport_coifish\output\student_report($report, false);
    echo $OUTPUT->render_from_template(
        'gradereport_coifish/student_report',
        $studentreport->export_for_template($OUTPUT)
    );
}

/**
 * Whether the cohort "Insights" surface is enabled for a course.
 *
 * Single source of truth for the tri-state rule shared by every surface that
 * shows insights: a per-course override (the course_<id> JSON blob, key
 * 'show_insights' = '1' | '0' | '') takes precedence; when unset ('') it falls
 * back to the site default, preserving that default's "unset means on"
 * convention. Centralising it here keeps the summary view, the student view and
 * the course-navigation node from drifting apart — the same motivation behind
 * gradereport_coifish_viewer_can_see_user() above.
 *
 * @param int $courseid The course ID.
 * @return bool True if the insights surface should be shown.
 */
function gradereport_coifish_insights_enabled(int $courseid): bool {
    $raw = get_config('gradereport_coifish', 'course_' . $courseid);
    $coursesettings = $raw ? (json_decode($raw, true) ?: []) : [];
    $courseoverride = $coursesettings['show_insights'] ?? '';
    if ($courseoverride !== '') {
        return ($courseoverride === '1');
    }
    $siteinsights = get_config('gradereport_coifish', 'show_insights');
    return ($siteinsights === false || $siteinsights !== '0');
}

/**
 * Add a "CoIFish Insights" node to the course navigation ("More" menu).
 *
 * Surfaces the teacher cohort-insights view — otherwise reachable only by
 * opening the CoIFish grade report and switching to the Insights tab — as a
 * one-click course-level entry point. Shown only to graders
 * (moodle/grade:viewall) when the insights surface is enabled for the course,
 * so the node never advertises a destination the viewer cannot use. Hosted in
 * the report plugin itself (not local_coifish) so the report owns its own
 * navigation and the node does not depend on an optional sibling plugin being
 * installed. The callback does nothing but two cheap checks — it never
 * instantiates the report — because it runs on every course page load.
 *
 * @param navigation_node $navigation The course navigation node.
 * @param stdClass $course The course.
 * @param context_course $context The course context.
 */
function gradereport_coifish_extend_navigation_course(
    navigation_node $navigation,
    stdClass $course,
    context_course $context
): void {
    if (!has_capability('moodle/grade:viewall', $context)) {
        return;
    }
    if (!gradereport_coifish_insights_enabled((int)$course->id)) {
        return;
    }

    $url = new moodle_url('/grade/report/coifish/index.php', [
        'id' => $course->id,
        'view' => 'insights',
    ]);
    $navigation->add(
        get_string('insights_nav', 'gradereport_coifish'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'gradereport_coifish_insights',
        new pix_icon('i/report', '')
    );
}
