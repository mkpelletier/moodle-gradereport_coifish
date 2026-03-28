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
 * Admin setting: optional range slider with enable/disable checkbox.
 *
 * When disabled (unchecked), the value is saved as empty string.
 * When enabled, the slider value is saved.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * An optional range slider — checkbox toggles whether the slider value is active.
 */
class gradereport_coifish_admin_setting_configoptionalslider extends admin_setting {
    /** @var int Minimum value. */
    protected int $min;

    /** @var int Maximum value. */
    protected int $max;

    /** @var int Step increment. */
    protected int $step;

    /** @var string Unit suffix. */
    protected string $unit;

    /** @var int Default slider position when first enabled. */
    protected int $sliderdefault;

    /**
     * Constructor.
     *
     * @param string $name Setting name.
     * @param string $visiblename Localised title.
     * @param string $description Localised description.
     * @param string $defaultsetting Default value ('' = disabled, number = enabled).
     * @param int $min Minimum slider value.
     * @param int $max Maximum slider value.
     * @param int $step Step increment.
     * @param string $unit Unit suffix for display.
     * @param int $sliderdefault Default slider position when first enabled.
     */
    public function __construct(
        string $name,
        string $visiblename,
        string $description,
        string $defaultsetting,
        int $min = 0,
        int $max = 100,
        int $step = 1,
        string $unit = '',
        int $sliderdefault = 65
    ) {
        $this->min = $min;
        $this->max = $max;
        $this->step = $step;
        $this->unit = $unit;
        $this->sliderdefault = $sliderdefault;
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
     * @param mixed $data The submitted data (array with 'enabled' and 'value' keys).
     * @return string Empty on success, error message on failure.
     */
    public function write_setting($data) {
        if (!is_array($data)) {
            // Fallback: treat raw string as the value.
            $value = trim($data);
            if ($value === '') {
                return $this->config_write($this->name, '') ? '' : get_string('errorsetting', 'admin');
            }
            $value = (int)$value;
        } else {
            $enabled = !empty($data['enabled']);
            $value = (int)($data['value'] ?? $this->sliderdefault);
            if (!$enabled) {
                return $this->config_write($this->name, '') ? '' : get_string('errorsetting', 'admin');
            }
        }

        if ($value < $this->min || $value > $this->max) {
            return get_string('errorsetting', 'admin');
        }
        return $this->config_write($this->name, $value) ? '' : get_string('errorsetting', 'admin');
    }

    /**
     * Render the optional slider HTML.
     *
     * @param string $data Current value ('' = disabled).
     * @param string $query Admin search query.
     * @return string HTML output.
     */
    public function output_html($data, $query = '') {
        global $OUTPUT;

        $enabled = ($data !== '' && $data !== null && $data !== false);
        $slidervalue = $enabled ? (int)$data : $this->sliderdefault;

        $context = (object)[
            'id' => $this->get_id(),
            'name' => $this->get_full_name(),
            'value' => $slidervalue,
            'enabled' => $enabled,
            'min' => $this->min,
            'max' => $this->max,
            'step' => $this->step,
            'unit' => $this->unit,
            'readonly' => $this->is_readonly(),
        ];

        $element = $OUTPUT->render_from_template('gradereport_coifish/setting_configoptionalslider', $context);

        $default = $this->get_defaultsetting();
        $defaultinfo = ($default === '') ? get_string('setting_disabled', 'gradereport_coifish') : $default . $this->unit;
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
