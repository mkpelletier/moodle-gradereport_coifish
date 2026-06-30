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
 * Renderable for the course-level settings form.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gradereport_coifish\output;

use renderable;
use templatable;
use renderer_base;
use moodle_url;

/**
 * Renderable that prepares course settings form data for the mustache template.
 */
class coursesettings implements renderable, templatable {
    /** @var int The course ID. */
    protected int $courseid;

    /** @var array Current course settings. */
    protected array $coursesettings;

    /** @var array Site-enabled widgets (key => label). */
    protected array $sitewidgets;

    /** @var moodle_url The form action URL. */
    protected moodle_url $pageurl;

    /** @var moodle_url The return/cancel URL. */
    protected moodle_url $returnurl;

    /**
     * Constructor.
     *
     * @param int $courseid The course ID.
     * @param array $coursesettings Current course settings.
     * @param array $sitewidgets Site-enabled widgets.
     * @param moodle_url $pageurl Form action URL.
     * @param moodle_url $returnurl Cancel/return URL.
     */
    public function __construct(
        int $courseid,
        array $coursesettings,
        array $sitewidgets,
        moodle_url $pageurl,
        moodle_url $returnurl
    ) {
        $this->courseid = $courseid;
        $this->coursesettings = $coursesettings;
        $this->sitewidgets = $sitewidgets;
        $this->pageurl = $pageurl;
        $this->returnurl = $returnurl;
    }

    /**
     * Export data for the mustache template.
     *
     * @param renderer_base $output The renderer.
     * @return \stdClass Template data.
     */
    public function export_for_template(renderer_base $output): \stdClass {
        $data = new \stdClass();
        $data->actionurl = $this->pageurl->out(true);
        $data->returnurl = $this->returnurl->out(true);
        $data->sesskey = sesskey();

        $currentdefaultview = $this->coursesettings['defaultview'] ?? '';
        $currentwidgetposition = $this->coursesettings['widgetposition'] ?? '';
        $currentshowinsights = $this->coursesettings['show_insights'] ?? '';
        $currentshowlongitudinal = $this->coursesettings['show_longitudinal'] ?? '';
        $gamificationenabled = $this->coursesettings['gamification_enabled'] ?? false;
        $widgetoverrides = $this->coursesettings['widgets'] ?? [];

        // Default view options.
        $data->viewoptions = [
            ['value' => '', 'label' => get_string('defaultview_usesite', 'gradereport_coifish'),
             'selected' => ($currentdefaultview === '')],
            ['value' => 'table', 'label' => get_string('defaultview_table', 'gradereport_coifish'),
             'selected' => ($currentdefaultview === 'table')],
            ['value' => 'progress', 'label' => get_string('defaultview_progress', 'gradereport_coifish'),
             'selected' => ($currentdefaultview === 'progress')],
        ];

        // Widget position options.
        $data->positionoptions = [
            ['value' => '', 'label' => get_string('defaultview_usesite', 'gradereport_coifish'),
             'selected' => ($currentwidgetposition === '')],
            ['value' => 'top', 'label' => get_string('widgetposition_top', 'gradereport_coifish'),
             'selected' => ($currentwidgetposition === 'top')],
            ['value' => 'bottom', 'label' => get_string('widgetposition_bottom', 'gradereport_coifish'),
             'selected' => ($currentwidgetposition === 'bottom')],
        ];

        // Insights options.
        $data->insightsoptions = [
            ['value' => '', 'label' => get_string('defaultview_usesite', 'gradereport_coifish'),
             'selected' => ($currentshowinsights === '')],
            ['value' => '1', 'label' => get_string('setting_enabled', 'gradereport_coifish'),
             'selected' => ($currentshowinsights === '1')],
            ['value' => '0', 'label' => get_string('setting_disabled', 'gradereport_coifish'),
             'selected' => ($currentshowinsights === '0')],
        ];

        // Longitudinal options.
        $data->showlongitudinal = class_exists('\local_coifish\api');
        $data->longitudinaloptions = [
            ['value' => '', 'label' => get_string('defaultview_usesite', 'gradereport_coifish'),
             'selected' => ($currentshowlongitudinal === '')],
            ['value' => '1', 'label' => get_string('setting_enabled', 'gradereport_coifish'),
             'selected' => ($currentshowlongitudinal === '1')],
            ['value' => '0', 'label' => get_string('setting_disabled', 'gradereport_coifish'),
             'selected' => ($currentshowlongitudinal === '0')],
        ];

        // Gamification toggle.
        $data->gamificationenabled = (bool)$gamificationenabled;

        // Student pulse dashboard (per-course trial toggle + frequency).
        $enabledval = $this->coursesettings['student_dashboard_enabled'] ?? '';
        if ($enabledval === true) {
            $enabledval = '1';
        } else if ($enabledval === false) {
            $enabledval = '0';
        }
        $data->dashboardoptions = [
            ['value' => '', 'label' => get_string('defaultview_usesite', 'gradereport_coifish'),
                'selected' => ($enabledval === '')],
            ['value' => '1', 'label' => get_string('setting_enabled', 'gradereport_coifish'),
                'selected' => ($enabledval === '1')],
            ['value' => '0', 'label' => get_string('setting_disabled', 'gradereport_coifish'),
                'selected' => ($enabledval === '0')],
        ];
        $di = $this->coursesettings['student_dashboard_interval_days'] ?? '';
        $data->studentdashboardinterval = (is_numeric($di) && (int)$di > 0) ? (int)$di : '';
        $siteinterval = (int)get_config('gradereport_coifish', 'student_dashboard_interval');
        $data->sitedashboardinterval = $siteinterval > 0
            ? $siteinterval
            : \gradereport_coifish\pulse::DEFAULT_INTERVAL_DAYS;

        // Widget overrides.
        $data->haswidgets = !empty($this->sitewidgets);
        $data->widgets = [];
        foreach ($this->sitewidgets as $key => $label) {
            $checked = $widgetoverrides[$key] ?? true;
            $data->widgets[] = [
                'key' => $key,
                'label' => $label,
                'checked' => (bool)$checked,
            ];
        }

        return $data;
    }
}
