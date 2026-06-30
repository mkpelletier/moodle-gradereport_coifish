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
 * Course-level gamification settings for the Grade Tracker report.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');

$courseid = required_param('id', PARAM_INT);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('moodle/grade:viewall', $context);

$pageurl = new moodle_url('/grade/report/coifish/coursesettings.php', ['id' => $courseid]);
$returnurl = new moodle_url('/grade/report/coifish/index.php', ['id' => $courseid]);

$PAGE->set_url($pageurl);
$PAGE->set_title(get_string('coursesettings_title', 'gradereport_coifish', $course->shortname));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('admin');

// Load current course settings.
$configkey = 'course_' . $courseid;
$raw = get_config('gradereport_coifish', $configkey);
$coursesettings = $raw ? json_decode($raw, true) : [];

// Widget definitions — only show widgets that are enabled at site level.
$widgetkeys = [
    'overall', 'neighbours', 'improvement', 'trend', 'streak', 'milestones', 'feedback', 'consistency', 'earlybird',
    'coi_community', 'coi_peerconnection', 'coi_learningdepth', 'coi_feedbackloop',
];
$sitewidgets = [];
foreach ($widgetkeys as $key) {
    if (!empty(get_config('gradereport_coifish', 'widget_' . $key))) {
        $sitewidgets[$key] = get_string('widget_' . $key, 'gradereport_coifish');
    }
}

// Handle form submission.
if (data_submitted() && confirm_sesskey()) {
    $newsettings = [];

    $newsettings['defaultview'] = optional_param('defaultview', '', PARAM_ALPHA);
    $newsettings['widgetposition'] = optional_param('widgetposition', '', PARAM_ALPHA);
    $newsettings['show_insights'] = optional_param('show_insights', '', PARAM_ALPHANUMEXT);
    $newsettings['show_longitudinal'] = optional_param('show_longitudinal', '', PARAM_ALPHANUMEXT);
    $newsettings['gamification_enabled'] = (bool)optional_param('gamification_enabled', 0, PARAM_BOOL);

    // Student pulse dashboard (per-course trial toggle + frequency).
    // Tri-state ('' = use site default, '1' = on, '0' = off); blank interval
    // (0) likewise means "use the site default frequency".
    $newsettings['student_dashboard_enabled'] =
        optional_param('student_dashboard_enabled', '', PARAM_ALPHANUMEXT);
    $interval = optional_param('student_dashboard_interval_days', 0, PARAM_INT);
    $newsettings['student_dashboard_interval_days'] = ($interval > 0) ? min(120, max(1, $interval)) : '';

    // Widget overrides — only for site-enabled widgets.
    $newsettings['widgets'] = [];
    foreach (array_keys($sitewidgets) as $key) {
        $newsettings['widgets'][$key] = (bool)optional_param('widget_' . $key, 0, PARAM_BOOL);
    }

    set_config($configkey, json_encode($newsettings), 'gradereport_coifish');

    redirect(
        $returnurl,
        get_string('coursesettings_saved', 'gradereport_coifish'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coursesettings_title', 'gradereport_coifish', $course->shortname));

$renderable = new \gradereport_coifish\output\coursesettings(
    $courseid,
    $coursesettings,
    $sitewidgets,
    $pageurl,
    $returnurl
);
echo $OUTPUT->render_from_template('gradereport_coifish/coursesettings', $renderable->export_for_template($OUTPUT));

echo $OUTPUT->footer();
