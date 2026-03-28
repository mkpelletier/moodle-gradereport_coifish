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
 * Admin setting: range slider with live value display.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * A range slider admin setting with min, max, step, and a unit suffix.
 */
class gradereport_coifish_admin_setting_configslider extends admin_setting {
    /** @var int Minimum value. */
    protected int $min;

    /** @var int Maximum value. */
    protected int $max;

    /** @var int Step increment. */
    protected int $step;

    /** @var string Unit suffix displayed after the value (e.g. '%', ' days'). */
    protected string $unit;

    /**
     * Constructor.
     *
     * @param string $name Setting name.
     * @param string $visiblename Localised title.
     * @param string $description Localised description.
     * @param int $defaultsetting Default value.
     * @param int $min Minimum slider value.
     * @param int $max Maximum slider value.
     * @param int $step Step increment.
     * @param string $unit Unit suffix for display.
     */
    public function __construct(
        string $name,
        string $visiblename,
        string $description,
        int $defaultsetting,
        int $min = 0,
        int $max = 100,
        int $step = 1,
        string $unit = ''
    ) {
        $this->min = $min;
        $this->max = $max;
        $this->step = $step;
        $this->unit = $unit;
        parent::__construct($name, $visiblename, $description, $defaultsetting);
    }

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
     * @param string $data The submitted value.
     * @return string Empty on success, error message on failure.
     */
    public function write_setting($data) {
        $data = (int)$data;
        if ($data < $this->min || $data > $this->max) {
            return get_string('errorsetting', 'admin');
        }
        return $this->config_write($this->name, $data) ? '' : get_string('errorsetting', 'admin');
    }

    /**
     * Render the slider HTML.
     *
     * @param string $data Current value.
     * @param string $query Admin search query.
     * @return string HTML output.
     */
    public function output_html($data, $query = '') {
        global $OUTPUT;

        $default = $this->get_defaultsetting();
        $context = (object)[
            'id' => $this->get_id(),
            'name' => $this->get_full_name(),
            'value' => (int)$data,
            'min' => $this->min,
            'max' => $this->max,
            'step' => $this->step,
            'unit' => $this->unit,
            'readonly' => $this->is_readonly(),
        ];

        $element = $OUTPUT->render_from_template('gradereport_coifish/setting_configslider', $context);

        $defaultinfo = $default . $this->unit;
        return format_admin_setting(
            $this,
            $this->visiblename,
            $element,
            $this->description,
            true,
            '',
            $defaultinfo,
            $query
        );
    }
}
