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
 * Live-session (BigBlueButton) interaction analytics for the CoI presence triad.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gradereport_coifish;

/**
 * Reads mod_bigbluebuttonbn's own logs and turns them into per-student
 * social- and teaching-presence signals.
 *
 * When the BBB "meeting events" analytics callback is enabled, core writes one
 * 'Summary' log per attendee per held session, whose meta JSON carries the BBB
 * learning-dashboard record (duration, talk time, chats, raised hands, poll
 * votes, emojis) and the internal meeting id of that sitting. Sessions held
 * before the callback was enabled, or whose callback failed, only have 'Join'
 * clicks; those are still counted as attendance but with a fixed, neutral
 * participation contribution. Recording playback ('Played') and activity
 * housekeeping rows are ignored — watching a recording is not social presence.
 *
 * A held session ("occurrence") is facilitated when any course teacher
 * (moodle/grade:viewall) attended it, and peer otherwise. The BBB moderator
 * flag is deliberately not used for this: student role-play rooms commonly
 * make every participant a moderator.
 */
class live_sessions {
    /** @var float Default weight of a peer-only session relative to a facilitated one. */
    public const PEER_MULTIPLIER_DEFAULT = 2.0;

    /** @var int Largest student head-count for which co-attendance counts as a peer connection. */
    public const SMALL_GROUP_MAX = 8;

    /** @var float Participation credited for a join-only session (no analytics available). */
    public const JOINONLY_CONTRIBUTION = 0.7;

    /** @var int Seconds of voice that one chat message is treated as equivalent to. */
    public const CHAT_SECONDS = 15;

    /** @var float Default credit a teacher earns for a peer session in a room they are responsible for. */
    public const PEER_CREDIT_DEFAULT = 0.5;

    /** @var float Credited sessions per week at which a teacher's live-session frequency scores 100%. */
    public const FACULTY_SESSIONS_PER_WEEK = 0.5;

    /** @var int Minutes assumed for a join-only sitting (no analytics to measure it). */
    public const JOINONLY_MINUTES = 60;

    /** @var int Most analysers kept in the request memo (batch tasks walk many courses). */
    protected const CACHE_MAX = 50;

    /** @var array Request-level memo of analysers keyed by "courseid:until". */
    protected static array $cache = [];

    /** @var int Course id. */
    protected int $courseid;

    /** @var int Ignore logs after this timestamp (report's effective "now"). */
    protected int $until;

    /** @var int[] Student user ids (keys) in the course. */
    protected array $students = [];

    /** @var int[] Teacher user ids (keys) in the course. */
    protected array $teachers = [];

    /** @var array Held sessions, keyed by occurrence key. */
    protected array $occurrences = [];

    /** @var array Per-student metrics, keyed by userid. */
    protected array $studentdata = [];

    /** @var float Mean weighted number of sessions available per student. */
    protected float $intensity = 0.0;

    /** @var array Group id => [userid => true] member maps, loaded on demand. */
    protected array $groupmembers = [];

    /** @var array|null BBB instance id => creator user id, loaded on demand. */
    protected ?array $creators = null;

    /**
     * Get the (memoised) analyser for a course.
     *
     * @param int $courseid Course id.
     * @param int $until Ignore logs after this timestamp (0 = now).
     * @return self
     */
    public static function for_course(int $courseid, int $until = 0): self {
        $key = $courseid . ':' . ($until > 0 ? $until : 'now');
        if (!isset(self::$cache[$key])) {
            if (count(self::$cache) >= self::CACHE_MAX) {
                array_shift(self::$cache);
            }
            self::$cache[$key] = new self($courseid, $until > 0 ? $until : time());
        }
        return self::$cache[$key];
    }

    /**
     * Clear the request-level memo (used by unit tests).
     */
    public static function reset_cache(): void {
        self::$cache = [];
    }

    /**
     * Relative weight of a peer-only session, from the admin setting.
     *
     * @return float
     */
    public static function get_peer_multiplier(): float {
        $val = get_config('gradereport_coifish', 'sp_bbb_peer_multiplier');
        if ($val === false || $val === '' || !is_numeric($val)) {
            return self::PEER_MULTIPLIER_DEFAULT;
        }
        return max(1.0, (float)$val);
    }

