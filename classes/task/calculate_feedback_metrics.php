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
 * Scheduled task to pre-compute feedback quality metrics for the coordinator report.
 *
 * Runs daily (default 2:00 AM) to calculate feedback coverage, depth,
 * personalisation, and structured grading scores per teacher per course.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gradereport_coifish\task;

use core\task\scheduled_task;

/**
 * Calculate feedback quality metrics and cache them in the database.
 */
class calculate_feedback_metrics extends scheduled_task {
    /**
     * Return the task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_feedback_metrics', 'gradereport_coifish');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        global $DB;

        // Only process if the coordinator feature and feedback dimension are enabled.
        $coordenabled = get_config('gradereport_coifish', 'coordinator_enabled');
        if ($coordenabled === '0') {
            return;
        }
        $feedbackenabled = get_config('gradereport_coifish', 'coordinator_feedback_enabled');
        if ($feedbackenabled === '0') {
            return;
        }

        // Find all visible courses with assigned teachers.
        $courses = $DB->get_records('course', ['visible' => 1], '', 'id');
        unset($courses[SITEID]);

        $now = time();

        foreach ($courses as $course) {
            $context = \context_course::instance($course->id, IGNORE_MISSING);
            if (!$context) {
                continue;
            }

            // Get teachers (users with grading capability).
            $teachers = get_enrolled_users($context, 'moodle/grade:viewall', 0, 'u.id', null, 0, 0, true);
            if (empty($teachers)) {
                continue;
            }

            $teacherids = array_keys($teachers);

            // Calculate metrics for each teacher.
            $coveragedata = $this->get_feedback_coverage($course->id, $teacherids);
            $textdata = $this->get_feedback_text_analysis($course->id, $teacherids);
            $structuredscore = $this->get_structured_grading_score($course->id);

            foreach ($teacherids as $uid) {
                $coverage = $coveragedata[$uid] ?? ['total' => 0, 'withfeedback' => 0, 'score' => 0];
                $text = $textdata[$uid] ?? [
                    'avgwords' => 0, 'depthscore' => 0,
                    'uniquepct' => 0, 'persscore' => 0,
                    'qualityscore' => 0,
                ];

                // Sub-weights: coverage 30%, depth 20%, quality 20%, personalisation 15%, structured 15%.
                $composite = round(
                    $coverage['score'] * 0.30 +
                    $text['depthscore'] * 0.20 +
                    $text['qualityscore'] * 0.20 +
                    $text['persscore'] * 0.15 +
                    $structuredscore * 0.15
                );

                $record = [
                    'courseid' => $course->id,
                    'userid' => $uid,
                    'coverage' => $coverage['score'],
                    'depth' => $text['depthscore'],
                    'personalisation' => $text['persscore'],
                    'structured' => $structuredscore,
                    'composite' => $composite,
                    'totalgraded' => $coverage['total'],
                    'withfeedback' => $coverage['withfeedback'],
                    'avgwords' => $text['avgwords'],
                    'uniquepct' => $text['uniquepct'],
                    'qualityscore' => $text['qualityscore'],
                    'timemodified' => $now,
                ];

                $existing = $DB->get_record('gradereport_coifish_feedback', [
                    'courseid' => $course->id,
                    'userid' => $uid,
                ]);

                if ($existing) {
                    $record['id'] = $existing->id;
                    $DB->update_record('gradereport_coifish_feedback', (object)$record);
                } else {
                    $DB->insert_record('gradereport_coifish_feedback', (object)$record);
                }
            }
        }
    }

    /**
     * Calculate feedback coverage per teacher: % of graded items with written comments.
     *
     * @param int $courseid The course ID.
     * @param array $teacherids Teacher user IDs.
     * @return array Keyed by userid with 'total', 'withfeedback', 'score'.
     */
    protected function get_feedback_coverage(int $courseid, array $teacherids): array {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'tc');

