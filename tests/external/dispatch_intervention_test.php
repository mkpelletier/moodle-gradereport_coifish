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

namespace gradereport_coifish\external;

/**
 * Tests for sending a message about one activity (missed due date cards).
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \gradereport_coifish\external\dispatch_intervention
 * @covers     \gradereport_coifish\external\log_intervention
 */
final class dispatch_intervention_test extends \advanced_testcase {
    /**
     * A message about an activity fills in {activityname}, {duedate} and
     * {activitylink}, personalises {firstname}, and records the activity.
     */
    public function test_message_about_activity(): void {
        global $DB;
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $student = $generator->create_user(['firstname' => 'Ruth']);
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');
        $due = time() - DAYSECS;
        $assign = $generator->create_module('assign', [
            'course' => $course->id, 'name' => 'Essay 1', 'duedate' => $due,
        ]);

        $this->setUser($teacher);
        $sink = $this->redirectMessages();
        $result = dispatch_intervention::execute(
            $course->id,
            [$student->id],
            'missed_activity',
            'cohort',
            'message',
            '{activityname}: not yet completed',
            'Hi {firstname}, {activityname} was due {duedate}: {activitylink}',
            '',
            $assign->cmid
        );
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertEquals(1, $result['delivered']);
        $this->assertCount(1, $messages);
        $this->assertEquals('Essay 1: not yet completed', $messages[0]->subject);
        $url = (new \moodle_url('/mod/assign/view.php', ['id' => $assign->cmid]))->out(false);
        $duestr = userdate($due, get_string('strftimedatetime', 'langconfig'));
        $this->assertStringContainsString('Hi Ruth, Essay 1 was due ' . $duestr . ': ' . $url, $messages[0]->fullmessage);
        $this->assertEquals(
            $assign->cmid,
            $DB->get_field('gradereport_coifish_intv', 'cmid', ['id' => $result['interventionid']])
        );
    }

    /**
     * A course module from another course is rejected.
     */
    public function test_rejects_cmid_from_another_course(): void {
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $other = $generator->create_course();
        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');
        $foreign = $generator->create_module('assign', ['course' => $other->id]);

        $this->setUser($teacher);
        $this->expectException(\invalid_parameter_exception::class);
        log_intervention::execute(
            $course->id,
            [$student->id],
            'missed_activity',
            'cohort',
            'deadline_adjustment',
            '',
            '',
            $foreign->cmid
        );
    }
}
