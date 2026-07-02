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
 * Hook callbacks for CoIFish.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gradereport_coifish;

use core\hook\output\before_footer_html_generation;

/**
 * Hook callbacks for CoIFish.
 */
class hook_callbacks {
    /**
     * Inject the student pulse dashboard modal on course-page entry.
     *
     * Shows the self-referenced progress modal once per period to enrolled
     * students (never to graders) in courses where the dashboard is enabled,
     * unless the student has muted it. The "shown" timestamp is recorded as a
     * user preference so the modal does not re-appear until the next period.
     * Everything rendered is precomputed; no analytics are calculated here.
     *
     * @param before_footer_html_generation $hook The footer hook.
     */
    public static function before_footer_html_generation(before_footer_html_generation $hook): void {
        global $PAGE, $USER;

        if (!isloggedin() || isguestuser()) {
            return;
        }
        $context = $PAGE->context ?? null;
        if (!$context || $context->contextlevel != CONTEXT_COURSE) {
            return;
        }
        $courseid = (int)$context->instanceid;
        if ($courseid <= SITEID) {
            return;
        }
        // Only on the course landing page, not every course-context page.
        if (strpos((string)$PAGE->pagetype, 'course-view') !== 0) {
            return;
        }
        if (!pulse::course_config($courseid)['enabled']) {
            return;
        }
        // Students only — graders get the teacher analytics, not this modal.
        $isgrader = has_capability('moodle/grade:viewall', $context);
        $canview = has_capability('gradereport/coifish:view', $context);
        if ($isgrader || !$canview) {
            return;
        }
        if (get_user_preferences(pulse::PREF_MUTED . $courseid, 0)) {
            return;
        }

        // Show once per captured period.
        $rows = pulse::recent_rows($courseid, (int)$USER->id, 1);
        if (empty($rows)) {
            return;
        }
        $latest = (int)$rows[0]->periodstart;
        if ($latest <= (int)get_user_preferences(pulse::PREF_LASTSHOWN . $courseid, 0)) {
            return;
        }

        $renderable = new \gradereport_coifish\output\student_pulse($courseid, (int)$USER->id);
        $data = $renderable->export_for_template($hook->renderer);
        if (empty($data['hasdata'])) {
            return;
        }

        $hook->add_html($hook->renderer->render_from_template('gradereport_coifish/student_pulse_modal', $data));
        $PAGE->requires->js_call_amd('gradereport_coifish/student_pulse', 'init');
        set_user_preference(pulse::PREF_LASTSHOWN . $courseid, $latest);
    }
}
