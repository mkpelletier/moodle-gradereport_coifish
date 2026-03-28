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
 * Renderable for the teacher summary view (all students).
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gradereport_coifish\output;

use renderable;
use templatable;
use renderer_base;
use gradereport_coifish\report;

/**
 * Renderable that prepares summary data for the all-students mustache template.
 */
class summary_report implements renderable, templatable {
    /** @var report The report instance. */
    protected report $report;

    /** @var string URL-requested view override. */
    protected string $viewoverride;

    /**
     * Constructor.
     *
     * @param report $report The report instance.
     * @param string $viewoverride URL-requested view override (e.g. 'insights').
     */
    public function __construct(report $report, string $viewoverride = '') {
        $this->report = $report;
        $this->viewoverride = $viewoverride;
    }

    /**
     * Export data for the mustache template.
     *
     * @param renderer_base $output The renderer.
     * @return \stdClass Template data.
     */
    public function export_for_template(renderer_base $output): \stdClass {
        $data = new \stdClass();

        $summary = $this->report->get_summary_data();

        $data->hasusers = !empty($summary);
        $data->users = $summary;
        $data->usercount = count($summary);

        // Cohort-level insights for the teacher.
        $data->cohortinsights = $this->report->get_cohort_insights_data();

        // Cross-group and cross-teacher comparisons.
        $data->cohortinsights['crossgroup'] = $this->report->get_cross_group_data();
        $data->cohortinsights['crossteacher'] = $this->report->get_cross_teacher_data();

        // Gate scatter and sociogram behind settings toggles.
        $showscatter = get_config('gradereport_coifish', 'show_riskquadrant');
        $showsociogram = get_config('gradereport_coifish', 'show_sociogram');
        if ($showscatter === false || $showscatter) {
            // Default on — show unless explicitly disabled.
            $data->cohortinsights['showscatter'] = ($showscatter !== '0')
                && !empty($data->cohortinsights['hasscatter']);
        } else {
            $data->cohortinsights['showscatter'] = !empty($data->cohortinsights['hasscatter']);
        }
        if ($showsociogram === false || $showsociogram) {
            $data->cohortinsights['showsociogram'] = ($showsociogram !== '0')
                && !empty($data->cohortinsights['hassociogram']);
        } else {
            $data->cohortinsights['showsociogram'] = !empty($data->cohortinsights['hassociogram']);
        }

        // Preserve the active view across group/user changes.
        $data->defaultinsights = ($this->viewoverride === 'insights');

        return $data;
    }
}
