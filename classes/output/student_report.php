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
 * Renderable for the single-student grade overview report.
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
 * Renderable that prepares grade data for the student_report mustache template.
 */
class student_report implements renderable, templatable {
    /** @var report The report instance. */
    protected report $report;

    /** @var bool Whether this is being viewed by a teacher (show user name). */
    protected bool $isteacherview;

    /** @var bool Whether the viewer has the capability to view hidden items. */
    protected bool $canviewhidden;

    /** @var bool Whether hidden items are currently being shown. */
    protected bool $showhidden;

    /** @var bool Whether to show the running total (based on graded items only). */
    protected bool $runningtotal;

    /** @var string Requested view override from URL (e.g. 'insights'). */
    protected string $viewoverride;

    /**
     * Constructor.
     *
     * @param report $report The report instance with grade data loaded.
     * @param bool $isteacherview Whether the viewer is a teacher looking at a student.
     * @param bool $canviewhidden Whether the viewer has the moodle/grade:viewhidden capability.
     * @param bool $showhidden Whether hidden items are currently being shown.
     * @param bool $runningtotal Whether to show the running total.
     * @param string $viewoverride URL-requested view override (e.g. 'insights').
     */
    public function __construct(
        report $report,
        bool $isteacherview = false,
        bool $canviewhidden = false,
        bool $showhidden = false,
        bool $runningtotal = false,
        string $viewoverride = ''
    ) {
        $this->report = $report;
        $this->isteacherview = $isteacherview;
        $this->canviewhidden = $canviewhidden;
        $this->showhidden = $showhidden;
        $this->runningtotal = $runningtotal;
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

        if (!$this->report->has_grades()) {
            $data->has_grades = false;
            $data->nogradesmessage = get_string('nogrades', 'gradereport_coifish');
            return $data;
        }

        $data->has_grades = true;
        $data->hasweights = $this->report->has_weights();

        // Show user name in teacher view.
        if ($this->isteacherview) {
            $user = \core_user::get_user($this->report->get_userid());
            $data->userfullname = fullname($user);
            $data->showuser = true;
        } else {
            $data->showuser = false;
        }

        // Categories with items.
        $data->categories = $this->report->get_grade_data();

        // Course total.
        $data->coursetotal = $this->report->get_course_total();

        // Running total (based on graded items only).
        $data->runningtotal = $this->runningtotal;
        if ($this->runningtotal) {
            $runningtotaldata = $this->report->get_running_total();
            $data->runningtotaldata = $runningtotaldata;

            // Inject per-category running totals into the category data for the table view.
            $categoryrunningtotals = $this->report->get_category_running_totals();
            foreach ($categoryrunningtotals as $i => $crt) {
                if (isset($data->categories[$i])) {
                    $data->categories[$i]['hasrunningtotal'] = true;
                    $data->categories[$i]['runningtotalpercentage'] = $crt['percentage'];
                }
            }
        }

        // View toggle and default view.
        $data->showviewtoggle = true;
        // URL override (e.g. ?view=insights from cohort drill-down) takes priority.
        if ($this->viewoverride === 'insights' && $this->isteacherview) {
            $data->defaultprogress = false;
            $data->defaultinsights = true;
        } else if ($this->viewoverride === 'progress') {
            $data->defaultprogress = true;
            $data->defaultinsights = false;
        } else {
            $defaultview = $this->resolve_default_view();
            $data->defaultprogress = ($defaultview === 'progress');
            $data->defaultinsights = false;
        }

        // Progress view data.
        $data->progressdata = $this->report->get_progress_data();
        // Inject running total into progress data when the toggle is on.
        if ($this->runningtotal && isset($runningtotaldata['percentage_raw'])) {
            $data->progressdata['coursetotalbar']['running_percentage'] = $runningtotaldata['percentage_raw'];
            // Add running total flag to each category bar so Mustache can access it inside the loop.
            foreach ($data->progressdata['categorybars'] as &$bar) {
                $bar['runningtotal'] = true;
            }
            unset($bar);
        }
        $data->progressjson = json_encode($data->progressdata);

        // Gamification widgets.
        // Teachers always see widgets; when widgets are disabled for students, teacher gets preview mode.
        $gamification = $this->report->get_gamification_data($this->isteacherview);

        // Extract feedback and self-regulation widgets to top level so they render in the goal planner area.
        // These are built independently of the gamification toggle — they are part of the goal
        // planner, not the gamification widgets grid, and should always be visible to students.
        $data->feedbackwidget = null;
        $data->selfregwidget = null;
        if (!empty($gamification['widgets'])) {
            foreach ($gamification['widgets'] as $i => $widget) {
                $type = $widget['type'] ?? '';
                if ($type === 'feedback') {
                    $data->feedbackwidget = $widget;
                    unset($gamification['widgets'][$i]);
                } else if ($type === 'selfregulation') {
                    $data->selfregwidget = $widget;
                    unset($gamification['widgets'][$i]);
                }
            }
            $gamification['widgets'] = array_values($gamification['widgets']);
            $gamification['haswidgets'] = !empty($gamification['widgets']);
        }

        // When gamification is disabled, the widgets array is empty — build goal planner
        // widgets directly so they still appear regardless of the display toggle.
        if ($data->feedbackwidget === null) {
            $fbwidget = $this->report->build_widget_feedback();
            if ($fbwidget) {
                $data->feedbackwidget = $fbwidget;
            }
        }
        if ($data->selfregwidget === null) {
            $srwidget = $this->report->build_widget_selfregulation();
            if ($srwidget) {
                $data->selfregwidget = $srwidget;
            }
        }

        $data->gamification = $gamification;

        // Community of Inquiry (COI) widgets — separate section.
        $data->coi = $this->report->get_coi_data($this->isteacherview);

        // Widget position: above or below grade bars.
        $data->widgetstop = ($this->resolve_setting('widgetposition', 'top') === 'top');

        // Teacher-only insights tab: diagnostic + prescriptive analytics.
        // Gate behind site/course show_insights setting.
        $configkey = 'course_' . $this->report->courseid;
        $raw = get_config('gradereport_coifish', $configkey);
        $coursesettings = $raw ? json_decode($raw, true) : [];
        $courseoverride = $coursesettings['show_insights'] ?? '';
        if ($courseoverride !== '') {
            $showinsights = ($courseoverride === '1');
        } else {
            $siteinsights = get_config('gradereport_coifish', 'show_insights');
            $showinsights = ($siteinsights === false || $siteinsights !== '0');
        }
        $data->isteacherview = $this->isteacherview && $showinsights;
        if ($data->isteacherview) {
            $data->insights = $this->report->get_insights_data();
            // Intervention history for the student insights tab.
            $interventionenabled = get_config('gradereport_coifish', 'intervention_enabled');
            if ($interventionenabled === false || $interventionenabled !== '0') {
                $data->insights['interventionhistory'] = $this->report->get_intervention_history(
                    $this->report->get_userid()
                );
            }
        }

        // Show hidden toggle (only for users with the capability).
        $data->canviewhidden = $this->canviewhidden;
        $data->showhidden = $this->showhidden;

        return $data;
    }

    /**
     * Resolve a cascading setting: course override > site default > fallback.
     *
     * @param string $key The setting key.
     * @param string $fallback The fallback value.
     * @return string The resolved value.
     */
    protected function resolve_setting(string $key, string $fallback): string {
        $coursesettings = $this->report->get_course_gamification_settings();
        $courseval = $coursesettings[$key] ?? '';
        if ($courseval !== '') {
            return $courseval;
        }

        $siteval = get_config('gradereport_coifish', $key);
        if ($siteval !== false && $siteval !== '') {
            return $siteval;
        }

        return $fallback;
    }

    /**
     * Resolve the effective default view (table or progress).
     *
     * Course-level setting takes priority, then site-level, then falls back to 'table'.
     *
     * @return string 'table' or 'progress'.
     */
    protected function resolve_default_view(): string {
        $val = $this->resolve_setting('defaultview', 'table');
        return ($val === 'table' || $val === 'progress') ? $val : 'table';
    }
}
