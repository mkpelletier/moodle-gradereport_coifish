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
            $teachers = get_enrolled_users($context, 'moodle/grade:viewall', 0, 'u.id');
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

        $records = $DB->get_records_sql(
            "SELECT ag.grader AS userid,
                    COUNT(ag.id) AS total_graded,
                    SUM(CASE WHEN (fc.id IS NOT NULL OR pc.cnt > 0) THEN 1 ELSE 0 END) AS with_feedback
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
               ) pc ON pc.gradeid = ag.id
              WHERE a.course = :courseid
                AND ag.grader $insql
                AND ag.grade >= 0
           GROUP BY ag.grader",
            array_merge(['courseid' => $courseid], $inparams)
        );

        $result = [];
        foreach ($records as $row) {
            $total = (int)$row->total_graded;
            $withfb = (int)$row->with_feedback;
            $pct = $total > 0 ? ($withfb / $total) : 0;
            // 80% coverage = 100 score.
            $score = min(100, round($pct / 0.80 * 100));
            $result[$row->userid] = [
                'total' => $total,
                'withfeedback' => $withfb,
                'score' => $score,
            ];
        }
        return $result;
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
            "SELECT fc.id, ag.grader AS userid, ag.assignment, fc.commenttext
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
            $byteacher[$uid][] = $row;
        }

        $result = [];
        foreach ($byteacher as $uid => $comments) {
            $totalwords = 0;
            $count = count($comments);
            $byassignment = [];
            $totalqualityscore = 0;

            foreach ($comments as $row) {
                $plaintext = strip_tags($row->commenttext);
                $wordcount = str_word_count($plaintext);
                $totalwords += $wordcount;

                // Qualitative analysis per comment (0-3 points).
                $totalqualityscore += $this->score_comment_quality($plaintext);

                $assignid = $row->assignment;
                if (!isset($byassignment[$assignid])) {
                    $byassignment[$assignid] = [];
                }
                $normalised = strtolower(trim($plaintext));
                $byassignment[$assignid][] = $normalised;
            }

            // Depth score.
            $avgwords = $count > 0 ? round($totalwords / $count, 1) : 0;
            $depthscore = min(100, round($avgwords / 50 * 100));

            // Personalisation score.
            $totalcomments = 0;
            $uniquecomments = 0;
            foreach ($byassignment as $assigncomments) {
                $totalcomments += count($assigncomments);
                $uniquecomments += count(array_unique($assigncomments));
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
