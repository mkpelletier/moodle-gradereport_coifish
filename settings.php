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
 * Site-level settings for the Grade Tracker report.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/classes/admin_setting_configslider.php');
require_once(__DIR__ . '/classes/admin_setting_configoptionalslider.php');
require_once(__DIR__ . '/classes/admin_setting_configlevels.php');

if ($ADMIN->fulltree) {
    // Default view.
    $settings->add(new admin_setting_configselect(
        'gradereport_coifish/defaultview',
        get_string('defaultview', 'gradereport_coifish'),
        get_string('defaultview_desc', 'gradereport_coifish'),
        'table',
        [
            'table' => get_string('defaultview_table', 'gradereport_coifish'),
            'progress' => get_string('defaultview_progress', 'gradereport_coifish'),
        ]
    ));

    // Widget position.
    $settings->add(new admin_setting_configselect(
        'gradereport_coifish/widgetposition',
        get_string('widgetposition', 'gradereport_coifish'),
        get_string('widgetposition_desc', 'gradereport_coifish'),
        'top',
        [
            'top' => get_string('widgetposition_top', 'gradereport_coifish'),
            'bottom' => get_string('widgetposition_bottom', 'gradereport_coifish'),
        ]
    ));

    // Grade thresholds heading.
    $settings->add(new admin_setting_heading(
        'gradereport_coifish/thresholdsheading',
        get_string('thresholds', 'gradereport_coifish'),
        get_string('thresholds_desc', 'gradereport_coifish')
    ));

    // Pass threshold.
    $settings->add(new gradereport_coifish_admin_setting_configslider(
        'gradereport_coifish/threshold_pass',
        get_string('threshold_pass', 'gradereport_coifish'),
        get_string('threshold_pass_desc', 'gradereport_coifish'),
        50,
        0,
        100,
        1,
        '%'
    ));

    // Merit threshold (optional — uncheck to disable).
    $settings->add(new gradereport_coifish_admin_setting_configoptionalslider(
        'gradereport_coifish/threshold_merit',
        get_string('threshold_merit', 'gradereport_coifish'),
        get_string('threshold_merit_desc', 'gradereport_coifish'),
        '',
        0,
        100,
        1,
        '%',
        65
    ));

    // Distinction threshold (optional — uncheck to disable).
    $settings->add(new gradereport_coifish_admin_setting_configoptionalslider(
        'gradereport_coifish/threshold_distinction',
        get_string('threshold_distinction', 'gradereport_coifish'),
        get_string('threshold_distinction_desc', 'gradereport_coifish'),
        '',
        0,
        100,
        1,
        '%',
        75
    ));

    // Gamification widgets heading.
    $settings->add(new admin_setting_heading(
        'gradereport_coifish/gamificationheading',
        get_string('gamification', 'gradereport_coifish'),
        get_string('gamification_desc', 'gradereport_coifish')
    ));

    // Minimum enrolment for competitive widgets.
    $settings->add(new gradereport_coifish_admin_setting_configslider(
        'gradereport_coifish/leaderboard_min_enrolment',
        get_string('leaderboard_min_enrolment', 'gradereport_coifish'),
        get_string('leaderboard_min_enrolment_desc', 'gradereport_coifish'),
        10,
        2,
        100,
        1,
        ''
    ));

    // Overall percentile widget.
    $settings->add(new admin_setting_configcheckbox(
        'gradereport_coifish/widget_overall',
        get_string('widget_overall', 'gradereport_coifish'),
        get_string('widget_overall_desc', 'gradereport_coifish'),
        0
    ));

    // Nearest neighbour widget.
    $settings->add(new admin_setting_configcheckbox(
        'gradereport_coifish/widget_neighbours',
        get_string('widget_neighbours', 'gradereport_coifish'),
        get_string('widget_neighbours_desc', 'gradereport_coifish'),
        0
    ));

    // Improvement rank widget.
    $settings->add(new admin_setting_configcheckbox(
        'gradereport_coifish/widget_improvement',
        get_string('widget_improvement', 'gradereport_coifish'),
        get_string('widget_improvement_desc', 'gradereport_coifish'),
        0
    ));

    // Personal trend widget.
    $settings->add(new admin_setting_configcheckbox(
        'gradereport_coifish/widget_trend',
        get_string('widget_trend', 'gradereport_coifish'),
        get_string('widget_trend_desc', 'gradereport_coifish'),
        1
    ));

    // Streak tracker widget.
    $settings->add(new admin_setting_configcheckbox(
        'gradereport_coifish/widget_streak',
        get_string('widget_streak', 'gradereport_coifish'),
        get_string('widget_streak_desc', 'gradereport_coifish'),
        1
    ));

    // Milestone badges widget.
    $settings->add(new admin_setting_configcheckbox(
        'gradereport_coifish/widget_milestones',
        get_string('widget_milestones', 'gradereport_coifish'),
        get_string('widget_milestones_desc', 'gradereport_coifish'),
        1
    ));

    // Feedback engagement widget.
    $settings->add(new admin_setting_configcheckbox(
        'gradereport_coifish/widget_feedback',
        get_string('widget_feedback', 'gradereport_coifish'),
        get_string('widget_feedback_desc', 'gradereport_coifish'),
        1
    ));

    // Consistency tracker widget.
    $settings->add(new admin_setting_configcheckbox(
        'gradereport_coifish/widget_consistency',
        get_string('widget_consistency', 'gradereport_coifish'),
        get_string('widget_consistency_desc', 'gradereport_coifish'),
        1
    ));

    // Self-regulation widget.
    $settings->add(new admin_setting_configcheckbox(
        'gradereport_coifish/widget_selfregulation',
        get_string('widget_selfregulation', 'gradereport_coifish'),
        get_string('widget_selfregulation_desc', 'gradereport_coifish'),
        1
    ));

    // Early bird widget.
    $settings->add(new admin_setting_configcheckbox(
        'gradereport_coifish/widget_earlybird',
        get_string('widget_earlybird', 'gradereport_coifish'),
        get_string('widget_earlybird_desc', 'gradereport_coifish'),
        1
    ));

    // Analytics thresholds heading.
    $settings->add(new admin_setting_heading(
        'gradereport_coifish/analyticsheading',
        get_string('analytics', 'gradereport_coifish'),
        get_string('analytics_desc', 'gradereport_coifish')
    ));

    // Stale activity threshold (days).
    $settings->add(new gradereport_coifish_admin_setting_configslider(
        'gradereport_coifish/stale_days',
        get_string('setting_stale_days', 'gradereport_coifish'),
        get_string('setting_stale_days_desc', 'gradereport_coifish'),
        14,
        3,
        60,
        1,
        ' days'
    ));

    // COI presence level boundaries.
    $settings->add(new gradereport_coifish_admin_setting_configlevels(
        'gradereport_coifish/coi_levels_sp',
        get_string('setting_coi_levels_sp', 'gradereport_coifish'),
        get_string('setting_coi_levels_sp_desc', 'gradereport_coifish'),
        '1,20,50,80'
    ));

    $settings->add(new gradereport_coifish_admin_setting_configlevels(
        'gradereport_coifish/coi_levels_cp',
        get_string('setting_coi_levels_cp', 'gradereport_coifish'),
        get_string('setting_coi_levels_cp_desc', 'gradereport_coifish'),
        '1,20,50,80'
    ));

    $settings->add(new gradereport_coifish_admin_setting_configlevels(
        'gradereport_coifish/coi_levels_tp',
        get_string('setting_coi_levels_tp', 'gradereport_coifish'),
        get_string('setting_coi_levels_tp_desc', 'gradereport_coifish'),
        '1,25,75,100'
    ));

    $settings->add(new gradereport_coifish_admin_setting_configlevels(
        'gradereport_coifish/coi_levels_peer',
        get_string('setting_coi_levels_peer', 'gradereport_coifish'),
        get_string('setting_coi_levels_peer_desc', 'gradereport_coifish'),
        '1,15,40,70'
    ));

    // Cohort diagnostic sensitivity.
    $settings->add(new admin_setting_configselect(
        'gradereport_coifish/diagnostic_sensitivity',
        get_string('setting_diagnostic_sensitivity', 'gradereport_coifish'),
        get_string('setting_diagnostic_sensitivity_desc', 'gradereport_coifish'),
        'normal',
        [
            'low' => get_string('setting_sensitivity_low', 'gradereport_coifish'),
            'normal' => get_string('setting_sensitivity_normal', 'gradereport_coifish'),
            'high' => get_string('setting_sensitivity_high', 'gradereport_coifish'),
        ]
    ));

    // Cohort visualisations heading.
    $settings->add(new admin_setting_heading(
        'gradereport_coifish/visualisationsheading',
        get_string('visualisations', 'gradereport_coifish'),
        get_string('visualisations_desc', 'gradereport_coifish')
    ));

    // S3 risk quadrant scatter graph.
    $settings->add(new admin_setting_configcheckbox(
        'gradereport_coifish/show_riskquadrant',
        get_string('setting_riskquadrant', 'gradereport_coifish'),
        get_string('setting_riskquadrant_desc', 'gradereport_coifish'),
        1
    ));

    // Forum sociogram.
    $settings->add(new admin_setting_configcheckbox(
        'gradereport_coifish/show_sociogram',
        get_string('setting_sociogram', 'gradereport_coifish'),
        get_string('setting_sociogram_desc', 'gradereport_coifish'),
        1
    ));

    // Community of Inquiry (COI) widgets heading.
    $settings->add(new admin_setting_heading(
        'gradereport_coifish/coiheading',
        get_string('coi', 'gradereport_coifish'),
        get_string('coi_desc', 'gradereport_coifish')
    ));

    // COI: Community engagement (social presence).
    $settings->add(new admin_setting_configcheckbox(
        'gradereport_coifish/widget_coi_community',
        get_string('widget_coi_community', 'gradereport_coifish'),
        get_string('widget_coi_community_desc', 'gradereport_coifish'),
        0
    ));

    // COI: Peer connection (social presence).
    $settings->add(new admin_setting_configcheckbox(
        'gradereport_coifish/widget_coi_peerconnection',
        get_string('widget_coi_peerconnection', 'gradereport_coifish'),
        get_string('widget_coi_peerconnection_desc', 'gradereport_coifish'),
        0
    ));

    // COI: Learning depth (cognitive presence).
    $settings->add(new admin_setting_configcheckbox(
        'gradereport_coifish/widget_coi_learningdepth',
        get_string('widget_coi_learningdepth', 'gradereport_coifish'),
        get_string('widget_coi_learningdepth_desc', 'gradereport_coifish'),
        0
    ));

    // COI: Feedback loop (teaching presence).
    $settings->add(new admin_setting_configcheckbox(
        'gradereport_coifish/widget_coi_feedbackloop',
        get_string('widget_coi_feedbackloop', 'gradereport_coifish'),
        get_string('widget_coi_feedbackloop_desc', 'gradereport_coifish'),
        0
    ));

    // Coordinator tab heading.
    $settings->add(new admin_setting_heading(
        'gradereport_coifish/coordinatorheading',
        get_string('coordinator', 'gradereport_coifish'),
        get_string('coordinator_desc', 'gradereport_coifish')
    ));

    // Enable coordinator tab.
    $settings->add(new admin_setting_configcheckbox(
        'gradereport_coifish/coordinator_enabled',
        get_string('coordinator_enabled', 'gradereport_coifish'),
        get_string('coordinator_enabled_desc', 'gradereport_coifish'),
        0
    ));
}