        // Native assignment-grading feedback signals: assignfeedback_comments + editpdf.
        // Unified Grader scomm/annot records for the matching cmid/student also count —
        // but only when UG is installed AND configured to handle assignments.
        $ugjoin = '';
        $ugcondition = '';
        $ugparams = [];
        $ugmodnames = \gradereport_coifish\report::get_unifiedgrader_enabled_modnames();
        if (in_array('assign', $ugmodnames, true)) {
            $ugjoin = "
               LEFT JOIN (
                    SELECT DISTINCT cm.instance AS assignid, s.userid, s.authorid
                      FROM {local_unifiedgrader_scomm} s
                      JOIN {course_modules} cm ON cm.id = s.cmid
                      JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                     WHERE cm.course = :ugcid1
               ) ugs ON ugs.assignid = ag.assignment AND ugs.userid = ag.userid AND ugs.authorid = ag.grader
               LEFT JOIN (
                    SELECT DISTINCT cm.instance AS assignid, an.userid, an.authorid
                      FROM {local_unifiedgrader_annot} an
                      JOIN {course_modules} cm ON cm.id = an.cmid
                      JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                     WHERE cm.course = :ugcid2
               ) uga ON uga.assignid = ag.assignment AND uga.userid = ag.userid AND uga.authorid = ag.grader";
            $ugcondition = " OR ugs.userid IS NOT NULL OR uga.userid IS NOT NULL";
            $ugparams = ['ugcid1' => $courseid, 'ugcid2' => $courseid];
        }

        $records = $DB->get_records_sql(
            "SELECT ag.grader AS userid,
                    COUNT(ag.id) AS total_graded,
                    SUM(CASE WHEN (fc.id IS NOT NULL OR pc.cnt > 0$ugcondition) THEN 1 ELSE 0 END) AS with_feedback
               FROM {assign_grades} ag
               JOIN {assign} a ON a.id = ag.assignment
               LEFT JOIN {assignfeedback_comments} fc
                    ON fc.grade = ag.id
                    AND fc.commenttext IS NOT NULL
                    AND fc.commenttext != ''
               LEFT JOIN (
                    SELECT gradeid, COUNT(*) AS cnt
                      FROM {assignfeedback_editpdf_cmnt}
                     WHERE draft = 0
                  GROUP BY gradeid
               ) pc ON pc.gradeid = ag.id$ugjoin
              WHERE a.course = :courseid
                AND ag.grader $insql
                AND ag.grade >= 0
           GROUP BY ag.grader",
            array_merge(['courseid' => $courseid], $inparams, $ugparams)
        );

        $result = [];
        foreach ($records as $row) {
            $total = (int)$row->total_graded;
            $withfb = (int)$row->with_feedback;
            $result[$row->userid] = [
                'total' => $total,
                'withfeedback' => $withfb,
            ];
        }

        // Add UG-only feedback for non-assignment activities (quiz/forum/BBB).
        // Each unique (teacher, cmid, student) interaction in scomm/annot/qfb counts
        // as one graded item with feedback, since UG only writes these when the
        // teacher is actively grading.
        $nonassign = $this->get_ug_nonassign_signals($courseid, $teacherids);

        // Gradebook-native feedback on non-assign items — this is where UG's
        // forum adapter writes its overall feedback (grade_grades.feedback on
        // the forum's grade item) and also where any teacher entering
        // feedback via the gradebook editor or advanced grading on lessons,
        // workshops, etc. ends up. Not gated by UG since this is a Moodle-
        // native channel that any institution may use.
        $gradebook = $this->get_gradebook_feedback_signals($courseid, $teacherids);

