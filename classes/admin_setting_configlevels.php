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
 * Admin setting: four-level boundary editor with visual zone bar.
 *
 * Stores four comma-separated integers representing the boundaries
 * between the COI presence levels: Emerging, Developing, Established, Exemplary.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * A four-handle level boundary editor with a visual colour bar.
 */
class gradereport_coifish_admin_setting_configlevels extends admin_setting {
    /**
     * Read the current value.
     *
     * @return string|null
     */
    public function get_setting() {
        return $this->config_read($this->name);
    }

    /**
     * Validate and save.
     *
     * @param mixed $data Submitted data — array of 4 values or comma-separated string.
     * @return string Empty on success, error message on failure.
     */
    public function write_setting($data) {
        if (is_array($data)) {
            $values = array_map('intval', $data);
        } else {
            $values = array_map('intval', array_map('trim', explode(',', $data)));
        }

        if (count($values) !== 4) {
            return get_string('errorsetting', 'admin');
        }

        sort($values);

        foreach ($values as $v) {
            if ($v < 0 || $v > 100) {
                return get_string('errorsetting', 'admin');
            }
        }

        $csv = implode(',', $values);
        return $this->config_write($this->name, $csv) ? '' : get_string('errorsetting', 'admin');
    }

    /**
     * Render the level boundary editor.
     *
     * @param string $data Current comma-separated value.
     * @param string $query Admin search query.
     * @return string HTML output.
     */
    public function output_html($data, $query = '') {
        global $OUTPUT;

        $default = $this->get_defaultsetting();
        $values = array_map('intval', array_map('trim', explode(',', $data ?: $default)));
        if (count($values) !== 4) {
            $values = array_map('intval', array_map('trim', explode(',', $default)));
        }
        sort($values);

        $labels = [
            get_string('coi_level_emerging', 'gradereport_coifish'),
            get_string('coi_level_developing', 'gradereport_coifish'),
            get_string('coi_level_established', 'gradereport_coifish'),
            get_string('coi_level_exemplary', 'gradereport_coifish'),
        ];

        $handles = [];
        for ($i = 0; $i < 4; $i++) {
            $handles[] = [
                'index' => $i,
                'value' => $values[$i],
                'label' => $labels[$i],
            ];
        }

        $context = (object)[
            'id' => $this->get_id(),
            'name' => $this->get_full_name(),
            'handles' => $handles,
            'readonly' => $this->is_readonly(),
        ];

        $element = $OUTPUT->render_from_template('gradereport_coifish/setting_configlevels', $context);

        return format_admin_setting(
            $this,
            $this->visiblename,
            $element,
            $this->description,
            true,
            '',
            $default,
            $query
        );
    }
}