    /**
     * Credit (0–1) a teacher earns for a student-only session in a room they
     * are responsible for, relative to a session they attended, from the admin setting.
     *
     * @return float
     */
    public static function get_peer_credit(): float {
        $val = get_config('gradereport_coifish', 'faculty_bbb_peer_credit');
        if ($val === false || $val === '' || !is_numeric($val)) {
            return self::PEER_CREDIT_DEFAULT;
        }
        return max(0.0, min(1.0, (float)$val));
    }

    /**
     * Live-teaching score (0–100) for a teacher: 60% session frequency (credited
     * sessions per week against {@see FACULTY_SESSIONS_PER_WEEK}) and 40% reach
     * (share of their students who attended at least one of their sessions).
     *
     * @param array $teacher Stats from {@see get_teacher()}.
     * @param float $weeks Weeks the period covers.
     * @return int
     */
    public static function score_teacher(array $teacher, float $weeks): int {
        $perweek = $teacher['credited'] / max(1.0, $weeks);
        $frequency = min(100, round($perweek / self::FACULTY_SESSIONS_PER_WEEK * 100));
        return (int)round(0.6 * $frequency + 0.4 * $teacher['reach']);
    }

    /**
     * Constructor: loads and analyses the course's live-session logs.
     *
     * @param int $courseid Course id.
     * @param int $until Ignore logs after this timestamp.
     */
    public function __construct(int $courseid, int $until) {
        global $DB;

        $this->courseid = $courseid;
        $this->until = $until;

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('bigbluebuttonbn_logs') || !$dbman->table_exists('bigbluebuttonbn')) {
            return;
        }

        $context = \context_course::instance($courseid);
        $this->teachers = array_fill_keys(array_map('intval', array_keys(
            get_enrolled_users($context, 'moodle/grade:viewall', 0, 'u.id', null, 0, 0, true)
        )), true);
        foreach (get_enrolled_users($context, 'moodle/course:isincompletionreports', 0, 'u.id', null, 0, 0, true) as $u) {
            if (!isset($this->teachers[(int)$u->id])) {
                $this->students[(int)$u->id] = true;
            }
        }

        $course = get_course($courseid);
        $logs = $DB->get_records_sql(
            "SELECT l.id, l.bigbluebuttonbnid, l.userid, l.meetingid, l.log, l.timecreated, l.meta
               FROM {bigbluebuttonbn_logs} l
               JOIN {bigbluebuttonbn} b ON b.id = l.bigbluebuttonbnid
              WHERE b.course = :courseid
                AND l.log IN ('Summary', 'Join')
                AND l.userid IS NOT NULL
                AND l.timecreated >= :start
                AND l.timecreated <= :until
           ORDER BY l.timecreated, l.id",
            ['courseid' => $courseid, 'start' => (int)$course->startdate, 'until' => $until]
        );
        if (empty($logs)) {
            return;
        }

