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
 * Renderable for the coordinator view (teacher engagement analytics).
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
 * Renderable that prepares coordinator analytics data for the mustache template.
 */
class coordinator_report implements renderable, templatable {
    /** @var report The report instance. */
    protected report $report;

    /**
     * Constructor.
     *
     * @param report $report The report instance.
     */
    public function __construct(report $report) {
        $this->report = $report;
    }

    /**
     * Export data for the mustache template.
     *
     * @param renderer_base $output The renderer.
     * @return \stdClass Template data.
     */
    public function export_for_template(renderer_base $output): \stdClass {
        $data = new \stdClass();
        $data->coursename = format_string(get_course($this->report->courseid)->fullname);

        $coorddata = $this->report->get_coordinator_teacher_data();

        $data->hasdata = $coorddata['hasteachers'];
        $data->hasbbb = $coorddata['hasbbb'] ?? false;
        $data->hascontent = $coorddata['hascontent'] ?? true;
        $data->hasfeedback = $coorddata['hasfeedback'] ?? true;
        $data->teachers = $coorddata['teachers'] ?? [];
        $data->summary = $coorddata['summary'] ?? [];
        $data->recommendations = $coorddata['recommendations'] ?? [];
        $data->hasrecommendations = $coorddata['hasrecommendations'] ?? false;

        // Prepare chart data for the engagement breakdown bar chart.
        if ($data->hasdata) {
            $chartdata = [];
            foreach ($coorddata['teachers'] as $t) {
                $entry = [
                    'name' => $t['fullname'],
                    'composite' => $t['composite'],
                    'insight' => $t['insightscore'],
                    'grading' => $t['gradingscore'],
                    'forum' => $t['forumscore'],
                    'monitoring' => $t['grademonitoringscore'],
                    'messaging' => $t['messagescore'],
                    'active' => $t['activescore'],
                ];
                if ($data->hasfeedback) {
                    $entry['feedback'] = $t['feedbackscore'];
                }
                if ($data->hascontent) {
                    $entry['content'] = $t['contentscore'];
                }
                $chartdata[] = $entry;
            }
            $data->chartjson = json_encode($chartdata);
        }

        // Cross-teacher comparison (student outcomes by teacher).
        $data->crossteacher = $this->report->get_cross_teacher_data();

        return $data;
    }
}