        foreach ($teacherids as $uid) {
            $extra = ($nonassign[$uid] ?? 0) + ($gradebook[$uid] ?? 0);
            $total = ($result[$uid]['total'] ?? 0) + $extra;
            $withfb = ($result[$uid]['withfeedback'] ?? 0) + $extra;
            $pct = $total > 0 ? ($withfb / $total) : 0;
            // 80% coverage = 100 score.
            $score = min(100, round($pct / 0.80 * 100));
            $result[$uid] = [
                'total' => $total,
                'withfeedback' => $withfb,
                'score' => $score,
            ];
        }
        return $result;
    }

    /**
     * Count gradebook-native feedback on non-assignment graded items.
     *
     * Each non-empty `grade_grades.feedback` row on a non-`assign` grade item
     * counts as one feedback artifact, attributed to whoever last modified
     * the row (`usermodified`). This is where Unified Grader's forum adapter
     * writes overall feedback, and also where any institution that uses the
     * gradebook editor / advanced grading interface for lessons, workshops,
     * etc. stores their feedback. Pure Moodle-core lookup — works without UG.
     *
     * @param int $courseid The course ID.
     * @param array $teacherids Teacher user IDs.
     * @return array Map of teacherid => artifact count.
     */
    protected function get_gradebook_feedback_signals(int $courseid, array $teacherids): array {
        global $DB;
        if (empty($teacherids)) {
            return [];
        }
        [$insql, $inparams] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'gbfb');
        $rows = $DB->get_records_sql(
            "SELECT gg.usermodified AS userid, COUNT(gg.id) AS cnt
               FROM {grade_grades} gg
               JOIN {grade_items} gi ON gi.id = gg.itemid
              WHERE gi.courseid = :courseid
                AND gi.itemtype = 'mod'
                AND gi.itemmodule != 'assign'
                AND gg.feedback IS NOT NULL
                AND gg.feedback != ''
                AND gg.usermodified $insql
           GROUP BY gg.usermodified",
            array_merge(['courseid' => $courseid], $inparams)
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int)$row->userid] = (int)$row->cnt;
        }
        return $out;
    }

    /**
     * Count Unified Grader feedback interactions on non-assignment activities.
     *
     * Each unique (teacher, cmid, student) pair in scomm/annot/qfb where the cmid
     * is a quiz/forum/bigbluebuttonbn counts as one feedback artifact. Returns
     * empty when UG is not installed.
     *
     * @param int $courseid The course ID.
     * @param array $teacherids Teacher user IDs.
     * @return array Map of teacherid => artifact count.
     */
    protected function get_ug_nonassign_signals(int $courseid, array $teacherids): array {
        global $DB;

        // Only count UG records for activity types that are actually enabled in UG.
        $enabledmods = \gradereport_coifish\report::get_unifiedgrader_enabled_modnames();
        $nonassignmods = array_values(array_filter($enabledmods, function ($m) {
            return $m !== 'assign';
        }));
        if (empty($nonassignmods)) {
            return [];
        }

        // Moodle's named-parameter SQL is converted to positional placeholders
        // per occurrence, so re-using the same IN-clause variable across branches
        // of a UNION inflates the expected param count. We mint a fresh IN
        // clause (and a fresh teacher-IN clause) for every branch.
        [$inscomm, $pscomm] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'ugtsc');
        [$inannot, $psannot] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'ugtan');
        [$modscomm, $pmodscomm] = $DB->get_in_or_equal($nonassignmods, SQL_PARAMS_NAMED, 'ugmsc');
        [$modannot, $pmodannot] = $DB->get_in_or_equal($nonassignmods, SQL_PARAMS_NAMED, 'ugman');

        $sql = "SELECT authorid AS userid, COUNT(*) AS cnt
                  FROM (
                      SELECT DISTINCT s.authorid, s.cmid, s.userid
                        FROM {local_unifiedgrader_scomm} s
                        JOIN {course_modules} cm ON cm.id = s.cmid
                        JOIN {modules} m ON m.id = cm.module
                       WHERE cm.course = :cid1
                         AND m.name $modscomm
                         AND s.authorid $inscomm
                      UNION
                      SELECT DISTINCT an.authorid, an.cmid, an.userid
                        FROM {local_unifiedgrader_annot} an
                        JOIN {course_modules} cm ON cm.id = an.cmid
                        JOIN {modules} m ON m.id = cm.module
                       WHERE cm.course = :cid2
                         AND m.name $modannot
                         AND an.authorid $inannot
                  ) sub
              GROUP BY authorid";
        $params = array_merge(
            ['cid1' => $courseid, 'cid2' => $courseid],
            $pscomm,
            $psannot,
            $pmodscomm,
            $pmodannot
        );

        // The qfb table is quiz-only — include only when UG is enabled for quizzes.
        $dbman = $DB->get_manager();
        if (in_array('quiz', $nonassignmods, true) && $dbman->table_exists('local_unifiedgrader_qfb')) {
            [$inqfb, $pqfb] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'ugtqf');
            $sql = "SELECT userid, SUM(cnt) AS cnt FROM (" . $sql . "
                    UNION ALL
                    SELECT q.grader AS userid, COUNT(*) AS cnt
                      FROM {local_unifiedgrader_qfb} q
                      JOIN {course_modules} cm ON cm.id = q.cmid
                     WHERE cm.course = :cid3
                       AND q.feedback IS NOT NULL
                       AND q.feedback != ''
                       AND q.grader $inqfb
                  GROUP BY q.grader
                  ) merged
              GROUP BY userid";
            $params['cid3'] = $courseid;
            $params = array_merge($params, $pqfb);
        }

        $rows = $DB->get_records_sql($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $out[(int)$row->userid] = (int)$row->cnt;
        }
        return $out;
    }

    /**
     * Analyse feedback text for depth, personalisation, and qualitative indicators.
     *
     * Qualitative analysis is based on three research-informed markers:
     * - Dialogic: contains questions that invite student reflection (Nicol & Macfarlane-Dick, 2006)
     * - Actionable: contains forward-looking language with suggestions (Hattie & Timperley, 2007)
     * - Substantive: goes beyond generic praise phrases (Boud & Molloy, 2013)
     *
     * @param int $courseid The course ID.
     * @param array $teacherids Teacher user IDs.
     * @return array Keyed by userid.
     */
    protected function get_feedback_text_analysis(int $courseid, array $teacherids): array {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'td');

        $records = $DB->get_records_sql(
            "SELECT fc.id, ag.grader AS userid, ag.assignment AS bucketid, fc.commenttext
               FROM {assignfeedback_comments} fc
               JOIN {assign_grades} ag ON ag.id = fc.grade
               JOIN {assign} a ON a.id = ag.assignment
              WHERE a.course = :courseid
                AND ag.grader $insql
                AND ag.grade >= 0
                AND fc.commenttext IS NOT NULL
                AND fc.commenttext != ''",
            array_merge(['courseid' => $courseid], $inparams)
        );

        // Group by teacher.
        $byteacher = [];
        foreach ($records as $row) {
            $uid = $row->userid;
            if (!isset($byteacher[$uid])) {
                $byteacher[$uid] = [];
            }
            $byteacher[$uid][] = (object)[
                'commenttext' => $row->commenttext,
                'bucketid' => 'assign:' . $row->bucketid,
            ];
        }

        // Augment with Unified Grader scomm + qfb feedback content (any activity type).
        foreach ($this->get_ug_feedback_comments($courseid, $teacherids) as $uid => $rows) {
            if (!isset($byteacher[$uid])) {
                $byteacher[$uid] = [];
            }
            foreach ($rows as $row) {
                $byteacher[$uid][] = $row;
            }
        }

        // Augment with gradebook-native feedback on non-assign items. This is
        // where UG's forum adapter writes its overall feedback, and the only
        // way to pick up forum / lesson / workshop feedback that isn't echoed
        // into a side-channel table.
        foreach ($this->get_gradebook_feedback_comments($courseid, $teacherids) as $uid => $rows) {
            if (!isset($byteacher[$uid])) {
                $byteacher[$uid] = [];
            }
            foreach ($rows as $row) {
                $byteacher[$uid][] = $row;
            }
        }

        $result = [];
        foreach ($byteacher as $uid => $comments) {
            $totalwords = 0;
            $count = count($comments);
            $byassignment = [];
            $totalqualityscore = 0;

            $count = count($comments);
            foreach ($comments as $row) {
                $plaintext = strip_tags($row->commenttext);
                $wordcount = str_word_count($plaintext);
                $hasmedia = $this->has_multimedia_feedback($row->commenttext);

                // Multimedia feedback (voice notes, video, screen-shares embedded
                // via UG, Loom, BBB, native HTML5 recorders) is high-effort and
                // student-engaging but invisible to plain-text analysis. Without
                // AI-based transcription we can't measure its depth directly —
                // so we credit it with a generous floor: word-count treated as
                // 80 (well over the 50-word depth ceiling) and full 3/3 on
                // qualitative markers.
                if ($hasmedia) {
                    $totalwords += max($wordcount, 80);
                    $totalqualityscore += 3;
                } else {
                    $totalwords += $wordcount;
                    $totalqualityscore += $this->score_comment_quality($plaintext);
                }

                $bucket = $row->bucketid;
                if (!isset($byassignment[$bucket])) {
                    $byassignment[$bucket] = [];
                }
                // Multimedia bodies should be treated as unique per recipient
                // (the teacher recorded specifically for them), so we don't
                // collapse them into a "duplicate" bucket via shared text.
                $normalised = $hasmedia
                    ? ('media:' . spl_object_hash((object)$row))
                    : strtolower(trim($plaintext));
                $byassignment[$bucket][] = $normalised;
            }

            // Depth score.
            $avgwords = $count > 0 ? round($totalwords / $count, 1) : 0;
            $depthscore = min(100, round($avgwords / 50 * 100));

            // Personalisation score.
            $totalcomments = 0;
            $uniquecomments = 0;
            foreach ($byassignment as $bucketcomments) {
                $totalcomments += count($bucketcomments);
                $uniquecomments += count(array_unique($bucketcomments));
            }
            $uniquepct = $totalcomments > 0
                ? round($uniquecomments / $totalcomments * 100, 1)
                : 100;
            $persscore = min(100, round($uniquepct / 70 * 100));

            // Quality score: average quality points (0-3) normalised to 0-100.
            // 2 out of 3 markers on average = 100 score.
            $avgquality = $count > 0 ? ($totalqualityscore / $count) : 0;
            $qualityscore = min(100, round($avgquality / 2.0 * 100));

            $result[$uid] = [
                'avgwords' => $avgwords,
                'depthscore' => $depthscore,
                'uniquepct' => $uniquepct,
                'persscore' => $persscore,
                'qualityscore' => $qualityscore,
            ];
        }
        return $result;
    }

    /**
     * Fetch Unified Grader textual feedback (scomm + qfb) per teacher.
     *
     * Includes feedback authored across every UG-supported activity type
     * (assignments, quizzes, forums, BigBlueButton). Each comment is bucketed
     * by cmid so personalisation analysis treats per-activity uniqueness the
     * same way as native assignment-feedback. Returns empty when UG is not
     * installed.
     *
     * @param int $courseid The course ID.
     * @param array $teacherids Teacher user IDs.
     * @return array Map of teacherid => array of stdClass{commenttext, bucketid}.
     */
    protected function get_ug_feedback_comments(int $courseid, array $teacherids): array {
        global $DB;

        $enabledmods = \gradereport_coifish\report::get_unifiedgrader_enabled_modnames();
        if (empty($enabledmods)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'ugc');
        [$modinsql, $modparams] = $DB->get_in_or_equal($enabledmods, SQL_PARAMS_NAMED, 'ugcm');
        $result = [];

        // Submission comments — only on UG-enabled activity types.
        $rows = $DB->get_records_sql(
            "SELECT s.id, s.authorid AS userid, s.cmid, s.content AS commenttext
               FROM {local_unifiedgrader_scomm} s
               JOIN {course_modules} cm ON cm.id = s.cmid
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid
                AND m.name $modinsql
                AND s.authorid $insql
                AND s.content IS NOT NULL
                AND s.content != ''",
            array_merge(['courseid' => $courseid], $inparams, $modparams)
        );
        foreach ($rows as $row) {
            $result[(int)$row->userid][] = (object)[
                'commenttext' => $row->commenttext,
                'bucketid' => 'ug-scomm:' . $row->cmid,
            ];
        }

        // Quiz feedback per attempt — only when UG is enabled for quizzes.
        $dbman = $DB->get_manager();
        if (in_array('quiz', $enabledmods, true) && $dbman->table_exists('local_unifiedgrader_qfb')) {
            $qrows = $DB->get_records_sql(
                "SELECT q.id, q.grader AS userid, q.cmid, q.feedback AS commenttext
                   FROM {local_unifiedgrader_qfb} q
                   JOIN {course_modules} cm ON cm.id = q.cmid
                  WHERE cm.course = :courseid
                    AND q.grader $insql
                    AND q.feedback IS NOT NULL
                    AND q.feedback != ''",
                array_merge(['courseid' => $courseid], $inparams)
            );
            foreach ($qrows as $row) {
                $result[(int)$row->userid][] = (object)[
                    'commenttext' => $row->commenttext,
                    'bucketid' => 'ug-qfb:' . $row->cmid,
                ];
            }
        }

        return $result;
    }

    /**
     * Fetch gradebook-native feedback text on non-assign graded items.
     *
     * Reads non-empty `grade_grades.feedback` rows on grade items of any
     * non-`assign` module. This catches UG forum feedback (which is written to
     * the forum's grade_grades.feedback by the forum adapter) and any other
     * gradebook-entered feedback on lessons, workshops, etc. The grader is the
     * row's `usermodified`. Pure Moodle-core lookup — works without UG.
     *
     * @param int $courseid The course ID.
     * @param array $teacherids Teacher user IDs.
     * @return array Map of teacherid => array of stdClass{commenttext, bucketid}.
     */
    protected function get_gradebook_feedback_comments(int $courseid, array $teacherids): array {
        global $DB;
        if (empty($teacherids)) {
            return [];
        }
        [$insql, $inparams] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'gbfc');
        $rows = $DB->get_records_sql(
            "SELECT gg.id, gg.usermodified AS userid, gi.id AS itemid,
                    gi.itemmodule, gg.feedback AS commenttext
               FROM {grade_grades} gg
               JOIN {grade_items} gi ON gi.id = gg.itemid
              WHERE gi.courseid = :courseid
                AND gi.itemtype = 'mod'
                AND gi.itemmodule != 'assign'
                AND gg.feedback IS NOT NULL
                AND gg.feedback != ''
                AND gg.usermodified $insql",
            array_merge(['courseid' => $courseid], $inparams)
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row->userid][] = (object)[
                'commenttext' => $row->commenttext,
                'bucketid' => 'gradebook:' . $row->itemmodule . ':' . $row->itemid,
            ];
        }
        return $result;
    }

    /**
     * Score a single feedback comment for qualitative indicators (0-3 points).
     *
     * Markers:
     * 1. Dialogic - contains a question mark (invites reflection)
     * 2. Actionable - contains forward-looking/improvement language
     * 3. Substantive - is NOT a short generic praise phrase
     *
     * @param string $plaintext The plain-text comment (HTML already stripped).
     * @return int Score 0-3.
     */
    /**
     * Detect whether a feedback body embeds multimedia (voice / video / screen-share).
     *
     * Teachers increasingly leave personalised feedback as audio, video, or
     * screen-recording rather than text — UG, Loom, BBB, and HTML5 media
     * recorders all produce content that includes one of these signatures:
     *  - native `<audio>` / `<video>` tags
     *  - `<iframe>` (Loom, YouTube, Vimeo, BBB recording embeds)
     *  - file-area links to .mp3 / .mp4 / .webm / .ogg / .m4a / .mov
     *  - the "@@PLUGINFILE@@" marker pointing at media extensions
     *
     * The match is intentionally permissive — false positives (flagging a
     * filename mentioned in text as media) cost less here than false negatives,
     * which would discard the entire effort the teacher put into recording.
     *
     * @param string $html The raw HTML body of the feedback record.
     * @return bool
     */
    protected function has_multimedia_feedback(string $html): bool {
        if ($html === '' || $html === null) {
            return false;
        }
        $lower = strtolower($html);
        if (preg_match('/<\s*(audio|video|iframe|source|embed|object)[\s>]/', $lower)) {
            return true;
        }
        if (preg_match('/\.(mp3|mp4|m4a|m4v|webm|ogg|ogv|mov|wav|aac|flac)(\?|\b)/', $lower)) {
            return true;
        }
        // Common embed-provider markers.
        if (preg_match('/(loom\.com|youtube\.com|youtu\.be|vimeo\.com|bigbluebutton|screencast)/', $lower)) {
            return true;
        }
        return false;
    }

    /**
     * Score a feedback comment on a 0-3 quality scale (dialogic + actionable + substantive).
     *
     * @param string $plaintext Stripped feedback text.
     * @return int Score from 0 to 3.
     */
    protected function score_comment_quality(string $plaintext): int {
        $score = 0;
        $lower = strtolower($plaintext);

        // 1. Dialogic: contains a question.
        if (strpos($plaintext, '?') !== false) {
            $score++;
        }

        // 2. Actionable: contains forward-looking or improvement language.
        $actionable = [
            'consider', 'try', 'next time', 'improve', 'revise', 'strengthen',
            'revisit', 'think about', 'reflect on', 'you could', 'you might',
            'suggest', 'recommendation', 'work on', 'focus on', 'develop',
            'expand', 'elaborate', 'clarify', 'address', 'explore',
        ];
        foreach ($actionable as $phrase) {
            if (strpos($lower, $phrase) !== false) {
                $score++;
                break;
            }
        }

        // 3. Substantive: not a short generic praise phrase.
        // Generic if fewer than 8 words AND matches common praise patterns.
        $wordcount = str_word_count($plaintext);
        $generic = [
            'good', 'great', 'well done', 'nice work', 'nice job', 'excellent',
            'good job', 'good work', 'keep it up', 'ok', 'okay', 'fine',
            'pass', 'adequate', 'satisfactory', 'perfect',
        ];
        $isgeneric = false;
        if ($wordcount < 8) {
            foreach ($generic as $phrase) {
                if (strpos($lower, $phrase) !== false) {
                    $isgeneric = true;
                    break;
                }
            }
        }
        if (!$isgeneric) {
            $score++;
        }

        return $score;
    }

    /**
     * Calculate structured grading usage score: % of assignments using rubrics or marking guides.
     *
     * This is a course-level metric applied equally to all teachers.
     *
     * @param int $courseid The course ID.
     * @return int Score 0-100.
     */
    protected function get_structured_grading_score(int $courseid): int {
        global $DB;

        // Total assignments in the course.
        $totalassignments = $DB->count_records('assign', ['course' => $courseid]);
        if ($totalassignments == 0) {
            return 50; // Neutral if no assignments.
        }

        // Assignments with active rubric or marking guide.
        $withrubric = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT cm.instance)
               FROM {grading_areas} ga
               JOIN {grading_definitions} gd ON gd.areaid = ga.id
               JOIN {context} ctx ON ctx.id = ga.contextid
               JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :ctxlevel
               JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
              WHERE cm.course = :courseid
                AND ga.component = 'mod_assign'
                AND gd.status = :status",
            [
                'ctxlevel' => CONTEXT_MODULE,
                'courseid' => $courseid,
                'status' => 2, // Active definition.
            ]
        );

        $pct = $withrubric / $totalassignments;
        // 50% usage = 100 score.
        return min(100, round($pct / 0.50 * 100));
    }
}
