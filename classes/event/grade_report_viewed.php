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
 * Event for when the Grade Tracker report is viewed.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gradereport_coifish\event;

/**
 * Grade report viewed event.
 */
class grade_report_viewed extends \core\event\base {
    /**
     * Initialise the event.
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Get the event name.
     *
     * @return string The localised event name.
     */
    public static function get_name(): string {
        return get_string('pluginname', 'gradereport_coifish');
    }

    /**
     * Get the event description.
     *
     * @return string A description of what happened.
     */
    public function get_description(): string {
        $desc = "The user with id '{$this->userid}' viewed the Grade tracker report";
        if ($this->relateduserid) {
            $desc .= " for user with id '{$this->relateduserid}'";
        }
        $desc .= " in course with id '{$this->courseid}'.";
        return $desc;
    }

    /**
     * Get the URL related to the event.
     *
     * @return \moodle_url The event URL.
     */
    public function get_url(): \moodle_url {
        $params = ['id' => $this->courseid];
        if ($this->relateduserid) {
            $params['userid'] = $this->relateduserid;
        }
        return new \moodle_url('/grade/report/coifish/index.php', $params);
    }
}
