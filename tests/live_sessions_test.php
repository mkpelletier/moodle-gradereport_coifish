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

/**
 * Unit tests for the live-session (BigBlueButton) analyser.
 *
 * @package    gradereport_coifish
 * @category   test
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \gradereport_coifish\live_sessions
 */
final class live_sessions_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    protected \stdClass $course;

    /** @var \stdClass Teacher. */
    protected \stdClass $teacher;

    /** @var \stdClass[] Students s1..s3. */
    protected array $students = [];

    /**
     * Create a course with a teacher and three students.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        live_sessions::reset_cache();
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['startdate' => time() - 60 * DAYSECS]);
        $this->teacher = $gen->create_and_enrol($this->course, 'editingteacher');
        for ($i = 1; $i <= 3; $i++) {
            $this->students[$i] = $gen->create_and_enrol($this->course, 'student');
        }
    }

    /**
     * Create a BBB activity.
     *
     * @param array $options Extra module options (e.g. groupmode).
     * @return \stdClass
     */
    protected function create_bbb(array $options = []): \stdClass {
        return $this->getDataGenerator()->create_module('bigbluebuttonbn', array_merge(
            ['course' => $this->course->id],
            $options
        ));
    }

    /**
     * Insert a bigbluebuttonbn_logs row.
     *
     * @param \stdClass $bbb Activity.
     * @param int $userid User.
     * @param string $log Log type (Summary, Join, Played...).
     * @param int $time Timestamp.
     * @param array|null $meta Meta payload.
     * @param int $groupid Group room.
     */
    protected function add_log(\stdClass $bbb, int $userid, string $log, int $time, ?array $meta = null, int $groupid = 0): void {
        global $DB;
        $DB->insert_record('bigbluebuttonbn_logs', [
            'courseid' => $this->course->id,
            'bigbluebuttonbnid' => $bbb->id,
            'userid' => $userid,
            'timecreated' => $time,
            'meetingid' => $bbb->meetingid . '-' . $this->course->id . '-' . $bbb->id . '[' . $groupid . ']',
            'log' => $log,
            'meta' => $meta === null ? null : json_encode($meta),
        ]);
    }

    /**
     * Insert a meeting-events Summary row for one attendee.
     *
     * @param \stdClass $bbb Activity.
     * @param int $userid User.
     * @param string $recordid Internal meeting id (one held sitting).
     * @param int $time Timestamp.
     * @param array $stats duration, talk_time, chats... (seconds / counts).
     * @param int $groupid Group room.
     */
    protected function add_summary(
        \stdClass $bbb,
        int $userid,
        string $recordid,
        int $time,
        array $stats = [],
        int $groupid = 0
    ): void {
        $engagement = [
            'chats' => $stats['chats'] ?? 0,
            'talks' => $stats['talks'] ?? 0,
            'raisehand' => $stats['raisehand'] ?? 0,
            'emojis' => $stats['emojis'] ?? 0,
            'poll_votes' => $stats['poll_votes'] ?? 0,
            'talk_time' => $stats['talk_time'] ?? 0,
        ];
        $this->add_log($bbb, $userid, 'Summary', $time, [
            'recordid' => $recordid,
            'data' => [
                'ext_user_id' => $userid,
                'moderator' => $stats['moderator'] ?? false,
                'duration' => $stats['duration'] ?? 1800,
                'engagement' => $engagement,
            ],
        ], $groupid);
    }

    /**
     * Summary rows make one sitting each; their Join clicks are not double-counted;
     * an un-summarised Join becomes a join-only sitting; recording playback is ignored.
     */
    public function test_occurrences_from_summary_join_and_played(): void {
        [$s1, $s2, $s3] = [$this->students[1], $this->students[2], $this->students[3]];
        $bbb = $this->create_bbb();
        $t = time() - 10 * DAYSECS;

        // Facilitated sitting with summary analytics, preceded by Join clicks.
        $this->add_log($bbb, $s1->id, 'Join', $t - 3600);
        $this->add_log($bbb, $this->teacher->id, 'Join', $t - 3600);
        $this->add_summary($bbb, $s1->id, 'rec-1', $t);
        $this->add_summary($bbb, $s2->id, 'rec-1', $t);
        $this->add_summary($bbb, $this->teacher->id, 'rec-1', $t, ['moderator' => true]);

        // A later sitting with only a Join click (callback missing).
        $this->add_log($bbb, $s3->id, 'Join', $t + 3 * DAYSECS);

        // Watching the recording is not attendance.
        $this->add_log($bbb, $s2->id, 'Played', $t + 5 * DAYSECS);

        $live = live_sessions::for_course($this->course->id);
        $occurrences = $live->get_occurrences();
        $this->assertCount(2, $occurrences);
        $this->assertTrue($live->has_sessions());

        $types = array_column($occurrences, 'type', 'key');
        $this->assertSame('facilitated', $types['s:rec-1']);
        $joinonly = array_values(array_filter($occurrences, fn($o) => !$o['rich']));
        $this->assertCount(1, $joinonly);
        $this->assertSame('peer', $joinonly[0]['type']);

        $m1 = $live->get_student($s1->id);
        $this->assertSame(1, $m1['facilitatedavailable']);
        $this->assertSame(1, $m1['facilitatedattended']);
        $this->assertSame(100, $m1['facilitatedrate']);
        $m3 = $live->get_student($s3->id);
        $this->assertSame(0, $m3['facilitatedattended']);
        $this->assertSame(0, $m3['facilitatedrate']);
        $this->assertSame(1, $m3['attended']);

        $summary = $live->get_teacher_summary();
        $this->assertSame(1, $summary['held']);
        $this->assertSame(30, $summary['meanminutes']);
        $this->assertSame(67, $summary['reach']);
    }

    /**
     * A student who is a BBB moderator (common in role-play rooms) does not make
     * the sitting facilitated.
     */
    public function test_student_moderator_is_not_a_teacher(): void {
        $bbb = $this->create_bbb();
        $t = time() - 5 * DAYSECS;
        $this->add_summary($bbb, $this->students[1]->id, 'rec-rp', $t, ['moderator' => true]);
        $this->add_summary($bbb, $this->students[2]->id, 'rec-rp', $t, ['moderator' => true]);

        $occurrences = live_sessions::for_course($this->course->id)->get_occurrences();
        $this->assertSame('peer', $occurrences['s:rec-rp']['type']);
    }

    /**
     * Voice share: a student who takes their fair share of the talking earns full
     * credit, a silent one only the attendance part. Peer sittings in an open room
     * are only "available" to students who use that room.
     */
    public function test_voice_share_peers_and_open_room_audience(): void {
        [$s1, $s2, $s3] = [$this->students[1], $this->students[2], $this->students[3]];
        $bbb = $this->create_bbb();
        $t = time() - 5 * DAYSECS;
        $this->add_summary($bbb, $s1->id, 'rec-rp', $t, ['talk_time' => 600, 'talks' => 12]);
        $this->add_summary($bbb, $s2->id, 'rec-rp', $t, ['talk_time' => 0]);

        $live = live_sessions::for_course($this->course->id);
        $m1 = $live->get_student($s1->id);
        $m2 = $live->get_student($s2->id);
        $m3 = $live->get_student($s3->id);

        // Fair share = 300s; s1 ≥ fair share → 0.4 + 0.6 = 100%; s2 silent → 40%.
        $this->assertSame(100, $m1['liverate']);
        $this->assertSame(40, $m2['liverate']);
        $this->assertSame(10, $m1['talkminutes']);
        $this->assertSame([(int)$s2->id], $m1['peerids']);
        $this->assertSame(1, $m1['peersessions']);

        // Student s3 never used this pair's room, so is not marked absent from it.
        $this->assertSame(0, $m3['available']);
        $this->assertSame(0, $m3['liverate']);

        // Intensity: two students with one peer sitting at 2×, over 3 students.
        $this->assertEqualsWithDelta(4 / 3, $live->get_intensity(), 0.0001);
    }

    /**
     * When nobody speaks (muted lecture), chatting or voting earns full credit and
     * silent attendance half; a join-only sitting earns the fixed neutral credit.
     */
    public function test_participation_without_speech_and_join_only(): void {
        [$s1, $s2] = [$this->students[1], $this->students[2]];
        $bbb = $this->create_bbb();
        $t = time() - 5 * DAYSECS;
        $this->add_summary($bbb, $this->teacher->id, 'rec-lec', $t);
        $this->add_summary($bbb, $s1->id, 'rec-lec', $t, ['chats' => 3]);
        $this->add_summary($bbb, $s2->id, 'rec-lec', $t);

        $live = live_sessions::for_course($this->course->id);
        $this->assertSame(100, $live->get_student($s1->id)['liverate']);
        $this->assertSame(70, $live->get_student($s2->id)['liverate']);

        live_sessions::reset_cache();
        $bbb2 = $this->create_bbb();
        $this->add_log($bbb2, $this->teacher->id, 'Join', $t + DAYSECS);
        $this->add_log($bbb2, $s1->id, 'Join', $t + DAYSECS);
        $live = live_sessions::for_course($this->course->id);
        // Join-only facilitated sitting: 0.7 credit. s1 = (1.0 + 0.7) / 2.
        $this->assertSame(85, $live->get_student($s1->id)['liverate']);
    }

    /**
     * In a group-mode activity, a group room only counts for that group's members.
     */
    public function test_group_room_counts_only_for_its_group(): void {
        [$s1, $s2, $s3] = [$this->students[1], $this->students[2], $this->students[3]];
        $gen = $this->getDataGenerator();
        $g1 = $gen->create_group(['courseid' => $this->course->id]);
        $g2 = $gen->create_group(['courseid' => $this->course->id]);
        $gen->create_group_member(['groupid' => $g1->id, 'userid' => $s1->id]);
        $gen->create_group_member(['groupid' => $g1->id, 'userid' => $s2->id]);
        $gen->create_group_member(['groupid' => $g2->id, 'userid' => $s3->id]);

        $bbb = $this->create_bbb(['groupmode' => SEPARATEGROUPS]);
        $t = time() - 5 * DAYSECS;
        $this->add_summary($bbb, $s1->id, 'rec-g1', $t, ['talk_time' => 300], $g1->id);

        $live = live_sessions::for_course($this->course->id);
        $this->assertSame((int)$g1->id, $live->get_occurrences()['s:rec-g1']['groupid']);
        $this->assertSame(1, $live->get_student($s1->id)['attended']);
        $this->assertSame(1, $live->get_student($s2->id)['available']);
        $this->assertSame(0, $live->get_student($s2->id)['liverate']);
        $this->assertSame(0, $live->get_student($s3->id)['available']);
    }

    /**
     * Peer sittings weigh more than facilitated ones by the configured multiplier.
     */
    public function test_peer_multiplier(): void {
        [$s1, $s2] = [$this->students[1], $this->students[2]];
        $gen = $this->getDataGenerator();
        $g1 = $gen->create_group(['courseid' => $this->course->id]);
        $gen->create_group_member(['groupid' => $g1->id, 'userid' => $s1->id]);
        $gen->create_group_member(['groupid' => $g1->id, 'userid' => $s2->id]);
        $bbb = $this->create_bbb(['groupmode' => SEPARATEGROUPS]);
        $t = time() - 5 * DAYSECS;

        // Student s1 attends the peer sitting (full voice), misses the facilitated one.
        $this->add_summary($bbb, $s1->id, 'rec-peer', $t, ['talk_time' => 300], $g1->id);
        $this->add_summary($bbb, $s2->id, 'rec-peer', $t, ['talk_time' => 300], $g1->id);
        $this->add_summary($bbb, $this->teacher->id, 'rec-fac', $t + DAYSECS, [], $g1->id);
        $this->add_summary($bbb, $s2->id, 'rec-fac', $t + DAYSECS, ['talk_time' => 60], $g1->id);

        // Default 2×: s1 = 2 / (2 + 1) = 67%.
        $this->assertSame(67, live_sessions::for_course($this->course->id)->get_student($s1->id)['liverate']);

        live_sessions::reset_cache();
        set_config('sp_bbb_peer_multiplier', '1', 'gradereport_coifish');
        // 1×: s1 = 1 / 2 = 50%.
        $this->assertSame(50, live_sessions::for_course($this->course->id)->get_student($s1->id)['liverate']);
    }

    /**
     * No BBB logs: nothing held, zeroed student metrics.
     */
    public function test_no_sessions(): void {
        $this->create_bbb();
        $live = live_sessions::for_course($this->course->id);
        $this->assertFalse($live->has_sessions());
        $this->assertSame(0, $live->get_student($this->students[1]->id)['available']);
        $this->assertSame(0, $live->get_teacher_summary()['held']);
    }

    /**
     * Teachers get full credit for sittings they attended and part credit for
     * student-only sittings in their group's room; reach counts their own students.
     */
    public function test_teacher_attribution_and_reach(): void {
        global $DB;
        [$s1, $s2, $s3] = [$this->students[1], $this->students[2], $this->students[3]];
        $gen = $this->getDataGenerator();
        $teacherb = $gen->create_and_enrol($this->course, 'editingteacher');
        $g1 = $gen->create_group(['courseid' => $this->course->id]);
        $g2 = $gen->create_group(['courseid' => $this->course->id]);
        foreach ([[$g1, $s1], [$g1, $s2], [$g1, $this->teacher], [$g2, $s3], [$g2, $teacherb]] as [$g, $u]) {
            $gen->create_group_member(['groupid' => $g->id, 'userid' => $u->id]);
        }
        $bbb = $this->create_bbb(['groupmode' => SEPARATEGROUPS]);
        $t = time() - 20 * DAYSECS;
        // Teacher A runs a group 1 sitting; s1 attends.
        $this->add_summary($bbb, $this->teacher->id, 'rec-a', $t, ['duration' => 2700], $g1->id);
        $this->add_summary($bbb, $s1->id, 'rec-a', $t, [], $g1->id);
        // Student-only role-play in group 1's room: s1 and s2.
        $this->add_summary($bbb, $s1->id, 'rec-rp1', $t + 7 * DAYSECS, [], $g1->id);
        $this->add_summary($bbb, $s2->id, 'rec-rp1', $t + 7 * DAYSECS, [], $g1->id);
        // A solo student in group 2's room is not a peer session.
        $this->add_summary($bbb, $s3->id, 'rec-solo', $t + 7 * DAYSECS, [], $g2->id);

        // A no-group activity created by teacher B hosts another role-play.
        $open = $this->create_bbb();
        $DB->delete_records('bigbluebuttonbn_logs', ['bigbluebuttonbnid' => $open->id, 'log' => 'Add']);
        $this->add_log($open, $teacherb->id, 'Add', $t - DAYSECS);
        $this->add_summary($open, $s1->id, 'rec-rp2', $t + 10 * DAYSECS);
        $this->add_summary($open, $s2->id, 'rec-rp2', $t + 10 * DAYSECS);

        $live = live_sessions::for_course($this->course->id);
        $a = $live->get_teacher($this->teacher->id);
        $this->assertSame(1, $a['facilitated']);
        $this->assertSame(1, $a['peer']);
        $this->assertEqualsWithDelta(1.5, $a['credited'], 0.001);
        $this->assertSame(45, $a['minutes']);
        $this->assertSame(2, $a['reachable']);
        $this->assertSame(100, $a['reach']);

        $b = $live->get_teacher($teacherb->id);
        $this->assertSame(0, $b['facilitated']);
        $this->assertSame(1, $b['peer']);
        $this->assertSame(1, $b['reachable']);
        $this->assertSame(0, $b['reach']);

        // Time window: only the first week.
        $a1 = $live->get_teacher($this->teacher->id, $t - DAYSECS, $t + DAYSECS);
        $this->assertSame(1, $a1['facilitated']);
        $this->assertSame(0, $a1['peer']);
        $this->assertSame(50, $a1['reach']);

        // Peer credit setting.
        set_config('faculty_bbb_peer_credit', '1', 'gradereport_coifish');
        $this->assertEqualsWithDelta(2.0, $live->get_teacher($this->teacher->id)['credited'], 0.001);
    }

    /**
     * Live-teaching score: 60% frequency against 0.5 credited sessions a week, 40% reach.
     */
    public function test_score_teacher(): void {
        $this->assertSame(100, live_sessions::score_teacher(['credited' => 1.5, 'reach' => 100], 3.0));
        $this->assertSame(35, live_sessions::score_teacher(['credited' => 0.5, 'reach' => 50], 4.0));
        $this->assertSame(0, live_sessions::score_teacher(['credited' => 0, 'reach' => 0], 10.0));
    }
}
