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
 * Scheduled task: capture student pulse snapshots for opted-in courses.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gradereport_coifish\task;

use core\task\scheduled_task;
use gradereport_coifish\pulse;

/**
 * Captures a fortnightly (configurable) metric snapshot per student for every
 * course where the student pulse dashboard is enabled. Runs daily but only
 * writes a row once per period per course, so cron timing never skips a period.
 */
class build_student_pulse extends scheduled_task {
    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_build_student_pulse', 'gradereport_coifish');
    }

    /**
     * Capture due pulse snapshots for each opted-in course.
     */
    public function execute(): void {
        foreach (pulse::enabled_courseids() as $courseid) {
            try {
                $written = pulse::capture_course($courseid);
                if ($written > 0) {
                    mtrace("gradereport_coifish: captured {$written} student pulse rows for course {$courseid}");
                }
            } catch (\Throwable $e) {
                mtrace("gradereport_coifish: pulse capture failed for course {$courseid}: " . $e->getMessage());
            }
        }
    }
}
