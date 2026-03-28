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
 * Main entry point for the Grade Tracker report.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/grade/lib.php');

$courseid      = required_param('id', PARAM_INT);
$userid        = optional_param('userid', 0, PARAM_INT);
$groupid       = optional_param('group', 0, PARAM_INT);
$showhidden    = optional_param('showhidden', 0, PARAM_BOOL);
$runningtotal  = optional_param('runningtotal', 1, PARAM_BOOL);
$togglewidgets = optional_param('togglewidgets', 0, PARAM_BOOL);
$viewparam     = optional_param('view', '', PARAM_ALPHA);

// Authentication and authorisation.
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('gradereport/coifish:view', $context);

// Handle widget display toggle (teacher action — flips gamification_enabled and redirects).
if ($togglewidgets && has_capability('moodle/grade:viewall', $context)) {
    require_sesskey();
    $configkey = 'course_' . $courseid;
    $raw = get_config('gradereport_coifish', $configkey);
    $settings = $raw ? (json_decode($raw, true) ?: []) : [];
    $settings['gamification_enabled'] = empty($settings['gamification_enabled']);
    set_config($configkey, json_encode($settings), 'gradereport_coifish');

    $redirecturl = new moodle_url('/grade/report/coifish/index.php', ['id' => $courseid]);
    if ($userid) {
        $redirecturl->param('userid', $userid);
    }
    if ($groupid) {
        $redirecturl->param('group', $groupid);
    }
    redirect($redirecturl);
}

// Set up the page.
$pageurl = new moodle_url('/grade/report/coifish/index.php', ['id' => $courseid]);
if ($userid) {
    $pageurl->param('userid', $userid);
}
if ($groupid) {
    $pageurl->param('group', $groupid);
}
if ($showhidden) {
    $pageurl->param('showhidden', 1);
}
if ($runningtotal) {
    $pageurl->param('runningtotal', 1);
}

$PAGE->set_url($pageurl);
$PAGE->set_title(get_string('pluginname', 'gradereport_coifish'));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('report');

// Ensure grades are up to date.
grade_regrade_final_grades($courseid);

// Grade plugin return tracking.
$gpr = new grade_plugin_return([
    'type' => 'report',
    'plugin' => 'coifish',
    'courseid' => $courseid,
    'userid' => $userid,
]);

// Determine if the user can view all grades (teacher view) or only their own (student view).
$canviewall = has_capability('moodle/grade:viewall', $context);

// Coordinator tab: requires both the capability and the site setting.
$coordinatorenabled = get_config('gradereport_coifish', 'coordinator_enabled');
$canviewcoordinator = $coordinatorenabled && has_capability('gradereport/coifish:viewcoordinator', $context);

if (!$canviewall) {
    // Student view: force to own user ID, ignore group parameter.
    $userid = $USER->id;
    $groupid = 0;
    // Students cannot access coordinator view.
    if ($viewparam === 'coordinator') {
        $viewparam = '';
    }
}

// Instantiate the report.
$report = new \gradereport_coifish\report($courseid, $gpr, $context, $userid, $groupid, $showhidden);

// Start output.
echo $OUTPUT->header();

// Teacher view: show action bar.
if ($canviewall) {
    $actionbar = new \gradereport_coifish\output\action_bar(
        $context,
        $courseid,
        $userid,
        $groupid,
        $viewparam,
        $canviewcoordinator
    );
    echo $OUTPUT->render_from_template(
        'gradereport_coifish/action_bar',
        $actionbar->export_for_template($OUTPUT)
    );
}

// Render the appropriate view.
if ($viewparam === 'coordinator' && $canviewcoordinator && $canviewall) {
    // Coordinator view: teacher engagement analytics.
    $coordinatorreport = new \gradereport_coifish\output\coordinator_report($report);
    echo $OUTPUT->render_from_template(
        'gradereport_coifish/coordinator_report',
        $coordinatorreport->export_for_template($OUTPUT)
    );
} else if ($userid > 0) {
    // Single-user detailed report (student view, or teacher drill-down).
    $canviewhidden = has_capability('moodle/grade:viewhidden', $context);
    $studentreport = new \gradereport_coifish\output\student_report(
        $report,
        $canviewall,
        $canviewhidden,
        $showhidden,
        $runningtotal,
        $viewparam
    );
    echo $OUTPUT->render_from_template(
        'gradereport_coifish/student_report',
        $studentreport->export_for_template($OUTPUT)
    );
} else if ($canviewall) {
    // Teacher summary view: show all students.
    $summaryreport = new \gradereport_coifish\output\summary_report($report, $viewparam);
    echo $OUTPUT->render_from_template(
        'gradereport_coifish/summary_report',
        $summaryreport->export_for_template($OUTPUT)
    );
}

// Log the event.
$event = \gradereport_coifish\event\grade_report_viewed::create([
    'context' => $context,
    'courseid' => $courseid,
    'relateduserid' => $userid ?: null,
]);
$event->trigger();

echo $OUTPUT->footer();
