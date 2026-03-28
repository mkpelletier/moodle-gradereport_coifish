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
