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

namespace gradereport_coifish;

use core_privacy\tests\provider_testcase;
use gradereport_coifish\privacy\provider;

/**
 * Privacy provider tests for the grade tracker report.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \gradereport_coifish\privacy\provider
 */
final class privacy_provider_test extends provider_testcase {
    /**
     * Test that the provider implements the null_provider interface.
     */
    public function test_null_provider(): void {
        $this->assertInstanceOf(
            \core_privacy\local\metadata\null_provider::class,
            new provider()
        );
    }

    /**
     * Test that the reason string exists.
     */
    public function test_get_reason(): void {
        $reason = provider::get_reason();
        $this->assertEquals('privacy:metadata', $reason);
        // Ensure the string actually exists.
        $string = get_string($reason, 'gradereport_coifish');
        $this->assertNotEmpty($string);
    }
}