        $this->build_occurrences($logs);
        $this->analyse();
    }

    /**
     * Group log rows into held sessions.
     *
     * @param array $logs bigbluebuttonbn_logs rows.
     */
    protected function build_occurrences(array $logs): void {
        // Pass 1: Summary rows. Remember when each meeting's analytics arrived
        // so that the Join clicks for the same sitting are not double-counted.
        $summarytimes = [];
        $joins = [];
        foreach ($logs as $log) {
            if ($log->log !== 'Summary') {
                $joins[] = $log;
                continue;
            }
            $meta = json_decode((string)$log->meta);
            $data = $meta->data ?? null;
            if (!is_object($data)) {
                continue;
            }
            $recordid = (string)($meta->recordid ?? '');
            $key = $recordid !== ''
                ? 's:' . $recordid
                : 's:' . $log->meetingid . ':' . date('Ymd', (int)$log->timecreated);
            $duration = (int)($data->duration ?? 0);
            $engagement = $data->engagement ?? new \stdClass();
            $stats = [
                'duration' => $duration,
                'talk_time' => (int)($engagement->talk_time ?? ($data->talk_time ?? 0)),
                'talks' => (int)($engagement->talks ?? 0),
                'chats' => (int)($engagement->chats ?? 0),
                'raisehand' => (int)($engagement->raisehand ?? 0),
                'poll_votes' => (int)($engagement->poll_votes ?? 0),
                'emojis' => (int)($engagement->emojis ?? 0),
            ];
            $occ = $this->get_or_create_occurrence($key, $log, true);
            $occ['start'] = min($occ['start'], (int)$log->timecreated - $duration);
            $userid = (int)$log->userid;
            if (isset($occ['attendees'][$userid])) {
                // Duplicate callback for the same sitting: keep the larger values.
                foreach ($stats as $field => $value) {
                    $stats[$field] = max($value, $occ['attendees'][$userid][$field]);
                }
            }
            $occ['attendees'][$userid] = $stats;
            $this->occurrences[$key] = $occ;
            $summarytimes[$log->meetingid][] = (int)$log->timecreated;
        }

        // Pass 2: Join rows not covered by a Summary for the same meeting
        // arriving within a day become join-only occurrences (one per meeting
        // per calendar day).
        foreach ($joins as $log) {
            $jointime = (int)$log->timecreated;
            $covered = false;
            foreach ($summarytimes[$log->meetingid] ?? [] as $t) {
                if ($t >= $jointime && $t - $jointime <= DAYSECS) {
                    $covered = true;
                    break;
                }
            }
            if ($covered) {
                continue;
            }
            $key = 'j:' . $log->meetingid . ':' . date('Ymd', $jointime);
            $occ = $this->get_or_create_occurrence($key, $log, false);
            $occ['start'] = min($occ['start'], $jointime);
            $userid = (int)$log->userid;
            if (!isset($occ['attendees'][$userid])) {
                $occ['attendees'][$userid] = [
                    'duration' => 0, 'talk_time' => 0, 'talks' => 0, 'chats' => 0,
                    'raisehand' => 0, 'poll_votes' => 0, 'emojis' => 0,
                ];
            }
            $this->occurrences[$key] = $occ;
        }

        // Classify each occurrence.
        foreach ($this->occurrences as $key => $occ) {
            $studentids = [];
            $hasteacher = false;
            foreach (array_keys($occ['attendees']) as $uid) {
                if (isset($this->teachers[$uid])) {
                    $hasteacher = true;
                } else if (isset($this->students[$uid])) {
                    $studentids[] = $uid;
                }
            }
            $occ['studentids'] = $studentids;
            $occ['hasteacher'] = $hasteacher;
            $occ['type'] = $hasteacher ? 'facilitated' : 'peer';
            $this->occurrences[$key] = $occ;
        }
    }

    /**
     * Fetch an occurrence by key, creating an empty one from a log row if new.
     *
     * @param string $key Occurrence key.
     * @param \stdClass $log The log row it came from.
     * @param bool $rich Whether it carries BBB analytics.
     * @return array
     */
    protected function get_or_create_occurrence(string $key, \stdClass $log, bool $rich): array {
        if (isset($this->occurrences[$key])) {
            return $this->occurrences[$key];
        }
        $groupid = 0;
        if (preg_match('/\[(\d+)\]$/', (string)$log->meetingid, $m)) {
            $groupid = (int)$m[1];
        }
        return [
            'key' => $key,
            'bbbid' => (int)$log->bigbluebuttonbnid,
            'groupid' => $groupid,
            'rich' => $rich,
            'start' => (int)$log->timecreated,
            'attendees' => [],
        ];
    }

    /**
     * Work out which students could attend each occurrence.
     *
     * - Students must be able to access the BBB activity (availability rules).
     * - In a group-mode activity, a group room counts only for that group's members.
     * - In an open, no-group activity, peer sessions count only for students who
     *   have ever used that room: separate BBB activities for pairs are common,
     *   and a student should not be marked absent from someone else's role-play.
     *   Facilitated sessions in an open room count for everyone who can access it.
     *
     * @return array Map of occurrence key => [userid => true].
     */
    protected function resolve_audiences(): array {
        $modinfo = get_fast_modinfo($this->courseid);
        $cms = $modinfo->get_instances_of('bigbluebuttonbn');
        $allstudents = [];
        foreach (array_keys($this->students) as $uid) {
            $allstudents[$uid] = (object)['id' => $uid];
        }

        // Per-activity audience and room users.
        $cmaudience = [];
        $cmopen = [];
        $roomusers = [];
        foreach ($this->occurrences as $occ) {
            foreach ($occ['studentids'] as $uid) {
                $roomusers[$occ['bbbid']][$uid] = true;
            }
        }
        $audiences = [];
        foreach ($this->occurrences as $key => $occ) {
            $bbbid = $occ['bbbid'];
            $cm = $cms[$bbbid] ?? null;
            if (!$cm) {
                // Activity deleted or not visible in modinfo: not measurable.
                $audiences[$key] = [];
                continue;
            }
            if (!isset($cmaudience[$bbbid])) {
                $info = new \core_availability\info_module($cm);
                $allowed = $cm->visible ? $info->filter_user_list($allstudents) : [];
                $cmaudience[$bbbid] = array_fill_keys(array_map('intval', array_keys($allowed)), true);
                $cmopen[$bbbid] = ((int)$cm->effectivegroupmode === NOGROUPS)
                    && count($cmaudience[$bbbid]) === count($allstudents);
            }
            $audience = $cmaudience[$bbbid];
            if ((int)$cm->effectivegroupmode !== NOGROUPS && $occ['groupid'] > 0) {
                $audience = array_intersect_key($audience, $this->get_group_members($occ['groupid']));
            } else if ($cmopen[$bbbid] && $occ['type'] === 'peer') {
                $audience = array_intersect_key($audience, $roomusers[$bbbid] ?? []);
            }
            // Anyone who actually attended could evidently attend.
            foreach ($occ['studentids'] as $uid) {
                $audience[$uid] = true;
            }
            $audiences[$key] = $audience;
        }
        return $audiences;
    }

    /**
     * Compute per-student metrics from the classified occurrences.
     */
    protected function analyse(): void {
        $multiplier = self::get_peer_multiplier();
        $audiences = $this->resolve_audiences();

        $blank = [
            'available' => 0,
            'attended' => 0,
            'weightavailable' => 0.0,
            'weightscore' => 0.0,
            'minutes' => 0,
            'talkminutes' => 0,
            'peersessions' => 0,
            'facilitatedavailable' => 0,
            'facilitatedattended' => 0,
            'peerids' => [],
            'lastattended' => 0,
        ];

        foreach ($this->occurrences as $key => $occ) {
            $weight = $occ['type'] === 'peer' ? $multiplier : 1.0;
            $isfacilitated = $occ['type'] === 'facilitated';
            $nstudents = count($occ['studentids']);

            // Fair share of student voice in this sitting.
            $studenttalk = 0;
            foreach ($occ['studentids'] as $uid) {
                $studenttalk += $occ['attendees'][$uid]['talk_time'];
            }
            $fairshare = $nstudents > 0 ? $studenttalk / $nstudents : 0;

            foreach (array_keys($audiences[$key] ?? []) as $uid) {
                $d = $this->studentdata[$uid] ?? $blank;
                $d['available']++;
                $d['weightavailable'] += $weight;
                if ($isfacilitated) {
                    $d['facilitatedavailable']++;
                }
                $this->studentdata[$uid] = $d;
            }

            foreach ($occ['studentids'] as $uid) {
                $a = $occ['attendees'][$uid];
                $d = $this->studentdata[$uid] ?? $blank;
                $d['attended']++;
                $d['weightscore'] += $weight * $this->participation_contribution($occ, $a, $fairshare);
                $d['minutes'] += (int)round($a['duration'] / 60);
                $d['talkminutes'] += (int)round($a['talk_time'] / 60);
                $d['lastattended'] = max($d['lastattended'], $occ['start']);
                if ($isfacilitated) {
                    $d['facilitatedattended']++;
                } else if ($nstudents >= 2) {
                    $d['peersessions']++;
                }
                if ($nstudents >= 2 && $nstudents <= self::SMALL_GROUP_MAX) {
                    foreach ($occ['studentids'] as $peer) {
                        if ($peer !== $uid) {
                            $d['peerids'][$peer] = true;
                        }
                    }
                }
                $this->studentdata[$uid] = $d;
            }
        }

        $total = 0.0;
        foreach ($this->studentdata as $d) {
            $total += $d['weightavailable'];
        }
        $this->intensity = !empty($this->students) ? $total / count($this->students) : 0.0;
    }

    /**
     * Participation credit (0–1) for one attendee in one sitting.
     *
     * 40% for being there, 60% for taking a fair share of the voice (speaking,
     * with each chat message counted as a few seconds of speech). When nobody
     * spoke — a muted lecture — any chat, poll vote, raised hand or reaction
     * earns the full share, and silent attendance half of it.
     *
     * @param array $occ The occurrence.
     * @param array $a The attendee's stats.
     * @param float $fairshare Mean student talk time in the sitting (seconds).
     * @return float
     */
    protected function participation_contribution(array $occ, array $a, float $fairshare): float {
        if (!$occ['rich']) {
            return self::JOINONLY_CONTRIBUTION;
        }
        if ($fairshare > 0) {
            $voice = min(1.0, ($a['talk_time'] + self::CHAT_SECONDS * $a['chats']) / $fairshare);
        } else {
            $active = $a['chats'] + $a['poll_votes'] + $a['raisehand'] + $a['emojis'] + $a['talks'];
            $voice = $active > 0 ? 1.0 : 0.5;
        }
        return 0.4 + 0.6 * $voice;
    }

    /**
     * Whether any live session has been held in the course.
     *
     * @return bool
     */
    public function has_sessions(): bool {
        return !empty($this->occurrences);
    }

    /**
     * Whether any live session started within a time window.
     *
     * @param int $from Window start (0 = no bound).
     * @param int $to Window end (0 = no bound).
     * @return bool
     */
    public function has_sessions_between(int $from, int $to): bool {
        foreach ($this->occurrences as $occ) {
            if (($from <= 0 || $occ['start'] >= $from) && ($to <= 0 || $occ['start'] <= $to)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Mean weighted number of live sessions available per student — how much
     * the course relies on live interaction. Peer sessions count at the peer
     * multiplier.
     *
     * @return float
     */
    public function get_intensity(): float {
        return $this->intensity;
    }

    /**
     * Held sessions, for diagnostics and tests.
     *
     * @return array
     */
    public function get_occurrences(): array {
        return $this->occurrences;
    }

    /**
     * Live-session metrics for one student.
     *
     * @param int $userid User id.
     * @return array{available:int, attended:int, liverate:int, minutes:int, talkminutes:int,
     *               peersessions:int, peerids:int[], facilitatedavailable:int,
     *               facilitatedattended:int, facilitatedrate:int, lastattended:int}
     */
    public function get_student(int $userid): array {
        $d = $this->studentdata[$userid] ?? null;
        if ($d === null) {
            return [
                'available' => 0, 'attended' => 0, 'liverate' => 0, 'minutes' => 0, 'talkminutes' => 0,
                'peersessions' => 0, 'peerids' => [], 'facilitatedavailable' => 0,
                'facilitatedattended' => 0, 'facilitatedrate' => 0, 'lastattended' => 0,
            ];
        }
        $liverate = $d['weightavailable'] > 0
            ? (int)min(100, round(100 * $d['weightscore'] / $d['weightavailable']))
            : 0;
        $facilitatedrate = $d['facilitatedavailable'] > 0
            ? (int)min(100, round(100 * $d['facilitatedattended'] / $d['facilitatedavailable']))
            : 0;
        return [
            'available' => $d['available'],
            'attended' => $d['attended'],
            'liverate' => $liverate,
            'minutes' => $d['minutes'],
            'talkminutes' => $d['talkminutes'],
            'peersessions' => $d['peersessions'],
            'peerids' => array_keys($d['peerids']),
            'facilitatedavailable' => $d['facilitatedavailable'],
            'facilitatedattended' => $d['facilitatedattended'],
            'facilitatedrate' => $facilitatedrate,
            'lastattended' => $d['lastattended'],
        ];
    }

    /**
     * Course-level summary of facilitated live sessions, for teaching presence.
     *
     * @param int[]|null $userids Restrict reach to these students (e.g. the
     *                            viewer's scoped cohort); null = all students.
     * @return array{held:int, meanminutes:int, reach:int, reachable:int, reached:int}
     */
    public function get_teacher_summary(?array $userids = null): array {
        $held = 0;
        $minutes = 0;
        foreach ($this->occurrences as $occ) {
            if ($occ['type'] !== 'facilitated') {
                continue;
            }
            $held++;
            $longest = 0;
            foreach ($occ['attendees'] as $a) {
                $longest = max($longest, $a['duration']);
            }
            $minutes += $longest / 60;
        }
        $reachable = 0;
        $reached = 0;
        $ids = $userids ?? array_keys($this->students);
        foreach ($ids as $uid) {
            $d = $this->studentdata[(int)$uid] ?? null;
            if ($d && $d['facilitatedavailable'] > 0) {
                $reachable++;
                if ($d['facilitatedattended'] > 0) {
                    $reached++;
                }
            }
        }
        return [
            'held' => $held,
            'meanminutes' => $held > 0 ? (int)round($minutes / $held) : 0,
            'reach' => $reachable > 0 ? (int)round(100 * $reached / $reachable) : 0,
            'reachable' => $reachable,
            'reached' => $reached,
        ];
    }

    /**
     * Members of a group, memoised.
     *
     * @param int $groupid Group id.
     * @return array userid => true
     */
    protected function get_group_members(int $groupid): array {
        if (!isset($this->groupmembers[$groupid])) {
            $this->groupmembers[$groupid] = array_fill_keys(
                array_map('intval', array_keys(groups_get_members($groupid, 'u.id'))),
                true
            );
        }
        return $this->groupmembers[$groupid];
    }

    /**
     * Teachers responsible for a peer (student-only) session: the teachers in
     * the group whose room it was; otherwise the teacher who created the BBB
     * activity; otherwise every course teacher.
     *
     * @param array $occ The occurrence.
     * @return int[]
     */
    protected function get_responsible_teachers(array $occ): array {
        global $DB;
        if ($occ['groupid'] > 0) {
            $ids = array_keys(array_intersect_key($this->teachers, $this->get_group_members($occ['groupid'])));
            if (!empty($ids)) {
                return $ids;
            }
        }
        if ($this->creators === null) {
            $this->creators = [];
            $rows = $DB->get_records_sql(
                "SELECT l.id, l.bigbluebuttonbnid, l.userid
                   FROM {bigbluebuttonbn_logs} l
                   JOIN {bigbluebuttonbn} b ON b.id = l.bigbluebuttonbnid
                  WHERE b.course = :courseid AND l.log = 'Add' AND l.userid IS NOT NULL
               ORDER BY l.timecreated, l.id",
                ['courseid' => $this->courseid]
            );
            foreach ($rows as $row) {
                $this->creators[(int)$row->bigbluebuttonbnid] ??= (int)$row->userid;
            }
        }
        $creator = $this->creators[$occ['bbbid']] ?? 0;
        if ($creator && isset($this->teachers[$creator])) {
            return [$creator];
        }
        return array_keys($this->teachers);
    }

    /**
     * The students a teacher is responsible for: members of the teacher's
     * groups, or every student when the teacher is in no group.
     *
     * @param int $teacherid Teacher user id.
     * @return array userid => true
     */
    protected function get_teacher_students(int $teacherid): array {
        $groupids = groups_get_user_groups($this->courseid, $teacherid)[0] ?? [];
        if (empty($groupids)) {
            return $this->students;
        }
        $members = [];
        foreach ($groupids as $gid) {
            $members += $this->get_group_members((int)$gid);
        }
        return array_intersect_key($this->students, $members);
    }

    /**
     * Live-teaching metrics for one teacher, optionally within a time window.
     *
     * Sessions the teacher attended count in full; student-only sessions in
     * rooms they are responsible for (see {@see get_responsible_teachers()})
     * count at the peer credit. Reach is the share of the teacher's students
     * who attended at least one of those sessions.
     *
     * @param int $teacherid Teacher user id.
     * @param int $from Only sessions starting at or after this time (0 = no bound).
     * @param int $to Only sessions starting at or before this time (0 = no bound).
     * @return array{facilitated:int, peer:int, credited:float, minutes:int, reach:int,
     *               reachable:int, reached:int, last:int}
     */
    public function get_teacher(int $teacherid, int $from = 0, int $to = 0): array {
        $facilitated = 0;
        $peer = 0;
        $minutes = 0.0;
        $reachedids = [];
        $last = 0;
        foreach ($this->occurrences as $occ) {
            if (($from > 0 && $occ['start'] < $from) || ($to > 0 && $occ['start'] > $to)) {
                continue;
            }
            if (isset($occ['attendees'][$teacherid])) {
                $facilitated++;
                $minutes += $occ['rich']
                    ? $occ['attendees'][$teacherid]['duration'] / 60
                    : self::JOINONLY_MINUTES;
            } else if (
                $occ['type'] === 'peer' && count($occ['studentids']) >= 2
                && in_array($teacherid, $this->get_responsible_teachers($occ), true)
            ) {
                $peer++;
            } else {
                continue;
            }
            foreach ($occ['studentids'] as $uid) {
                $reachedids[$uid] = true;
            }
            $last = max($last, $occ['start']);
        }
        $mystudents = $this->get_teacher_students($teacherid);
        $reachable = count($mystudents);
        $reached = count(array_intersect_key($reachedids, $mystudents));
        return [
            'facilitated' => $facilitated,
            'peer' => $peer,
            'credited' => $facilitated + self::get_peer_credit() * $peer,
            'minutes' => (int)round($minutes),
            'reach' => $reachable > 0 ? (int)round(100 * $reached / $reachable) : 0,
            'reachable' => $reachable,
            'reached' => $reached,
            'last' => $last,
        ];
    }
}
