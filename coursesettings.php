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
    $newsettings['gamification_enabled'] = (bool)optional_param('gamification_enabled', 0, PARAM_BOOL);

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

// Determine current values.
$currentdefaultview = $coursesettings['defaultview'] ?? '';
$currentwidgetposition = $coursesettings['widgetposition'] ?? '';
$gamificationenabled = $coursesettings['gamification_enabled'] ?? false;
$widgetoverrides = $coursesettings['widgets'] ?? [];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coursesettings_title', 'gradereport_coifish', $course->shortname));

echo '<form method="post" action="' . $pageurl->out(true) . '">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';

// Default view selector.
echo '<div class="form-group row mb-3">';
echo '  <label class="col-md-3 col-form-label" for="defaultview"><strong>';
echo       get_string('coursesettings_defaultview', 'gradereport_coifish');
echo '  </strong></label>';
echo '  <div class="col-md-6">';
echo '    <select class="form-select" id="defaultview" name="defaultview">';
$viewoptions = [
    '' => get_string('defaultview_usesite', 'gradereport_coifish'),
    'table' => get_string('defaultview_table', 'gradereport_coifish'),
    'progress' => get_string('defaultview_progress', 'gradereport_coifish'),
];
foreach ($viewoptions as $val => $label) {
    $sel = ($currentdefaultview === $val) ? ' selected' : '';
    echo '      <option value="' . $val . '"' . $sel . '>' . $label . '</option>';
}
echo '    </select>';
echo '    <small class="form-text text-muted">';
echo         get_string('coursesettings_defaultview_desc', 'gradereport_coifish');
echo '    </small>';
echo '  </div>';
echo '</div>';

// Widget position selector.
echo '<div class="form-group row mb-3">';
echo '  <label class="col-md-3 col-form-label" for="widgetposition"><strong>';
echo       get_string('coursesettings_widgetposition', 'gradereport_coifish');
echo '  </strong></label>';
echo '  <div class="col-md-6">';
echo '    <select class="form-select" id="widgetposition" name="widgetposition">';
$positionoptions = [
    '' => get_string('defaultview_usesite', 'gradereport_coifish'),
    'top' => get_string('widgetposition_top', 'gradereport_coifish'),
    'bottom' => get_string('widgetposition_bottom', 'gradereport_coifish'),
];
foreach ($positionoptions as $val => $label) {
    $sel = ($currentwidgetposition === $val) ? ' selected' : '';
    echo '      <option value="' . $val . '"' . $sel . '>' . $label . '</option>';
}
echo '    </select>';
echo '    <small class="form-text text-muted">';
echo         get_string('coursesettings_widgetposition_desc', 'gradereport_coifish');
echo '    </small>';
echo '  </div>';
echo '</div>';

// Gamification master toggle.
echo '<div class="form-group row mb-3">';
echo '  <div class="col-md-9">';
echo '    <div class="form-check form-switch">';
echo '      <input type="hidden" name="gamification_enabled" value="0">';
echo '      <input type="checkbox" class="form-check-input" id="gamification_enabled" ';
echo '             name="gamification_enabled" value="1"' . ($gamificationenabled ? ' checked' : '') . '>';
echo '      <label class="form-check-label" for="gamification_enabled">';
echo           get_string('coursesettings_gamification_enabled', 'gradereport_coifish');
echo '      </label>';
echo '    </div>';
echo '    <small class="form-text text-muted">';
echo         get_string('coursesettings_gamification_enabled_desc', 'gradereport_coifish');
echo '    </small>';
echo '  </div>';
echo '</div>';

// Widget selection.
if (!empty($sitewidgets)) {
    echo '<div class="form-group row mb-3">';
    echo '  <label class="col-md-12 mb-2"><strong>';
    echo       get_string('coursesettings_widget_override', 'gradereport_coifish');
    echo '  </strong></label>';
    echo '  <div class="col-md-9">';
    echo '    <small class="form-text text-muted mb-2 d-block">';
    echo         get_string('coursesettings_widget_override_desc', 'gradereport_coifish');
    echo '    </small>';

    foreach ($sitewidgets as $key => $label) {
        $checked = $widgetoverrides[$key] ?? true; // Default to on if no override.
        echo '    <div class="form-check mb-1">';
        echo '      <input type="hidden" name="widget_' . $key . '" value="0">';
        echo '      <input type="checkbox" class="form-check-input" id="widget_' . $key . '" ';
        echo '             name="widget_' . $key . '" value="1"' . ($checked ? ' checked' : '') . '>';
        echo '      <label class="form-check-label" for="widget_' . $key . '">' . $label . '</label>';
        echo '    </div>';
    }

    echo '  </div>';
    echo '</div>';
}

echo '<div class="form-group row">';
echo '  <div class="col-md-9">';
echo '    <button type="submit" class="btn btn-primary">' . get_string('savechanges') . '</button>';
echo '    <a href="' . $returnurl->out(true) . '" class="btn btn-secondary ms-2">' . get_string('cancel') . '</a>';
echo '  </div>';
echo '</div>';

echo '</form>';
echo $OUTPUT->footer();
