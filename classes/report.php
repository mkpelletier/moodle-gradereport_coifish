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
 * Core report class for the Grade Tracker.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gradereport_coifish;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/grade/report/lib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Grade report that displays a user-friendly overview of assessments with weights and contributions.
 */
class report extends \grade_report {
    /** @var int Max at-risk rows rendered in the cohort insights table (sorted most-at-risk-first). */
    protected const ATRISK_RENDER_CAP = 100;

    /** @var int The user ID to display grades for (0 = no user selected). */
    protected int $userid;

    /** @var int The group ID for filtering (0 = all users). */
    protected int $groupid;

    /** @var array Structured grade data ready for template rendering. */
    protected array $gradedata = [];

    /** @var \grade_item The course-level grade item. */
    protected \grade_item $courseitem;

    /** @var array Cached grade_grade records keyed by grade item ID. */
    protected array $usergrades = [];

    /** @var bool Whether any categories have weights (multiple top-level categories). */
    protected bool $hasweights = false;

    /** @var bool Whether the current user can view hidden grade items. */
    protected bool $canviewhidden = false;

    /** @var \course_modinfo|null Cached course module info for URL/availability lookups. */
    protected ?\course_modinfo $modinfo = null;

    /** @var array Cached assignment submission/deadline data keyed by assign instance ID. */
    protected array $assigndata = [];

    /** @var array Cached quiz attempt/deadline data keyed by quiz instance ID. */
    protected array $quizdata = [];

    /**
     * Constructor.
     *
     * @param int $courseid The course ID.
     * @param \grade_plugin_return $gpr Grade plugin return tracking object.
     * @param \context_course $context The course context.
     * @param int $userid The user whose grades to display (0 = none selected).
     * @param int $groupid The group filter (0 = all).
     * @param bool $showhidden Whether to show hidden items (requires moodle/grade:viewhidden).
     */
    public function __construct(
        int $courseid,
        \grade_plugin_return $gpr,
        \context_course $context,
        int $userid = 0,
        int $groupid = 0,
        bool $showhidden = false
    ) {
        parent::__construct($courseid, $gpr, $context);

        $this->userid = $userid;
        $this->groupid = $groupid;
        // Only show hidden items if the user has the capability AND has opted in.
        $this->canviewhidden = $showhidden && has_capability('moodle/grade:viewhidden', $context);

        // Cache course module info for activity URL and availability lookups.
        $this->modinfo = get_fast_modinfo($this->courseid);

        // Build the grade tree without fillers, with category totals last.
        $this->gtree = new \grade_tree($this->courseid, false, true);

        // Get the course-level grade item.
        $this->courseitem = \grade_item::fetch_course_item($this->courseid);

        if ($this->userid > 0) {
            $this->load_user_grades();
            $this->load_submission_data();
            $this->build_grade_data();
        }
    }

    /**
     * Event names that count as "the student viewed the feedback their teacher wrote".
     *
     * - `mod_assign\event\feedback_viewed` / `submission_status_viewed` — student
     *   opens an assignment's feedback panel.
     * - `local_unifiedgrader\event\feedback_viewed` — student opens UG's
     *   feedback-viewer page (forums, quizzes, BBB).
     * - `gradereport_user\event\grade_report_viewed` — student opens their own
     *   user grade report. Used as a fallback signal: a student who has viewed
     *   their grade report has been exposed to whatever feedback the teacher
     *   left in `grade_grades.feedback` for the modules they were graded on.
     *   Imperfect (we can't tell *which* feedback they actually read), but a
     *   strict UG-event-only count materially under-reports for institutions
     *   whose students consume feedback through the gradebook rather than
     *   activity pages.
     *
     * @return string[]
     */
    public static function get_feedback_view_event_names(): array {
        $events = [
            '\\mod_assign\\event\\feedback_viewed',
            '\\mod_assign\\event\\submission_status_viewed',
            '\\gradereport_user\\event\\grade_report_viewed',
        ];
        if (class_exists('\\local_unifiedgrader\\event\\feedback_viewed')) {
            $events[] = '\\local_unifiedgrader\\event\\feedback_viewed';
        }
        return $events;
    }

    /**
     * Module types where teacher feedback is a normal expectation.
     *
     * Used as the denominator scope for cohort feedback-coverage and feedback-
     * review metrics so that auto-graded module types (lti external tools,
     * scorm packages, hotpot, attendance, chat, choice, etc.) don't drag the
     * percentage down. A course where the teacher leaves perfect feedback on
     * every forum but uses lti/scorm for skill practice should score 100%
     * coverage, not be penalised for the auto-graded items.
     *
     * Kept narrow and explicit — adding a module here grants institution-wide
     * visibility into how often it's getting feedback, which is an editorial
     * call rather than something we infer from data.
     *
     * @return string[]
     */
    public static function get_feedback_relevant_modnames(): array {
        return ['assign', 'forum', 'quiz', 'lesson', 'workshop', 'bigbluebuttonbn', 'data'];
    }

    /**
     * Activity modnames for which Unified Grader is installed AND configured.
     *
     * UG ships adapters for assign, quiz, forum and bigbluebuttonbn but each is
     * gated by a `local_unifiedgrader/enable_<modname>` admin setting. An institution
     * may enable UG for assignments only — in which case stale forum/quiz/BBB rows
     * (left over from a previously-enabled type or test data) should not count.
     *
     * Returns [] when UG is not installed at all, or when no activity type is enabled.
     *
     * @return string[] Lower-case modnames (e.g. ['assign', 'forum']).
     */
    public static function get_unifiedgrader_enabled_modnames(): array {
        global $DB;
        // The UG install + its enable_* settings are invariant for the request,
        // so memoise: this is probed twice per assignment-feedback breakdown and
        // several times per cohort run, and table_exists() is not free.
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        if (!$DB->get_manager()->table_exists('local_unifiedgrader_scomm')) {
            return $cache = [];
        }
        $enabled = [];
        foreach (['assign', 'quiz', 'forum', 'bigbluebuttonbn'] as $modname) {
            if ((bool)get_config('local_unifiedgrader', 'enable_' . $modname)) {
                $enabled[] = $modname;
            }
        }
        return $cache = $enabled;
    }

    /**
     * Build an SQL IN clause for the feedback-view event names.
     *
     * @param string $prefix Parameter name prefix (must be unique within the query).
     * @return array{0:string,1:array} [SQL fragment with leading space, params].
     */
    public static function get_feedback_view_event_sql(string $prefix = 'fve'): array {
        global $DB;
        return $DB->get_in_or_equal(self::get_feedback_view_event_names(), SQL_PARAMS_NAMED, $prefix);
    }

    /**
     * Default sub-weights (as percentages) for the feedback-quality composite.
     * Admins override these via the gradereport_coifish/feedback_weight_* settings.
     *
     * @var array
     */
    public const FEEDBACK_WEIGHT_DEFAULTS = [
        'coverage' => 30,
        'depth' => 20,
        'quality' => 20,
        'personalisation' => 15,
        'structured' => 15,
    ];

    /**
     * Feedback-quality composite sub-weights, normalised to fractions that sum
     * to 1. Reads the admin-configurable percentages and falls back to the
     * defaults; if an admin zeroes everything, the defaults are used so the
     * composite never collapses to zero.
     *
     * @return array Map of dimension => fraction (coverage, depth, quality,
     *               personalisation, structured).
     */
    public static function get_feedback_weights(): array {
        $raw = [];
        $sum = 0.0;
        foreach (self::FEEDBACK_WEIGHT_DEFAULTS as $key => $default) {
            $val = get_config('gradereport_coifish', 'feedback_weight_' . $key);
            $val = ($val === false || $val === '') ? $default : (float)$val;
            $val = max(0.0, $val);
            $raw[$key] = $val;
            $sum += $val;
        }
        if ($sum <= 0) {
            $raw = self::FEEDBACK_WEIGHT_DEFAULTS;
            $sum = array_sum(self::FEEDBACK_WEIGHT_DEFAULTS);
        }
        $out = [];
        foreach ($raw as $key => $val) {
            $out[$key] = $val / $sum;
        }
        return $out;
    }

    /**
     * Human-readable summary of the current feedback-quality weights, e.g.
     * "coverage 30%, depth 20%, ...". Used in the "How is this determined" card
     * so the methodology shown always matches the configured weights.
     *
     * @return string
     */
    public static function format_feedback_weights_summary(): string {
        $weights = self::get_feedback_weights();
        $labels = [
            'coverage' => get_string('coord_feedback_coverage', 'gradereport_coifish'),
            'depth' => get_string('coord_feedback_depth', 'gradereport_coifish'),
            'quality' => get_string('coord_feedback_quality', 'gradereport_coifish'),
            'personalisation' => get_string('coord_feedback_personalisation', 'gradereport_coifish'),
            'structured' => get_string('coord_feedback_structured', 'gradereport_coifish'),
        ];
        $parts = [];
        foreach ($weights as $key => $fraction) {
            $label = $labels[$key] ?? $key;
            $parts[] = $label . ' ' . round($fraction * 100) . '%';
        }
        return implode(', ', $parts);
    }

    /**
     * Per-assignment feedback-quality breakdown for one teacher in one course.
     *
     * Decomposes the composite feedback score (the same one the
     * {@see \gradereport_coifish\task\calculate_feedback_metrics} task caches per
     * teacher) down to the individual assign activities the teacher graded, so a
     * coordinator can tell which assignments drag a lecturer's score down — i.e.
     * spot assignments that need redesign versus a lecturer who needs support.
     *
     * The four text-derived sub-scores (coverage, depth, quality, personalisation)
     * are recomputed per assignment with the SAME formulas as the cohort task,
     * reusing its scoring primitives so the two never drift. The structured-grading
     * sub-score is course-level, so it is the same for every row and is folded into
     * each row's composite via the admin-configurable weights.
     *
     * Efficiency: every per-assignment aggregate comes from a query GROUPED BY the
     * assign instance (never one query per assignment). See {@see get_assignment_feedback_coverage()},
     * {@see get_assignment_feedback_text()}, and the single cmid/name map below.
     *
     * @param int $courseid The course ID.
     * @param int $teacherid The grader's user ID.
     * @return array List of rows (one per assign the teacher graded), each an assoc
     *               array with keys: cmid, name, coverage, depth, quality,
     *               personalisation, composite, ngraded, nwithfeedback. Ordered by
     *               composite ascending (worst first).
     */
    public static function get_assignment_feedback_breakdown(int $courseid, int $teacherid): array {
        global $DB;

        // Grouped coverage: ngraded + nwithfeedback per assign instance (one query).
        $coverage = self::get_assignment_feedback_coverage($courseid, $teacherid);
        if (empty($coverage)) {
            return [];
        }

        // Grouped text analysis: depth/quality/personalisation per assign instance
        // (a handful of grouped queries, assembled in PHP — no per-assign query).
        $text = self::get_assignment_feedback_text($courseid, $teacherid);

        // Course-level structured-grading score and composite weights (fetched once).
        $structured = feedback_scorer::structured_score($courseid);
        $weights = self::get_feedback_weights();

        // One query maps every graded assign instance to its cmid and name.
        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($coverage), SQL_PARAMS_NAMED, 'afb');
        $cmrows = $DB->get_records_sql(
            "SELECT a.id AS assignid, cm.id AS cmid, a.name, a.grade AS gradetype
               FROM {assign} a
               JOIN {course_modules} cm ON cm.instance = a.id
               JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
              WHERE a.course = :courseid
                AND a.id $insql",
            array_merge(['courseid' => $courseid], $inparams)
        );

        $rows = [];
        foreach ($cmrows as $cm) {
            $assignid = (int)$cm->assignid;
            $cov = $coverage[$assignid];
            $total = (int)$cov['total'];
            $withfb = (int)$cov['withfeedback'];

            $coveragescore = $total > 0 ? min(100, (int)round(($withfb / $total) / 0.80 * 100)) : 0;

            $t = $text[$assignid] ?? ['depth' => 0, 'quality' => 0, 'personalisation' => 0];
            $composite = (int)round(
                $coveragescore * $weights['coverage'] +
                $t['depth'] * $weights['depth'] +
                $t['quality'] * $weights['quality'] +
                $t['personalisation'] * $weights['personalisation'] +
                $structured * $weights['structured']
            );

            $rows[] = [
                'cmid' => (int)$cm->cmid,
                'name' => format_string($cm->name),
                'coverage' => $coveragescore,
                'depth' => (int)$t['depth'],
                'quality' => (int)$t['quality'],
                'personalisation' => (int)$t['personalisation'],
                'composite' => $composite,
                'ngraded' => $total,
                'nwithfeedback' => $withfb,
                // Scale-graded (complete/incomplete) assignments are not feedback-
                // relevant by default; the consumer badges and overrides on this.
                'scalegraded' => ((float)$cm->gradetype < 0),
            ];
        }

        // Worst first, so the assignments that need attention surface at the top.
        usort($rows, function ($a, $b) {
            return $a['composite'] <=> $b['composite'];
        });
        return $rows;
    }

    /**
     * Per-assign coverage counts (graded + with-feedback) for one teacher.
     *
     * One query, GROUPED BY the assign instance. Counts each graded item (grade
     * >= 0) and flags it as covered when it carries any feedback signal: a written
     * comment, a non-draft editpdf annotation, an audio/video feedback file, or a
     * Unified Grader submission-comment/annotation (only when UG is enabled for
     * assignments). Mirrors the cohort task's coverage signals exactly.
     *
     * @param int $courseid The course ID.
     * @param int $teacherid The grader's user ID.
     * @return array Map of assignid => ['total' => int, 'withfeedback' => int].
     */
    protected static function get_assignment_feedback_coverage(int $courseid, int $teacherid): array {
        global $DB;

        // Unified Grader scomm/annot signals — only when UG handles assignments.
        $ugjoin = '';
        $ugcondition = '';
        $ugparams = [];
        if (in_array('assign', self::get_unifiedgrader_enabled_modnames(), true)) {
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

        // Audio/video feedback delivered as a file attachment also counts as covered.
        $mediajoin = "
               LEFT JOIN (
                    SELECT itemid, COUNT(*) AS cnt
                      FROM {files}
                     WHERE component = 'assignfeedback_file'
                       AND filearea = 'feedback_files'
                       AND filename <> '.'
                       AND (" . $DB->sql_like('mimetype', ':mfa') . " OR " . $DB->sql_like('mimetype', ':mfv') . ")
                  GROUP BY itemid
               ) mf ON mf.itemid = ag.id";

        $records = $DB->get_records_sql(
            "SELECT ag.assignment AS assignid,
                    COUNT(ag.id) AS total_graded,
                    SUM(CASE WHEN (fc.id IS NOT NULL OR pc.cnt > 0 OR mf.cnt > 0$ugcondition)
                        THEN 1 ELSE 0 END) AS with_feedback
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
               ) pc ON pc.gradeid = ag.id$mediajoin$ugjoin
              WHERE a.course = :courseid
                AND ag.grader = :grader
                AND ag.grade >= 0
           GROUP BY ag.assignment",
            array_merge(
                ['courseid' => $courseid, 'grader' => $teacherid, 'mfa' => 'audio/%', 'mfv' => 'video/%'],
                $ugparams
            )
        );

        $result = [];
        foreach ($records as $row) {
            $result[(int)$row->assignid] = [
                'total' => (int)$row->total_graded,
                'withfeedback' => (int)$row->with_feedback,
            ];
        }
        return $result;
    }

    /**
     * Per-assign depth/quality/personalisation scores for one teacher.
     *
     * Gathers the teacher's feedback artifacts bucketed by assign instance using a
     * small set of GROUPED queries (native comments, audio/video feedback files,
     * and — when enabled — Unified Grader submission comments), then runs the same
     * per-comment scoring the cohort task uses, reusing its scoring primitives so
     * the formulas never diverge. No query is issued inside the per-assignment loop.
     *
     * @param int $courseid The course ID.
     * @param int $teacherid The grader's user ID.
     * @return array Map of assignid => ['depth' => int, 'quality' => int, 'personalisation' => int].
     */
    protected static function get_assignment_feedback_text(int $courseid, int $teacherid): array {
        global $DB;

        // Bucket all artifacts per assign instance: each is {text, media, key}.
        $byassign = [];

        // Native assignment comments (one grouped query).
        $crows = $DB->get_records_sql(
            "SELECT fc.id, ag.assignment AS assignid, fc.commenttext
               FROM {assignfeedback_comments} fc
               JOIN {assign_grades} ag ON ag.id = fc.grade
               JOIN {assign} a ON a.id = ag.assignment
              WHERE a.course = :courseid
                AND ag.grader = :grader
                AND ag.grade >= 0
                AND fc.commenttext IS NOT NULL
                AND fc.commenttext != ''",
            ['courseid' => $courseid, 'grader' => $teacherid]
        );
        foreach ($crows as $row) {
            $byassign[(int)$row->assignid][] = ['text' => $row->commenttext, 'media' => null];
        }

        // Audio/video feedback files (one grouped query); size proxies depth.
        $mrows = $DB->get_records_sql(
            "SELECT f.id, ag.assignment AS assignid, f.filesize
               FROM {files} f
               JOIN {assign_grades} ag ON ag.id = f.itemid
               JOIN {assign} a ON a.id = ag.assignment
              WHERE f.component = 'assignfeedback_file'
                AND f.filearea = 'feedback_files'
                AND f.filename <> '.'
                AND (" . $DB->sql_like('f.mimetype', ':mfa') . " OR " . $DB->sql_like('f.mimetype', ':mfv') . ")
                AND a.course = :courseid
                AND ag.grader = :grader
                AND ag.grade >= 0",
            ['courseid' => $courseid, 'grader' => $teacherid, 'mfa' => 'audio/%', 'mfv' => 'video/%']
        );
        foreach ($mrows as $row) {
            $byassign[(int)$row->assignid][] = ['text' => '', 'media' => (int)$row->filesize];
        }

        // Unified Grader submission comments on assignments (one grouped query),
        // only when UG is enabled for assignments. Joined to assign_grades on the
        // matching (assignment, student, grader) graded item so the text source is
        // consistent with coverage: a UG comment is only analysed when it backs a
        // graded row this teacher actually graded. Without this, depth/quality
        // would count UG comments that coverage never credits, producing an
        // assignment with 0% coverage yet non-zero depth.
        if (in_array('assign', self::get_unifiedgrader_enabled_modnames(), true)) {
            $urows = $DB->get_records_sql(
                "SELECT s.id, cm.instance AS assignid, s.content AS commenttext
                   FROM {local_unifiedgrader_scomm} s
                   JOIN {course_modules} cm ON cm.id = s.cmid
                   JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                   JOIN {assign_grades} ag
                        ON ag.assignment = cm.instance
                        AND ag.userid = s.userid
                        AND ag.grader = s.authorid
                        AND ag.grade >= 0
                  WHERE cm.course = :courseid
                    AND s.authorid = :grader
                    AND s.content IS NOT NULL
                    AND s.content != ''",
                ['courseid' => $courseid, 'grader' => $teacherid]
            );
            foreach ($urows as $row) {
                $byassign[(int)$row->assignid][] = ['text' => $row->commenttext, 'media' => null];
            }
        }

        // Score each assignment's bucket with the cohort task's primitives.
        $result = [];
        foreach ($byassign as $assignid => $artifacts) {
            $result[$assignid] = feedback_scorer::score_bucket($artifacts);
        }
        return $result;
    }

    /**
     * Count the activities a student is reasonably expected to engage with in a course.
     *
     * Mirrors the engagement-metric activity set (assign, quiz, page, book, resource,
     * url, folder) but subtracts assign/quiz items that the category's drop-lowest or
     * keep-highest aggregation rule makes optional. Used by both the per-course COI
     * report and the intervention snapshot so students aren't flagged as disengaged
     * for skipping optional assessments.
     *
     * @param int $courseid Course ID.
     * @return int Effective expected activity count.
     */
    public static function get_expected_activity_count(int $courseid): int {
        global $DB;

        $total = (int)$DB->count_records_sql(
            "SELECT COUNT(cm.id)
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :cid AND cm.deletioninprogress = 0
                AND m.name IN ('assign', 'quiz', 'page', 'book', 'resource', 'url', 'folder')",
            ['cid' => $courseid]
        );

        $cats = $DB->get_records_select(
            'grade_categories',
            'courseid = :cid AND (droplow > 0 OR keephigh > 0)',
            ['cid' => $courseid],
            '',
            'id, droplow, keephigh'
        );
        if (empty($cats)) {
            return $total;
        }

        $catids = array_keys($cats);
        [$insql, $inparams] = $DB->get_in_or_equal($catids, SQL_PARAMS_NAMED, 'cg');
        $counts = $DB->get_records_sql(
            "SELECT categoryid, COUNT(id) AS cnt
               FROM {grade_items}
              WHERE categoryid $insql AND itemmodule IN ('assign', 'quiz')
           GROUP BY categoryid",
            $inparams
        );

        $dropped = 0;
        foreach ($cats as $cat) {
            $cnt = isset($counts[$cat->id]) ? (int)$counts[$cat->id]->cnt : 0;
            if ($cnt <= 0) {
                continue;
            }
            if ((int)$cat->keephigh > 0) {
                $dropped += max(0, $cnt - (int)$cat->keephigh);
            } else if ((int)$cat->droplow > 0) {
                $dropped += min($cnt, (int)$cat->droplow);
            }
        }

        return max(0, $total - $dropped);
    }

    /**
     * The effective "now" for analytics — clamped to the course end date when the
     * course has already concluded.
     *
     * For a concluded course, time-based diagnostics (days inactive, weeks enrolled,
     * sliding activity windows) should freeze at the course's official end date so
     * a closed course doesn't keep "drifting" — e.g. a stale-activity card claiming
     * a student has been absent for hundreds of days after the term has ended.
     *
     * @return int Unix timestamp.
     */
    public function effective_now(): int {
        $now = time();
        $enddate = (int)($this->course->enddate ?? 0);
        if ($enddate > 0 && $enddate < $now) {
            return $enddate;
        }
        return $now;
    }

    /**
     * Return the group IDs the current viewer is scoped to for cohort-level queries.
     *
     * Use {@see has_unconstrained_view()} to distinguish "unconstrained" (cap holder
     * with no specific group selected) from "no groups at all" (no cap + viewer not
     * in any group) — both return [], but mean very different things to the caller.
     *
     * - If a specific group is selected (groupid > 0) and the viewer may see it, returns [groupid].
     * - If groupid == 0 and the viewer has gradereport/coifish:viewallgroups, returns [] (no filter).
     * - Otherwise returns the IDs of every group the viewer is a member of in this course
     *   (which may itself be []).
     *
     * @return int[]
     */
    public function get_scoped_groupids(): array {
        global $USER;

        $canviewall = has_capability('gradereport/coifish:viewallgroups', $this->context);
        $usergroups = groups_get_user_groups($this->courseid, $USER->id);
        $mygroupids = array_values(array_map('intval', $usergroups[0] ?? []));

        if ($this->groupid > 0) {
            if ($canviewall || in_array((int)$this->groupid, $mygroupids, true)) {
                return [(int)$this->groupid];
            }
            // Viewer asked for a group they may not see — fall back to their own groups.
            return $mygroupids;
        }

        if ($canviewall) {
            return [];
        }

        return $mygroupids;
    }

    /**
     * Build a userid → "Group A, Group B" map for the given cohort, using a single
     * query so the at-risk table can show group membership without per-row lookups.
     *
     * @param int[] $userids Student user IDs.
     * @return array Map of userid => formatted comma-separated group names (may be '').
     */
    protected function get_cohort_group_names(array $userids): array {
        global $DB;
        if (empty($userids)) {
            return [];
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'cgn');
        $rows = $DB->get_records_sql(
            "SELECT gm.id, gm.userid, g.id AS groupid, g.name
               FROM {groups_members} gm
               JOIN {groups} g ON g.id = gm.groupid
              WHERE g.courseid = :courseid AND gm.userid $insql
           ORDER BY g.name ASC",
            array_merge(['courseid' => $this->courseid], $inparams)
        );
        $by = [];
        foreach ($rows as $row) {
            $by[(int)$row->userid][] = format_string($row->name);
        }
        $result = [];
        foreach ($userids as $uid) {
            $result[(int)$uid] = isset($by[$uid]) ? implode(', ', $by[$uid]) : '';
        }
        return $result;
    }

    /**
     * Compute missed-deadline and extension counts per student for the cohort.
     *
     * "Missed" = the assessment's due date is in the past, the student has not
     * submitted, and the student has neither a user override nor a group override
     * on that item. Items where the student holds any override are excluded from
     * "missed" entirely — they've been deliberately re-scoped for that student.
     *
     * Returns per-student missed counts plus a separate count of user-level
     * extensions (override rows) so chronic extension-seeking can be flagged.
     * Covers Moodle's three deadline-bearing module types: assign (duedate),
     * quiz (timeclose), forum (duedate on graded forums).
     *
     * @param int[] $userids Student user IDs.
     * @return array Map of userid => ['missed' => int, 'missedlist' => string[],
     *                                  'missedlistraw' => string[], 'extensions' => int].
     *               missedlist holds format_string'd names for HTML display;
     *               missedlistraw holds the plain names for plain-text consumers.
     */
    public function get_cohort_missed_deadlines(array $userids): array {
        global $DB;
        $now = $this->effective_now();
        $out = [];
        foreach ($userids as $uid) {
            $out[(int)$uid] = ['missed' => 0, 'missedlist' => [], 'missedlistraw' => [], 'extensions' => 0];
        }
        if (empty($userids)) {
            return $out;
        }
        [$uinsql, $uinparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'mdu');

        // Group memberships are needed to honor group overrides.
        $usergroupids = [];
        $gmrows = $DB->get_records_sql(
            "SELECT gm.id, gm.userid, gm.groupid
               FROM {groups_members} gm
               JOIN {groups} g ON g.id = gm.groupid
              WHERE g.courseid = :cid AND gm.userid $uinsql",
            array_merge(['cid' => $this->courseid], $uinparams)
        );
        foreach ($gmrows as $row) {
            $usergroupids[(int)$row->userid][] = (int)$row->groupid;
        }

        // Module-by-module: gather past-due items, submission/attempt presence,
        // user overrides, group overrides. Each branch builds a uniform shape
        // [moduleinstanceid => name] of past-due items first.
        $itemsbymod = [
            'assign' => $this->fetch_pastdue_assigns($now),
            'quiz' => $this->fetch_pastdue_quizzes($now),
            'forum' => $this->fetch_pastdue_forums($now),
        ];

        // Per module, gather submissions / overrides — each keyed [modname][userid][instanceid]
        // (or [modname][groupid][instanceid] for group overrides) so per-student
        // counting in the second pass is a constant-time lookup.
        $haswork = [];
        $useroverrides = [];
        $groupoverrides = [];
        $userextensions = [];

        foreach ($itemsbymod as $modname => $items) {
            if (empty($items)) {
                continue;
            }
            $iids = array_keys($items);
            [$iinsql, $iinparams] = $DB->get_in_or_equal($iids, SQL_PARAMS_NAMED, 'md' . substr($modname, 0, 3));

            if ($modname === 'assign') {
                $rows = $DB->get_records_sql(
                    "SELECT id, assignment, userid FROM {assign_submission}
                      WHERE assignment $iinsql AND userid $uinsql AND status = 'submitted'",
                    array_merge($iinparams, $uinparams)
                );
                foreach ($rows as $r) {
                    $haswork['assign'][(int)$r->userid][(int)$r->assignment] = true;
                }
                $ovrows = $DB->get_records_sql(
                    "SELECT id, assignid, userid, groupid, duedate FROM {assign_overrides}
                      WHERE assignid $iinsql AND duedate IS NOT NULL",
                    $iinparams
                );
                foreach ($ovrows as $r) {
                    if ($r->userid) {
                        $useroverrides['assign'][(int)$r->userid][(int)$r->assignid] = true;
                        $userextensions[(int)$r->userid][] = $r->id;
                    } else if ($r->groupid) {
                        $groupoverrides['assign'][(int)$r->groupid][(int)$r->assignid] = true;
                    }
                }
            } else if ($modname === 'quiz') {
                $rows = $DB->get_records_sql(
                    "SELECT id, quiz, userid FROM {quiz_attempts}
                      WHERE quiz $iinsql AND userid $uinsql AND state IN ('finished', 'abandoned')",
                    array_merge($iinparams, $uinparams)
                );
                foreach ($rows as $r) {
                    $haswork['quiz'][(int)$r->userid][(int)$r->quiz] = true;
                }
                $ovrows = $DB->get_records_sql(
                    "SELECT id, quiz, userid, groupid, timeclose FROM {quiz_overrides}
                      WHERE quiz $iinsql AND timeclose IS NOT NULL",
                    $iinparams
                );
                foreach ($ovrows as $r) {
                    if ($r->userid) {
                        $useroverrides['quiz'][(int)$r->userid][(int)$r->quiz] = true;
                        $userextensions[(int)$r->userid][] = $r->id;
                    } else if ($r->groupid) {
                        $groupoverrides['quiz'][(int)$r->groupid][(int)$r->quiz] = true;
                    }
                }
            } else if ($modname === 'forum') {
                // Graded forums: "submission" = posting at least once in the forum.
                $rows = $DB->get_records_sql(
                    "SELECT MIN(fp.id) AS id, fd.forum, fp.userid
                       FROM {forum_posts} fp
                       JOIN {forum_discussions} fd ON fd.id = fp.discussion
                      WHERE fd.forum $iinsql AND fp.userid $uinsql
                   GROUP BY fd.forum, fp.userid",
                    array_merge($iinparams, $uinparams)
                );
                foreach ($rows as $r) {
                    $haswork['forum'][(int)$r->userid][(int)$r->forum] = true;
                }
                // Forums use mod_forum's grading subsystem; no per-instance override
                // table exists in core, so user/group overrides don't apply.
            }
        }

        // Per-user counting.
        foreach ($userids as $uid) {
            $uid = (int)$uid;
            $missed = 0;
            $missedlist = [];
            $missedlistraw = [];
            foreach ($itemsbymod as $modname => $items) {
                foreach ($items as $iid => $name) {
                    // Skip if student already engaged.
                    if (!empty($haswork[$modname][$uid][$iid])) {
                        continue;
                    }
                    // Skip if student has a user override on this item.
                    if (!empty($useroverrides[$modname][$uid][$iid])) {
                        continue;
                    }
                    // Skip if any of the student's groups has a group override.
                    $skip = false;
                    foreach ($usergroupids[$uid] ?? [] as $gid) {
                        if (!empty($groupoverrides[$modname][$gid][$iid])) {
                            $skip = true;
                            break;
                        }
                    }
                    if ($skip) {
                        continue;
                    }
                    $missed++;
                    // The missedlist field is format_string'd for HTML display;
                    // missedlistraw keeps the plain activity name for plain-text
                    // consumers such as the {missedwork} message placeholder, which
                    // gets escaped once by the messaging channel (double-escaping
                    // plain names would surface entities like "&amp;" in the message).
                    $missedlist[] = format_string($name);
                    $missedlistraw[] = $name;
                }
            }
            $out[$uid] = [
                'missed' => $missed,
                'missedlist' => $missedlist,
                'missedlistraw' => $missedlistraw,
                'extensions' => isset($userextensions[$uid]) ? count($userextensions[$uid]) : 0,
            ];
        }
        return $out;
    }

    /**
     * Return [assignid => name] for assignments past their due date.
     *
     * @param int $now Effective current timestamp.
     * @return array
     */
    protected function fetch_pastdue_assigns(int $now): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT id, name FROM {assign}
              WHERE course = :cid AND duedate > 0 AND duedate < :now",
            ['cid' => $this->courseid, 'now' => $now]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r->id] = $r->name;
        }
        return $out;
    }

    /**
     * Return [quizid => name] for quizzes past their close time.
     *
     * @param int $now Effective current timestamp.
     * @return array
     */
    protected function fetch_pastdue_quizzes(int $now): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT id, name FROM {quiz}
              WHERE course = :cid AND timeclose > 0 AND timeclose < :now",
            ['cid' => $this->courseid, 'now' => $now]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r->id] = $r->name;
        }
        return $out;
    }

    /**
     * Return [forumid => name] for graded forums past their due date.
     *
     * Only forums with a positive duedate are considered — purely discussion
     * forums without deadlines are not relevant to the missed-deadline metric.
     *
     * @param int $now Effective current timestamp.
     * @return array
     */
    protected function fetch_pastdue_forums(int $now): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT id, name FROM {forum}
              WHERE course = :cid AND duedate > 0 AND duedate < :now",
            ['cid' => $this->courseid, 'now' => $now]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r->id] = $r->name;
        }
        return $out;
    }

    /**
     * Whether the viewer has unrestricted, course-wide visibility.
     *
     * True only when the viewer holds gradereport/coifish:viewallgroups AND has not
     * narrowed the view to a single group. A teacher without the capability never
     * has unconstrained view, even if they happen to be in no groups — they simply
     * have no students to see.
     *
     * @return bool
     */
    public function has_unconstrained_view(): bool {
        if ($this->groupid > 0) {
            return false;
        }
        return has_capability('gradereport/coifish:viewallgroups', $this->context);
    }

    /**
     * Fetch enrolled users honoring the viewer's group scope.
     *
     * Wraps get_enrolled_users() so summary/insights queries automatically respect the
     * gradereport/coifish:viewallgroups capability and "all my groups" semantics.
     * `$onlyactive=true` excludes suspended/withdrawn enrolments from the live report
     * (longitudinal snapshots in local_coifish still capture the data for history).
     *
     * @param string $userfields Fields clause forwarded to get_enrolled_users().
     * @param string $sort Sort clause forwarded to get_enrolled_users().
     * @return array Array of user records keyed by userid.
     */
    public function get_scoped_enrolled_users(string $userfields = 'u.*', string $sort = 'u.lastname, u.firstname'): array {
        $scope = $this->get_scoped_groupids();

        if (empty($scope)) {
            // Two cases collapse to empty scope: an unconstrained cap holder (show
            // everyone) or a teacher without the cap who isn't in any group (show
            // nobody — they're not assigned to teach anyone).
            if (!$this->has_unconstrained_view()) {
                return [];
            }
            return get_enrolled_users(
                $this->context,
                'moodle/course:isincompletionreports',
                0,
                $userfields,
                $sort,
                0,
                0,
                true
            );
        }

        if (count($scope) === 1) {
            return get_enrolled_users(
                $this->context,
                'moodle/course:isincompletionreports',
                $scope[0],
                $userfields,
                $sort,
                0,
                0,
                true
            );
        }

        // Union of members across multiple groups.
        $merged = [];
        foreach ($scope as $gid) {
            $members = get_enrolled_users(
                $this->context,
                'moodle/course:isincompletionreports',
                $gid,
                $userfields,
                $sort,
                0,
                0,
                true
            );
            foreach ($members as $uid => $user) {
                if (!isset($merged[$uid])) {
                    $merged[$uid] = $user;
                }
            }
        }
        return $merged;
    }

    /**
     * Batch-load all grade records for the user to avoid per-item queries.
     */
    protected function load_user_grades(): void {
        global $DB;

        $grades = $DB->get_records('grade_grades', ['userid' => $this->userid]);
        foreach ($grades as $grade) {
            $this->usergrades[$grade->itemid] = $grade;
        }
    }

    /**
     * Batch-load submission timestamps, deadlines, overrides and extensions for the user.
     */
    protected function load_submission_data(): void {
        global $DB;

        $userid = $this->userid;

        // Get the user's group IDs for group override lookups.
        $groupings = groups_get_user_groups($this->courseid, $userid);
        $usergroups = !empty($groupings[0]) ? $groupings[0] : [];

        // Assignments.
        $assigns = $DB->get_records('assign', ['course' => $this->courseid], '', 'id, duedate');
        if (!empty($assigns)) {
            $assignids = array_keys($assigns);
            [$insql, $params] = $DB->get_in_or_equal($assignids, SQL_PARAMS_NAMED);

            // Submissions for this user.
            $params['userid'] = $userid;
            $submissions = $DB->get_records_select(
                'assign_submission',
                "assignment $insql AND userid = :userid AND status = 'submitted'",
                $params,
                'timemodified DESC',
                'assignment, timemodified'
            );

            // Extensions for this user.
            $params2 = $params;
            $userflags = $DB->get_records_select(
                'assign_user_flags',
                "assignment $insql AND userid = :userid",
                $params2,
                '',
                'assignment, extensionduedate'
            );

            // User-specific overrides.
            $params3 = $params;
            $useroverrides = $DB->get_records_select(
                'assign_overrides',
                "assignid $insql AND userid = :userid",
                $params3,
                '',
                'assignid, duedate'
            );

            // Group overrides.
            $groupoverrides = [];
            if (!empty($usergroups)) {
                [$ginsql, $gparams] = $DB->get_in_or_equal($usergroups, SQL_PARAMS_NAMED, 'grp');
                [$ainsql, $aparams] = $DB->get_in_or_equal($assignids, SQL_PARAMS_NAMED, 'asgn');
                $allparams = array_merge($gparams, $aparams);
                $records = $DB->get_records_select(
                    'assign_overrides',
                    "assignid $ainsql AND groupid $ginsql AND duedate IS NOT NULL",
                    $allparams,
                    'sortorder ASC'
                );
                foreach ($records as $rec) {
                    // First match per assignment wins (lowest sortorder).
                    if (!isset($groupoverrides[$rec->assignid])) {
                        $groupoverrides[$rec->assignid] = $rec;
                    }
                }
            }

            foreach ($assigns as $id => $assign) {
                $this->assigndata[$id] = [
                    'duedate' => (int)$assign->duedate,
                    'submissiontime' => isset($submissions[$id]) ? (int)$submissions[$id]->timemodified : null,
                    'extensionduedate' => isset($userflags[$id]) ? (int)$userflags[$id]->extensionduedate : 0,
                    'useroverride' => isset($useroverrides[$id]) ? (int)$useroverrides[$id]->duedate : null,
                    'groupoverride' => isset($groupoverrides[$id]) ? (int)$groupoverrides[$id]->duedate : null,
                ];
            }
        }

        // Quizzes.
        $quizzes = $DB->get_records('quiz', ['course' => $this->courseid], '', 'id, timeclose');
        if (!empty($quizzes)) {
            $quizids = array_keys($quizzes);
            [$insql, $params] = $DB->get_in_or_equal($quizids, SQL_PARAMS_NAMED);

            // Finished attempts for this user.
            $params['userid'] = $userid;
            $attempts = $DB->get_records_select(
                'quiz_attempts',
                "quiz $insql AND userid = :userid AND state = 'finished'",
                $params,
                'timefinish DESC',
                'quiz, timefinish'
            );

            // User-specific overrides.
            $params2 = $params;
            $useroverrides = $DB->get_records_select(
                'quiz_overrides',
                "quiz $insql AND userid = :userid",
                $params2,
                '',
                'quiz, timeclose'
            );

            // Group overrides.
            $groupoverrides = [];
            if (!empty($usergroups)) {
                [$ginsql, $gparams] = $DB->get_in_or_equal($usergroups, SQL_PARAMS_NAMED, 'grp');
                [$qinsql, $qparams] = $DB->get_in_or_equal($quizids, SQL_PARAMS_NAMED, 'qz');
                $allparams = array_merge($gparams, $qparams);
                $records = $DB->get_records_select(
                    'quiz_overrides',
                    "quiz $qinsql AND groupid $ginsql AND timeclose IS NOT NULL",
                    $allparams,
                    'id ASC'
                );
                foreach ($records as $rec) {
                    if (!isset($groupoverrides[$rec->quiz])) {
                        $groupoverrides[$rec->quiz] = $rec;
                    }
                }
            }

            foreach ($quizzes as $id => $quiz) {
                $this->quizdata[$id] = [
                    'timeclose' => (int)$quiz->timeclose,
                    'finishtime' => isset($attempts[$id]) ? (int)$attempts[$id]->timefinish : null,
                    'useroverride' => isset($useroverrides[$id]) ? (int)$useroverrides[$id]->timeclose : null,
                    'groupoverride' => isset($groupoverrides[$id]) ? (int)$groupoverrides[$id]->timeclose : null,
                ];
            }
        }
    }

    /**
     * Get a user's grade_grade object for a specific grade item.
     *
     * @param int $itemid The grade item ID.
     * @return \grade_grade The grade object (may have null finalgrade if ungraded).
     */
    protected function get_user_grade(int $itemid): \grade_grade {
        if (isset($this->usergrades[$itemid])) {
            return new \grade_grade($this->usergrades[$itemid], false);
        }
        // Return an empty grade object.
        $grade = new \grade_grade();
        $grade->itemid = $itemid;
        $grade->userid = $this->userid;
        return $grade;
    }

    /**
     * Build the structured grade data by traversing the grade tree.
     */
    protected function build_grade_data(): void {
        $topelement = $this->gtree->top_element;

        // Determine if there are multiple top-level categories (which means weights apply).
        $toplevelcats = 0;
        if (!empty($topelement['children'])) {
            foreach ($topelement['children'] as $child) {
                if ($child['type'] === 'category') {
                    $toplevelcats++;
                }
            }
        }
        $this->hasweights = ($toplevelcats > 1);

        $this->gradedata = $this->process_children($topelement, 1.0);
    }

    /**
     * Process the children of a grade tree element, extracting categories and their items.
     *
     * @param array $element The parent grade tree element.
     * @param float $parenteffectiveweight The cumulative weight from all ancestor categories.
     * @return array Array of category data structures.
     */
    protected function process_children(array $element, float $parenteffectiveweight): array {
        $categories = [];

        if (empty($element['children'])) {
            return $categories;
        }

        foreach ($element['children'] as $child) {
            $type = $child['type'];

            if ($type === 'category') {
                $categorydata = $this->process_category($child, $parenteffectiveweight);
                if ($categorydata !== null) {
                    $categories[] = $categorydata;
                }
            }
        }

        // Items that live directly at this level (siblings of categories rather
        // than inside one) are emitted as their own standalone cards so the
        // student sees the actual gradebook layout: "Final exam — 60%" reads
        // very differently from "Course-level assessments — 60% (containing
        // an exam)". Each top-level item becomes its own one-item virtual
        // category whose name and weight match the item exactly.
        foreach ($element['children'] as $child) {
            if (
                $child['type'] !== 'item' || $child['object']->is_course_item()
                    || $child['object']->is_category_item()
            ) {
                continue;
            }
            $childitem = $child['object'];
            // Skip items with zero weight that aren't extra credit.
            $itemweight = $this->get_item_weight($childitem);
            if ($itemweight == 0 && !$this->is_extra_credit($childitem)) {
                continue;
            }
            $ishidden = $childitem->is_hidden() && !$this->canviewhidden;
            // Wrap with item weight relative to wrapper = 1.0 so per-category
            // averaging math (which expects item weights to be normalised
            // within their parent) collapses cleanly to the item's own pct.
            $itemdata = $this->process_grade_item($childitem, 1.0, $parenteffectiveweight * $itemweight, $ishidden);
            $itemdata['weight_raw'] = 1.0;
            $itemdata['weight'] = $this->format_percentage(1.0);

            $categories[] = [
                'categoryname' => format_string($childitem->get_name()),
                'categoryweight' => $this->format_percentage($itemweight),
                'categoryweight_raw' => $itemweight,
                // Only show the weight badge when there's more than one top-level
                // entry — single-item courses don't need a "100%" badge.
                'hasweight' => $this->hasweights,
                // Reuse the existing flag so the category-section template
                // suppresses the inner "category total" row; the single item's
                // own row already conveys the grade.
                'iscoursecategory' => true,
                'items' => [$itemdata],
                'hasitems' => true,
                'subcategories' => [],
                'hassubcategories' => false,
                'categorytotal' => $this->get_category_total_data($childitem),
            ];
        }

        return $categories;
    }

    /**
     * Process a single category element from the grade tree.
     *
     * @param array $element The category element.
     * @param float $parenteffectiveweight The effective weight of the parent.
     * @return array|null The category data structure, or null if empty.
     */
    protected function process_category(array $element, float $parenteffectiveweight): ?array {
        $gradecat = $element['object'];
        $catitem = $gradecat->get_grade_item();

        // Skip hidden categories for users who cannot view them.
        if ($catitem->is_hidden() && !$this->canviewhidden) {
            return null;
        }

        // Calculate the category's weight within its parent.
        $catweight = $this->get_item_weight($catitem);

        // Skip categories with zero weight — they don't contribute to the final grade.
        if ($catweight == 0) {
            return null;
        }

        $effectiveweight = $parenteffectiveweight * $catweight;

        $items = [];
        $subcategories = [];
        $haschilditems = false;

        if (!empty($element['children'])) {
            foreach ($element['children'] as $child) {
                if ($child['type'] === 'item') {
                    $childitem = $child['object'];
                    // Skip category total items (they are the aggregate, not a real assessment).
                    if ($childitem->is_category_item() || $childitem->is_course_item()) {
                        continue;
                    }
                    // Skip items with zero weight that aren't extra credit.
                    $itemweight = $this->get_item_weight($childitem);
                    if ($itemweight == 0 && !$this->is_extra_credit($childitem)) {
                        continue;
                    }
                    $haschilditems = true;
                    $ishidden = $childitem->is_hidden() && !$this->canviewhidden;
                    $items[] = $this->process_grade_item($childitem, $catweight, $effectiveweight, $ishidden);
                } else if ($child['type'] === 'category') {
                    $haschilditems = true;
                    $subcat = $this->process_category($child, $effectiveweight);
                    if ($subcat !== null) {
                        $subcategories[] = $subcat;
                    }
                }
            }
        }

        // Skip categories that have no grade items at all (truly empty).
        // But keep categories whose items are all hidden — the category itself should still show.
        if (!$haschilditems) {
            return null;
        }

        $categorytotal = $this->get_category_total_data($catitem);
        $droplow = (int)($gradecat->droplow ?? 0);
        $keephigh = (int)($gradecat->keephigh ?? 0);

        return [
            'categoryname' => $gradecat->get_name(),
            'categoryweight' => $this->format_percentage($catweight),
            'categoryweight_raw' => $catweight,
            'hasweight' => $this->hasweights,
            'items' => $items,
            'hasitems' => !empty($items),
            'subcategories' => $subcategories,
            'hassubcategories' => !empty($subcategories),
            'categorytotal' => $categorytotal,
            'droplow' => $droplow,
            'keephigh' => $keephigh,
            'aggregation_label' => $this->build_aggregation_label($droplow, $keephigh),
            'has_aggregation_label' => ($droplow > 0 || $keephigh > 0),
        ];
    }

    /**
     * Build a short human-readable label for a category's drop/keep aggregation rule.
     *
     * Moodle stores these on grade_category; we surface them in the report so students
     * understand why some grades may not contribute to the category total.
     *
     * @param int $droplow Number of lowest grades to drop.
     * @param int $keephigh Number of highest grades to keep.
     * @return string The label, or '' if neither rule is in effect.
     */
    protected function build_aggregation_label(int $droplow, int $keephigh): string {
        if ($keephigh > 0) {
            return get_string('aggregation_keephigh', 'gradereport_coifish', $keephigh);
        }
        if ($droplow > 0) {
            return get_string('aggregation_droplow', 'gradereport_coifish', $droplow);
        }
        return '';
    }

    /**
     * Apply the category's drop-lowest / keep-highest rule to a list of graded items.
     *
     * Mirrors Moodle's grade_category::apply_limit_rules(): items are ranked by their
     * achieved percentage and either the lowest N are removed (droplow) or only the
     * top N are retained (keephigh). Operates only on items that have been graded —
     * ungraded items are passed through untouched so the caller can still decide how
     * to treat them.
     *
     * @param array $items Items with at minimum 'graded', 'grade_raw', 'grademax_raw',
     *                    'isextracredit'. Extra-credit items are never dropped.
     * @param int $droplow Number of lowest grades to drop.
     * @param int $keephigh Number of highest grades to keep.
     * @return array Items with the dropped ones excluded (preserving original order).
     */
    protected function apply_drop_keep(array $items, int $droplow, int $keephigh): array {
        if (($droplow <= 0 && $keephigh <= 0) || empty($items)) {
            return $items;
        }

        // Partition: graded non-extra-credit items participate in ranking; others bypass.
        $rankable = [];
        $bypass = [];
        foreach ($items as $idx => $item) {
            $isgraded = !empty($item['graded']) && $item['grade_raw'] !== null && ($item['grademax_raw'] ?? 0) > 0;
            $isextra = !empty($item['isextracredit']);
            if ($isgraded && !$isextra) {
                $rankable[$idx] = (float)$item['grade_raw'] / (float)$item['grademax_raw'];
            } else {
                $bypass[$idx] = true;
            }
        }

        if (empty($rankable)) {
            return $items;
        }

        // Sort by percentage descending — Moodle drops from the bottom, keeps from the top.
        arsort($rankable);
        $ranked = array_keys($rankable);

        if ($keephigh > 0) {
            $keepidx = array_slice($ranked, 0, $keephigh);
        } else {
            // Drop the lowest N: equivalent to keeping (count - N) from the top.
            $keepcount = max(0, count($ranked) - $droplow);
            $keepidx = array_slice($ranked, 0, $keepcount);
        }
        $keepset = array_fill_keys($keepidx, true);

        $result = [];
        foreach ($items as $idx => $item) {
            if (isset($bypass[$idx]) || isset($keepset[$idx])) {
                $result[] = $item;
            }
        }
        return $result;
    }

    /**
     * Process a single grade item into a template-ready data structure.
     *
     * @param \grade_item $item The grade item.
     * @param float $catweight The weight of the parent category (for display).
     * @param float $effectiveweight The effective weight of the parent category in the course.
     * @param bool $ishidden Whether this item is hidden from the current user.
     * @return array The item data structure.
     */
    protected function process_grade_item(
        \grade_item $item,
        float $catweight,
        float $effectiveweight,
        bool $ishidden = false
    ): array {
        $itemweight = $this->get_item_weight($item);
        $gradegrade = $this->get_user_grade($item->id);
        $graded = ($gradegrade->finalgrade !== null);

        $isextracredit = $this->is_extra_credit($item);

        $contribution = null;
        if ($graded && !$isextracredit && !$ishidden) {
            $contribution = $this->calculate_contribution(
                (float)$gradegrade->finalgrade,
                $item,
                $itemweight,
                $effectiveweight
            );
        }

        $notposted = get_string('notposted', 'gradereport_coifish');

        // Resolve activity URL and availability.
        $itemurl = null;
        $itemaccessible = false;
        if ($item->itemtype === 'mod' && $item->itemmodule && $item->iteminstance && $this->modinfo) {
            try {
                $cm = $this->modinfo->get_instances_of($item->itemmodule)[$item->iteminstance] ?? null;
                if ($cm) {
                    $itemaccessible = $cm->uservisible;
                    if ($itemaccessible) {
                        $itemurl = $cm->get_url();
                        if ($itemurl) {
                            $itemurl = $itemurl->out(false);
                        }
                    }
                }
            } catch (\Exception $e) {
                // Module may have been deleted; leave URL as null.
                unset($e);
            }
        }

        $result = [
            'itemid' => (int)$item->id,
            'itemname' => $item->get_name(),
            'itemurl' => $itemurl,
            'hasurl' => !empty($itemurl),
            'unavailable' => ($item->itemtype === 'mod' && !$itemaccessible),
            'weight' => $isextracredit
                ? get_string('extracredit', 'gradereport_coifish')
                : $this->format_percentage($itemweight),
            'weight_raw' => $isextracredit ? 0 : $itemweight,
            'grade' => $ishidden ? $notposted : ($graded ? $this->format_grade($gradegrade->finalgrade, $item) : '–'),
            'grade_raw' => $ishidden ? null : ($graded ? (float)$gradegrade->finalgrade : null),
            'grademax' => $ishidden ? '' : $this->format_grademax((float)$item->grademax, $item),
            'grademax_raw' => (float)$item->grademax,
            'contribution' => $ishidden ? $notposted : (($contribution !== null) ? $this->format_percentage($contribution) : '–'),
            'graded' => $graded,
            'isextracredit' => $isextracredit,
            'ishidden' => $ishidden,
            'islate' => false,
            'latetext' => '',
            'hasextension' => false,
        ];

        // Add late submission status (skip for hidden items — grades not posted yet).
        if (!$ishidden) {
            $status = $this->get_submission_status($item);
            if ($status !== null) {
                $result['islate'] = $status['islate'];
                $result['latetext'] = $status['latetext'];
                $result['hasextension'] = $status['hasextension'];
            }
        }

        return $result;
    }

    /**
     * Get the submission status (late/on-time/extension) for a grade item.
     *
     * @param \grade_item $item The grade item.
     * @return array|null Null for non-mod items, or array with islate, latetext, hasextension.
     */
    protected function get_submission_status(\grade_item $item): ?array {
        if ($item->itemtype !== 'mod') {
            return null;
        }

        if ($item->itemmodule === 'assign' && isset($this->assigndata[$item->iteminstance])) {
            $data = $this->assigndata[$item->iteminstance];

            // Determine effective deadline: extension > user override > group override > default.
            $hasextension = ($data['extensionduedate'] > 0);
            $effectivedue = $data['duedate'];
            if ($data['groupoverride'] !== null) {
                $effectivedue = $data['groupoverride'];
            }
            if ($data['useroverride'] !== null) {
                $effectivedue = $data['useroverride'];
            }
            if ($hasextension) {
                $effectivedue = $data['extensionduedate'];
            }

            // No deadline set — cannot be late.
            if ($effectivedue == 0) {
                return ['islate' => false, 'latetext' => '', 'hasextension' => $hasextension];
            }

            // No submission — nothing to judge.
            if ($data['submissiontime'] === null) {
                return ['islate' => false, 'latetext' => '', 'hasextension' => $hasextension];
            }

            $islate = ($data['submissiontime'] > $effectivedue);
            $latetext = '';
            if ($islate) {
                $latetext = format_time($data['submissiontime'] - $effectivedue);
            }

            return ['islate' => $islate, 'latetext' => $latetext, 'hasextension' => $hasextension];
        }

        if ($item->itemmodule === 'quiz' && isset($this->quizdata[$item->iteminstance])) {
            $data = $this->quizdata[$item->iteminstance];

            // Determine effective close time: user override > group override > default.
            $effectiveclose = $data['timeclose'];
            if ($data['groupoverride'] !== null) {
                $effectiveclose = $data['groupoverride'];
            }
            if ($data['useroverride'] !== null) {
                $effectiveclose = $data['useroverride'];
            }

            // No close time set — cannot be late.
            if ($effectiveclose == 0) {
                return ['islate' => false, 'latetext' => '', 'hasextension' => false];
            }

            // No finished attempt — nothing to judge.
            if ($data['finishtime'] === null) {
                return ['islate' => false, 'latetext' => '', 'hasextension' => false];
            }

            $islate = ($data['finishtime'] > $effectiveclose);
            $latetext = '';
            if ($islate) {
                $latetext = format_time($data['finishtime'] - $effectiveclose);
            }

            return ['islate' => $islate, 'latetext' => $latetext, 'hasextension' => false];
        }

        return null;
    }

    /**
     * Get the category total display data.
     *
     * @param \grade_item $catitem The category's grade item.
     * @return array Category total data.
     */
    protected function get_category_total_data(\grade_item $catitem): array {
        $gradegrade = $this->get_user_grade($catitem->id);
        return [
            'grade' => ($gradegrade->finalgrade !== null)
                ? $this->format_grade($gradegrade->finalgrade, $catitem) : '–',
            'grademax' => $this->format_grademax((float)$catitem->grademax, $catitem),
        ];
    }

    /**
     * Determine the effective weight of a grade item within its parent category.
     *
     * @param \grade_item $item The grade item.
     * @return float Weight as a decimal (0.0 to 1.0).
     */
    protected function get_item_weight(\grade_item $item): float {
        // For category items, get_parent_category() returns the category itself,
        // not the category it is aggregated within. We need the actual parent.
        if ($item->is_category_item()) {
            $mycat = \grade_category::fetch(['id' => $item->iteminstance]);
            if ($mycat && $mycat->parent) {
                $parentcat = \grade_category::fetch(['id' => $mycat->parent]);
            } else {
                return 1.0;
            }
        } else {
            $parentcat = $item->get_parent_category();
        }
        if (!$parentcat) {
            return 1.0;
        }

        // Get all sibling grade items in this category.
        $siblings = $this->get_category_grade_items($parentcat);
        $aggregation = (int)$parentcat->aggregation;

        switch ($aggregation) {
            case GRADE_AGGREGATE_WEIGHTED_MEAN:
                $totalcoef = 0;
                foreach ($siblings as $sibling) {
                    $totalcoef += (float)$sibling->aggregationcoef;
                }
                return ($totalcoef > 0) ? ((float)$item->aggregationcoef / $totalcoef) : 0;

            case GRADE_AGGREGATE_WEIGHTED_MEAN2: // Simple weighted mean of grades.
                $totalmax = 0;
                foreach ($siblings as $sibling) {
                    if ((float)$sibling->aggregationcoef == 0) { // Exclude extra credit.
                        $totalmax += (float)$sibling->grademax;
                    }
                }
                if ($this->is_extra_credit_in_swm($item, $parentcat)) {
                    return 0;
                }
                return ($totalmax > 0) ? ((float)$item->grademax / $totalmax) : 0;

            case GRADE_AGGREGATE_SUM: // Natural aggregation.
                if ((float)$item->aggregationcoef > 0) {
                    return 0; // Extra credit item.
                }
                if (!empty($item->weightoverride) && $item->aggregationcoef2 !== null) {
                    return (float)$item->aggregationcoef2;
                }
                $totalmax = 0;
                foreach ($siblings as $sibling) {
                    if ((float)$sibling->aggregationcoef == 0) {
                        $totalmax += (float)$sibling->grademax;
                    }
                }
                return ($totalmax > 0) ? ((float)$item->grademax / $totalmax) : 0;

            case GRADE_AGGREGATE_MEAN:
                $count = count($siblings);
                return ($count > 0) ? (1.0 / $count) : 0;

            default:
                // Median, min, max, mode — no meaningful weight.
                return 0;
        }
    }

    /**
     * Get all direct grade items in a category (excluding sub-category totals).
     *
     * @param \grade_category $category The parent category.
     * @return \grade_item[] Array of grade items.
     */
    protected function get_category_grade_items(\grade_category $category): array {
        $items = [];
        $children = \grade_item::fetch_all([
            'categoryid' => $category->id,
            'courseid' => $this->courseid,
        ]);
        if ($children) {
            foreach ($children as $child) {
                $items[] = $child;
            }
        }

        // Also include sub-category total items (they participate in aggregation).
        $subcats = \grade_category::fetch_all(['parent' => $category->id, 'courseid' => $this->courseid]);
        if ($subcats) {
            foreach ($subcats as $subcat) {
                $subcatitem = $subcat->get_grade_item();
                if ($subcatitem) {
                    $items[] = $subcatitem;
                }
            }
        }

        return $items;
    }

    /**
     * Check if a grade item is an extra credit item.
     *
     * @param \grade_item $item The grade item.
     * @return bool True if the item is extra credit.
     */
    protected function is_extra_credit(\grade_item $item): bool {
        $parentcat = $item->get_parent_category();
        if (!$parentcat) {
            return false;
        }

        $aggregation = (int)$parentcat->aggregation;

        // In Natural aggregation, aggregationcoef > 0 means extra credit.
        if ($aggregation == GRADE_AGGREGATE_SUM && (float)$item->aggregationcoef > 0) {
            return true;
        }

        // In Simple Weighted Mean, aggregationcoef > 0 means extra credit.
        if ($aggregation == GRADE_AGGREGATE_WEIGHTED_MEAN2 && (float)$item->aggregationcoef > 0) {
            return true;
        }

        return false;
    }

    /**
     * Check if an item is extra credit in Simple Weighted Mean aggregation.
     *
     * @param \grade_item $item The grade item.
     * @param \grade_category $parentcat The parent category.
     * @return bool True if extra credit.
     */
    protected function is_extra_credit_in_swm(\grade_item $item, \grade_category $parentcat): bool {
        return ((int)$parentcat->aggregation == GRADE_AGGREGATE_WEIGHTED_MEAN2
                && (float)$item->aggregationcoef > 0);
    }

    /**
     * Calculate how much a graded item contributes to the final course grade.
     *
     * @param float $grade The user's actual grade.
     * @param \grade_item $item The grade item.
     * @param float $itemweight Weight of item within its category (0–1).
     * @param float $effectiveweight Effective weight of the category in the course (0–1).
     * @return float Contribution as a decimal (e.g., 0.15 = 15% of course total).
     */
    protected function calculate_contribution(
        float $grade,
        \grade_item $item,
        float $itemweight,
        float $effectiveweight
    ): float {
        $range = (float)$item->grademax - (float)$item->grademin;
        if ($range == 0) {
            return 0;
        }
        $normalized = ($grade - (float)$item->grademin) / $range;
        return $normalized * $itemweight * $effectiveweight;
    }

    /**
     * Format a grade value for display.
     *
     * @param float|null $value The raw grade value.
     * @param \grade_item $item The grade item (for formatting context).
     * @return string Formatted grade string.
     */
    protected function format_grade(?float $value, \grade_item $item): string {
        if ($value === null) {
            return '–';
        }
        return grade_format_gradevalue($value, $item, true);
    }

    /**
     * Format a grade max value for display (always as a plain number, no percentage).
     *
     * @param float $value The grade max value.
     * @param \grade_item $item The grade item (for formatting context).
     * @return string Formatted grade string.
     */
    protected function format_grademax(float $value, \grade_item $item): string {
        return grade_format_gradevalue($value, $item, true, GRADE_DISPLAY_TYPE_REAL);
    }

    /**
     * Format a weight as a percentage string.
     *
     * @param float $weight Weight as a decimal (0–1).
     * @return string Formatted percentage (e.g., "40.0%").
     */
    protected function format_percentage(float $weight): string {
        return format_float($weight * 100, 1) . '%';
    }

    /**
     * Get the structured grade data for a single user.
     *
     * @return array Array of category data structures.
     */
    public function get_grade_data(): array {
        return $this->gradedata;
    }

    /**
     * Whether this course uses category weights.
     *
     * @return bool True if there are multiple weighted categories.
     */
    public function has_weights(): bool {
        return $this->hasweights;
    }

    /**
     * Get the course total for the current user.
     *
     * @return array Course total data with 'grade', 'grademax', and 'percentage' keys.
     */
    public function get_course_total(): array {
        $gradegrade = $this->get_user_grade($this->courseitem->id);
        $percentage = '–';
        if ($gradegrade->finalgrade !== null && (float)$this->courseitem->grademax > 0) {
            $percentage = $this->format_percentage(
                (float)$gradegrade->finalgrade / (float)$this->courseitem->grademax
            );
        }
        return [
            'grade' => $this->format_grade(
                $gradegrade->finalgrade !== null ? (float)$gradegrade->finalgrade : null,
                $this->courseitem
            ),
            'grademax' => $this->format_grademax((float)$this->courseitem->grademax, $this->courseitem),
            'percentage' => $percentage,
        ];
    }

    /**
     * Calculate a running total based only on graded items.
     *
     * For each category, computes the weighted average considering only items that
     * have been graded, re-normalizing weights within the category. Categories with
     * no graded items are excluded from the course-level calculation.
     *
     * @return array Running total data with 'percentage' key, or null percentage if nothing graded.
     */
    public function get_running_total(): array {
        $categoryresults = [];

        foreach ($this->gradedata as $cat) {
            $catweight = $cat['categoryweight_raw'] ?? 1.0;
            $result = $this->calculate_category_running_total($cat);

            if ($result !== null) {
                $categoryresults[] = [
                    'weight' => $catweight,
                    'percentage' => $result,
                ];
            }
        }

        if (empty($categoryresults)) {
            return ['percentage' => '–'];
        }

        // Re-normalize category weights to only include categories with graded items.
        $totalweight = 0;
        foreach ($categoryresults as $cr) {
            $totalweight += $cr['weight'];
        }

        $runningpercentage = 0;
        foreach ($categoryresults as $cr) {
            $normalizedweight = ($totalweight > 0) ? ($cr['weight'] / $totalweight) : 0;
            $runningpercentage += $normalizedweight * $cr['percentage'];
        }

        return [
            'percentage' => $this->format_percentage($runningpercentage),
            'percentage_raw' => round($runningpercentage * 100, 1),
        ];
    }

    /**
     * Calculate the running total for a single category based on graded items only.
     *
     * @param array $cat The category data structure from get_grade_data().
     * @return float|null The category percentage (0–1) based on graded items, or null if none graded.
     */
    protected function calculate_category_running_total(array $cat): ?float {
        $droplow = (int)($cat['droplow'] ?? 0);
        $keephigh = (int)($cat['keephigh'] ?? 0);

        // Gather direct graded items (skipping hidden ones from the user's view).
        $eligibleitems = [];
        if (!empty($cat['items'])) {
            foreach ($cat['items'] as $item) {
                if (!$item['graded'] || $item['ishidden']) {
                    continue;
                }
                if (($item['weight_raw'] ?? 0) <= 0 || ($item['grademax_raw'] ?? 0) <= 0) {
                    continue;
                }
                $eligibleitems[] = $item;
            }
        }

        // Apply drop/keep on the category's direct items.
        $keptitems = $this->apply_drop_keep($eligibleitems, $droplow, $keephigh);

        $gradedweightsum = 0;
        $weightedscoresum = 0;
        foreach ($keptitems as $item) {
            $weight = (float)$item['weight_raw'];
            $gradedweightsum += $weight;
            $weightedscoresum += $weight * ($item['grade_raw'] / $item['grademax_raw']);
        }

        // Recurse into subcategories — these are not subject to the parent's drop/keep
        // because Moodle applies drop/keep only across the direct children of a category;
        // a subcategory total participates as one item, but our reporting uses the
        // subcategory's own aggregated percentage rather than dropping it.
        if (!empty($cat['subcategories'])) {
            foreach ($cat['subcategories'] as $subcat) {
                $subresult = $this->calculate_category_running_total($subcat);
                if ($subresult !== null) {
                    $subweight = $subcat['categoryweight_raw'] ?? 0;
                    $gradedweightsum += $subweight;
                    $weightedscoresum += $subweight * $subresult;
                }
            }
        }

        if ($gradedweightsum <= 0) {
            return null;
        }

        return $weightedscoresum / $gradedweightsum;
    }

    /**
     * Get running total percentages for all top-level categories.
     *
     * Returns an array keyed by category name with formatted percentage strings,
     * or null for categories with no graded items.
     *
     * @return array Array of ['percentage' => string, 'percentage_raw' => float] keyed by index.
     */
    public function get_category_running_totals(): array {
        $result = [];
        foreach ($this->gradedata as $i => $cat) {
            $raw = $this->calculate_category_running_total($cat);
            if ($raw !== null) {
                $result[$i] = [
                    'percentage' => $this->format_percentage($raw),
                    'percentage_raw' => round($raw * 100, 1),
                ];
            }
        }
        return $result;
    }

    /**
     * Get progress bar data for the visual progress view.
     *
     * Returns per-category stacked bar data and a course total bar,
     * along with threshold markers for pass/merit/distinction.
     *
     * @return array Progress data for the template.
     */
    public function get_progress_data(): array {
        // Build thresholds — pass is always present, merit and distinction are optional.
        $thresholds = [];

        $passval = get_config('gradereport_coifish', 'threshold_pass');
        $thresholds[] = [
            'key' => 'pass',
            'label' => get_string('threshold_pass', 'gradereport_coifish'),
            'value' => ($passval !== false && $passval !== '') ? (int)$passval : 50,
        ];

        $meritval = get_config('gradereport_coifish', 'threshold_merit');
        if ($meritval !== false && $meritval !== '' && (int)$meritval > 0) {
            $thresholds[] = [
                'key' => 'merit',
                'label' => get_string('threshold_merit', 'gradereport_coifish'),
                'value' => (int)$meritval,
            ];
        }

        $distinctionval = get_config('gradereport_coifish', 'threshold_distinction');
        if ($distinctionval !== false && $distinctionval !== '' && (int)$distinctionval > 0) {
            $thresholds[] = [
                'key' => 'distinction',
                'label' => get_string('threshold_distinction', 'gradereport_coifish'),
                'value' => (int)$distinctionval,
            ];
        }

        $categorybars = [];
        foreach ($this->gradedata as $cat) {
            $categorybars[] = $this->build_category_bar($cat);
        }

        // Course total bar.
        $gradegrade = $this->get_user_grade($this->courseitem->id);
        $coursepercent = 0;
        if ($gradegrade->finalgrade !== null && (float)$this->courseitem->grademax > 0) {
            $coursepercent = round((float)$gradegrade->finalgrade / (float)$this->courseitem->grademax * 100, 1);
        }

        // Best possible: assume 100% on all ungraded items.
        $bestpossible = $this->calculate_best_possible();

        $coursetotalbar = [
            'name' => get_string('coursetotal', 'gradereport_coifish'),
            'percentage' => $coursepercent,
            'segments' => [],
            'iscoursetotal' => true,
            'bestpossible' => $bestpossible,
        ];

        // Goal planner: what does the student need on remaining items for each threshold?
        $goals = $this->calculate_goal_targets($thresholds, $bestpossible);

        // Pre-render goal messages for the template, hiding unachievable goals.
        foreach ($goals as $key => &$goal) {
            if (!$goal['achievable'] && !$goal['already_met']) {
                unset($goals[$key]);
            } else if ($goal['already_met']) {
                $goal['message'] = get_string('goal_achieved', 'gradereport_coifish');
            } else {
                $goal['message'] = get_string(
                    'goal_target',
                    'gradereport_coifish',
                    (object)['label' => $goal['label'], 'required' => $goal['required']]
                );
            }
        }
        unset($goal);
        $goals = array_values($goals);

        return [
            'categorybars' => $categorybars,
            'coursetotalbar' => $coursetotalbar,
            'thresholds' => $thresholds,
            'goals' => $goals,
            'hasgoals' => !empty($goals),
        ];
    }

    /**
     * Build stacked bar data for a single category.
     *
     * @param array $cat The category data from get_grade_data().
     * @return array Bar data with segments for each item.
     */
    protected function build_category_bar(array $cat): array {
        $droplow = (int)($cat['droplow'] ?? 0);
        $keephigh = (int)($cat['keephigh'] ?? 0);

        $totalgraded = 0;
        $totalitems = 0;

        // The ring reports against the EFFECTIVE expected work — the count the
        // student is required to complete after drop/keep is applied. The graded
        // count is capped at the expected count so "5 of 3" never appears when
        // a student has graded more than the rule keeps.
        $directeligible = array_values(array_filter($cat['items'] ?? [], static function ($item) {
            return empty($item['isextracredit']) && empty($item['ishidden']);
        }));
        $directexpected = count($directeligible);
        if ($keephigh > 0) {
            $directexpected = min($directexpected, $keephigh);
        } else if ($droplow > 0) {
            $directexpected = max(0, $directexpected - $droplow);
        }
        $directgraded = 0;
        foreach ($directeligible as $item) {
            if ($item['graded'] && $item['grade_raw'] !== null) {
                $directgraded++;
            }
        }
        $totalitems += $directexpected;
        $totalgraded += min($directgraded, $directexpected);

        // Compute subcategory bars once; reuse counts AND percentages below.
        $subbars = [];
        foreach ($cat['subcategories'] ?? [] as $subcat) {
            $subbar = $this->build_category_bar($subcat);
            $subbars[] = ['bar' => $subbar, 'weight' => (float)($subcat['categoryweight_raw'] ?? 0)];
            $totalitems += (int)$subbar['total_count'];
            $totalgraded += (int)$subbar['graded_count'];
        }

        // For the bar percentage, apply drop/keep on the category's direct items so
        // a "best N of M" category does not look 0% while later attempts are pending.
        // Ungraded items still count as 0% in the projected total of the *kept* set —
        // matching how Moodle aggregates the category once everything is in.
        $directitems = array_filter($cat['items'] ?? [], static function ($item) {
            return empty($item['isextracredit']) && empty($item['ishidden'])
                && ($item['weight_raw'] ?? 0) > 0 && ($item['grademax_raw'] ?? 0) > 0;
        });
        $keptdirect = $this->apply_drop_keep_projected($directitems, $droplow, $keephigh);

        $weightedscoreall = 0;
        $totalweightall = 0;
        foreach ($keptdirect as $item) {
            $weight = (float)$item['weight_raw'];
            $totalweightall += $weight;
            if (!empty($item['graded']) && $item['grade_raw'] !== null) {
                $weightedscoreall += $weight * ((float)$item['grade_raw'] / (float)$item['grademax_raw']);
            }
        }
        foreach ($subbars as $entry) {
            if ($entry['weight'] > 0) {
                $totalweightall += $entry['weight'];
                $weightedscoreall += $entry['weight'] * ($entry['bar']['percentage'] / 100);
            }
        }
        $catpercent = ($totalweightall > 0)
            ? round(($weightedscoreall / $totalweightall) * 100, 1)
            : 0;

        // Running total: percentage based on graded items only, also honoring drop/keep.
        $runningresult = $this->calculate_category_running_total($cat);
        $runningpercent = ($runningresult !== null) ? round($runningresult * 100, 1) : $catpercent;

        return [
            'name' => $cat['categoryname'],
            'weight' => $cat['categoryweight'] ?? '',
            'percentage' => $catpercent,
            'running_percentage' => $runningpercent,
            'graded_count' => $totalgraded,
            'total_count' => $totalitems,
            'aggregation_label' => $cat['aggregation_label'] ?? '',
            'has_aggregation_label' => !empty($cat['has_aggregation_label']),
        ];
    }

    /**
     * Apply drop/keep using a projected ranking that treats ungraded items as 0%.
     *
     * Useful for the "current" view (where missed work counts as zero) so the bar
     * shows the category total as if drop/keep were applied at the current moment.
     *
     * @param array $items Items with grade_raw/grademax_raw/graded/isextracredit.
     * @param int $droplow Number of lowest grades to drop.
     * @param int $keephigh Number of highest grades to keep.
     * @param float $ungradedpct Percentage (0–1) to assume for ungraded items when ranking.
     * @return array Items remaining after drop/keep.
     */
    protected function apply_drop_keep_projected(array $items, int $droplow, int $keephigh, float $ungradedpct = 0.0): array {
        if (($droplow <= 0 && $keephigh <= 0) || empty($items)) {
            return $items;
        }

        $rankable = [];
        $bypass = [];
        foreach ($items as $idx => $item) {
            if (!empty($item['isextracredit'])) {
                $bypass[$idx] = true;
                continue;
            }
            $gmax = (float)($item['grademax_raw'] ?? 0);
            $pct = (!empty($item['graded']) && $item['grade_raw'] !== null && $gmax > 0)
                ? (float)$item['grade_raw'] / $gmax
                : $ungradedpct;
            $rankable[$idx] = $pct;
        }
        if (empty($rankable)) {
            return $items;
        }

        arsort($rankable);
        $ranked = array_keys($rankable);
        if ($keephigh > 0) {
            $keepidx = array_slice($ranked, 0, $keephigh);
        } else {
            $keepidx = array_slice($ranked, 0, max(0, count($ranked) - $droplow));
        }
        $keepset = array_fill_keys($keepidx, true);

        $result = [];
        foreach ($items as $idx => $item) {
            if (isset($bypass[$idx]) || isset($keepset[$idx])) {
                $result[] = $item;
            }
        }
        return $result;
    }

    /**
     * Flatten subcategory items recursively into a single array.
     *
     * @param array $subcategories The subcategories to flatten.
     * @return array Flat array of item data.
     */
    protected function flatten_subcategory_items(array $subcategories): array {
        $items = [];
        foreach ($subcategories as $subcat) {
            if (!empty($subcat['items'])) {
                $items = array_merge($items, $subcat['items']);
            }
            if (!empty($subcat['subcategories'])) {
                $items = array_merge($items, $this->flatten_subcategory_items($subcat['subcategories']));
            }
        }
        return $items;
    }

    /**
     * Calculate the best possible course percentage if the student scores 100% on all remaining work.
     *
     * @return float Best possible percentage (0-100).
     */
    protected function calculate_best_possible(): float {
        $totalweightedachieved = 0;
        $totalweight = 0;

        foreach ($this->gradedata as $cat) {
            if (!empty($cat['iscoursecategory'])) {
                $catpercent = $this->calculate_category_projection($cat, 1.0);
                $catweight = $cat['categoryweight_raw'] ?? 1.0;
                $totalweight += $catweight;
                $totalweightedachieved += $catweight * $catpercent;
                continue;
            }
            $catweight = $cat['categoryweight_raw'] ?? 1.0;
            $totalweight += $catweight;
            $totalweightedachieved += $catweight * $this->calculate_category_projection($cat, 1.0);
        }

        if ($totalweight <= 0) {
            return 100;
        }

        return round(($totalweightedachieved / $totalweight) * 100, 1);
    }

    /**
     * Project a category's percentage assuming ungraded items score $projection (0–1).
     *
     * Applies the category's drop/keep rule using the projected ranking so the
     * computation matches Moodle's aggregation behavior. Recurses into subcategories.
     *
     * @param array $cat The category data structure.
     * @param float $projection Percentage (0–1) to assume for ungraded items.
     * @return float Category percentage (0–1).
     */
    protected function calculate_category_projection(array $cat, float $projection): float {
        $droplow = (int)($cat['droplow'] ?? 0);
        $keephigh = (int)($cat['keephigh'] ?? 0);

        $eligibleitems = [];
        foreach ($cat['items'] ?? [] as $item) {
            if (!empty($item['isextracredit']) || !empty($item['ishidden'])) {
                continue;
            }
            if (($item['weight_raw'] ?? 0) <= 0 || ($item['grademax_raw'] ?? 0) <= 0) {
                continue;
            }
            $eligibleitems[] = $item;
        }

        $keptitems = $this->apply_drop_keep_projected($eligibleitems, $droplow, $keephigh, $projection);

        $weightsum = 0;
        $weightedscore = 0;
        foreach ($keptitems as $item) {
            $weight = (float)$item['weight_raw'];
            $weightsum += $weight;
            if (!empty($item['graded']) && $item['grade_raw'] !== null) {
                $weightedscore += $weight * ((float)$item['grade_raw'] / (float)$item['grademax_raw']);
            } else {
                $weightedscore += $weight * $projection;
            }
        }

        if (!empty($cat['subcategories'])) {
            foreach ($cat['subcategories'] as $subcat) {
                $subweight = (float)($subcat['categoryweight_raw'] ?? 0);
                if ($subweight <= 0) {
                    continue;
                }
                $weightsum += $subweight;
                $weightedscore += $subweight * $this->calculate_category_projection($subcat, $projection);
            }
        }

        return $weightsum > 0 ? ($weightedscore / $weightsum) : $projection;
    }

    /**
     * Calculate what average score is needed on remaining items to reach each threshold.
     *
     * For each enabled threshold, this solves for the required score (0-1) on ungraded
     * items using the same weighted category model as calculate_best_possible().
     *
     * @param array $thresholds The threshold definitions (label, value).
     * @param float $bestpossible The best possible percentage.
     * @return array Goal targets, each with label, value, required, achievable, and already_met.
     */
    protected function calculate_goal_targets(array $thresholds, float $bestpossible): array {
        // Current (everything ungraded counted as 0%) and best-possible (100%) anchor
        // the search space. Drop/keep makes the relationship between ungraded x and
        // total non-linear, so we solve numerically.
        $currentpercent = $this->project_overall_percent(0.0);
        $hasungraded = $this->has_ungraded_items();

        $goals = [];
        foreach ($thresholds as $threshold) {
            $target = (float)$threshold['value'];

            if ($currentpercent >= $target) {
                $goals[] = [
                    'label' => $threshold['label'],
                    'value' => $threshold['value'],
                    'required' => 0,
                    'achievable' => true,
                    'already_met' => true,
                ];
                continue;
            }

            if (!$hasungraded || $bestpossible < $target) {
                $goals[] = [
                    'label' => $threshold['label'],
                    'value' => $threshold['value'],
                    'required' => 0,
                    'achievable' => false,
                    'already_met' => false,
                ];
                continue;
            }

            // Bisect on x ∈ [0, 1] to find minimum ungraded percentage that hits the target.
            $lo = 0.0;
            $hi = 1.0;
            for ($i = 0; $i < 24; $i++) {
                $mid = ($lo + $hi) / 2;
                if ($this->project_overall_percent($mid) >= $target) {
                    $hi = $mid;
                } else {
                    $lo = $mid;
                }
            }
            $requiredpercent = round($hi * 100, 1);

            $goals[] = [
                'label' => $threshold['label'],
                'value' => $threshold['value'],
                'required' => min($requiredpercent, 100),
                'achievable' => true,
                'already_met' => false,
            ];
        }

        return $goals;
    }

    /**
     * Project the overall course percentage assuming ungraded items score $x (0–1).
     *
     * @param float $x Percentage assumed for ungraded items.
     * @return float Course percentage (0–100).
     */
    protected function project_overall_percent(float $x): float {
        $totalweight = 0;
        $totalcontribution = 0;
        foreach ($this->gradedata as $cat) {
            $catweight = (float)($cat['categoryweight_raw'] ?? 1.0);
            $totalweight += $catweight;
            $totalcontribution += $catweight * $this->calculate_category_projection($cat, $x);
        }
        if ($totalweight <= 0) {
            return $x * 100;
        }
        return ($totalcontribution / $totalweight) * 100;
    }

    /**
     * Return true if any visible item in any category is still ungraded.
     *
     * @return bool
     */
    protected function has_ungraded_items(): bool {
        foreach ($this->gradedata as $cat) {
            if ($this->category_has_ungraded($cat)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Recursively check a category for any ungraded eligible items.
     *
     * @param array $cat Category data.
     * @return bool
     */
    protected function category_has_ungraded(array $cat): bool {
        foreach ($cat['items'] ?? [] as $item) {
            if (!empty($item['isextracredit']) || !empty($item['ishidden'])) {
                continue;
            }
            if (($item['weight_raw'] ?? 0) <= 0 || ($item['grademax_raw'] ?? 0) <= 0) {
                continue;
            }
            if (empty($item['graded']) || $item['grade_raw'] === null) {
                return true;
            }
        }
        foreach ($cat['subcategories'] ?? [] as $subcat) {
            if ($this->category_has_ungraded($subcat)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the course-level gamification settings.
     *
     * Returns the decoded JSON settings for the current course, or an empty array if none are set.
     *
     * @return array Course-level settings.
     */
    public function get_course_gamification_settings(): array {
        $raw = get_config('gradereport_coifish', 'course_' . $this->courseid);
        return $raw ? (json_decode($raw, true) ?: []) : [];
    }

    /**
     * Check if a specific widget is enabled, considering both site-level and course-level settings.
     *
     * @param string $widgetkey The widget key (e.g. 'overall', 'trend').
     * @param array $coursesettings The course-level settings.
     * @return bool Whether the widget is active.
     */
    protected function is_widget_enabled(string $widgetkey, array $coursesettings): bool {
        // Must be enabled at site level first.
        if (empty(get_config('gradereport_coifish', 'widget_' . $widgetkey))) {
            return false;
        }
        // If course has widget overrides, check them.
        if (isset($coursesettings['widgets'][$widgetkey])) {
            return (bool)$coursesettings['widgets'][$widgetkey];
        }
        // No course override — default to enabled (follows site setting).
        return true;
    }

    /**
     * Build gamification widget data for the progress view.
     *
     * Returns an array of enabled widgets, each keyed by type.
     * Competitive widgets are suppressed when enrolment is below the configured minimum.
     * Course-level settings can disable gamification or specific widgets.
     * Preview mode (teacher only) bypasses the course-level enabled check.
     *
     * @param bool $preview Whether this is a teacher preview (bypasses course enabled check).
     * @return array Gamification data with 'widgets' array, 'haswidgets' bool, and 'ispreview' bool.
     */
    public function get_gamification_data(bool $preview = false): array {
        $coursesettings = $this->get_course_gamification_settings();
        $courseenabled = !empty($coursesettings['gamification_enabled']);

        // If not enabled and not a teacher preview, return empty.
        if (!$courseenabled && !$preview) {
            return ['widgets' => [], 'haswidgets' => false, 'nograded' => false, 'ispreview' => false];
        }

        // Show the "hidden from students" banner only when teacher is viewing disabled widgets.
        $ispreview = $preview && !$courseenabled;

        $widgets = [];

        // Gather the student's per-item scores (needed by personal widgets).
        $itemscores = $this->get_student_item_scores();
        $hasgraded = !empty($itemscores);

        // If the student has nothing graded, return early with the unlock message.
        if (!$hasgraded) {
            return ['widgets' => [], 'haswidgets' => false, 'nograded' => true, 'ispreview' => $ispreview];
        }

        // Competitive widgets need enrolment data.
        $competitivedata = $this->get_competitive_base_data();

        // 1. Overall percentile.
        if ($this->is_widget_enabled('overall', $coursesettings) && $competitivedata !== null) {
            $widget = $this->build_widget_overall($competitivedata);
            if ($widget) {
                $widgets[] = $widget;
            }
        }

        // 2. Nearest neighbours.
        if ($this->is_widget_enabled('neighbours', $coursesettings) && $competitivedata !== null) {
            $widget = $this->build_widget_neighbours($competitivedata);
            if ($widget) {
                $widgets[] = $widget;
            }
        }

        // 3. Improvement rank.
        if ($this->is_widget_enabled('improvement', $coursesettings) && $competitivedata !== null) {
            $widget = $this->build_widget_improvement($competitivedata);
            if ($widget) {
                $widgets[] = $widget;
            }
        }

        // 4. Personal trend (non-competitive).
        if ($this->is_widget_enabled('trend', $coursesettings)) {
            $widget = $this->build_widget_trend($itemscores);
            if ($widget) {
                $widgets[] = $widget;
            }
        }

        // 5. Streak tracker (non-competitive).
        if ($this->is_widget_enabled('streak', $coursesettings)) {
            $widget = $this->build_widget_streak($itemscores);
            if ($widget) {
                $widgets[] = $widget;
            }
        }

        // 6. Feedback engagement (non-competitive) — built first so stats can feed into milestones.
        $feedbackwidget = null;
        $feedbackstats = null;
        if ($this->is_widget_enabled('feedback', $coursesettings)) {
            $feedbackwidget = $this->build_widget_feedback();
            if ($feedbackwidget) {
                $feedbackstats = [
                    'viewed' => $feedbackwidget['viewed'],
                    'total' => $feedbackwidget['total'],
                ];
            }
        }

        // 7. Milestone badges (non-competitive) — includes feedback milestones.
        if ($this->is_widget_enabled('milestones', $coursesettings)) {
            $widget = $this->build_widget_milestones($itemscores, $feedbackstats);
            if ($widget) {
                $widgets[] = $widget;
            }
        }

        // Add feedback widget after milestones.
        if ($feedbackwidget) {
            $widgets[] = $feedbackwidget;
        }

        // 8. Consistency tracker (non-competitive).
        if ($this->is_widget_enabled('consistency', $coursesettings)) {
            $widget = $this->build_widget_consistency();
            if ($widget) {
                $widgets[] = $widget;
            }
        }

        // 9. Early bird / submission timeliness (non-competitive).
        if ($this->is_widget_enabled('earlybird', $coursesettings)) {
            $widget = $this->build_widget_earlybird();
            if ($widget) {
                $widgets[] = $widget;
            }
        }

        // 10. Self-regulation tracker (non-competitive).
        if ($this->is_widget_enabled('selfregulation', $coursesettings)) {
            $widget = $this->build_widget_selfregulation();
            if ($widget) {
                $widgets[] = $widget;
            }
        }

        return [
            'widgets' => $widgets,
            'haswidgets' => !empty($widgets),
            'nograded' => false,
            'ispreview' => $ispreview,
        ];
    }

    /**
     * Get the current student's graded item scores ordered by time graded.
     *
     * @return array Array of ['name' => string, 'percent' => float, 'time' => int] sorted by time.
     */
    protected function get_student_item_scores(): array {
        $scores = [];
        foreach ($this->gradedata as $cat) {
            $this->collect_category_scores($cat, $scores);
        }
        // Sort by time graded.
        usort($scores, function ($a, $b) {
            return $a['time'] <=> $b['time'];
        });
        return $scores;
    }

    /**
     * Append the kept (non-dropped) graded item scores from a category and its sub-categories.
     *
     * Items that would be removed by the category's drop-lowest / keep-highest rule are
     * excluded so diagnostics like the pass-streak don't penalise optional work the
     * student legitimately skipped.
     *
     * @param array $cat Category data structure.
     * @param array $scores Score accumulator (passed by reference).
     */
    protected function collect_category_scores(array $cat, array &$scores): void {
        $droplow = (int)($cat['droplow'] ?? 0);
        $keephigh = (int)($cat['keephigh'] ?? 0);

        $eligible = [];
        foreach ($cat['items'] ?? [] as $item) {
            if (!empty($item['isextracredit']) || !empty($item['ishidden'])) {
                continue;
            }
            if (empty($item['graded']) || $item['grade_raw'] === null || ($item['grademax_raw'] ?? 0) <= 0) {
                continue;
            }
            $eligible[] = $item;
        }
        $kept = $this->apply_drop_keep($eligible, $droplow, $keephigh);

        foreach ($kept as $item) {
            $itemid = $item['itemid'];
            $time = 0;
            if (isset($this->usergrades[$itemid])) {
                $time = (int)($this->usergrades[$itemid]->timemodified ?? 0);
            }
            $scores[] = [
                'name' => $item['itemname'],
                'percent' => round(($item['grade_raw'] / $item['grademax_raw']) * 100, 1),
                'time' => $time,
            ];
        }

        foreach ($cat['subcategories'] ?? [] as $subcat) {
            $this->collect_category_scores($subcat, $scores);
        }
    }

    /**
     * Get competitive base data: all student percentages, ranked, with enrolment check.
     *
     * Returns null if enrolment is below the configured minimum.
     *
     * @return array|null Array with 'percentages' (uid => percent sorted desc), 'userids', 'count', 'userrank', 'userpercent'.
     */
    protected function get_competitive_base_data(): ?array {
        global $DB;

        $minenrolment = (int)get_config('gradereport_coifish', 'leaderboard_min_enrolment');
        if ($minenrolment <= 0) {
            $minenrolment = 10;
        }

        $enrolledusers = get_enrolled_users(
            $this->context,
            'moodle/course:isincompletionreports',
            0,
            'u.id',
            null,
            0,
            0,
            true
        );
        $enrolledcount = count($enrolledusers);

        if ($enrolledcount < $minenrolment) {
            return null;
        }

        $userids = array_keys($enrolledusers);
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['itemid'] = $this->courseitem->id;

        $grades = $DB->get_records_sql(
            "SELECT userid, finalgrade
               FROM {grade_grades}
              WHERE itemid = :itemid AND userid $insql",
            $params
        );

        $grademax = (float)$this->courseitem->grademax;
        $percentages = [];
        foreach ($userids as $uid) {
            $finalgrade = isset($grades[$uid]) ? (float)$grades[$uid]->finalgrade : 0;
            $percentages[$uid] = ($grademax > 0) ? round($finalgrade / $grademax * 100, 1) : 0;
        }

        arsort($percentages);

        // Build rank map with tied rank support.
        $ranks = [];
        $counter = 0;
        $prevpercent = null;
        $rank = 0;
        foreach ($percentages as $uid => $percent) {
            $counter++;
            if ($percent !== $prevpercent) {
                $rank = $counter;
                $prevpercent = $percent;
            }
            $ranks[$uid] = $rank;
        }

        return [
            'percentages' => $percentages,
            'ranks' => $ranks,
            'count' => $enrolledcount,
            'userrank' => $ranks[$this->userid] ?? $enrolledcount,
            'userpercent' => $percentages[$this->userid] ?? 0,
            'grades' => $grades,
            'userids' => $userids,
        ];
    }

    /**
     * Build the overall percentile widget.
     *
     * @param array $data Competitive base data.
     * @return array|null Widget data or null.
     */
    protected function build_widget_overall(array $data): ?array {
        $rank = $data['userrank'];
        $total = $data['count'];

        // Percentile: percentage of students you are at or above.
        $percentile = round((($total - $rank) / $total) * 100);
        // Show as "Top X%" — cap at 99 to avoid "Top 0%", floor at 1 to avoid "Top 100%".
        $toppercent = max(1, 100 - $percentile);

        // Only show if the student is within the configured top percentile threshold.
        $threshold = (int)get_config('gradereport_coifish', 'percentile_threshold');
        if ($threshold <= 0) {
            $threshold = 33;
        }
        if ($toppercent > $threshold) {
            return null;
        }

        return [
            'type' => 'overall',
            'isoverall' => true,
            'title' => get_string('widget_overall_title', 'gradereport_coifish'),
            'toppercent' => $toppercent,
            'toppercentlabel' => get_string('widget_overall_top', 'gradereport_coifish', $toppercent),
            'percentage' => $data['userpercent'],
        ];
    }

    /**
     * Build the nearest neighbours widget — 2 above and 2 below the current student.
     *
     * @param array $data Competitive base data.
     * @return array|null Widget data or null.
     */
    protected function build_widget_neighbours(array $data): ?array {
        $sorted = $data['percentages'];  // Already sorted descending.
        $uids = array_keys($sorted);
        $myindex = array_search($this->userid, $uids);

        if ($myindex === false) {
            return null;
        }

        // Take 2 above and 2 below.
        $startabove = max(0, $myindex - 2);
        $endbelow = min(count($uids) - 1, $myindex + 2);

        $rows = [];
        $counter = 0;
        for ($i = $startabove; $i <= $endbelow; $i++) {
            $uid = $uids[$i];
            $counter++;
            $isyou = ($uid === $this->userid);
            $rows[] = [
                'position' => $counter,
                'percentage' => $sorted[$uid],
                'isyou' => $isyou,
                'label' => $isyou
                    ? get_string('widget_neighbours_you', 'gradereport_coifish')
                    : get_string('widget_neighbours_student', 'gradereport_coifish') . ' ' . $counter,
            ];
        }

        return [
            'type' => 'neighbours',
            'isneighbours' => true,
            'title' => get_string('widget_neighbours_title', 'gradereport_coifish'),
            'rows' => $rows,
        ];
    }

    /**
     * Build the improvement rank widget.
     *
     * Ranks students by percentage-point improvement from their first to their latest graded item.
     *
     * @param array $data Competitive base data.
     * @return array|null Widget data or null.
     */
    protected function build_widget_improvement(array $data): ?array {
        global $DB;

        // Get all grade items for this course (excluding course total and category totals).
        $gradeitems = $DB->get_records_select(
            'grade_items',
            "courseid = :courseid AND itemtype != 'course' AND itemtype != 'category'",
            ['courseid' => $this->courseid],
            '',
            'id, grademax'
        );

        if (empty($gradeitems)) {
            return null;
        }

        $itemids = array_keys($gradeitems);
        $userids = $data['userids'];

        [$iinsql, $iparams] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'item');
        [$uinsql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');

        $allgrades = $DB->get_records_sql(
            "SELECT id, userid, itemid, finalgrade, timemodified
               FROM {grade_grades}
              WHERE itemid $iinsql AND userid $uinsql AND finalgrade IS NOT NULL
           ORDER BY timemodified ASC, id ASC",
            array_merge($iparams, $uparams)
        );

        // Group by user: find first and latest score.
        $userfirstandlast = [];
        foreach ($allgrades as $grade) {
            $uid = (int)$grade->userid;
            $gmax = (float)($gradeitems[$grade->itemid]->grademax ?? 100);
            if ($gmax <= 0) {
                continue;
            }
            $percent = round((float)$grade->finalgrade / $gmax * 100, 1);

            if (!isset($userfirstandlast[$uid])) {
                $userfirstandlast[$uid] = ['first' => $percent, 'latest' => $percent];
            } else {
                $userfirstandlast[$uid]['latest'] = $percent;
            }
        }

        // Calculate improvement deltas and rank.
        $improvements = [];
        foreach ($userfirstandlast as $uid => $fl) {
            $improvements[$uid] = round($fl['latest'] - $fl['first'], 1);
        }
        arsort($improvements);

        // Find current user's rank and delta.
        $myimprovement = $improvements[$this->userid] ?? 0;
        $myrank = 1;
        foreach ($improvements as $uid => $delta) {
            if ($uid === $this->userid) {
                break;
            }
            $myrank++;
        }

        return [
            'type' => 'improvement',
            'isimprovement' => true,
            'title' => get_string('widget_improvement_title', 'gradereport_coifish'),
            'delta' => $myimprovement,
            'deltaabs' => abs($myimprovement),
            'ispositive' => $myimprovement > 0,
            'isnegative' => $myimprovement < 0,
            'deltalabel' => get_string(
                'widget_improvement_up',
                'gradereport_coifish',
                abs($myimprovement)
            ),
            'ranklabel' => get_string('widget_improvement_rank', 'gradereport_coifish', $myrank),
        ];
    }

    /**
     * Build the personal trend widget — sparkline of recent scores.
     *
     * @param array $itemscores Array of per-item scores sorted by time.
     * @return array|null Widget data or null.
     */
    protected function build_widget_trend(array $itemscores): ?array {
        if (count($itemscores) < 2) {
            return null;
        }

        // Take up to the last 8 scores for the sparkline.
        $recent = array_slice($itemscores, -8);

        // Calculate trend direction from the last 3 scores.
        $last3 = array_slice($itemscores, -3);
        $first = $last3[0]['percent'];
        $last = end($last3)['percent'];
        $diff = $last - $first;

        $action = '';
        if ($diff > 2) {
            $direction = 'up';
            $directionlabel = get_string('widget_trend_up', 'gradereport_coifish');
        } else if ($diff < -2) {
            $direction = 'down';
            $directionlabel = get_string('widget_trend_down', 'gradereport_coifish');
            $action = get_string('widget_trend_action_down', 'gradereport_coifish');
        } else {
            $direction = 'steady';
            $directionlabel = get_string('widget_trend_steady', 'gradereport_coifish');
        }

        $isrisk = ($direction === 'down');

        // Prepare sparkline data points for JS.
        $sparkpoints = [];
        foreach ($recent as $score) {
            $sparkpoints[] = $score['percent'];
        }

        return [
            'type' => 'trend',
            'istrend' => true,
            'title' => get_string('widget_trend_title', 'gradereport_coifish'),
            'direction' => $direction,
            'directionlabel' => $directionlabel,
            'sparkjson' => json_encode($sparkpoints),
            'latestpercent' => end($itemscores)['percent'],
            'action' => $action,
            'hasaction' => !empty($action),
            'isrisk' => $isrisk,
        ];
    }

    /**
     * Build the streak tracker widget.
     *
     * @param array $itemscores Array of per-item scores sorted by time.
     * @return array|null Widget data or null.
     */
    protected function build_widget_streak(array $itemscores): ?array {
        if (empty($itemscores)) {
            return null;
        }

        $passval = get_config('gradereport_coifish', 'threshold_pass');
        $passthreshold = ($passval !== false && $passval !== '') ? (int)$passval : 50;

        // Count current streak (consecutive passes from the end).
        $currentstreak = 0;
        for ($i = count($itemscores) - 1; $i >= 0; $i--) {
            if ($itemscores[$i]['percent'] >= $passthreshold) {
                $currentstreak++;
            } else {
                break;
            }
        }

        // Find best ever streak.
        $beststreak = 0;
        $run = 0;
        foreach ($itemscores as $score) {
            if ($score['percent'] >= $passthreshold) {
                $run++;
                $beststreak = max($beststreak, $run);
            } else {
                $run = 0;
            }
        }

        $hasstreak = $currentstreak > 0;
        $isrisk = !$hasstreak && $beststreak > 0;

        $action = '';
        if ($isrisk) {
            $action = get_string(
                'widget_streak_action',
                'gradereport_coifish',
                $passthreshold
            );
        }

        return [
            'type' => 'streak',
            'isstreak' => true,
            'title' => get_string('widget_streak_title', 'gradereport_coifish'),
            'currentstreak' => $currentstreak,
            'beststreak' => $beststreak,
            'hasstreak' => $hasstreak,
            'streaklabel' => $hasstreak
                ? get_string('widget_streak_count', 'gradereport_coifish', $currentstreak)
                : get_string('widget_streak_none', 'gradereport_coifish'),
            'bestlabel' => get_string('widget_streak_best', 'gradereport_coifish', $beststreak),
            'action' => $action,
            'hasaction' => !empty($action),
            'isrisk' => $isrisk,
        ];
    }

    /**
     * Build the milestone badges widget.
     *
     * @param array $itemscores Array of per-item scores sorted by time.
     * @param array|null $feedbackstats Feedback engagement stats ['viewed' => int, 'total' => int] or null.
     * @return array|null Widget data or null.
     */
    protected function build_widget_milestones(array $itemscores, ?array $feedbackstats = null): ?array {
        if (empty($itemscores)) {
            return null;
        }

        $passval = get_config('gradereport_coifish', 'threshold_pass');
        $passthreshold = ($passval !== false && $passval !== '') ? (int)$passval : 50;

        // Count items and passes.
        $gradedcount = count($itemscores);
        $passcount = 0;
        $hasperfect = false;
        $totalscores = 0;
        $beatavgcount = 0;

        foreach ($itemscores as $i => $score) {
            $totalscores += $score['percent'];
            if ($score['percent'] >= $passthreshold) {
                $passcount++;
            }
            if ($score['percent'] >= 99.9) {
                $hasperfect = true;
            }
            // Beat your average — is this score above the running average of prior scores?
            if ($i > 0) {
                $prioravg = 0;
                for ($j = 0; $j < $i; $j++) {
                    $prioravg += $itemscores[$j]['percent'];
                }
                $prioravg /= $i;
                if ($score['percent'] > $prioravg) {
                    $beatavgcount++;
                }
            }
        }

        // Count total items in course (graded + ungraded) for "all submitted" check.
        $totalitems = 0;
        foreach ($this->gradedata as $cat) {
            $items = $cat['items'] ?? [];
            if (!empty($cat['subcategories'])) {
                $items = array_merge($items, $this->flatten_subcategory_items($cat['subcategories']));
            }
            foreach ($items as $item) {
                if (!$item['isextracredit'] && !$item['ishidden']) {
                    $totalitems++;
                }
            }
        }

        $badges = [];

        // First grade — always earned if we have any scores.
        $badges[] = [
            'key' => 'first_grade',
            'label' => get_string('milestone_first_grade', 'gradereport_coifish'),
            'earned' => true,
            'icon' => 'star',
        ];

        // All submitted.
        $allsubmitted = ($gradedcount >= $totalitems && $totalitems > 0);
        $badges[] = [
            'key' => 'all_submitted',
            'label' => get_string('milestone_all_submitted', 'gradereport_coifish'),
            'earned' => $allsubmitted,
            'icon' => 'check-circle',
        ];

        // Passed 5.
        $badges[] = [
            'key' => 'passed_five',
            'label' => get_string('milestone_passed_five', 'gradereport_coifish'),
            'earned' => $passcount >= 5,
            'icon' => 'trophy',
        ];

        // Beat your average (at least 3 times).
        $badges[] = [
            'key' => 'beat_average',
            'label' => get_string('milestone_beat_average', 'gradereport_coifish'),
            'earned' => $beatavgcount >= 3,
            'icon' => 'arrow-up',
        ];

        // Hat trick — 3 consecutive passes.
        $beststreak = 0;
        $run = 0;
        foreach ($itemscores as $score) {
            if ($score['percent'] >= $passthreshold) {
                $run++;
                $beststreak = max($beststreak, $run);
            } else {
                $run = 0;
            }
        }
        $badges[] = [
            'key' => 'hat_trick',
            'label' => get_string('milestone_hat_trick', 'gradereport_coifish'),
            'earned' => $beststreak >= 3,
            'icon' => 'fire',
        ];

        // Perfect score.
        $badges[] = [
            'key' => 'perfect_score',
            'label' => get_string('milestone_perfect_score', 'gradereport_coifish'),
            'earned' => $hasperfect,
            'icon' => 'gem',
        ];

        // Feedback-related milestones.
        if ($feedbackstats && $feedbackstats['total'] > 0) {
            // First feedback viewed.
            $badges[] = [
                'key' => 'first_feedback',
                'label' => get_string('milestone_first_feedback', 'gradereport_coifish'),
                'earned' => $feedbackstats['viewed'] >= 1,
                'icon' => 'comment',
            ];

            // Feedback champion — viewed all available feedback.
            $badges[] = [
                'key' => 'feedback_champion',
                'label' => get_string('milestone_feedback_champion', 'gradereport_coifish'),
                'earned' => $feedbackstats['viewed'] >= $feedbackstats['total'],
                'icon' => 'award',
            ];
        }

        $earnedcount = count(array_filter($badges, function ($b) {
            return $b['earned'];
        }));

        return [
            'type' => 'milestones',
            'ismilestones' => true,
            'title' => get_string('widget_milestones_title', 'gradereport_coifish'),
            'badges' => $badges,
            'earnedcount' => $earnedcount,
            'totalcount' => count($badges),
        ];
    }

    /**
     * Build the Consistency Tracker widget.
     *
     * Measures how evenly spaced a student's assignment submissions are.
     * Uses the coefficient of variation of inter-submission gaps — a low
     * CV means steady pacing, a high CV means bursty/cramming behaviour.
     *
     * @return array|null Widget data or null if fewer than 3 submissions.
     */
    protected function build_widget_consistency(): ?array {
        global $DB;

        $userid = $this->userid;

        // Get submission timestamps for all assignments in this course.
        $submissions = $DB->get_records_sql(
            "SELECT asub.id, asub.timemodified
               FROM {assign_submission} asub
               JOIN {assign} a ON a.id = asub.assignment
              WHERE a.course = :courseid
                AND asub.userid = :userid
                AND asub.status = 'submitted'
                AND asub.latest = 1
           ORDER BY asub.timemodified ASC",
            ['courseid' => $this->courseid, 'userid' => $userid]
        );

        // Also include quiz attempt timestamps.
        $quizattempts = $DB->get_records_sql(
            "SELECT qa.id, qa.timefinish AS timemodified
               FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
              WHERE q.course = :courseid
                AND qa.userid = :userid
                AND qa.state IN ('finished', 'abandoned')
           ORDER BY qa.timefinish ASC",
            ['courseid' => $this->courseid, 'userid' => $userid]
        );

        $timestamps = [];
        foreach ($submissions as $s) {
            $timestamps[] = (int)$s->timemodified;
        }
        foreach ($quizattempts as $qa) {
            $timestamps[] = (int)$qa->timemodified;
        }
        sort($timestamps);

        // Need at least 3 submissions to measure consistency.
        if (count($timestamps) < 3) {
            return null;
        }

        // Calculate gaps between consecutive submissions (in days).
        $gaps = [];
        for ($i = 1; $i < count($timestamps); $i++) {
            $gaps[] = ($timestamps[$i] - $timestamps[$i - 1]) / 86400;
        }

        $meangap = array_sum($gaps) / count($gaps);
        if ($meangap <= 0) {
            return null;
        }

        // Coefficient of variation: stddev / mean. Lower = more consistent.
        $variance = 0;
        foreach ($gaps as $gap) {
            $variance += pow($gap - $meangap, 2);
        }
        $stddev = sqrt($variance / count($gaps));
        $cv = $stddev / $meangap;

        // Convert CV to a 0-100 consistency score (inverse relationship).
        // CV of 0 = perfect consistency (100), CV of 2+ = very inconsistent (0).
        $score = max(0, min(100, round((1 - ($cv / 2)) * 100)));

        // Determine rating.
        $action = '';
        if ($score >= 70) {
            $rating = 'excellent';
            $message = get_string('widget_consistency_excellent', 'gradereport_coifish');
        } else if ($score >= 40) {
            $rating = 'good';
            $message = get_string('widget_consistency_good', 'gradereport_coifish');
        } else {
            $rating = 'needswork';
            $message = get_string('widget_consistency_needswork', 'gradereport_coifish');
            $action = get_string('widget_consistency_action_needswork', 'gradereport_coifish');
        }

        // Build a mini timeline of gaps for visual display (last 8 gaps max).
        $displaygaps = array_slice($gaps, -8);
        $maxgap = max($displaygaps);
        $timeline = [];
        foreach ($displaygaps as $gap) {
            $timeline[] = [
                'height' => ($maxgap > 0) ? max(5, round(($gap / $maxgap) * 100)) : 50,
                'days' => round($gap, 1),
            ];
        }

        $isrisk = ($rating === 'needswork');

        return [
            'type' => 'consistency',
            'isconsistency' => true,
            'title' => get_string('widget_consistency_title', 'gradereport_coifish'),
            'score' => $score,
            'rating' => $rating,
            'isexcellent' => ($rating === 'excellent'),
            'isgood' => ($rating === 'good'),
            'isneedswork' => ($rating === 'needswork'),
            'message' => $message,
            'action' => $action,
            'hasaction' => !empty($action),
            'isrisk' => $isrisk,
            'submissioncount' => count($timestamps),
            'avggap' => round($meangap, 1),
            'timeline' => $timeline,
            'hastimeline' => !empty($timeline),
        ];
    }

    /**
     * Build the Self-Regulation widget.
     *
     * Tracks how frequently a student checks their grade report, based on
     * Macfadyen & Dawson (2012) finding that grade-checking frequency has
     * a strong correlation (r=.93) with final grade. Students who monitor
     * their own progress demonstrate self-regulated learning behaviour.
     *
     * @return array|null Widget data or null if insufficient data.
     */
    public function build_widget_selfregulation(): ?array {
        global $DB;

        $userid = $this->userid;
        $component = 'gradereport_coifish';

        // Get course start date for calculating weeks enrolled.
        $course = get_course($this->courseid);
        $coursestart = (int)$course->startdate;
        $now = $this->effective_now();
        $weeksenrolled = max(1, ceil(($now - $coursestart) / (7 * 86400)));

        // Need at least 2 weeks of enrolment for meaningful data.
        if ($weeksenrolled < 2) {
            return null;
        }

        // Indicator 1: Progress monitoring (grade report views).
        // Weight: 40% — strongest predictor (Macfadyen & Dawson, r=.93).
        $gradeviews = (int)$DB->count_records_sql(
            "SELECT COUNT(l.id)
               FROM {logstore_standard_log} l
              WHERE l.courseid = :courseid
                AND l.userid = :userid
                AND l.component LIKE 'gradereport_%'
                AND l.action = 'viewed'",
            ['courseid' => $this->courseid, 'userid' => $userid]
        );
        $gradeviewspw = round($gradeviews / $weeksenrolled, 2);
        // Score: 0-100 based on views/week. 2+/wk = 100, 0 = 0.
        $monitoringscore = min(100, round(($gradeviewspw / 2.0) * 100));

        // Indicator 2: Feedback utilisation.
        // Weight: 25% — reviewing graded feedback shows reflective behaviour.
        $gradedcount = (int)$DB->count_records_sql(
            "SELECT COUNT(ag.id)
               FROM {assign_grades} ag
               JOIN {assign} a ON a.id = ag.assignment
              WHERE a.course = :courseid AND ag.userid = :userid AND ag.grade >= 0",
            ['courseid' => $this->courseid, 'userid' => $userid]
        );
        [$evsql, $evparams] = self::get_feedback_view_event_sql('fv1');
        $feedbackviewed = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT l.contextinstanceid)
               FROM {logstore_standard_log} l
              WHERE l.userid = :userid AND l.courseid = :courseid
                AND l.eventname $evsql",
            array_merge([
                'userid' => $userid,
                'courseid' => $this->courseid,
            ], $evparams)
        );
        $feedbackrate = $gradedcount > 0 ? round(($feedbackviewed / $gradedcount) * 100) : 0;
        $feedbackscore = min(100, $feedbackrate);

        // Indicator 3: Resource revisiting.
        // Weight: 20% — returning to materials on multiple days = deeper processing.
        $distinctresourcedays = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT FROM_UNIXTIME(l.timecreated, '%Y-%m-%d'))
               FROM {logstore_standard_log} l
              WHERE l.courseid = :courseid AND l.userid = :userid
                AND l.action = 'viewed' AND l.target = 'course_module'
                AND l.component IN ('mod_page', 'mod_book', 'mod_resource', 'mod_url', 'mod_folder')",
            ['courseid' => $this->courseid, 'userid' => $userid]
        );
        $resourcedayspw = round($distinctresourcedays / $weeksenrolled, 2);
        // Score: 0-100. 3+ distinct days/week = 100.
        $resourcescore = min(100, round(($resourcedayspw / 3.0) * 100));

        // Indicator 4: Planning behaviour (early assignment views).
        // Weight: 15% — viewing assignment before first submission shows goal-setting.
        $assignviewsbeforesubmit = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT a.id)
               FROM {assign} a
               JOIN {logstore_standard_log} l ON l.contextinstanceid = (
                   SELECT cm.id FROM {course_modules} cm
                   JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                   WHERE cm.instance = a.id AND cm.course = :courseid1
                   LIMIT 1
               )
               JOIN {assign_submission} asub ON asub.assignment = a.id AND asub.userid = :userid2
                   AND asub.status = 'submitted' AND asub.latest = 1
              WHERE a.course = :courseid2
                AND l.userid = :userid1
                AND l.action = 'viewed'
                AND l.component = 'mod_assign'
                AND l.timecreated < asub.timemodified",
            [
                'courseid1' => $this->courseid,
                'courseid2' => $this->courseid,
                'userid1' => $userid,
                'userid2' => $userid,
            ]
        );
        $totalassignments = (int)$DB->count_records_sql(
            "SELECT COUNT(asub.id)
               FROM {assign_submission} asub
               JOIN {assign} a ON a.id = asub.assignment
              WHERE a.course = :courseid AND asub.userid = :userid
                AND asub.status = 'submitted' AND asub.latest = 1",
            ['courseid' => $this->courseid, 'userid' => $userid]
        );
        $planningrate = $totalassignments > 0 ? round(($assignviewsbeforesubmit / $totalassignments) * 100) : 0;
        $planningscore = min(100, $planningrate);

        // Composite score.
        $composite = round(
            $monitoringscore * 0.40 +
            $feedbackscore * 0.25 +
            $resourcescore * 0.20 +
            $planningscore * 0.15
        );

        // Hero metric: still show views/week as it's most recognisable.
        $viewsperweek = round($gradeviews / $weeksenrolled, 1);

        // Determine rating from composite score.
        $action = '';
        if ($composite >= 60) {
            $rating = 'strong';
            $message = get_string('widget_selfregulation_strong', $component);
        } else if ($composite >= 30) {
            $rating = 'moderate';
            $message = get_string('widget_selfregulation_moderate', $component);
            $action = get_string('widget_selfregulation_action_moderate', $component);
        } else {
            $rating = 'low';
            $message = get_string('widget_selfregulation_low', $component);
            $action = get_string('widget_selfregulation_action_low', $component);
        }

        // Get weekly grade-view distribution for sparkline (last 8 weeks).
        $startweek = max($coursestart, $now - 8 * 7 * 86400);
        $weeklyviews = $DB->get_records_sql(
            "SELECT FLOOR((l.timecreated - :starttime) / :weeklen) AS weeknum,
                    COUNT(l.id) AS cnt
               FROM {logstore_standard_log} l
              WHERE l.courseid = :courseid
                AND l.userid = :userid
                AND l.component LIKE 'gradereport_%'
                AND l.action = 'viewed'
                AND l.timecreated >= :startweek
           GROUP BY weeknum
           ORDER BY weeknum ASC",
            [
                'starttime' => $startweek,
                'weeklen' => 7 * 86400,
                'courseid' => $this->courseid,
                'userid' => $userid,
                'startweek' => $startweek,
            ]
        );

        $weeks = min(8, $weeksenrolled);
        $sparkline = [];
        $weekmap = [];
        foreach ($weeklyviews as $wv) {
            $weekmap[(int)$wv->weeknum] = (int)$wv->cnt;
        }
        $maxweekviews = max(1, !empty($weekmap) ? max($weekmap) : 1);
        for ($w = 0; $w < $weeks; $w++) {
            $cnt = $weekmap[$w] ?? 0;
            $sparkline[] = [
                'height' => max(5, round(($cnt / $maxweekviews) * 100)),
                'views' => $cnt,
            ];
        }

        $isrisk = ($rating === 'low');

        return [
            'type' => 'selfregulation',
            'isselfregulation' => true,
            'title' => get_string('widget_selfregulation_title', $component),
            'totalviews' => $gradeviews,
            'viewsperweek' => $viewsperweek,
            'weeksenrolled' => $weeksenrolled,
            'composite' => $composite,
            'monitoringscore' => $monitoringscore,
            'feedbackscore' => $feedbackscore,
            'resourcescore' => $resourcescore,
            'planningscore' => $planningscore,
            'rating' => $rating,
            'isstrong' => ($rating === 'strong'),
            'ismoderate' => ($rating === 'moderate'),
            'islow' => ($rating === 'low'),
            'message' => $message,
            'action' => $action,
            'hasaction' => !empty($action),
            'isrisk' => $isrisk,
            'sparkline' => $sparkline,
            'hassparkline' => !empty($sparkline),
        ];
    }

    /**
     * Build the Early Bird widget.
     *
     * Compares submission timestamps against assignment due dates to show
     * how far ahead of (or after) deadlines the student typically submits.
     *
     * @return array|null Widget data or null if no submissions with due dates.
     */
    protected function build_widget_earlybird(): ?array {
        global $DB;

        $userid = $this->userid;

        // Get submissions paired with their assignment due dates.
        $records = $DB->get_records_sql(
            "SELECT asub.id,
                    asub.timemodified AS submittime,
                    a.duedate,
                    a.name
               FROM {assign_submission} asub
               JOIN {assign} a ON a.id = asub.assignment
              WHERE a.course = :courseid
                AND asub.userid = :userid
                AND asub.status = 'submitted'
                AND asub.latest = 1
                AND a.duedate > 0
           ORDER BY a.duedate ASC",
            ['courseid' => $this->courseid, 'userid' => $userid]
        );

        if (empty($records)) {
            return null;
        }

        $items = [];
        $totaldelta = 0;
        $earlycount = 0;
        $latecount = 0;

        foreach ($records as $rec) {
            $delta = (int)$rec->duedate - (int)$rec->submittime;
            $totaldelta += $delta;
            $isearly = ($delta > 0);
            if ($isearly) {
                $earlycount++;
            } else {
                $latecount++;
            }

            $items[] = [
                'name' => format_string($rec->name),
                'delta' => $delta,
                'deltahuman' => format_time(abs($delta)),
                'isearly' => $isearly,
            ];
        }

        $count = count($records);
        $avgdelta = $totaldelta / $count;
        $avgisearly = ($avgdelta > 0);
        $avgdeltahuman = format_time(abs((int)$avgdelta));
        $avgdeltadays = abs($avgdelta) / 86400;

        // Thresholds based on average time before deadline.
        // 2+ days early = ahead, 12h-2d early = on track, 0-12h = cutting it close, late = behind.
        $action = '';
        if ($avgisearly && $avgdeltadays >= 2) {
            $rating = 'ahead';
            $message = get_string('widget_earlybird_ahead', 'gradereport_coifish');
        } else if ($avgisearly && $avgdeltadays >= 0.5) {
            $rating = 'ontrack';
            $message = get_string('widget_earlybird_ontrack', 'gradereport_coifish');
        } else if ($avgisearly) {
            $rating = 'close';
            $message = get_string('widget_earlybird_close', 'gradereport_coifish');
            $action = get_string('widget_earlybird_action_close', 'gradereport_coifish');
        } else {
            $rating = 'behind';
            $message = get_string('widget_earlybird_behind', 'gradereport_coifish');
            $action = get_string('widget_earlybird_action_behind', 'gradereport_coifish');
        }

        $avglabel = $avgisearly
            ? get_string('widget_earlybird_avg_early', 'gradereport_coifish', $avgdeltahuman)
            : get_string('widget_earlybird_avg_late', 'gradereport_coifish', $avgdeltahuman);

        $isrisk = ($rating === 'close' || $rating === 'behind');

        return [
            'type' => 'earlybird',
            'isearlybird' => true,
            'title' => get_string('widget_earlybird_title', 'gradereport_coifish'),
            'earlycount' => $earlycount,
            'latecount' => $latecount,
            'totalcount' => $count,
            'avgdeltahuman' => $avgdeltahuman,
            'avgisearly' => $avgisearly,
            'avglabel' => $avglabel,
            'rating' => $rating,
            'isahead' => ($rating === 'ahead'),
            'isontrack' => ($rating === 'ontrack'),
            'isclose' => ($rating === 'close'),
            'isbehind' => ($rating === 'behind'),
            'message' => $message,
            'action' => $action,
            'hasaction' => !empty($action),
            'isrisk' => $isrisk,
            'items' => $items,
            'hasitems' => !empty($items),
        ];
    }

    /**
     * Build the feedback engagement widget.
     *
     * Compares graded assignments that have teacher feedback against
     * feedback_viewed events in the log store to show how much feedback
     * the student has reviewed.
     *
     * @return array|null Widget data or null if no feedback is available.
     */
    public function build_widget_feedback(): ?array {
        global $DB;

        $userid = $this->userid;

        // Get all assign grade items in this course.
        $assignitems = $DB->get_records_sql(
            "SELECT gi.id, gi.iteminstance
               FROM {grade_items} gi
              WHERE gi.courseid = :courseid
                AND gi.itemtype = 'mod'
                AND gi.itemmodule = 'assign'
                AND gi.hidden = 0",
            ['courseid' => $this->courseid]
        );

        if (empty($assignitems)) {
            return null;
        }

        $assignids = array_column(array_values($assignitems), 'iteminstance');
        [$insql, $inparams] = $DB->get_in_or_equal($assignids, SQL_PARAMS_NAMED, 'assign');

        // Get graded assignments that have feedback comments.
        $inparams['userid'] = $userid;
        $feedbackrecords = $DB->get_records_sql(
            "SELECT ag.id, ag.assignment, ag.timemodified
               FROM {assign_grades} ag
              WHERE ag.assignment $insql
                AND ag.userid = :userid
                AND ag.grade >= 0",
            $inparams
        );

        if (empty($feedbackrecords)) {
            return null;
        }

        // Check which of those have actual feedback comments.
        $gradeids = array_column(array_values($feedbackrecords), 'id');
        [$ginsql, $ginparams] = $DB->get_in_or_equal($gradeids, SQL_PARAMS_NAMED, 'grade');
        $withfeedback = $DB->get_records_sql(
            "SELECT apc.grade, apc.commenttext
               FROM {assignfeedback_comments} apc
              WHERE apc.grade $ginsql
                AND apc.commenttext IS NOT NULL
                AND apc.commenttext != ''",
            $ginparams
        );

        // Map back to assignment IDs that have feedback.
        $feedbackbygrade = [];
        foreach ($feedbackrecords as $rec) {
            $feedbackbygrade[$rec->id] = $rec->assignment;
        }
        $assignmentswithfeedback = [];
        foreach ($withfeedback as $fb) {
            if (isset($feedbackbygrade[$fb->grade])) {
                $assignmentswithfeedback[$feedbackbygrade[$fb->grade]] = true;
            }
        }

        // Also count assignments where grading itself is the feedback (no comments plugin).
        // If no assignments have explicit comments, treat all graded assignments as having feedback.
        if (empty($assignmentswithfeedback)) {
            foreach ($feedbackrecords as $rec) {
                $assignmentswithfeedback[$rec->assignment] = true;
            }
        }

        $totalwithfeedback = count($assignmentswithfeedback);
        if ($totalwithfeedback === 0) {
            return null;
        }

        // Query the log store for feedback_viewed events.
        $viewedevents = $DB->get_records_sql(
            "SELECT DISTINCT " . $DB->sql_concat('l.objectid', "'_'", 'l.contextinstanceid') . " AS uid,
                    l.objectid, l.contextinstanceid
               FROM {logstore_standard_log} l
              WHERE l.userid = :userid
                AND l.courseid = :courseid
                AND l.eventname = :eventname",
            [
                'userid' => $userid,
                'courseid' => $this->courseid,
                'eventname' => '\\mod_assign\\event\\feedback_viewed',
            ]
        );

        // Also check submission_grading_table_viewed as an alternative feedback view signal.
        $gradingviews = $DB->get_records_sql(
            "SELECT DISTINCT l.contextinstanceid
               FROM {logstore_standard_log} l
              WHERE l.userid = :userid
                AND l.courseid = :courseid
                AND l.eventname = :eventname",
            [
                'userid' => $userid,
                'courseid' => $this->courseid,
                'eventname' => '\\mod_assign\\event\\submission_status_viewed',
            ]
        );

        // Get the course module IDs for our assignments.
        $cmids = $DB->get_records_sql(
            "SELECT cm.instance, cm.id
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
              WHERE cm.course = :courseid",
            ['courseid' => $this->courseid]
        );

        // Count viewed feedback.
        $viewedset = [];
        foreach ($viewedevents as $ev) {
            $viewedset[$ev->contextinstanceid] = true;
        }
        foreach ($gradingviews as $ev) {
            $viewedset[$ev->contextinstanceid] = true;
        }

        // Build per-assignment checklist with view status and links.
        $assigns = $DB->get_records_list('assign', 'id', array_keys($assignmentswithfeedback), '', 'id, name');
        $items = [];
        $viewedcount = 0;
        foreach ($assignmentswithfeedback as $assignid => $unused) {
            $viewed = isset($cmids[$assignid]) && isset($viewedset[$cmids[$assignid]->id]);
            if ($viewed) {
                $viewedcount++;
            }
            $assignname = isset($assigns[$assignid]) ? format_string($assigns[$assignid]->name) : 'Assignment';
            $cmid = isset($cmids[$assignid]) ? $cmids[$assignid]->id : 0;
            $url = $cmid ? (new \moodle_url('/mod/assign/view.php', ['id' => $cmid]))->out(false) : '';
            $items[] = [
                'name' => $assignname,
                'viewed' => $viewed,
                'url' => $url,
            ];
        }

        $percent = round(($viewedcount / $totalwithfeedback) * 100);

        // Build motivational message.
        $remaining = $totalwithfeedback - $viewedcount;
        $action = '';
        if ($remaining === 0) {
            $message = get_string('widget_feedback_all_viewed', 'gradereport_coifish');
        } else if ($viewedcount === 0) {
            $message = get_string('widget_feedback_none_viewed', 'gradereport_coifish');
            $action = get_string('widget_feedback_action', 'gradereport_coifish');
        } else {
            $message = get_string(
                'widget_feedback_some_viewed',
                'gradereport_coifish',
                (object)['remaining' => $remaining]
            );
            $action = get_string('widget_feedback_action', 'gradereport_coifish');
        }

        $isrisk = ($percent < 50);

        return [
            'type' => 'feedback',
            'isfeedback' => true,
            'title' => get_string('widget_feedback_title', 'gradereport_coifish'),
            'viewed' => $viewedcount,
            'total' => $totalwithfeedback,
            'percent' => $percent,
            'message' => $message,
            'action' => $action,
            'hasaction' => !empty($action),
            'isrisk' => $isrisk,
            'allviewed' => ($remaining === 0),
            'items' => $items,
            'hasitems' => !empty($items),
        ];
    }

    /**
     * Build Community of Inquiry (COI) widget data.
     *
     * Returns an array with enabled COI widgets in their own section,
     * parallel to get_gamification_data(). Each widget measures a
     * dimension of the COI framework: social, cognitive, or teaching presence.
     *
     * @param bool $preview Whether this is a teacher preview.
     * @return array COI data with 'widgets' array and 'haswidgets' bool.
     */
    public function get_coi_data(bool $preview = false): array {
        $coursesettings = $this->get_course_gamification_settings();
        $courseenabled = !empty($coursesettings['gamification_enabled']);

        if (!$courseenabled && !$preview) {
            return ['widgets' => [], 'haswidgets' => false];
        }

        $widgets = [];

        $isteacherview = $preview;

        // Social presence: Community engagement.
        if ($this->is_widget_enabled('coi_community', $coursesettings)) {
            $widget = $this->build_widget_coi_community($isteacherview);
            if ($widget) {
                $widgets[] = $widget;
            }
        }

        // Social presence: Peer connection.
        if ($this->is_widget_enabled('coi_peerconnection', $coursesettings)) {
            $widget = $this->build_widget_coi_peerconnection($isteacherview);
            if ($widget) {
                $widgets[] = $widget;
            }
        }

        // Cognitive presence: Course engagement.
        if ($this->is_widget_enabled('coi_learningdepth', $coursesettings)) {
            $widget = $this->build_widget_coi_learningdepth($isteacherview);
            if ($widget) {
                $widgets[] = $widget;
            }
        }

        // Teaching presence: Feedback loop.
        if ($this->is_widget_enabled('coi_feedbackloop', $coursesettings)) {
            $widget = $this->build_widget_coi_feedbackloop($isteacherview);
            if ($widget) {
                $widgets[] = $widget;
            }
        }

        return [
            'widgets' => $widgets,
            'haswidgets' => !empty($widgets),
        ];
    }

    /**
     * Build the Community Engagement widget (Social Presence).
     *
     * Counts forum posts, forum discussions started, and collaborative
     * activity contributions (glossary, wiki, database). Uses participation
     * rate (threads engaged / total threads) for level thresholds so the
     * widget scales with course size.
     *
     * @param bool $isteacherview Whether the viewer is a teacher.
     * @return array|null Widget data or null if no collaborative activities exist.
     */
    protected function build_widget_coi_community(bool $isteacherview = false): ?array {
        global $DB;

        $userid = $this->userid;

        // Count forum posts (not the initial discussion post).
        $forumposts = (int)$DB->count_records_sql(
            "SELECT COUNT(fp.id)
               FROM {forum_posts} fp
               JOIN {forum_discussions} fd ON fd.id = fp.discussion
              WHERE fd.course = :courseid
                AND fp.userid = :userid
                AND fp.parent != 0",
            ['courseid' => $this->courseid, 'userid' => $userid]
        );

        // Count forum discussions started.
        $forumdiscussions = (int)$DB->count_records_sql(
            "SELECT COUNT(fd.id)
               FROM {forum_discussions} fd
              WHERE fd.course = :courseid
                AND fd.userid = :userid",
            ['courseid' => $this->courseid, 'userid' => $userid]
        );

        // Count glossary entries.
        $glossaryentries = 0;
        if ($DB->get_manager()->table_exists('glossary_entries')) {
            $glossaryentries = (int)$DB->count_records_sql(
                "SELECT COUNT(ge.id)
                   FROM {glossary_entries} ge
                   JOIN {glossary} g ON g.id = ge.glossaryid
                  WHERE g.course = :courseid
                    AND ge.userid = :userid
                    AND ge.approved = 1",
                ['courseid' => $this->courseid, 'userid' => $userid]
            );
        }

        // Count wiki page edits.
        $wikiedits = 0;
        if ($DB->get_manager()->table_exists('wiki_pages')) {
            $wikiedits = (int)$DB->count_records_sql(
                "SELECT COUNT(DISTINCT wp.id)
                   FROM {wiki_pages} wp
                   JOIN {wiki_subwikis} ws ON ws.id = wp.subwikiid
                   JOIN {wiki} w ON w.id = ws.wikiid
                  WHERE w.course = :courseid
                    AND wp.userid = :userid",
                ['courseid' => $this->courseid, 'userid' => $userid]
            );
        }

        $total = $forumposts + $forumdiscussions + $glossaryentries + $wikiedits;

        // Build breakdown for display.
        $breakdown = [];
        if ($forumdiscussions > 0) {
            $breakdown[] = ['label' => get_string('discussions', 'forum'), 'count' => $forumdiscussions];
        }
        if ($forumposts > 0) {
            $breakdown[] = ['label' => get_string('replies', 'forum'), 'count' => $forumposts];
        }
        if ($glossaryentries > 0) {
            $breakdown[] = ['label' => get_string('entries', 'glossary'), 'count' => $glossaryentries];
        }
        if ($wikiedits > 0) {
            $breakdown[] = ['label' => get_string('pages', 'wiki'), 'count' => $wikiedits];
        }

        // Group-aware participation rate.
        $alldiscussions = $DB->get_records_sql(
            "SELECT fd.id, fd.groupid, cm.groupmode
               FROM {forum_discussions} fd
               JOIN {forum} f ON f.id = fd.forum
               JOIN {course_modules} cm ON cm.instance = f.id AND cm.course = :courseid
               JOIN {modules} m ON m.id = cm.module AND m.name = 'forum'
              WHERE fd.course = :courseid2",
            ['courseid' => $this->courseid, 'courseid2' => $this->courseid]
        );
        $usergroups = groups_get_user_groups($this->courseid, $userid);
        $mygroupids = $usergroups[0] ?? [];
        $visiblediscussions = 0;
        foreach ($alldiscussions as $disc) {
            if ((int)$disc->groupmode === SEPARATEGROUPS) {
                if ((int)$disc->groupid === -1 || in_array((int)$disc->groupid, $mygroupids)) {
                    $visiblediscussions++;
                }
            } else {
                $visiblediscussions++;
            }
        }
        $totaldiscussions = count($alldiscussions);
        $threadsparticipated = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT fd.id)
               FROM {forum_posts} fp
               JOIN {forum_discussions} fd ON fd.id = fp.discussion
              WHERE fd.course = :courseid AND fp.userid = :userid",
            ['courseid' => $this->courseid, 'userid' => $userid]
        );
        // Breadth against visible discussions, not all discussions.
        $breadth = $visiblediscussions > 0
            ? min(100, round(($threadsparticipated / $visiblediscussions) * 200))
            : ($total > 0 ? 50 : 0);
        // Volume relative to a reasonable benchmark (5 posts = 100%).
        $volume = min(100, round($total / 5 * 100));
        $participationrate = round($breadth * 0.6 + $volume * 0.4);

        $level = $this->get_coi_level($participationrate, $this->get_coi_thresholds('sp', [1, 20, 50, 80]));

        // Recency: when was the student last active in discussions?
        $lastactive = $DB->get_field_sql(
            "SELECT MAX(fp.created)
               FROM {forum_posts} fp
               JOIN {forum_discussions} fd ON fd.id = fp.discussion
              WHERE fd.course = :courseid AND fp.userid = :userid",
            ['courseid' => $this->courseid, 'userid' => $userid]
        );
        $daysinactive = $lastactive ? round(($this->effective_now() - (int)$lastactive) / 86400) : null;
        $isstale = ($total > 0 && $daysinactive !== null && $daysinactive >= $this->get_stale_days());

        $isrisk = ($level['level'] <= 1) || $isstale;

        // Graduated, context-aware actions.
        $action = $this->get_coi_widget_action('community', $level['level'], $isteacherview, $isstale, $daysinactive);

        return [
            'type' => 'coi_community',
            'iscoicommunity' => true,
            'title' => get_string('widget_coi_community_title', 'gradereport_coifish'),
            'total' => $total,
            'breakdown' => $breakdown,
            'hasbreakdown' => !empty($breakdown),
            'level' => $level,
            'action' => $action,
            'hasaction' => !empty($action),
            'isrisk' => $isrisk,
            'isstale' => $isstale,
            'daysinactive' => $daysinactive,
        ];
    }

    /**
     * Build the Peer Connection widget (Social Presence).
     *
     * Counts replies to other people's forum posts, workshop peer
     * assessments completed, and database activity contributions.
     * Uses participation rate for level thresholds.
     *
     * @param bool $isteacherview Whether the viewer is a teacher.
     * @return array|null Widget data or null.
     */
    protected function build_widget_coi_peerconnection(bool $isteacherview = false): ?array {
        global $DB;

        $userid = $this->userid;

        // Count forum replies to OTHER users' posts.
        $peerreplies = (int)$DB->count_records_sql(
            "SELECT COUNT(fp.id)
               FROM {forum_posts} fp
               JOIN {forum_posts} parent ON parent.id = fp.parent
               JOIN {forum_discussions} fd ON fd.id = fp.discussion
              WHERE fd.course = :courseid
                AND fp.userid = :userid
                AND parent.userid != :userid2
                AND fp.parent != 0",
            ['courseid' => $this->courseid, 'userid' => $userid, 'userid2' => $userid]
        );

        // Count workshop peer assessments.
        $peerassessments = 0;
        if ($DB->get_manager()->table_exists('workshop_assessments')) {
            $peerassessments = (int)$DB->count_records_sql(
                "SELECT COUNT(wa.id)
                   FROM {workshop_assessments} wa
                   JOIN {workshop_submissions} ws ON ws.id = wa.submissionid
                   JOIN {workshop} w ON w.id = ws.workshopid
                  WHERE w.course = :courseid
                    AND wa.reviewerid = :userid
                    AND wa.grade IS NOT NULL",
                ['courseid' => $this->courseid, 'userid' => $userid]
            );
        }

        // Count database activity records.
        $datarecords = 0;
        if ($DB->get_manager()->table_exists('data_records')) {
            $datarecords = (int)$DB->count_records_sql(
                "SELECT COUNT(dr.id)
                   FROM {data_records} dr
                   JOIN {data} d ON d.id = dr.dataid
                  WHERE d.course = :courseid
                    AND dr.userid = :userid
                    AND dr.approved = 1",
                ['courseid' => $this->courseid, 'userid' => $userid]
            );
        }

        $total = $peerreplies + $peerassessments + $datarecords;

        $breakdown = [];
        if ($peerreplies > 0) {
            $breakdown[] = ['label' => get_string('replies', 'forum'), 'count' => $peerreplies];
        }
        if ($peerassessments > 0) {
            $breakdown[] = ['label' => get_string('assessments', 'workshop'), 'count' => $peerassessments];
        }
        if ($datarecords > 0) {
            $breakdown[] = ['label' => get_string('entries', 'data'), 'count' => $datarecords];
        }

        // Group-aware peer connection: count distinct peers interacted with.
        // Get student's groups for filtering.
        $usergroups = groups_get_user_groups($this->courseid, $userid);
        $mygroupids = $usergroups[0] ?? [];

        // Peers replied to in forums.
        $peersrepliedto = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT parent.userid)
               FROM {forum_posts} fp
               JOIN {forum_posts} parent ON parent.id = fp.parent
               JOIN {forum_discussions} fd ON fd.id = fp.discussion
              WHERE fd.course = :courseid
                AND fp.userid = :userid
                AND parent.userid != :userid2
                AND fp.parent != 0",
            ['courseid' => $this->courseid, 'userid' => $userid, 'userid2' => $userid]
        );

        // Peers who replied to this student's posts.
        $peersreplying = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT fp.userid)
               FROM {forum_posts} fp
               JOIN {forum_posts} parent ON parent.id = fp.parent
               JOIN {forum_discussions} fd ON fd.id = fp.discussion
              WHERE fd.course = :courseid
                AND parent.userid = :userid
                AND fp.userid != :userid2
                AND fp.parent != 0",
            ['courseid' => $this->courseid, 'userid' => $userid, 'userid2' => $userid]
        );

        // Unique peers = union of both directions.
        $peersengaged = max($peersrepliedto, $peersreplying);

        // Active peers: group-aware count of students who could be interacted with.
        if (!empty($mygroupids)) {
            // In separate groups, count only group members.
            [$grpinsql, $grpparams] = $DB->get_in_or_equal($mygroupids, SQL_PARAMS_NAMED, 'grp');
            $activeposters = (int)$DB->count_records_sql(
                "SELECT COUNT(DISTINCT gm.userid)
                   FROM {groups_members} gm
                  WHERE gm.groupid $grpinsql
                    AND gm.userid != :userid",
                array_merge($grpparams, ['userid' => $userid])
            );
        } else {
            // No groups: count all enrolled students except self.
            $activeposters = (int)$DB->count_records_sql(
                "SELECT COUNT(DISTINCT fp.userid)
                   FROM {forum_posts} fp
                   JOIN {forum_discussions} fd ON fd.id = fp.discussion
                  WHERE fd.course = :courseid
                    AND fp.userid != :userid",
                ['courseid' => $this->courseid, 'userid' => $userid]
            );
        }

        // Peer interaction rate: how many available peers has this student connected with.
        $peerrate = $activeposters > 0
            ? round(($peersengaged / $activeposters) * 100)
            : ($total > 0 ? 50 : 0);

        $level = $this->get_coi_level($peerrate, $this->get_coi_thresholds('peer', [1, 15, 40, 70]));

        // Recency check.
        $lastactive = $DB->get_field_sql(
            "SELECT MAX(fp.created)
               FROM {forum_posts} fp
               JOIN {forum_posts} parent ON parent.id = fp.parent
               JOIN {forum_discussions} fd ON fd.id = fp.discussion
              WHERE fd.course = :courseid
                AND fp.userid = :userid
                AND parent.userid != :userid2
                AND fp.parent != 0",
            ['courseid' => $this->courseid, 'userid' => $userid, 'userid2' => $userid]
        );
        $daysinactive = $lastactive ? round(($this->effective_now() - (int)$lastactive) / 86400) : null;
        $isstale = ($total > 0 && $daysinactive !== null && $daysinactive >= $this->get_stale_days());

        $isrisk = ($level['level'] <= 1) || $isstale;

        $action = $this->get_coi_widget_action('peerconnection', $level['level'], $isteacherview, $isstale, $daysinactive);

        return [
            'type' => 'coi_peerconnection',
            'iscoipeerconnection' => true,
            'title' => get_string('widget_coi_peerconnection_title', 'gradereport_coifish'),
            'total' => $total,
            'breakdown' => $breakdown,
            'hasbreakdown' => !empty($breakdown),
            'level' => $level,
            'action' => $action,
            'hasaction' => !empty($action),
            'isrisk' => $isrisk,
            'isstale' => $isstale,
            'daysinactive' => $daysinactive,
        ];
    }

    /**
     * Build the Course Engagement widget (Cognitive Presence).
     *
     * Measures engagement breadth through assignment submissions, quiz attempts,
     * feedback viewing, and resource views. Uses engagement rate (activities
     * touched / total activities available) for level thresholds.
     *
     * @param bool $isteacherview Whether the viewer is a teacher.
     * @return array|null Widget data or null.
     */
    protected function build_widget_coi_learningdepth(bool $isteacherview = false): ?array {
        global $DB;

        $userid = $this->userid;

        // Count graded assignments (submissions that have been marked).
        $assignsubmissions = (int)$DB->count_records_sql(
            "SELECT COUNT(ag.id)
               FROM {assign_grades} ag
               JOIN {assign} a ON a.id = ag.assignment
              WHERE a.course = :courseid
                AND ag.userid = :userid
                AND ag.grade >= 0",
            ['courseid' => $this->courseid, 'userid' => $userid]
        );

        // Count quiz attempts.
        $quizattempts = (int)$DB->count_records_sql(
            "SELECT COUNT(qa.id)
               FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
              WHERE q.course = :courseid
                AND qa.userid = :userid
                AND qa.state IN ('finished', 'abandoned')",
            ['courseid' => $this->courseid, 'userid' => $userid]
        );

        // Count feedback viewed events.
        $feedbackviews = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT l.contextinstanceid)
               FROM {logstore_standard_log} l
              WHERE l.userid = :userid
                AND l.courseid = :courseid
                AND l.eventname = :eventname",
            [
                'userid' => $userid,
                'courseid' => $this->courseid,
                'eventname' => '\\mod_assign\\event\\feedback_viewed',
            ]
        );

        // Count distinct resource views (page, book, resource, url, folder).
        $resourceviews = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT l.contextinstanceid)
               FROM {logstore_standard_log} l
              WHERE l.userid = :userid
                AND l.courseid = :courseid
                AND l.action = 'viewed'
                AND l.target = 'course_module'
                AND l.component IN ('mod_page', 'mod_book', 'mod_resource', 'mod_url', 'mod_folder')",
            ['userid' => $userid, 'courseid' => $this->courseid]
        );

        $total = $assignsubmissions + $quizattempts + $feedbackviews + $resourceviews;

        $breakdown = [];
        if ($assignsubmissions > 0) {
            $breakdown[] = ['label' => get_string('submissions', 'assign'), 'count' => $assignsubmissions];
        }
        if ($quizattempts > 0) {
            $breakdown[] = ['label' => get_string('attempts', 'quiz'), 'count' => $quizattempts];
        }
        if ($feedbackviews > 0) {
            $breakdown[] = ['label' => get_string('feedback'), 'count' => $feedbackviews];
        }
        if ($resourceviews > 0) {
            $breakdown[] = ['label' => get_string('resources'), 'count' => $resourceviews];
        }

        // Relative thresholds: count total course activities and calculate engagement rate.
        $totalactivities = (int)$DB->count_records_sql(
            "SELECT COUNT(cm.id)
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid
                AND cm.deletioninprogress = 0
                AND m.name IN ('assign', 'quiz', 'page', 'book', 'resource', 'url', 'folder')",
            ['courseid' => $this->courseid]
        );
        $activitiesengaged = $assignsubmissions + $quizattempts + $resourceviews;
        $engagementrate = $totalactivities > 0
            ? round(($activitiesengaged / $totalactivities) * 100)
            : ($total > 0 ? 50 : 0);

        $level = $this->get_coi_level($engagementrate, $this->get_coi_thresholds('cp', [1, 20, 50, 80]));

        // Recency: most recent submission or resource view.
        $lastsubmission = $DB->get_field_sql(
            "SELECT MAX(asub.timemodified)
               FROM {assign_submission} asub
               JOIN {assign} a ON a.id = asub.assignment
              WHERE a.course = :courseid AND asub.userid = :userid AND asub.status = 'submitted'",
            ['courseid' => $this->courseid, 'userid' => $userid]
        );
        $lastquiz = $DB->get_field_sql(
            "SELECT MAX(qa.timefinish)
               FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
              WHERE q.course = :courseid AND qa.userid = :userid AND qa.state IN ('finished', 'abandoned')",
            ['courseid' => $this->courseid, 'userid' => $userid]
        );
        $lastactive = max((int)$lastsubmission, (int)$lastquiz);
        $daysinactive = $lastactive > 0 ? round(($this->effective_now() - $lastactive) / 86400) : null;
        $isstale = ($total > 0 && $daysinactive !== null && $daysinactive >= $this->get_stale_days());

        $isrisk = ($level['level'] <= 1) || $isstale;

        $action = $this->get_coi_widget_action('learningdepth', $level['level'], $isteacherview, $isstale, $daysinactive);

        return [
            'type' => 'coi_learningdepth',
            'iscoilearningdepth' => true,
            'title' => get_string('widget_coi_learningdepth_title', 'gradereport_coifish'),
            'total' => $total,
            'breakdown' => $breakdown,
            'hasbreakdown' => !empty($breakdown),
            'level' => $level,
            'action' => $action,
            'hasaction' => !empty($action),
            'isrisk' => $isrisk,
            'isstale' => $isstale,
            'daysinactive' => $daysinactive,
        ];
    }

    /**
     * Build the Feedback Loop widget (Teaching Presence).
     *
     * Measures how actively the student engages with teacher feedback:
     * feedback viewed, submission status page visits, and grade report views.
     * Already uses percentage-based thresholds (inherently relative).
     *
     * @param bool $isteacherview Whether the viewer is a teacher.
     * @return array|null Widget data or null.
     */
    protected function build_widget_coi_feedbackloop(bool $isteacherview = false): ?array {
        global $DB;

        $userid = $this->userid;

        // Get total graded assignments with feedback.
        $totalfeedback = (int)$DB->count_records_sql(
            "SELECT COUNT(ag.id)
               FROM {assign_grades} ag
               JOIN {assign} a ON a.id = ag.assignment
              WHERE a.course = :courseid
                AND ag.userid = :userid
                AND ag.grade >= 0",
            ['courseid' => $this->courseid, 'userid' => $userid]
        );

        if ($totalfeedback === 0) {
            $level = $this->get_coi_level(0, $this->get_coi_thresholds('tp', [1, 25, 75, 100]));
            $action = get_string('widget_coi_feedbackloop_action_none', 'gradereport_coifish');
            return [
                'type' => 'coi_feedbackloop',
                'iscoifeedbackloop' => true,
                'title' => get_string('widget_coi_feedbackloop_title', 'gradereport_coifish'),
                'total' => 0,
                'viewed' => 0,
                'percent' => 0,
                'breakdown' => [],
                'hasbreakdown' => false,
                'level' => $level,
                'action' => $action,
                'hasaction' => true,
                'isrisk' => true,
                'isstale' => false,
                'daysinactive' => null,
            ];
        }

        // Count distinct feedback view events.
        [$evsql, $evparams] = self::get_feedback_view_event_sql('fv2');
        $viewedfeedback = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT l.contextinstanceid)
               FROM {logstore_standard_log} l
              WHERE l.userid = :userid
                AND l.courseid = :courseid
                AND l.eventname $evsql",
            array_merge([
                'userid' => $userid,
                'courseid' => $this->courseid,
            ], $evparams)
        );

        $percent = ($totalfeedback > 0) ? round(($viewedfeedback / $totalfeedback) * 100) : 0;
        $percent = min(100, $percent);

        $level = $this->get_coi_level($percent, $this->get_coi_thresholds('tp', [1, 25, 75, 100]));
        $isrisk = ($level['level'] <= 1);
        $unreviewed = $totalfeedback - min($viewedfeedback, $totalfeedback);

        $action = $this->get_coi_widget_action('feedbackloop', $level['level'], $isteacherview, false, null, $unreviewed);

        return [
            'type' => 'coi_feedbackloop',
            'iscoifeedbackloop' => true,
            'title' => get_string('widget_coi_feedbackloop_title', 'gradereport_coifish'),
            'total' => $totalfeedback,
            'viewed' => $viewedfeedback,
            'percent' => $percent,
            'breakdown' => [],
            'hasbreakdown' => false,
            'level' => $level,
            'action' => $action,
            'hasaction' => !empty($action),
            'isrisk' => $isrisk,
            'isstale' => false,
            'daysinactive' => null,
        ];
    }

    /**
     * Get a context-aware action message for a COI widget.
     *
     * Returns graduated guidance based on the student's engagement level.
     * Teacher view gets intervention-focused messages; student view gets
     * self-improvement guidance. Every level except Exemplary gets an action.
     *
     * @param string $widget Widget type key (community, peerconnection, learningdepth, feedbackloop).
     * @param int $level The student's current level (0–4).
     * @param bool $isteacherview Whether the viewer is a teacher.
     * @param bool $isstale Whether the student's activity is stale (14+ days inactive).
     * @param int|null $daysinactive Days since last activity, or null.
     * @param int $extra Extra context (e.g. unreviewed feedback count).
     * @return string The action message, or empty string for Exemplary level.
     */
    protected function get_coi_widget_action(
        string $widget,
        int $level,
        bool $isteacherview,
        bool $isstale,
        ?int $daysinactive,
        int $extra = 0
    ): string {
        $component = 'gradereport_coifish';

        // Stale warning takes priority when activity has gone cold.
        if ($isstale && $daysinactive !== null) {
            if ($isteacherview) {
                return get_string("coi_stale_teacher", $component, $daysinactive);
            }
            return get_string("coi_stale_student", $component, $daysinactive);
        }

        // Teacher view: only show actions for risk states (level 0-1).
        if ($isteacherview) {
            if ($level <= 1) {
                $key = "widget_coi_{$widget}_teacher";
                if ($widget === 'feedbackloop' && $extra > 0) {
                    return get_string($key, $component, $extra);
                }
                return get_string($key, $component);
            }
            return '';
        }

        // Student view: graduated actions per level.
        // Level 4 (Exemplary) = no action needed.
        if ($level >= 4) {
            return '';
        }

        // Map levels to action string suffixes.
        $suffixes = [
            0 => '_action_none',
            1 => '_action_emerging',
            2 => '_action_growing',
            3 => '_action_strong',
        ];

        $key = "widget_coi_{$widget}" . $suffixes[$level];
        return get_string($key, $component);
    }

    /**
     * Get a COI engagement level based on count thresholds.
     *
     * Get the pass mark threshold from plugin settings.
     *
     * @return int The pass mark percentage.
     */
    protected function get_pass_threshold(): int {
        $val = get_config('gradereport_coifish', 'threshold_pass');
        return ($val !== false && $val !== '') ? (int)$val : 50;
    }

    /**
     * Get the stale activity threshold in days from plugin settings.
     *
     * @return int Number of days.
     */
    protected function get_stale_days(): int {
        $val = get_config('gradereport_coifish', 'stale_days');
        return ($val !== false && $val !== '' && (int)$val > 0) ? (int)$val : 14;
    }

    /**
     * Parse a COI level threshold setting string into an array of 4 integers.
     *
     * @param string $key The setting key suffix (sp, cp, tp, peer).
     * @param array $default The default thresholds.
     * @return array Array of 4 integer thresholds.
     */
    protected function get_coi_thresholds(string $key, array $default): array {
        $val = get_config('gradereport_coifish', 'coi_levels_' . $key);
        if ($val === false || $val === '') {
            return $default;
        }
        $parts = array_map('intval', array_map('trim', explode(',', $val)));
        if (count($parts) !== 4) {
            return $default;
        }
        sort($parts);
        return $parts;
    }

    /**
     * Get the diagnostic sensitivity multipliers.
     *
     * Returns an array of trigger thresholds adjusted by sensitivity setting.
     * Low sensitivity uses higher thresholds (fewer triggers), high uses lower.
     *
     * @return array Associative array with 'isolation', 'engagement', 'feedback',
     *               'stale_count', 'stale_pct', 'failing' trigger percentages.
     */
    protected function get_diagnostic_triggers(): array {
        $sensitivity = get_config('gradereport_coifish', 'diagnostic_sensitivity');
        if ($sensitivity === false || $sensitivity === '') {
            $sensitivity = 'normal';
        }
        // Normal defaults: isolation 30%, engagement 30%, feedback 25%, failing 20%.
        $triggers = [
            'isolation' => 30,
            'engagement' => 30,
            'feedback' => 25,
            'stale_count' => 3,
            'stale_pct' => 20,
            'failing' => 20,
            // Missed-deadline triggers — counts at the cohort level.
            'missed_count' => 3,
            'missed_pct' => 15,
            // Frequent-extension trigger — user-level overrides per student.
            'extensions_count' => 3,
        ];
        if ($sensitivity === 'high') {
            // Lower thresholds = more sensitive.
            $triggers['isolation'] = 20;
            $triggers['engagement'] = 20;
            $triggers['feedback'] = 15;
            $triggers['stale_count'] = 2;
            $triggers['stale_pct'] = 15;
            $triggers['failing'] = 15;
            $triggers['missed_count'] = 2;
            $triggers['missed_pct'] = 10;
            $triggers['extensions_count'] = 2;
        } else if ($sensitivity === 'low') {
            // Higher thresholds = less sensitive.
            $triggers['isolation'] = 40;
            $triggers['engagement'] = 40;
            $triggers['feedback'] = 35;
            $triggers['stale_count'] = 5;
            $triggers['stale_pct'] = 30;
            $triggers['failing'] = 30;
            $triggers['missed_count'] = 5;
            $triggers['missed_pct'] = 25;
            $triggers['extensions_count'] = 5;
        }
        return $triggers;
    }

    /**
     * Detect whether COI social presence flags are a course design issue.
     *
     * Counts the social activity types available in the course (forums, wikis,
     * glossaries, workshops, databases). If the course has very few social
     * opportunities and isolation is flagged, returns a notice for curriculum
     * design teams explaining the gap is structural, not behavioural.
     *
     * @param int $totaldiscussions Total forum discussions in the course.
     * @param int $lowisolation Number of students flagged for low social presence.
     * @param int $usercount Total enrolled students.
     * @param int $isolationpct Percentage of students with low social presence.
     * @param array $triggers Diagnostic trigger thresholds.
     * @return array Course design notice data, or empty array.
     */
    protected function get_course_design_notice(
        int $totaldiscussions,
        int $lowisolation,
        int $usercount,
        int $isolationpct,
        array $triggers
    ): array {
        global $DB;

        // Only relevant when isolation is actually flagged.
        if ($isolationpct < $triggers['isolation']) {
            return [];
        }

        $component = 'gradereport_coifish';

        // Count social activity modules present in the course.
        $socialmodules = ['forum', 'wiki', 'glossary', 'workshop', 'data'];
        [$insql, $inparams] = $DB->get_in_or_equal($socialmodules, SQL_PARAMS_NAMED, 'sm');
        $socialcount = (int)$DB->count_records_sql(
            "SELECT COUNT(cm.id)
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid
                AND cm.deletioninprogress = 0
                AND m.name $insql",
            array_merge(['courseid' => $this->courseid], $inparams)
        );

        // Count distinct social module types.
        $socialtypes = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT m.name)
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid
                AND cm.deletioninprogress = 0
                AND m.name $insql",
            array_merge(['courseid' => $this->courseid], $inparams)
        );

        // Thresholds: 0 social activities = no opportunity; 1-2 = limited.
        if ($socialcount === 0) {
            $severity = 'info';
            $diagnostic = get_string('coursedesign_no_social', $component);
            $action = get_string('coursedesign_action_no_social', $component);
        } else if ($socialcount <= 2 || ($totaldiscussions === 0 && $socialcount <= 3)) {
            $severity = 'info';
            $diagnostic = get_string('coursedesign_limited_social', $component, (object)[
                'count' => $socialcount,
                'types' => $socialtypes,
                'discussions' => $totaldiscussions,
            ]);
            $action = get_string('coursedesign_action_limited_social', $component);
        } else {
            // Course has adequate social activities; isolation flags are student-level.
            return [];
        }

        return [
            'icon' => 'puzzle-piece',
            'severity' => $severity,
            'title' => get_string('coursedesign_title', $component),
            'diagnostic' => $diagnostic,
            'action' => $action,
            'socialcount' => $socialcount,
            'socialtypes' => $socialtypes,
        ];
    }

    /**
     * Classify a COI rate into a level using plugin-configured thresholds.
     *
     * Returns a structured level array with label, numeric level (0–4),
     * and CSS class suffix. Used by all COI widgets for consistent level display.
     *
     * @param int $count The current count or percentage.
     * @param array $thresholds Array of 4 thresholds [bronze, silver, gold, platinum].
     * @return array Level data with 'level', 'label', and 'class' keys.
     */
    protected function get_coi_level(int $count, array $thresholds): array {
        $levels = [
            ['level' => 0, 'label' => get_string('coi_level_none', 'gradereport_coifish'), 'class' => 'none'],
            ['level' => 1, 'label' => get_string('coi_level_emerging', 'gradereport_coifish'), 'class' => 'emerging'],
            ['level' => 2, 'label' => get_string('coi_level_developing', 'gradereport_coifish'), 'class' => 'developing'],
            ['level' => 3, 'label' => get_string('coi_level_established', 'gradereport_coifish'), 'class' => 'established'],
            ['level' => 4, 'label' => get_string('coi_level_exemplary', 'gradereport_coifish'), 'class' => 'exemplary'],
        ];

        if ($count >= $thresholds[3]) {
            return $levels[4];
        } else if ($count >= $thresholds[2]) {
            return $levels[3];
        } else if ($count >= $thresholds[1]) {
            return $levels[2];
        } else if ($count >= $thresholds[0]) {
            return $levels[1];
        }
        return $levels[0];
    }

    /**
     * Build teacher-only diagnostic and prescriptive insights for a student.
     *
     * Cross-references gamification widgets, COI data, and grade trends to
     * produce prioritised insight cards. Each card has a diagnostic (why it
     * matters) and a prescriptive action (what to do). This directly addresses
     * the gap identified in COI/LAK research: the absence of diagnostic and
     * prescriptive analytics.
     *
     * @return array Insights data with 'cards', 'hascards', 'riskcount', 'totalindicators', 'risklevel'.
     */
    public function get_insights_data(): array {
        global $DB;

        $component = 'gradereport_coifish';
        $cards = [];

        // Student name for intervention buttons. fullname() needs the full set
        // of name fields (firstnamephonetic, alternatename, etc.) to avoid a
        // debug notice — use core_user\fields to fetch the canonical list.
        $namefields = implode(', ', \core_user\fields::for_name()->get_required_fields());
        $studentuser = $DB->get_record('user', ['id' => $this->userid], 'id, ' . $namefields);
        $studentname = $studentuser ? fullname($studentuser) : '';

        // Collect all widget data for cross-referencing.
        $gamification = $this->get_gamification_data(true);
        $coi = $this->get_coi_data(true);
        $progress = $this->get_progress_data();

        // Index widgets by type for easy lookup.
        $widgets = [];
        foreach ($gamification['widgets'] ?? [] as $w) {
            $widgets[$w['type'] ?? ''] = $w;
        }
        foreach ($coi['widgets'] ?? [] as $w) {
            $widgets[$w['type'] ?? ''] = $w;
        }

        $riskcount = 0;
        $totalindicators = 0;

        // Gather student log data for detail modals.
        $userid = $this->userid;
        $courseid = $this->courseid;
        $datefmt = get_string('strftimedatetimeshort', 'langconfig');

        // Forum activity: recent discussion views and posts.
        $forumlogs = $DB->get_records_sql(
            "SELECT l.id, l.timecreated, l.action, l.target,
                    COALESCE(fd.name, l.other) AS detail
               FROM {logstore_standard_log} l
          LEFT JOIN {forum_discussions} fd ON fd.id = l.objectid AND l.target = 'discussion'
              WHERE l.userid = :userid AND l.courseid = :courseid
                AND l.component = 'mod_forum'
                AND ((l.action = 'viewed' AND l.target = 'discussion')
                  OR (l.action = 'created' AND l.target IN ('post', 'discussion')))
           ORDER BY l.timecreated DESC",
            ['userid' => $userid, 'courseid' => $courseid],
            0,
            15
        );

        // Course module views by activity type (recent).
        $modulelogs = $DB->get_records_sql(
            "SELECT l.id, l.timecreated, l.component, l.action, l.target,
                    cm.id AS cmid
               FROM {logstore_standard_log} l
          LEFT JOIN {course_modules} cm ON cm.id = l.contextinstanceid
              WHERE l.userid = :userid AND l.courseid = :courseid
                AND l.action = 'viewed' AND l.target = 'course_module'
           ORDER BY l.timecreated DESC",
            ['userid' => $userid, 'courseid' => $courseid],
            0,
            15
        );

        // Grade report views (for self-regulation).
        $gradereportlogs = $DB->get_records_sql(
            "SELECT l.id, l.timecreated, l.component, l.action
               FROM {logstore_standard_log} l
              WHERE l.userid = :userid AND l.courseid = :courseid
                AND l.component LIKE 'gradereport_%' AND l.action = 'viewed'
           ORDER BY l.timecreated DESC",
            ['userid' => $userid, 'courseid' => $courseid],
            0,
            15
        );

        // Feedback view events (per assignment, plus Unified Grader if installed).
        [$evsql, $evparams] = self::get_feedback_view_event_sql('fv3');
        $feedbacklogs = $DB->get_records_sql(
            "SELECT l.id, l.timecreated, l.objectid, l.eventname,
                    a.name AS assignname
               FROM {logstore_standard_log} l
          LEFT JOIN {assign} a ON a.id = l.objectid
              WHERE l.userid = :userid AND l.courseid = :courseid
                AND l.eventname $evsql
           ORDER BY l.timecreated DESC",
            array_merge([
                'userid' => $userid, 'courseid' => $courseid,
            ], $evparams),
            0,
            15
        );

        // Submission timestamps with due dates (for timing & consistency).
        $submissionlogs = $DB->get_records_sql(
            "SELECT asub.id, asub.timemodified AS submitted, a.duedate, a.name AS assignname
               FROM {assign_submission} asub
               JOIN {assign} a ON a.id = asub.assignment
              WHERE asub.userid = :userid AND a.course = :courseid
                AND asub.status = 'submitted' AND asub.latest = 1
           ORDER BY asub.timemodified DESC",
            ['userid' => $userid, 'courseid' => $courseid],
            0,
            15
        );

        // Build formatted log data arrays for each card type.
        $logcoldate = get_string('logcol_date', $component);
        $logcolevent = get_string('logcol_event', $component);
        $logcoldetail = get_string('logcol_detail', $component);
        $logcolassessment = get_string('logcol_assessment', $component);
        $logcolscore = get_string('logcol_score', $component);
        $logcoldue = get_string('logcol_due', $component);
        $logcolsubmitted = get_string('logcol_submitted', $component);
        $logcoloffset = get_string('logcol_offset', $component);
        $logcolstatus = get_string('logcol_status', $component);
        $logcolcomponent = get_string('logcol_component', $component);

        // Trend & Streak: recent graded items.
        $itemscores = $this->get_student_item_scores();
        $trendlogdata = [];
        $passthresholdval = $this->get_pass_threshold();
        foreach (array_reverse(array_slice($itemscores, -8)) as $item) {
            $trendlogdata[] = [
                'cells' => [
                    $item['time'] > 0 ? userdate($item['time'], $datefmt) : '–',
                    $item['name'],
                    $item['percent'] . '%',
                ],
                'highlight' => ($item['percent'] < $passthresholdval),
            ];
        }

        // Isolation: forum views + posts.
        $isolationlogdata = [];
        foreach ($forumlogs as $log) {
            $eventlabel = ($log->action === 'viewed') ? get_string('log_event_read', $component) :
                (($log->target === 'discussion') ? get_string('log_event_started', $component)
                    : get_string('log_event_posted', $component));
            $isolationlogdata[] = [
                'cells' => [
                    userdate($log->timecreated, $datefmt),
                    $eventlabel,
                    !empty($log->detail) ? shorten_text($log->detail, 50) : '–',
                ],
                'highlight' => false,
            ];
        }

        // Feedback: per-assignment review status.
        $feedbacklogdata = [];
        // Get all graded assignments for this student.
        $gradedassigns = $DB->get_records_sql(
            "SELECT ag.id, ag.assignment, ag.timemodified AS gradedat, a.name AS assignname
               FROM {assign_grades} ag
               JOIN {assign} a ON a.id = ag.assignment
              WHERE ag.userid = :userid AND a.course = :courseid AND ag.grade >= 0
           ORDER BY ag.timemodified DESC",
            ['userid' => $userid, 'courseid' => $courseid],
            0,
            15
        );
        // Index feedback view events by assignment.
        $fbviewbyassign = [];
        foreach ($feedbacklogs as $fl) {
            $aid = (int)$fl->objectid;
            if (!isset($fbviewbyassign[$aid])) {
                $fbviewbyassign[$aid] = $fl->timecreated;
            }
        }
        foreach ($gradedassigns as $ga) {
            $aid = (int)$ga->assignment;
            $viewed = isset($fbviewbyassign[$aid]);
            $feedbacklogdata[] = [
                // Cells render HTML-escaped in the template, so keep them plain
                // text. The "not viewed" emphasis is carried by the row highlight
                // below rather than inline markup.
                'cells' => [
                    $ga->assignname,
                    userdate($ga->gradedat, $datefmt),
                    $viewed ? userdate($fbviewbyassign[$aid], $datefmt)
                        : get_string('log_not_viewed', $component),
                ],
                'highlight' => !$viewed,
            ];
        }

        // Engagement: recent module views.
        $engagementlogdata = [];
        foreach ($modulelogs as $log) {
            $compshort = str_replace('mod_', '', $log->component);
            $engagementlogdata[] = [
                'cells' => [
                    userdate($log->timecreated, $datefmt),
                    ucfirst($compshort),
                    $log->action . ' ' . $log->target,
                ],
                'highlight' => false,
            ];
        }

        // Timing & Consistency: submission timestamps.
        $timinglogdata = [];
        $consistencylogdata = [];
        foreach ($submissionlogs as $sub) {
            $duestr = $sub->duedate > 0 ? userdate($sub->duedate, $datefmt) : '–';
            $submittedstr = userdate($sub->submitted, $datefmt);
            $offsetstr = '–';
            $islate = false;
            if ($sub->duedate > 0) {
                $diff = $sub->duedate - $sub->submitted;
                $days = round(abs($diff) / 86400, 1);
                if ($diff >= 0) {
                    $offsetstr = $days . 'd early';
                } else {
                    $offsetstr = $days . 'd late';
                    $islate = true;
                }
            }
            $timinglogdata[] = [
                'cells' => [$sub->assignname, $duestr, $submittedstr, $offsetstr],
                'highlight' => $islate,
            ];
            $consistencylogdata[] = [
                'cells' => [$sub->assignname, $submittedstr],
                'highlight' => false,
            ];
        }

        // Self-regulation: grade report views.
        $selfreglogdata = [];
        foreach ($gradereportlogs as $log) {
            $compshort = str_replace('gradereport_', '', $log->component);
            $selfreglogdata[] = [
                'cells' => [
                    userdate($log->timecreated, $datefmt),
                    ucfirst($compshort),
                ],
                'highlight' => false,
            ];
        }

        // Detail modal helper for student-level cards.
        $studentcardindex = 0;
        $buildstudentdetail = function (
            array $metrics,
            array $thresholds,
            string $methodologykey,
            string $rationalekey,
            array $logcolumns = [],
            array $logdata = []
        ) use (
            $component,
            $studentname,
            &$studentcardindex
        ) {
            $studentcardindex++;
            return [
                'cardid' => 'scard' . $studentcardindex,
                'courseid' => $this->courseid,
                'studentid' => $this->userid,
                'studentname' => $studentname,
                // Raw JSON for the intervention modal's recipient list; the
                // template's {{studentsjson}} tag does the single HTML-attribute
                // escaping. Built with json_encode (not hand-assembled) so names
                // containing quotes can't break the JSON.
                'studentsjson' => json_encode([['id' => (int)$this->userid, 'name' => $studentname]]),
                'metrics' => $metrics,
                'hasmetrics' => !empty($metrics),
                'thresholds' => $thresholds,
                'hasthresholds' => !empty($thresholds),
                'students' => [],
                'hasstudents' => false,
                'methodology' => get_string($methodologykey, $component),
                'rationale' => get_string($rationalekey, $component),
                'logcolumns' => $logcolumns,
                'logdata' => $logdata,
                'haslogdata' => !empty($logdata),
            ];
        };

        // 1. Trend analysis — cross-reference with feedback engagement.
        if (!empty($widgets['trend'])) {
            $totalindicators++;
            $trend = $widgets['trend'];
            if ($trend['isrisk'] ?? false) {
                $riskcount++;
                $diagnostic = get_string('insight_trend_diagnostic', $component);
                $feedbacklow = !empty($widgets['feedback']) && ($widgets['feedback']['percent'] ?? 100) < 50;
                $coifblow = !empty($widgets['coi_feedbackloop']) && ($widgets['coi_feedbackloop']['percent'] ?? 100) < 50;
                if ($feedbacklow || $coifblow) {
                    $diagnostic .= ' ' . get_string('insight_trend_feedback_link', $component);
                }
                $trendscores = $trend['scores'] ?? [];
                $trendmetrics = [
                    ['label' => get_string('detail_student_metric_recentscores', $component),
                     'value' => !empty($trendscores) ? implode('%, ', array_slice($trendscores, -3)) . '%' : '–'],
                    ['label' => get_string('detail_student_metric_direction', $component),
                     'value' => $trend['direction'] ?? get_string('widget_trend_down', $component)],
                ];
                if ($feedbacklow || $coifblow) {
                    $fbpct = $widgets['coi_feedbackloop']['percent'] ?? $widgets['feedback']['percent'] ?? 0;
                    $trendmetrics[] = [
                        'label' => get_string('detail_student_metric_feedbackpct', $component),
                        'value' => $fbpct . '%',
                    ];
                }
                $detail = $buildstudentdetail(
                    $trendmetrics,
                    [
                        ['label' => get_string('detail_threshold_trigger', $component),
                         'value' => get_string('detail_student_threshold_trend_trigger', $component)],
                        ['label' => get_string('detail_student_threshold_crossref', $component),
                         'value' => get_string('detail_student_threshold_trend_crossref', $component)],
                    ],
                    'detail_student_method_trend',
                    'detail_student_rationale_trend',
                    [$logcoldate, $logcolassessment, $logcolscore],
                    $trendlogdata
                );
                $cards[] = array_merge([
                    'icon' => 'line-chart',
                    'diagnostictype' => 'trend_declining',
                    'severity' => 'danger',
                    'title' => get_string('insight_trend_title', $component),
                    'diagnostic' => $diagnostic,
                    'action' => get_string('insight_trend_action', $component),
                ], $detail);
            }
        }

        // 2. Streak broken — cross-reference with consistency.
        if (!empty($widgets['streak'])) {
            $totalindicators++;
            $streak = $widgets['streak'];
            if ($streak['isrisk'] ?? false) {
                $riskcount++;
                $diagnostic = get_string('insight_streak_diagnostic', $component);
                $inconsistent = !empty($widgets['consistency']) && ($widgets['consistency']['rating'] ?? '') === 'needswork';
                if ($inconsistent) {
                    $diagnostic .= ' ' . get_string('insight_streak_consistency_link', $component);
                }
                $streakmetrics = [
                    ['label' => get_string('detail_student_metric_beststreak', $component),
                     'value' => (string)($streak['best'] ?? 0)],
                    ['label' => get_string('detail_student_metric_currentstreak', $component),
                     'value' => (string)($streak['current'] ?? 0)],
                ];
                if ($inconsistent) {
                    $streakmetrics[] = ['label' => get_string('detail_student_metric_consistency', $component),
                                        'value' => get_string('widget_consistency_needswork', $component)];
                }
                $detail = $buildstudentdetail(
                    $streakmetrics,
                    [
                        ['label' => get_string('detail_threshold_trigger', $component),
                         'value' => get_string('detail_student_threshold_streak_trigger', $component)],
                        [
                            'label' => get_string('detail_threshold_passmark', $component),
                            'value' => $this->get_pass_threshold() . '%',
                        ],
                    ],
                    'detail_student_method_streak',
                    'detail_student_rationale_streak',
                    [$logcoldate, $logcolassessment, $logcolscore],
                    $trendlogdata
                );
                $cards[] = array_merge([
                    'icon' => 'fire-extinguisher',
                    'diagnostictype' => 'streak_broken',
                    'severity' => 'warning',
                    'title' => get_string('insight_streak_title', $component),
                    'diagnostic' => $diagnostic,
                    'action' => get_string('insight_streak_action', $component),
                ], $detail);
            }
        }

        // 3. Social isolation — cross-reference community + peer connection.
        $communitylow = !empty($widgets['coi_community']) && ($widgets['coi_community']['level']['level'] ?? 5) <= 1;
        $peerlow = !empty($widgets['coi_peerconnection']) && ($widgets['coi_peerconnection']['level']['level'] ?? 5) <= 1;
        if (!empty($widgets['coi_community'])) {
            $totalindicators++;
        }
        if (!empty($widgets['coi_peerconnection'])) {
            $totalindicators++;
        }

        // Check if the course has limited social activity opportunities.
        $socialmodules = ['forum', 'wiki', 'glossary', 'workshop', 'data'];
        [$smsql, $smparams] = $DB->get_in_or_equal($socialmodules, SQL_PARAMS_NAMED, 'smod');
        $socialactivitycount = (int)$DB->count_records_sql(
            "SELECT COUNT(cm.id)
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid
                AND cm.deletioninprogress = 0
                AND m.name $smsql",
            array_merge(['courseid' => $this->courseid], $smparams)
        );
        $islimitedsocial = ($socialactivitycount <= 2);

        if ($communitylow || $peerlow) {
            $riskcount += ($communitylow ? 1 : 0) + ($peerlow ? 1 : 0);
            $diagnostic = get_string('insight_isolation_diagnostic', $component);
            if ($islimitedsocial) {
                $diagnostic .= ' ' . get_string('coursedesign_note', $component);
            }
            $communitystale = !empty($widgets['coi_community']['isstale']);
            $peerstale = !empty($widgets['coi_peerconnection']['isstale']);
            if ($communitystale || $peerstale) {
                $days = max(
                    $widgets['coi_community']['daysinactive'] ?? 0,
                    $widgets['coi_peerconnection']['daysinactive'] ?? 0
                );
                $diagnostic .= ' ' . get_string('insight_isolation_stale', $component, $days);
            }
            $isolationmetrics = [];
            if (!empty($widgets['coi_community'])) {
                $isolationmetrics[] = ['label' => get_string('widget_coi_community_title', $component),
                    'value' => $widgets['coi_community']['level']['label'] ?? '–'];
                $isolationmetrics[] = ['label' => get_string('detail_student_metric_contributions', $component),
                    'value' => (string)($widgets['coi_community']['total'] ?? 0)];
            }
            if (!empty($widgets['coi_peerconnection'])) {
                $isolationmetrics[] = ['label' => get_string('widget_coi_peerconnection_title', $component),
                    'value' => $widgets['coi_peerconnection']['level']['label'] ?? '–'];
            }
            if ($communitystale || $peerstale) {
                $maxinactive = max(
                    $widgets['coi_community']['daysinactive'] ?? 0,
                    $widgets['coi_peerconnection']['daysinactive'] ?? 0
                );
                $isolationmetrics[] = [
                    'label' => get_string('detail_student_metric_daysinactive', $component),
                    'value' => $maxinactive . ' ' . get_string('detail_metric_days', $component),
                ];
            }
            $detail = $buildstudentdetail(
                $isolationmetrics,
                [
                    ['label' => get_string('detail_threshold_trigger', $component),
                     'value' => get_string('detail_student_threshold_isolation_trigger', $component)],
                    ['label' => get_string('detail_threshold_levels', $component),
                     'value' => get_string('detail_threshold_isolation_levels', $component)],
                    ['label' => get_string('detail_threshold_window', $component),
                     'value' => get_string('detail_student_threshold_stale_window', $component)],
                ],
                'detail_student_method_isolation',
                'detail_rationale_isolation',
                [$logcoldate, $logcolevent, $logcoldetail],
                $isolationlogdata
            );
            $cards[] = array_merge([
                'icon' => 'user-times',
                'diagnostictype' => 'social_isolation',
                'severity' => ($communitylow && $peerlow) ? 'danger' : 'warning',
                'title' => get_string('insight_isolation_title', $component),
                'diagnostic' => $diagnostic,
                'action' => get_string('insight_isolation_action', $component),
            ], $detail);
        }

        // 4. Feedback engagement — teaching presence gap.
        $feedbackwidget = $widgets['feedback'] ?? $widgets['coi_feedbackloop'] ?? null;
        if ($feedbackwidget) {
            $totalindicators++;
            if ($feedbackwidget['isrisk'] ?? false) {
                $riskcount++;
                $unreviewed = ($feedbackwidget['total'] ?? 0) - ($feedbackwidget['viewed'] ?? 0);
                $fbtotal = $feedbackwidget['total'] ?? 0;
                $fbviewed = $feedbackwidget['viewed'] ?? 0;
                $fbpct = $feedbackwidget['percent'] ?? 0;
                $detail = $buildstudentdetail(
                    [
                        ['label' => get_string('detail_student_metric_gradeditems', $component), 'value' => (string)$fbtotal],
                        ['label' => get_string('detail_student_metric_feedbackviewed', $component), 'value' => (string)$fbviewed],
                        ['label' => get_string('detail_student_metric_unreviewed', $component), 'value' => (string)$unreviewed],
                        ['label' => get_string('detail_student_metric_reviewrate', $component), 'value' => $fbpct . '%'],
                    ],
                    [
                        ['label' => get_string('detail_threshold_trigger', $component),
                         'value' => get_string('detail_student_threshold_feedback_trigger', $component)],
                        ['label' => get_string('detail_threshold_levels', $component),
                         'value' => get_string('detail_threshold_feedback_levels', $component)],
                    ],
                    'detail_student_method_feedback',
                    'detail_rationale_feedback',
                    [$logcolassessment, $logcoldate, $logcolstatus],
                    $feedbacklogdata
                );
                $cards[] = array_merge([
                    'icon' => 'comment-o',
                    'diagnostictype' => 'feedback_unreviewed',
                    'severity' => ($feedbackwidget['percent'] ?? 0) === 0 ? 'danger' : 'warning',
                    'title' => get_string('insight_feedback_title', $component),
                    'diagnostic' => get_string('insight_feedback_diagnostic', $component, $unreviewed),
                    'action' => get_string('insight_feedback_action', $component),
                ], $detail);
            }
        }

        // 5. Course engagement — cognitive presence gap.
        if (!empty($widgets['coi_learningdepth'])) {
            $totalindicators++;
            $engagement = $widgets['coi_learningdepth'];
            if ($engagement['isrisk'] ?? false) {
                $riskcount++;
                $detail = $buildstudentdetail(
                    [
                        ['label' => get_string('detail_student_metric_engagementlevel', $component),
                         'value' => $engagement['level']['label'] ?? '–'],
                        ['label' => get_string('detail_student_metric_engagementpct', $component),
                         'value' => ($engagement['percent'] ?? 0) . '%'],
                    ],
                    [
                        ['label' => get_string('detail_threshold_trigger', $component),
                         'value' => get_string('detail_student_threshold_engagement_trigger', $component)],
                        ['label' => get_string('detail_threshold_levels', $component),
                         'value' => get_string('detail_threshold_engagement_levels', $component)],
                    ],
                    'detail_student_method_engagement',
                    'detail_rationale_engagement',
                    [$logcoldate, $logcolcomponent, $logcolevent],
                    $engagementlogdata
                );
                $cards[] = array_merge([
                    'icon' => 'book',
                    'diagnostictype' => 'engagement_low',
                    'severity' => ($engagement['level']['level'] ?? 0) === 0 ? 'danger' : 'warning',
                    'title' => get_string('insight_engagement_title', $component),
                    'diagnostic' => get_string('insight_engagement_diagnostic', $component),
                    'action' => get_string('insight_engagement_action', $component),
                ], $detail);
            }
        }

        // 6. Submission timing — cross-reference with consistency.
        if (!empty($widgets['earlybird'])) {
            $totalindicators++;
            $earlybird = $widgets['earlybird'];
            if ($earlybird['isrisk'] ?? false) {
                $riskcount++;
                $diagnostic = get_string('insight_timing_diagnostic', $component);
                $inconsistent = !empty($widgets['consistency']) && ($widgets['consistency']['rating'] ?? '') === 'needswork';
                if ($inconsistent) {
                    $diagnostic .= ' ' . get_string('insight_timing_consistency_link', $component);
                }
                $timingmetrics = [
                    ['label' => get_string('detail_student_metric_timingrating', $component),
                     'value' => $earlybird['rating'] ?? '–'],
                    ['label' => get_string('detail_student_metric_avgoffset', $component),
                     'value' => $earlybird['avgtext'] ?? '–'],
                ];
                if ($inconsistent) {
                    $timingmetrics[] = ['label' => get_string('detail_student_metric_consistency', $component),
                                        'value' => get_string('widget_consistency_needswork', $component)];
                }
                $detail = $buildstudentdetail(
                    $timingmetrics,
                    [
                        ['label' => get_string('detail_threshold_trigger', $component),
                         'value' => get_string('detail_student_threshold_timing_trigger', $component)],
                        ['label' => get_string('detail_student_threshold_crossref', $component),
                         'value' => get_string('detail_student_threshold_timing_crossref', $component)],
                    ],
                    'detail_student_method_timing',
                    'detail_student_rationale_timing',
                    [$logcolassessment, $logcoldue, $logcolsubmitted, $logcoloffset],
                    $timinglogdata
                );
                $cards[] = array_merge([
                    'icon' => 'clock-o',
                    'diagnostictype' => 'timing_late',
                    'severity' => ($earlybird['rating'] ?? '') === 'behind' ? 'danger' : 'warning',
                    'title' => get_string('insight_timing_title', $component),
                    'diagnostic' => $diagnostic,
                    'action' => get_string('insight_timing_action', $component),
                ], $detail);
            }
        }

        // 7. Consistency — work spacing.
        if (!empty($widgets['consistency'])) {
            $totalindicators++;
            $consistency = $widgets['consistency'];
            if ($consistency['isrisk'] ?? false) {
                $riskcount++;
                $detail = $buildstudentdetail(
                    [
                        ['label' => get_string('detail_student_metric_consistencyscore', $component),
                         'value' => ($consistency['score'] ?? '–') . '%'],
                        ['label' => get_string('detail_student_metric_consistencyrating', $component),
                         'value' => $consistency['rating'] ?? '–'],
                    ],
                    [
                        ['label' => get_string('detail_threshold_trigger', $component),
                         'value' => get_string('detail_student_threshold_consistency_trigger', $component)],
                    ],
                    'detail_student_method_consistency',
                    'detail_student_rationale_consistency',
                    [$logcolassessment, $logcolsubmitted],
                    $consistencylogdata
                );
                $cards[] = array_merge([
                    'icon' => 'calendar',
                    'diagnostictype' => 'consistency_poor',
                    'severity' => 'warning',
                    'title' => get_string('insight_consistency_title', $component),
                    'diagnostic' => get_string('insight_consistency_diagnostic', $component),
                    'action' => get_string('insight_consistency_action', $component),
                ], $detail);
            }
        }

        // 8. Self-regulation — grade-checking behaviour.
        if (!empty($widgets['selfregulation'])) {
            $totalindicators++;
            $selfreg = $widgets['selfregulation'];
            if ($selfreg['isrisk'] ?? false) {
                $riskcount++;
                $selfregcomposite = $selfreg['composite'] ?? 0;
                $detail = $buildstudentdetail(
                    [
                        ['label' => get_string('detail_student_metric_selfreg_composite', $component),
                         'value' => $selfregcomposite . '%'],
                        ['label' => get_string('detail_student_metric_selfreg_monitoring', $component),
                         'value' => ($selfreg['monitoringscore'] ?? 0) . '% (40%)'],
                        ['label' => get_string('detail_student_metric_selfreg_feedback', $component),
                         'value' => ($selfreg['feedbackscore'] ?? 0) . '% (25%)'],
                        ['label' => get_string('detail_student_metric_selfreg_resources', $component),
                         'value' => ($selfreg['resourcescore'] ?? 0) . '% (20%)'],
                        ['label' => get_string('detail_student_metric_selfreg_planning', $component),
                         'value' => ($selfreg['planningscore'] ?? 0) . '% (15%)'],
                        ['label' => get_string('detail_student_metric_viewsperweek', $component),
                         'value' => ($selfreg['viewsperweek'] ?? 0) . '/week'],
                        ['label' => get_string('detail_student_metric_weeksenrolled', $component),
                         'value' => (string)($selfreg['weeksenrolled'] ?? 0)],
                    ],
                    [
                        ['label' => get_string('detail_threshold_trigger', $component),
                         'value' => get_string('detail_student_threshold_selfregulation_trigger', $component)],
                    ],
                    'detail_student_method_selfregulation',
                    'detail_student_rationale_selfregulation',
                    [$logcoldate, $logcolcomponent],
                    $selfreglogdata
                );
                $cards[] = array_merge([
                    'icon' => 'dashboard',
                    'diagnostictype' => 'selfregulation_low',
                    'severity' => 'warning',
                    'title' => get_string('insight_selfregulation_title', $component),
                    'diagnostic' => get_string('insight_selfregulation_diagnostic', $component, $selfregcomposite),
                    'action' => get_string('insight_selfregulation_action', $component),
                ], $detail);
            }
        }

        // Missed deadlines — direct, prescriptive card listing the activities.
        // Re-uses the same bulk helper as the cohort path so both views are computed identically.
        $totalindicators++;
        $studentmissed = $this->get_cohort_missed_deadlines([$this->userid])[$this->userid] ?? null;
        if ($studentmissed && $studentmissed['missed'] >= 1) {
            $riskcount++;
            $missedcount = (int)$studentmissed['missed'];
            $missedlist = $studentmissed['missedlist'];
            $missednames = implode(', ', $missedlist);
            $detail = $buildstudentdetail(
                [
                    [
                        'label' => get_string('detail_student_metric_missedcount', $component),
                        'value' => (string)$missedcount,
                    ],
                    [
                        'label' => get_string('detail_student_metric_missedlist', $component),
                        'value' => $missednames,
                    ],
                ],
                [
                    [
                        'label' => get_string('detail_threshold_trigger', $component),
                        'value' => get_string('detail_student_threshold_missed_trigger', $component),
                    ],
                ],
                'detail_student_method_missed',
                'detail_student_rationale_missed'
            );
            $cards[] = array_merge([
                'icon' => 'calendar-times-o',
                'diagnostictype' => 'missed_deadlines',
                'severity' => $missedcount >= 3 ? 'danger' : 'warning',
                'title' => get_string('insight_missed_title', $component),
                'diagnostic' => get_string('insight_missed_diagnostic', $component, (object)[
                    'count' => $missedcount, 'names' => $missednames,
                ]),
                'action' => get_string('insight_missed_action', $component),
            ], $detail);
        }

        // Quick stats summary.
        $stats = [];
        if (isset($progress['coursetotalbar']['percentage'])) {
            $stats[] = [
                'label' => get_string('coursetotal', $component),
                'value' => $progress['coursetotalbar']['percentage'] . '%',
            ];
        }
        if (!empty($widgets['coi_community'])) {
            $stats[] = [
                'label' => get_string('widget_coi_community_title', $component),
                'value' => $widgets['coi_community']['level']['label'] ?? '—',
                'isrisk' => $communitylow,
            ];
        }
        if (!empty($widgets['coi_learningdepth'])) {
            $stats[] = [
                'label' => get_string('widget_coi_learningdepth_title', $component),
                'value' => $widgets['coi_learningdepth']['level']['label'] ?? '—',
                'isrisk' => !empty($widgets['coi_learningdepth']['isrisk']),
            ];
        }
        if ($feedbackwidget) {
            $stats[] = [
                'label' => get_string('widget_coi_feedbackloop_title', $component),
                'value' => ($feedbackwidget['percent'] ?? 0) . '%',
                'isrisk' => !empty($feedbackwidget['isrisk']),
            ];
        }

        // Determine overall risk level.
        if ($riskcount === 0) {
            $risklevel = 'healthy';
            $risklabel = get_string('insight_risk_healthy', $component);
        } else if ($riskcount <= 2) {
            $risklevel = 'moderate';
            $risklabel = get_string('insight_risk_moderate', $component);
        } else {
            $risklevel = 'high';
            $risklabel = get_string('insight_risk_high', $component);
        }

        // Tag each card with its template family for the composer pre-fill.
        foreach ($cards as &$card) {
            $card['tplfamily'] = \gradereport_coifish\intervention_templates::family_for_diagnostic(
                $card['diagnostictype'] ?? ''
            );
        }
        unset($card);

        return [
            'cards' => $cards,
            'hascards' => !empty($cards),
            'nocards' => empty($cards),
            'riskcount' => $riskcount,
            'totalindicators' => $totalindicators,
            'risklevel' => $risklevel,
            'risklabel' => $risklabel,
            'stats' => $stats,
            'hasstats' => !empty($stats),
        ];
    }

    /**
     * Parse a formatted grade string back to a float.
     *
     * @param string $gradestr The formatted grade string.
     * @return float The numeric value.
     */
    protected function parse_grade_string(string $gradestr): float {
        // Strip everything except digits, dots, and minus signs.
        $cleaned = preg_replace('/[^0-9.\-]/', '', $gradestr);
        return $cleaned !== '' ? (float)$cleaned : 0;
    }

    /**
     * Get the user ID whose grades are displayed.
     *
     * @return int The user ID.
     */
    public function get_userid(): int {
        return $this->userid;
    }

    /**
     * Check if there are any grade items in the course.
     *
     * @return bool True if grade data exists.
     */
    public function has_grades(): bool {
        return !empty($this->gradedata);
    }

    /**
     * Get summary data for all enrolled users (teacher view).
     *
     * @return array Array of user summary data.
     */
    public function get_summary_data(): array {
        global $DB;

        $enrolledusers = $this->get_scoped_enrolled_users();

        // Get all course total grades in one query.
        $courseitemid = $this->courseitem->id;
        $userids = array_keys($enrolledusers);
        if (empty($userids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['itemid'] = $courseitemid;
        $grades = $DB->get_records_select(
            'grade_grades',
            "itemid = :itemid AND userid $insql",
            $params,
            '',
            'userid, finalgrade'
        );

        // Compute running averages for all students.
        $runningaverages = $this->get_bulk_running_averages($userids);

        $summary = [];
        foreach ($enrolledusers as $user) {
            $finalgrade = isset($grades[$user->id]) ? $grades[$user->id]->finalgrade : null;
            $percentage = '–';
            if ($finalgrade !== null && (float)$this->courseitem->grademax > 0) {
                $percentage = $this->format_percentage(
                    (float)$finalgrade / (float)$this->courseitem->grademax
                );
            }
            $runningavg = $runningaverages[$user->id] ?? null;
            $summary[] = [
                'userid' => $user->id,
                'fullname' => fullname($user),
                'grade' => ($finalgrade !== null)
                    ? $this->format_grade((float)$finalgrade, $this->courseitem) : '–',
                'grademax' => $this->format_grademax((float)$this->courseitem->grademax, $this->courseitem),
                'percentage' => $percentage,
                'runningaverage' => $runningavg !== null ? $runningavg . '%' : '–',
                'viewurl' => (new \moodle_url('/grade/report/coifish/index.php', [
                    'id' => $this->courseid,
                    'userid' => $user->id,
                ]))->out(false),
            ];
        }

        return $summary;
    }

    /**
     * Compute running averages for multiple students.
     *
     * For each student, calculates the weighted average based only on graded items,
     * re-normalising weights to exclude ungraded items. This gives a realistic
     * picture of performance early in a course.
     *
     * @param array $userids Array of student user IDs.
     * @return array Keyed by userid => running average percentage (0-100), or null if nothing graded.
     */
    public function get_bulk_running_averages(array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        // Build the category tree once with drop/keep metadata and item weights.
        $tree = $this->build_bulk_category_tree();
        if ($tree === null) {
            return array_fill_keys($userids, null);
        }

        // Load all relevant grades in one query.
        $itemids = $this->collect_tree_item_ids($tree);
        if (empty($itemids)) {
            return array_fill_keys($userids, null);
        }
        [$insqlitems, $inparamsitems] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'gi');
        [$insqlusers, $inparamsusers] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'gu');
        $allgrades = $DB->get_records_sql(
            "SELECT id, userid, itemid, finalgrade
               FROM {grade_grades}
              WHERE itemid $insqlitems AND userid $insqlusers",
            array_merge($inparamsitems, $inparamsusers)
        );

        $usergrades = [];
        foreach ($allgrades as $gg) {
            $usergrades[$gg->userid][$gg->itemid] = $gg->finalgrade;
        }

        $result = [];
        foreach ($userids as $uid) {
            $pct = $this->bulk_category_percent($tree, $usergrades[$uid] ?? []);
            $result[$uid] = $pct === null ? null : round($pct * 100, 1);
        }
        return $result;
    }

    /**
     * Build a lightweight category tree for the bulk running-average calculation.
     *
     * Each node carries droplow, keephigh, its own category weight (in its parent),
     * the list of direct grade items with their natural weights, and child categories.
     *
     * @return array|null Root node, or null if the course has no grade categories.
     */
    protected function build_bulk_category_tree(): ?array {
        global $DB;

        $categories = $DB->get_records(
            'grade_categories',
            ['courseid' => $this->courseid],
            'depth ASC, id ASC',
            'id, parent, depth, aggregation, droplow, keephigh'
        );
        if (empty($categories)) {
            return null;
        }

        // Find the root (course) category — the one with null parent.
        $root = null;
        foreach ($categories as $cat) {
            if ($cat->parent === null) {
                $root = $cat;
                break;
            }
        }
        if ($root === null) {
            return null;
        }

        // Category weights live on the corresponding category-total grade_item.
        $catweights = [];
        $catitems = $DB->get_records_select(
            'grade_items',
            'courseid = :courseid AND itemtype = :tcategory',
            ['courseid' => $this->courseid, 'tcategory' => 'category'],
            '',
            'id, iteminstance, aggregationcoef2, grademax'
        );
        foreach ($catitems as $ci) {
            $catweights[(int)$ci->iteminstance] = (float)$ci->aggregationcoef2 > 0
                ? (float)$ci->aggregationcoef2
                : (float)$ci->grademax;
        }

        // Load all grade items for the course (non-course, non-category-total).
        $items = $DB->get_records_select(
            'grade_items',
            'courseid = :courseid AND itemtype NOT IN (:tcourse, :tcategory)',
            ['courseid' => $this->courseid, 'tcourse' => 'course', 'tcategory' => 'category'],
            '',
            'id, grademax, aggregationcoef, aggregationcoef2, categoryid, hidden'
        );

        // Bucket items by parent category.
        $itemsbycat = [];
        foreach ($items as $item) {
            if ((float)$item->grademax <= 0) {
                continue;
            }
            if (!$this->canviewhidden && !empty($item->hidden)) {
                continue;
            }
            $weight = (float)$item->aggregationcoef2 > 0
                ? (float)$item->aggregationcoef2
                : (float)$item->grademax;
            if ($weight <= 0) {
                continue;
            }
            $itemsbycat[(int)$item->categoryid][] = [
                'id' => (int)$item->id,
                'grademax' => (float)$item->grademax,
                'weight' => $weight,
                'isextracredit' => ((int)$item->aggregationcoef !== 0),
            ];
        }

        // Recursively build the tree from the root.
        return $this->build_bulk_category_node($root, $categories, $itemsbycat, $catweights);
    }

    /**
     * Build a single tree node for the bulk computation.
     *
     * @param object $cat The category record.
     * @param array $categories All categories indexed by id.
     * @param array $itemsbycat Items grouped by categoryid.
     * @param array $catweights Map of category id => weight (from the category-total grade item).
     * @return array Node with id, droplow, keephigh, weight, items, children.
     */
    protected function build_bulk_category_node(
        object $cat,
        array $categories,
        array $itemsbycat,
        array $catweights
    ): array {
        $children = [];
        foreach ($categories as $other) {
            if ((int)$other->parent === (int)$cat->id) {
                $children[] = $this->build_bulk_category_node($other, $categories, $itemsbycat, $catweights);
            }
        }

        $items = $itemsbycat[(int)$cat->id] ?? [];

        return [
            'id' => (int)$cat->id,
            'droplow' => (int)$cat->droplow,
            'keephigh' => (int)$cat->keephigh,
            'weight' => $catweights[(int)$cat->id] ?? 1.0,
            'items' => $items,
            'children' => $children,
        ];
    }

    /**
     * Collect every grade item ID referenced by the tree.
     *
     * @param array $node Tree node.
     * @return int[]
     */
    protected function collect_tree_item_ids(array $node): array {
        $ids = [];
        foreach ($node['items'] as $item) {
            $ids[] = $item['id'];
        }
        foreach ($node['children'] as $child) {
            foreach ($this->collect_tree_item_ids($child) as $id) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Compute a category's running percentage for one user, honoring drop/keep.
     *
     * Returns null if the user has no graded item anywhere in the subtree.
     *
     * @param array $node Tree node.
     * @param array $usergrades itemid => finalgrade.
     * @return float|null Percentage in 0..1.
     */
    protected function bulk_category_percent(array $node, array $usergrades): ?float {
        // Partition direct items into graded vs ungraded (we only count graded for running).
        $gradeditems = [];
        $extra = [];
        foreach ($node['items'] as $item) {
            $fg = $usergrades[$item['id']] ?? null;
            if ($fg === null) {
                continue;
            }
            $entry = [
                'weight' => $item['weight'],
                'pct' => (float)$fg / $item['grademax'],
                'isextracredit' => $item['isextracredit'],
            ];
            if ($item['isextracredit']) {
                $extra[] = $entry;
            } else {
                $gradeditems[] = $entry;
            }
        }

        $kept = $this->apply_drop_keep_simple($gradeditems, $node['droplow'], $node['keephigh']);
        // Extra-credit items always pass through.
        $kept = array_merge($kept, $extra);

        $weightsum = 0;
        $weightedscore = 0;
        foreach ($kept as $entry) {
            $weightsum += $entry['weight'];
            $weightedscore += $entry['weight'] * $entry['pct'];
        }

        foreach ($node['children'] as $child) {
            $subpct = $this->bulk_category_percent($child, $usergrades);
            if ($subpct !== null) {
                $subweight = $child['weight'];
                $weightsum += $subweight;
                $weightedscore += $subweight * $subpct;
            }
        }

        if ($weightsum <= 0) {
            return null;
        }
        return $weightedscore / $weightsum;
    }

    /**
     * Simple drop/keep helper for the bulk tree (entries with weight + pct).
     *
     * @param array $entries Each: ['weight' => float, 'pct' => float, 'isextracredit' => bool].
     * @param int $droplow Number of lowest grades to drop.
     * @param int $keephigh Number of highest grades to keep.
     * @return array Surviving entries.
     */
    protected function apply_drop_keep_simple(array $entries, int $droplow, int $keephigh): array {
        if (($droplow <= 0 && $keephigh <= 0) || empty($entries)) {
            return $entries;
        }
        $rankable = [];
        foreach ($entries as $idx => $entry) {
            $rankable[$idx] = $entry['pct'];
        }
        arsort($rankable);
        $ranked = array_keys($rankable);
        if ($keephigh > 0) {
            $keepidx = array_slice($ranked, 0, $keephigh);
        } else {
            $keepidx = array_slice($ranked, 0, max(0, count($ranked) - $droplow));
        }
        $keepset = array_fill_keys($keepidx, true);
        $result = [];
        foreach ($entries as $idx => $entry) {
            if (isset($keepset[$idx])) {
                $result[] = $entry;
            }
        }
        return $result;
    }

    /**
     * Build the integrity-aware grading-turnaround clock fragments.
     *
     * Returns the clock-stop SQL expression and the referral LEFT JOIN, both
     * guarded by table_exists so the metric degrades gracefully on sites without
     * Unified Grader installed. Shared by every assign-turnaround query in this
     * report (cohort UNION, per-group block, per-lecturer historical trends) so
     * the same lecturer's number is identical on every surface. The fragments
     * reference the `ag` (assign_grades) and `asub` (assign_submission) aliases,
     * which every caller defines.
     *
     * @return array{0:string,1:string} [clock-stop expression, referral LEFT JOIN SQL]
     */
    protected function get_assign_turnaround_clock(): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_unifiedgrader_referral')) {
            // Table absent (Unified Grader not installed / other institutions):
            // fall back to grade creation as the clock-stop.
            return ['ag.timecreated', ''];
        }

        // Pause the clock at the referral moment (1-stamp model): the clock
        // stops at the earliest referral that lands strictly after submission
        // and no later than when the grade was created; otherwise it stops at
        // grade creation.
        $clockend = "CASE
                        WHEN ugref.timereferred IS NOT NULL
                             AND ugref.timereferred > asub.timemodified
                             AND ugref.timereferred <= ag.timecreated
                        THEN ugref.timereferred
                        ELSE ag.timecreated
                     END";
        $referraljoin = "LEFT JOIN (
                            SELECT cm.instance AS assignid, r.userid AS userid,
                                   MIN(r.timereferred) AS timereferred
                              FROM {local_unifiedgrader_referral} r
                              JOIN {course_modules} cm ON cm.id = r.cmid
                              JOIN {modules} mo ON mo.id = cm.module AND mo.name = 'assign'
                          GROUP BY cm.instance, r.userid
                         ) ugref ON ugref.assignid = ag.assignment AND ugref.userid = ag.userid";

        return [$clockend, $referraljoin];
    }

    /**
     * Build the assign branch of the grading-turnaround UNION.
     *
     * The clock runs from submission (asub.timemodified) to the moment the grade
     * was first created (ag.timecreated) — not last modified — so later edits to a
     * grade do not inflate a lecturer's turnaround. Where the Unified Grader
     * referral table is present, an academic-integrity referral pauses the clock:
     * if the item was referred for review after submission but before it was
     * graded, the referral timestamp becomes the clock-stop instead, so the
     * lecturer is not penalised for the hold. The expression is kept numerically
     * identical to local_coifish's assign turnaround.
     *
     * @param string $courseparamname Named SQL placeholder (without leading colon)
     *                                 that the caller binds to the course id.
     * @return string The SELECT ... fragment producing a single `gap` column.
     */
    protected function get_assign_turnaround_part(string $courseparamname): string {
        [$clockend, $referraljoin] = $this->get_assign_turnaround_clock();

        return "SELECT ($clockend - asub.timemodified) AS gap
                  FROM {assign_grades} ag
                  JOIN {assign_submission} asub
                       ON asub.assignment = ag.assignment
                      AND asub.userid = ag.userid
                      AND asub.latest = 1
                  JOIN {assign} a ON a.id = ag.assignment
                  $referraljoin
                 WHERE a.course = :$courseparamname
                   AND ag.grade >= 0
                   AND asub.status = 'submitted'
                   AND ($clockend) > asub.timemodified";
    }

    /**
     * Compute the average assign-only grading turnaround, in seconds, for the
     * current course. Thin seam over the assign UNION-part used by
     * {@see get_cohort_insights_data()}, exposed for unit testing the
     * timecreated base and the integrity-referral pause in isolation.
     *
     * @return float Average gap in seconds (0.0 when nothing qualifies).
     */
    protected function get_assign_avg_turnaround_seconds(): float {
        global $DB;

        $part = $this->get_assign_turnaround_part('tacourseid');
        $row = $DB->get_record_sql(
            "SELECT AVG(gap) AS avgturnaround FROM ($part) gaps WHERE gap > 0",
            ['tacourseid' => $this->courseid]
        );

        return $row && $row->avgturnaround !== null ? (float)$row->avgturnaround : 0.0;
    }

    /**
     * Get cohort-level insights for the teacher summary view.
     *
     * Aggregates COI presence indicators, grade distribution, and risk diagnostics
     * across all students in the current group (or all participants). Produces
     * diagnostic and prescriptive analytics at the cohort level — addressing the
     * research gap identified in the COI/LAK systematic review.
     *
     * @return array Cohort insights with 'presence', 'distribution', 'cards', 'atrisk', etc.
     */
    public function get_cohort_insights_data(): array {
        global $DB;

        $component = 'gradereport_coifish';

        // Load configurable thresholds.
        $passthreshold = $this->get_pass_threshold();
        $staledays = $this->get_stale_days();
        $spthresholds = $this->get_coi_thresholds('sp', [1, 20, 50, 80]);
        $cpthresholds = $this->get_coi_thresholds('cp', [1, 20, 50, 80]);
        $tpthresholds = $this->get_coi_thresholds('tp', [1, 25, 75, 100]);
        $triggers = $this->get_diagnostic_triggers();

        // Get the cohort — same filtering as get_summary_data().
        $enrolledusers = $this->get_scoped_enrolled_users();
        $userids = array_keys($enrolledusers);
        if (empty($userids)) {
            return ['hasdata' => false];
        }
        $usercount = count($userids);
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');

        // 1. Grade distribution.
        $params = array_merge($inparams, ['itemid' => $this->courseitem->id]);
        $grades = $DB->get_records_select(
            'grade_grades',
            "itemid = :itemid AND userid $insql",
            $params,
            '',
            'userid, finalgrade'
        );
        $grademax = (float)$this->courseitem->grademax;
        // Running averages give a realistic picture early in the course.
        $runningaverages = $this->get_bulk_running_averages($userids);
        $percentages = [];
        $graded = 0;
        $ungraded = 0;
        foreach ($userids as $uid) {
            $fg = isset($grades[$uid]) ? $grades[$uid]->finalgrade : null;
            if ($fg !== null && $grademax > 0) {
                // Use running average for display; fall back to marks achieved if unavailable.
                $pct = $runningaverages[$uid] ?? round(((float)$fg / $grademax) * 100, 1);
                $percentages[$uid] = $pct;
                $graded++;
            } else {
                $percentages[$uid] = null;
                $ungraded++;
            }
        }

        // Distribution buckets.
        $buckets = ['0-49' => 0, '50-59' => 0, '60-69' => 0, '70-79' => 0, '80-89' => 0, '90-100' => 0];
        $bucketlabels = [
            '0-49' => '0–49%', '50-59' => '50–59%', '60-69' => '60–69%',
            '70-79' => '70–79%', '80-89' => '80–89%', '90-100' => '90–100%',
        ];
        foreach ($percentages as $pct) {
            if ($pct === null) {
                continue;
            }
            if ($pct < 50) {
                $buckets['0-49']++;
            } else if ($pct < 60) {
                $buckets['50-59']++;
            } else if ($pct < 70) {
                $buckets['60-69']++;
            } else if ($pct < 80) {
                $buckets['70-79']++;
            } else if ($pct < 90) {
                $buckets['80-89']++;
            } else {
                $buckets['90-100']++;
            }
        }
        $distribution = [];
        $maxbucket = max(1, max($buckets));
        foreach ($buckets as $key => $count) {
            $distribution[] = [
                'label' => $bucketlabels[$key],
                'count' => $count,
                'height' => round(($count / $maxbucket) * 100),
                'hascount' => $count > 0,
            ];
        }

        // Class average.
        $validpcts = array_filter($percentages, function ($p) {
            return $p !== null;
        });
        $classaverage = !empty($validpcts) ? round(array_sum($validpcts) / count($validpcts), 1) : null;
        $classmedian = null;
        if (!empty($validpcts)) {
            sort($validpcts);
            $mid = floor(count($validpcts) / 2);
            $classmedian = (count($validpcts) % 2 === 0)
                ? round(($validpcts[$mid - 1] + $validpcts[$mid]) / 2, 1)
                : $validpcts[$mid];
        }

        // 2. COI presence aggregation.
        // Social Presence: multi-signal composite accounting for forum group modes,
        // BBB attendance, collaborative activities, and configured messaging.

        // 2a. Forum participation — group-aware.
        // Get all discussions with their forum group mode.
        $alldiscussions = $DB->get_records_sql(
            "SELECT fd.id, fd.groupid, fd.forum, cm.groupmode
               FROM {forum_discussions} fd
               JOIN {forum} f ON f.id = fd.forum
               JOIN {course_modules} cm ON cm.instance = f.id AND cm.course = :courseid
               JOIN {modules} m ON m.id = cm.module AND m.name = 'forum'
              WHERE fd.course = :courseid2",
            ['courseid' => $this->courseid, 'courseid2' => $this->courseid]
        );
        $totaldiscussions = count($alldiscussions);

        // Get group memberships for all students in this course.
        $studentgroups = [];
        foreach ($userids as $uid) {
            $ugroups = groups_get_user_groups($this->courseid, $uid);
            $studentgroups[$uid] = $ugroups[0] ?? [];
        }

        // Calculate per-student visible discussions (respecting group mode).
        $visiblediscussions = [];
        foreach ($userids as $uid) {
            $visible = 0;
            foreach ($alldiscussions as $disc) {
                if ((int)$disc->groupmode === SEPARATEGROUPS) {
                    // Separate groups: student can only see their group's discussions or "all groups" (-1).
                    if ((int)$disc->groupid === -1 || in_array((int)$disc->groupid, $studentgroups[$uid])) {
                        $visible++;
                    }
                } else {
                    // No groups or visible groups: student can see all discussions.
                    $visible++;
                }
            }
            $visiblediscussions[$uid] = $visible;
        }

        // Per-user forum participation: both thread breadth and post volume.
        $participationsql = "SELECT fp.userid, COUNT(DISTINCT fd.id) AS threads, COUNT(fp.id) AS posts
                               FROM {forum_posts} fp
                               JOIN {forum_discussions} fd ON fd.id = fp.discussion
                              WHERE fd.course = :courseid AND fp.userid $insql
                           GROUP BY fp.userid";
        $participations = $DB->get_records_sql(
            $participationsql,
            array_merge(['courseid' => $this->courseid], $inparams)
        );

        // Calculate cohort average post count for relative comparison.
        $allpostcounts = array_map(function ($p) {
            return (int)$p->posts;
        }, $participations);
        $avgposts = !empty($allpostcounts) ? array_sum($allpostcounts) / count($allpostcounts) : 0;

        // 2b. BBB session attendance (if installed).
        $bbbattendance = [];
        $dbman = $DB->get_manager();
        if ($dbman->table_exists('bigbluebuttonbn_logs')) {
            $bbbrecords = $DB->get_records_sql(
                "SELECT l.userid, COUNT(DISTINCT l.bigbluebuttonbnid) AS sessions
                   FROM {bigbluebuttonbn_logs} l
                   JOIN {bigbluebuttonbn} b ON b.id = l.bigbluebuttonbnid
                  WHERE b.course = :courseid AND l.userid $insql
               GROUP BY l.userid",
                array_merge(['courseid' => $this->courseid], $inparams)
            );
            foreach ($bbbrecords as $rec) {
                $bbbattendance[$rec->userid] = (int)$rec->sessions;
            }
        }
        // Count BBB activities whenever the module is installed, not gated on
        // student attendance. A course with five scheduled BBB sessions and
        // zero recorded attendance should still surface "5" so the teacher
        // and coordinator know the activities exist.
        $totalbbbsessions = 0;
        if ($dbman->table_exists('bigbluebuttonbn')) {
            $totalbbbsessions = (int)$DB->count_records_sql(
                "SELECT COUNT(DISTINCT id) FROM {bigbluebuttonbn} WHERE course = :courseid",
                ['courseid' => $this->courseid]
            );
        }

        // 2c. Collaborative activity participation (wiki, glossary, database, workshop).
        [$insqlcollab, $inparamscollab] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'collab');
        $collabcounts = $DB->get_records_sql(
            "SELECT l.userid, COUNT(DISTINCT l.contextinstanceid) AS activities
               FROM {logstore_standard_log} l
              WHERE l.courseid = :courseid AND l.userid $insqlcollab
                AND l.action = 'created'
                AND l.component IN ('mod_glossary', 'mod_wiki', 'mod_data', 'mod_workshop')
                AND l.timecreated > :mintime
           GROUP BY l.userid",
            array_merge(
                ['courseid' => $this->courseid, 'mintime' => $this->effective_now() - 365 * 86400],
                $inparamscollab
            )
        );

        // 2d. Peer messaging (using configured messaging sources).
        $msgsources = get_config('gradereport_coifish', 'coordinator_messaging_sources');
        $msgsources = !empty($msgsources) ? explode(',', $msgsources) : ['core'];
        [$insqlmsg, $inparamsmsg] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'msgp');
        $peermessages = [];
        foreach ($msgsources as $source) {
            $source = trim($source);
            if ($source === 'core') {
                // Core messaging: count messages sent to peers (other enrolled students).
                $msgcounts = $DB->get_records_sql(
                    "SELECT m.useridfrom AS userid, COUNT(*) AS cnt
                       FROM {messages} m
                       JOIN {message_conversation_members} mcm ON mcm.conversationid = m.conversationid
                      WHERE m.useridfrom $insqlmsg
                        AND mcm.userid $insqlmsg
                        AND m.useridfrom != mcm.userid
                   GROUP BY m.useridfrom",
                    array_merge($inparamsmsg, $inparamsmsg)
                );
            } else {
                // Plugin messaging via logstore.
                $msgcounts = $DB->get_records_sql(
                    "SELECT userid, COUNT(*) AS cnt
                       FROM {logstore_standard_log}
                      WHERE component = :component
                        AND action = 'sent' AND target LIKE '%message%'
                        AND userid $insqlmsg
                        AND courseid = :courseid
                   GROUP BY userid",
                    array_merge(['component' => $source, 'courseid' => $this->courseid], $inparamsmsg)
                );
            }
            foreach ($msgcounts as $rec) {
                $uid = (int)$rec->userid;
                $peermessages[$uid] = ($peermessages[$uid] ?? 0) + (int)$rec->cnt;
            }
        }
        $avgmessages = !empty($peermessages)
            ? array_sum($peermessages) / count($peermessages)
            : 0;

        // Per-user last forum activity.
        $lastpostsql = "SELECT fp.userid, MAX(fp.created) AS lastpost
                          FROM {forum_posts} fp
                          JOIN {forum_discussions} fd ON fd.id = fp.discussion
                         WHERE fd.course = :courseid AND fp.userid $insql
                      GROUP BY fp.userid";
        $lastposts = $DB->get_records_sql(
            $lastpostsql,
            array_merge(['courseid' => $this->courseid], $inparams)
        );

        // Cognitive Presence: engagement rate per student.
        // Excludes assignments/quizzes that the category's drop-lowest or
        // keep-highest rule makes optional so students aren't flagged as
        // disengaged for skipping work they aren't expected to complete.
        $totalactivities = self::get_expected_activity_count($this->courseid);

        // Each UNION branch needs its own IN clause with unique param names.
        [$insql2, $inparams2] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'q');
        [$insql3, $inparams3] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'l');
        $engagementsql = "SELECT sub.userid, SUM(sub.cnt) AS engaged FROM (
                            SELECT ag.userid, COUNT(ag.id) AS cnt
                              FROM {assign_grades} ag
                              JOIN {assign} a ON a.id = ag.assignment
                             WHERE a.course = :courseid1 AND ag.userid $insql AND ag.grade >= 0
                          GROUP BY ag.userid
                          UNION ALL
                            SELECT qa.userid, COUNT(qa.id) AS cnt
                              FROM {quiz_attempts} qa
                              JOIN {quiz} q ON q.id = qa.quiz
                             WHERE q.course = :courseid2 AND qa.userid $insql2 AND qa.state IN ('finished', 'abandoned')
                          GROUP BY qa.userid
                          UNION ALL
                            SELECT l.userid, COUNT(DISTINCT l.contextinstanceid) AS cnt
                              FROM {logstore_standard_log} l
                             WHERE l.courseid = :courseid3 AND l.userid $insql3
                               AND l.action = 'viewed' AND l.target = 'course_module'
                               AND l.component IN ('mod_page', 'mod_book', 'mod_resource', 'mod_url', 'mod_folder')
                          GROUP BY l.userid
                         ) sub
                         GROUP BY sub.userid";
        $engageparams = array_merge(
            ['courseid1' => $this->courseid, 'courseid2' => $this->courseid, 'courseid3' => $this->courseid],
            $inparams,
            $inparams2,
            $inparams3
        );
        $engagements = $DB->get_records_sql($engagementsql, $engageparams);

        // Teaching Presence: feedback review rate per student. The denominator
        // is "graded items per student" on module types where feedback is
        // normally expected (assign, forum, quiz, etc.) — auto-graded modules
        // like lti and scorm are excluded so they don't drag the rate down.
        [$ftinsql, $ftinparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'ftu');
        [$frminsqlft, $frmparamsft] = $DB->get_in_or_equal(self::get_feedback_relevant_modnames(), SQL_PARAMS_NAMED, 'frmft');
        $feedbacktotalsql = "SELECT userid, COUNT(*) AS total FROM (
                                SELECT ag.userid, ag.assignment AS instanceid, 'assign' AS modname
                                  FROM {assign_grades} ag
                                  JOIN {assign} a ON a.id = ag.assignment
                                 WHERE a.course = :ftcourseid1
                                   AND ag.grade >= 0
                                   AND ag.userid $insql
                                UNION
                                SELECT gg.userid, gi.iteminstance AS instanceid, gi.itemmodule AS modname
                                  FROM {grade_grades} gg
                                  JOIN {grade_items} gi ON gi.id = gg.itemid
                                 WHERE gi.courseid = :ftcourseid2
                                   AND gi.itemtype = 'mod'
                                   AND gi.itemmodule != 'assign'
                                   AND gi.itemmodule $frminsqlft
                                   AND gg.finalgrade IS NOT NULL
                                   AND gg.userid $ftinsql
                            ) graded
                          GROUP BY userid";
        $feedbacktotals = $DB->get_records_sql(
            $feedbacktotalsql,
            array_merge(
                ['ftcourseid1' => $this->courseid, 'ftcourseid2' => $this->courseid],
                $inparams,
                $ftinparams,
                $frmparamsft
            )
        );

        [$evsql, $evparams] = self::get_feedback_view_event_sql('fv4');
        $feedbackviewsql = "SELECT l.userid, COUNT(DISTINCT l.contextinstanceid) AS viewed
                              FROM {logstore_standard_log} l
                             WHERE l.userid $insql AND l.courseid = :courseid
                               AND l.eventname $evsql
                          GROUP BY l.userid";
        $feedbackviews = $DB->get_records_sql(
            $feedbackviewsql,
            array_merge(
                $inparams,
                ['courseid' => $this->courseid],
                $evparams
            )
        );

        // Teaching Presence: instructor-side metrics. The base cohort metrics here
        // were originally assignment-only; broaden to all graded items (forums,
        // quizzes, lessons, workshops) so courses without assignments — common
        // for discussion-driven curricula — get accurate teaching-presence stats.

        // 1. Grading turnaround — average days from submission to grade across
        // all module types. Sources: assign_submission → assign_grades (assignments),
        // forum_posts created → forum_grades.timecreated (graded forums),
        // quiz_attempts.timefinish → grade_grades.timemodified (quizzes),
        // generic fallback: grade_grades.timemodified for any other module that
        // has a non-empty feedback row, treating the grade row's own creation
        // time as the submission time (rough but better than excluding them).
        // The assign branch runs the clock to first-grade (timecreated) and is
        // paused by an academic-integrity referral; see
        // get_assign_turnaround_part(). Forum mirrors this first-grade base via
        // forum_grades.timecreated.
        $turnaroundparts = [];
        $turnaroundparts[] = $this->get_assign_turnaround_part('tcourseid1');
        $turnaroundparts[] = "SELECT (fg.timecreated - firstpost.created) AS gap
                                FROM {forum_grades} fg
                                JOIN {forum} f ON f.id = fg.forum
                                JOIN (
                                    SELECT fd.forum, fp.userid, MIN(fp.created) AS created
                                      FROM {forum_posts} fp
                                      JOIN {forum_discussions} fd ON fd.id = fp.discussion
                                  GROUP BY fd.forum, fp.userid
                                ) firstpost
                                     ON firstpost.forum = fg.forum
                                    AND firstpost.userid = fg.userid
                               WHERE f.course = :tcourseid2
                                 AND fg.grade >= 0
                                 AND fg.timecreated > firstpost.created";
        // Quiz turnaround is left on grade_grades.timemodified: quizzes are
        // auto-graded, so there is no lecturer grading clock to protect, and the
        // gradebook row's timecreated reflects gradebook bookkeeping (attempt
        // submit / regrade), not a first lecturer grade — switching it would not
        // be a meaningful first-grade signal. Referrals are keyed cmid→assign and
        // are intentionally not applied here.
        $turnaroundparts[] = "SELECT (gg.timemodified - qa.timefinish) AS gap
                                FROM {grade_grades} gg
                                JOIN {grade_items} gi ON gi.id = gg.itemid
                                JOIN {quiz_attempts} qa
                                     ON qa.quiz = gi.iteminstance
                                    AND qa.userid = gg.userid
                                    AND qa.state IN ('finished', 'abandoned')
                               WHERE gi.courseid = :tcourseid3
                                 AND gi.itemtype = 'mod'
                                 AND gi.itemmodule = 'quiz'
                                 AND gg.finalgrade IS NOT NULL
                                 AND gg.timemodified > qa.timefinish";
        $turnaroundsql = "SELECT AVG(gap) AS avgturnaround, COUNT(*) AS graded
                            FROM (" . implode(' UNION ALL ', $turnaroundparts) . ") gaps
                           WHERE gap > 0";
        $turnaroundrow = $DB->get_record_sql(
            $turnaroundsql,
            [
                'tcourseid1' => $this->courseid,
                'tcourseid2' => $this->courseid,
                'tcourseid3' => $this->courseid,
            ]
        );
        $avgturnaroundsecs = $turnaroundrow ? (float)$turnaroundrow->avgturnaround : 0;
        $avgturnarounddays = $avgturnaroundsecs > 0 ? round($avgturnaroundsecs / DAYSECS, 1) : 0;
        $totalgraded = $turnaroundrow ? (int)$turnaroundrow->graded : 0;

        // 2. Feedback coverage — proportion of graded (item, student) pairs
        // that received any form of teacher feedback. Numerator and denominator
        // must use the same unit of work, so both are built as a UNION over the
        // same module sources and deduped by (modname, instanceid, userid). UNION
        // (not UNION ALL) collapses the same pair appearing in multiple feedback
        // tables — without dedup, a graded item with both assignfeedback_comments
        // and UG scomm would count twice and the ratio could exceed 100%.

        // Denominator: every graded (item, student) pair on a module type where
        // teacher feedback is normally expected. Auto-graded modules (lti, scorm,
        // etc.) are intentionally excluded so the metric doesn't punish courses
        // that use them for skill practice alongside graded forums/assigns.
        $relevantmods = self::get_feedback_relevant_modnames();
        [$frminsql, $frmparams] = $DB->get_in_or_equal($relevantmods, SQL_PARAMS_NAMED, 'frm');
        $gradedparts = [];
        $gradedparts[] = "SELECT 'assign' AS modname, ag.assignment AS instanceid, ag.userid
                            FROM {assign_grades} ag
                            JOIN {assign} a ON a.id = ag.assignment
                           WHERE a.course = :gcid1 AND ag.grade >= 0";
        $gradedparts[] = "SELECT gi.itemmodule AS modname, gi.iteminstance AS instanceid, gg.userid
                            FROM {grade_grades} gg
                            JOIN {grade_items} gi ON gi.id = gg.itemid
                           WHERE gi.courseid = :gcid2
                             AND gi.itemtype = 'mod'
                             AND gi.itemmodule != 'assign'
                             AND gi.itemmodule $frminsql
                             AND gg.finalgrade IS NOT NULL";
        $gradedsql = "SELECT COUNT(*) AS cnt FROM (" . implode(' UNION ', $gradedparts) . ") graded";
        $gradedparams = array_merge(
            ['gcid1' => $this->courseid, 'gcid2' => $this->courseid],
            $frmparams
        );
        $totalgradedpairs = (int)$DB->get_field_sql($gradedsql, $gradedparams);

        /*
         * Numerator: every (item, student) pair where any feedback signal exists.
         * Signals — every channel a teacher might use:
         *   assign  → assignfeedback_comments (text),
         *             assignfeedback_editpdf_cmnt (PDF annotations),
         *             assignfeedback_file (file feedback — audio/video/Loom uploads),
         *   any mod → grade_grades.feedback (gradebook overall feedback),
         *   any mod → local_unifiedgrader_scomm (UG submission comments).
         * UNION (distinct) collapses pairs appearing in multiple signal sources.
         */
        $commentparts = [];
        $commentparts[] = "SELECT 'assign' AS modname, ag.assignment AS instanceid, ag.userid
                            FROM {assignfeedback_comments} fc
                            JOIN {assign_grades} ag ON ag.id = fc.grade
                            JOIN {assign} a ON a.id = ag.assignment
                           WHERE a.course = :ccid1
                             AND ag.grade >= 0
                             AND fc.commenttext IS NOT NULL
                             AND fc.commenttext != ''";
        $dbman = $DB->get_manager();
        $cparams = ['ccid1' => $this->courseid];
        if ($dbman->table_exists('assignfeedback_editpdf_cmnt')) {
            $commentparts[] = "SELECT 'assign' AS modname, ag.assignment AS instanceid, ag.userid
                                FROM {assignfeedback_editpdf_cmnt} pc
                                JOIN {assign_grades} ag ON ag.id = pc.gradeid
                                JOIN {assign} a ON a.id = ag.assignment
                               WHERE a.course = :ccid_pdf
                                 AND ag.grade >= 0
                                 AND pc.draft = 0";
            $cparams['ccid_pdf'] = $this->courseid;
        }
        if ($dbman->table_exists('assignfeedback_file')) {
            $commentparts[] = "SELECT 'assign' AS modname, ag.assignment AS instanceid, ag.userid
                                FROM {assignfeedback_file} ff
                                JOIN {assign_grades} ag ON ag.id = ff.grade
                                JOIN {assign} a ON a.id = ag.assignment
                               WHERE a.course = :ccid_file
                                 AND ag.grade >= 0
                                 AND ff.numfiles > 0";
            $cparams['ccid_file'] = $this->courseid;
        }
        // Gradebook overall-feedback signals via grade_grades.feedback for relevant
        // modules (including assign — UG and the gradebook editor write here too;
        // an assign item with grade_grades feedback but no assignfeedback_comments
        // row would otherwise be missed).
        [$frminsql2, $frmparams2] = $DB->get_in_or_equal($relevantmods, SQL_PARAMS_NAMED, 'frm2');
        $commentparts[] = "SELECT gi.itemmodule AS modname, gi.iteminstance AS instanceid, gg.userid
                            FROM {grade_grades} gg
                            JOIN {grade_items} gi ON gi.id = gg.itemid
                           WHERE gi.courseid = :ccid2
                             AND gi.itemtype = 'mod'
                             AND gi.itemmodule $frminsql2
                             AND gg.feedback IS NOT NULL
                             AND gg.feedback != ''";
        $cparams['ccid2'] = $this->courseid;
        $cparams = array_merge($cparams, $frmparams2);
        if ($dbman->table_exists('local_unifiedgrader_scomm')) {
            [$frminsql3, $frmparams3] = $DB->get_in_or_equal($relevantmods, SQL_PARAMS_NAMED, 'frm3');
            $commentparts[] = "SELECT m.name AS modname, cm.instance AS instanceid, ugs.userid
                                FROM {local_unifiedgrader_scomm} ugs
                                JOIN {course_modules} cm ON cm.id = ugs.cmid
                                JOIN {modules} m ON m.id = cm.module
                               WHERE cm.course = :ccid3
                                 AND m.name $frminsql3
                                 AND ugs.content IS NOT NULL
                                 AND ugs.content != ''";
            $cparams['ccid3'] = $this->courseid;
            $cparams = array_merge($cparams, $frmparams3);
        }
        $commentsql = "SELECT COUNT(*) AS commented FROM (" . implode(' UNION ', $commentparts) . ") allfb";
        $commentrow = $DB->get_record_sql($commentsql, $cparams);
        $commented = $commentrow ? (int)$commentrow->commented : 0;
        // Cap at 100% defensively in case a feedback record exists for a student
        // who has no grade row yet (e.g. teacher commented before entering a grade).
        $commentpct = $totalgradedpairs > 0
            ? min(100, round(($commented / $totalgradedpairs) * 100))
            : 0;

        // 3. Ungraded submissions — submitted but awaiting grades.
        $ungradedsql = "SELECT COUNT(asub.id) AS ungraded
                          FROM {assign_submission} asub
                          JOIN {assign} a ON a.id = asub.assignment
                     LEFT JOIN {assign_grades} ag
                               ON ag.assignment = asub.assignment
                              AND ag.userid = asub.userid
                              AND ag.grade >= 0
                         WHERE a.course = :courseid
                           AND asub.status = 'submitted'
                           AND asub.latest = 1
                           AND ag.id IS NULL";
        $ungradedrow = $DB->get_record_sql(
            $ungradedsql,
            ['courseid' => $this->courseid]
        );
        $ungradedcount = $ungradedrow ? (int)$ungradedrow->ungraded : 0;

        // Discussion reading — silent learners (Macfadyen & Dawson, 2012: r=.95).
        // Distinguish students who read discussions but don't post from those who are truly disengaged.
        [$insqldv, $inparamsdv] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'dv');
        $discussionviewsql = "SELECT l.userid, COUNT(DISTINCT l.contextinstanceid) AS viewed
                                FROM {logstore_standard_log} l
                               WHERE l.userid $insqldv AND l.courseid = :courseid
                                 AND l.component = 'mod_forum'
                                 AND l.action = 'viewed'
                                 AND l.target = 'discussion'
                            GROUP BY l.userid";
        $discussionviews = $DB->get_records_sql(
            $discussionviewsql,
            array_merge($inparamsdv, ['courseid' => $this->courseid])
        );

        // 3. Classify each student and build presence summaries.
        $splevels = ['none' => 0, 'emerging' => 0, 'developing' => 0, 'established' => 0, 'exemplary' => 0];
        $cplevels = ['none' => 0, 'emerging' => 0, 'developing' => 0, 'established' => 0, 'exemplary' => 0];
        $tplevels = ['none' => 0, 'emerging' => 0, 'developing' => 0, 'established' => 0, 'exemplary' => 0];

        $atrisk = [];    // Students with 2+ risk flags.
        $stale = [];     // Students inactive 14+ days on forums.
        $silentlearners = 0;  // Students who read but don't post.
        $lowengagement = 0;
        $lowfeedback = 0;
        $lowisolation = 0;
        $belowpass = 0;

        // Per-user engagement data for scatter plot and sociogram.
        $userratedata = [];

        // Per-card student detail lists for "read more" modals.
        $isolatedstudents = [];
        $lowengagementstudents = [];
        $lowfeedbackstudents = [];
        $belowpassstudents = [];

        // Build a single map of (userid → comma-separated group names) for the cohort,
        // so the at-risk table can show which tutorial/marking group each student belongs to.
        $usergroupnames = $this->get_cohort_group_names($userids);

        // Missed-deadline + extension counts per student.
        $misseddata = $this->get_cohort_missed_deadlines($userids);
        $missedstudents = []; // For the cohort diagnostic card.
        $extensionstudents = [];

        foreach ($userids as $uid) {
            $riskflags = 0;
            $flags = [];

            // Social Presence — multi-signal composite with group-aware forum metrics.
            $threads = isset($participations[$uid]) ? (int)$participations[$uid]->threads : 0;
            $postcount = isset($participations[$uid]) ? (int)$participations[$uid]->posts : 0;
            $myvisible = $visiblediscussions[$uid] ?? $totaldiscussions;

            // Forum breadth: fraction of VISIBLE discussions participated in.
            $breadthrate = $myvisible > 0
                ? min(100, round(($threads / $myvisible) * 200))
                : ($threads > 0 ? 50 : 0);
            // Forum volume: post count relative to cohort average.
            $volumerate = $avgposts > 0
                ? min(100, round(($postcount / $avgposts) * 50))
                : ($postcount > 0 ? 50 : 0);
            // Forum composite (breadth + volume).
            $forumrate = round($breadthrate * 0.6 + $volumerate * 0.4);

            // BBB attendance rate.
            $bbbrate = 0;
            if ($totalbbbsessions > 0) {
                $mysessions = $bbbattendance[$uid] ?? 0;
                $bbbrate = min(100, round(($mysessions / $totalbbbsessions) * 100));
            }

            // Collaborative activity rate (relative to cohort).
            $mycollabs = isset($collabcounts[$uid]) ? (int)$collabcounts[$uid]->activities : 0;
            $avgcollabs = !empty($collabcounts) ? array_sum(array_map(function ($c) {
                return (int)$c->activities;
            }, $collabcounts)) / count($collabcounts) : 0;
            $collabrate = $avgcollabs > 0
                ? min(100, round(($mycollabs / $avgcollabs) * 50))
                : ($mycollabs > 0 ? 50 : 0);

            // Peer messaging rate (relative to cohort).
            $mymsgs = $peermessages[$uid] ?? 0;
            $msgrate = $avgmessages > 0
                ? min(100, round(($mymsgs / $avgmessages) * 50))
                : ($mymsgs > 0 ? 50 : 0);

            // Weighted composite: forum 50%, BBB 20%, collaborative 15%, messaging 15%.
            // If BBB is not installed, redistribute weight to forum.
            if ($totalbbbsessions > 0) {
                $sprate = round($forumrate * 0.50 + $bbbrate * 0.20 + $collabrate * 0.15 + $msgrate * 0.15);
            } else {
                $sprate = round($forumrate * 0.65 + $collabrate * 0.20 + $msgrate * 0.15);
            }
            $splevel = $this->get_coi_level($sprate, $spthresholds);
            $splevels[$splevel['class']]++;
            // Discussion reading — silent learner detection.
            $dvcount = isset($discussionviews[$uid]) ? (int)$discussionviews[$uid]->viewed : 0;
            $issilent = ($splevel['level'] <= 1 && $dvcount >= 3);

            if ($splevel['level'] <= 1) {
                $riskflags++;
                $lowisolation++;
                if ($issilent) {
                    $silentlearners++;
                    $flags[] = get_string('cohort_flag_silent', $component);
                } else {
                    $flags[] = get_string('cohort_flag_sp', $component);
                }
                $isolatedstudents[] = [
                    'userid' => $uid,
                    'fullname' => fullname($enrolledusers[$uid]),
                    'metric' => $postcount . ' posts in ' . $threads . '/' . $myvisible
                        . ' discussions (' . $sprate . '%)'
                        . ($issilent ? ' · ' . get_string('cohort_silent_label', $component, $dvcount) : ''),
                    'viewurl' => (new \moodle_url('/grade/report/coifish/index.php', [
                        'id' => $this->courseid, 'userid' => $uid, 'view' => 'insights',
                    ]))->out(false),
                ];
            }

            // Social stale check.
            $lastpost = isset($lastposts[$uid]) ? (int)$lastposts[$uid]->lastpost : 0;
            $effectivenow = $this->effective_now();
            $isstale = ($threads > 0 && $lastpost > 0 && ($effectivenow - $lastpost) >= $staledays * 86400);
            if ($isstale) {
                $daysinactive = round(($effectivenow - $lastpost) / 86400);
                $stale[] = [
                    'userid' => $uid,
                    'fullname' => fullname($enrolledusers[$uid]),
                    'days' => $daysinactive,
                    'viewurl' => (new \moodle_url('/grade/report/coifish/index.php', [
                        'id' => $this->courseid, 'userid' => $uid, 'view' => 'insights',
                    ]))->out(false),
                ];
                $riskflags++;
                $flags[] = get_string('cohort_flag_stale', $component, $daysinactive);
            }

            // Cognitive Presence — engagement rate.
            $engaged = isset($engagements[$uid]) ? (int)$engagements[$uid]->engaged : 0;
            $cprate = $totalactivities > 0 ? round(($engaged / $totalactivities) * 100) : ($engaged > 0 ? 50 : 0);
            $cplevel = $this->get_coi_level($cprate, $cpthresholds);
            $cplevels[$cplevel['class']]++;
            if ($cplevel['level'] <= 1) {
                $riskflags++;
                $lowengagement++;
                $flags[] = get_string('cohort_flag_cp', $component);
                $lowengagementstudents[] = [
                    'userid' => $uid,
                    'fullname' => fullname($enrolledusers[$uid]),
                    'metric' => $engaged . ' / ' . $totalactivities . ' (' . $cprate . '%)',
                    'viewurl' => (new \moodle_url('/grade/report/coifish/index.php', [
                        'id' => $this->courseid, 'userid' => $uid, 'view' => 'insights',
                    ]))->out(false),
                ];
            }

            // Teaching Presence — feedback review rate.
            $fbtotal = isset($feedbacktotals[$uid]) ? (int)$feedbacktotals[$uid]->total : 0;
            $fbviewed = isset($feedbackviews[$uid]) ? (int)$feedbackviews[$uid]->viewed : 0;
            $fbrate = $fbtotal > 0 ? round(($fbviewed / $fbtotal) * 100) : 0;
            $tplevel = $this->get_coi_level($fbrate, $tpthresholds);
            $tplevels[$tplevel['class']]++;
            if ($fbtotal > 0 && $tplevel['level'] <= 1) {
                $riskflags++;
                $lowfeedback++;
                $flags[] = get_string('cohort_flag_tp', $component);
                $lowfeedbackstudents[] = [
                    'userid' => $uid,
                    'fullname' => fullname($enrolledusers[$uid]),
                    'metric' => $fbviewed . ' / ' . $fbtotal . ' (' . $fbrate . '%)',
                    'viewurl' => (new \moodle_url('/grade/report/coifish/index.php', [
                        'id' => $this->courseid, 'userid' => $uid, 'view' => 'insights',
                    ]))->out(false),
                ];
            }

            // Below pass mark.
            $pct = $percentages[$uid];
            if ($pct !== null && $pct < $passthreshold) {
                $belowpass++;
                $riskflags++;
                $flags[] = get_string('cohort_flag_failing', $component);
                $belowpassstudents[] = [
                    'userid' => $uid,
                    'fullname' => fullname($enrolledusers[$uid]),
                    'metric' => $pct . '%',
                    'viewurl' => (new \moodle_url('/grade/report/coifish/index.php', [
                        'id' => $this->courseid, 'userid' => $uid, 'view' => 'insights',
                    ]))->out(false),
                ];
            }

            // Missed deadlines — strong risk signal.
            $missedcount = (int)($misseddata[$uid]['missed'] ?? 0);
            $extensionscount = (int)($misseddata[$uid]['extensions'] ?? 0);
            if ($missedcount >= 1) {
                $riskflags++;
                $flags[] = get_string('cohort_flag_missed', $component, $missedcount);
                $missedstudents[] = [
                    'userid' => $uid,
                    'fullname' => fullname($enrolledusers[$uid]),
                    'metric' => $missedcount,
                    'missedlist' => $misseddata[$uid]['missedlist'] ?? [],
                    'viewurl' => (new \moodle_url('/grade/report/coifish/index.php', [
                        'id' => $this->courseid, 'userid' => $uid, 'view' => 'insights',
                    ]))->out(false),
                ];
            }
            // Frequent extensions — secondary risk signal (doesn't push to at-risk on its own,
            // but contributes a flag when chronic).
            if ($extensionscount >= (int)$triggers['extensions_count']) {
                $riskflags++;
                $flags[] = get_string('cohort_flag_extensions', $component, $extensionscount);
                $extensionstudents[] = [
                    'userid' => $uid,
                    'fullname' => fullname($enrolledusers[$uid]),
                    'metric' => $extensionscount,
                    'viewurl' => (new \moodle_url('/grade/report/coifish/index.php', [
                        'id' => $this->courseid, 'userid' => $uid, 'view' => 'insights',
                    ]))->out(false),
                ];
            }

            // Store per-user rates for scatter plot.
            $userratedata[$uid] = [
                'sprate' => $sprate,
                'cprate' => $cprate,
                'fbrate' => $fbrate,
                'posts' => $postcount,
            ];

            // Collect at-risk students (2+ flags).
            if ($riskflags >= 2) {
                $groupnames = $usergroupnames[$uid] ?? '';
                $atrisk[] = [
                    'userid' => $uid,
                    'fullname' => fullname($enrolledusers[$uid]),
                    'riskflags' => $riskflags,
                    'percentage' => $pct !== null ? $pct . '%' : '–',
                    // Numeric sort values so the sortable-table JS doesn't have to parse cell text.
                    'percentage_raw' => $pct !== null ? (float)$pct : -1,
                    'splevel' => $splevel['label'],
                    'spclass' => $splevel['class'],
                    'splevel_raw' => (int)$splevel['level'],
                    'cplevel' => $cplevel['label'],
                    'cpclass' => $cplevel['class'],
                    'cplevel_raw' => (int)$cplevel['level'],
                    'tplevel' => $tplevel['label'],
                    'tpclass' => $tplevel['class'],
                    'tplevel_raw' => (int)$tplevel['level'],
                    'flaglist' => implode(', ', $flags),
                    'groupnames' => $groupnames,
                    'missedcount' => $missedcount,
                    'extensionscount' => $extensionscount,
                    'viewurl' => (new \moodle_url('/grade/report/coifish/index.php', [
                        'id' => $this->courseid, 'userid' => $uid, 'view' => 'insights',
                    ]))->out(false),
                ];
            }
        }

        // Sort at-risk by risk flag count descending.
        usort($atrisk, function ($a, $b) {
            return $b['riskflags'] - $a['riskflags'];
        });

        // Cap the rendered at-risk list so a very large cohort doesn't push
        // hundreds of rows into a single HTML page. The list is sorted
        // most-at-risk-first, so the cap keeps the rows that matter most; the
        // template shows a "top N of M" note when truncated. The true total is
        // preserved for the KPI count below.
        $atrisktotal = count($atrisk);
        if ($atrisktotal > self::ATRISK_RENDER_CAP) {
            $atrisk = array_slice($atrisk, 0, self::ATRISK_RENDER_CAP);
        }
        $atriskcapped = $atrisktotal > count($atrisk);

        // 4. Build presence breakdown for template.
        $presencelevels = ['none', 'emerging', 'developing', 'established', 'exemplary'];
        $presencelabels = [];
        $presenceshort = [];
        foreach ($presencelevels as $lv) {
            $presencelabels[$lv] = get_string('coi_level_' . $lv, $component);
            $presenceshort[$lv] = get_string('coi_level_short_' . $lv, $component);
        }

        $buildpresence = function (
            array $counts,
            string $title
        ) use (
            $usercount,
            $presencelabels,
            $presenceshort,
            $presencelevels
        ) {
            $maxcount = max(1, max($counts));
            $bars = [];
            foreach ($presencelevels as $lv) {
                $bars[] = [
                    'level' => $lv,
                    'label' => $presencelabels[$lv],
                    'shortlabel' => $presenceshort[$lv],
                    'count' => $counts[$lv],
                    'height' => round(($counts[$lv] / $maxcount) * 100),
                    'hascount' => $counts[$lv] > 0,
                    'percentage' => $usercount > 0 ? round(($counts[$lv] / $usercount) * 100) : 0,
                ];
            }
            // Overall health: proportion at developing or above.
            $healthy = $counts['developing'] + $counts['established'] + $counts['exemplary'];
            $healthypct = $usercount > 0 ? round(($healthy / $usercount) * 100) : 0;
            return [
                'title' => $title,
                'bars' => $bars,
                'healthypct' => $healthypct,
                'ishealthy' => $healthypct >= 60,
                'isconcern' => $healthypct < 40,
            ];
        };

        $presence = [
            'social' => $buildpresence($splevels, get_string('cohort_sp_title', $component)),
            'cognitive' => $buildpresence($cplevels, get_string('cohort_cp_title', $component)),
            'teaching' => $buildpresence($tplevels, get_string('cohort_tp_title', $component)),
        ];

        // Enrich teaching presence with instructor-side metrics.
        $turnaroundrating = 'good';
        if ($avgturnarounddays > 7) {
            $turnaroundrating = 'concern';
        } else if ($avgturnarounddays > 3) {
            $turnaroundrating = 'moderate';
        }
        $commentrating = 'good';
        if ($commentpct < 30) {
            $commentrating = 'concern';
        } else if ($commentpct < 60) {
            $commentrating = 'moderate';
        }
        $ungradedrating = 'good';
        if ($ungradedcount > 10) {
            $ungradedrating = 'concern';
        } else if ($ungradedcount > 0) {
            $ungradedrating = 'moderate';
        }
        $presence['teaching']['subtitle'] = get_string(
            'cohort_tp_subtitle',
            $component
        );
        $presence['teaching']['hasteachermetrics'] = true;
        $presence['teaching']['teachermetrics'] = [
            [
                'label' => get_string('cohort_tp_turnaround', $component),
                'value' => $avgturnarounddays . ' ' . get_string('cohort_tp_days', $component),
                'rating' => $turnaroundrating,
                'isgood' => $turnaroundrating === 'good',
                'ismoderate' => $turnaroundrating === 'moderate',
                'isconcern' => $turnaroundrating === 'concern',
                'tooltip' => get_string('cohort_tp_turnaround_tip', $component),
            ],
            [
                'label' => get_string('cohort_tp_comments', $component),
                'value' => $commentpct . '%',
                'rating' => $commentrating,
                'isgood' => $commentrating === 'good',
                'ismoderate' => $commentrating === 'moderate',
                'isconcern' => $commentrating === 'concern',
                'tooltip' => get_string('cohort_tp_comments_tip', $component),
            ],
            [
                'label' => get_string('cohort_tp_ungraded', $component),
                'value' => (string)$ungradedcount,
                'rating' => $ungradedrating,
                'isgood' => $ungradedrating === 'good',
                'ismoderate' => $ungradedrating === 'moderate',
                'isconcern' => $ungradedrating === 'concern',
                'tooltip' => get_string('cohort_tp_ungraded_tip', $component),
            ],
        ];

        // 5. Diagnostic cards.
        // Helper: build a detail block for "read more" modals.
        $cardindex = 0;
        $builddetail = function (
            array $metrics,
            array $thresholds,
            array $students,
            string $methodologykey,
            string $rationalekey
        ) use (
            $component,
            &$cardindex
        ) {
            $cardindex++;
            // Build JSON for intervention modal student selection. Emit raw JSON
            // here and let the Mustache {{studentsjson}} tag perform the single
            // HTML-attribute escaping. Pre-escaping in PHP as well caused a
            // double-escape: the doubly-encoded value reached the browser as
            // malformed JSON, JSON.parse() threw, and the modal silently fell
            // back to "all enrolled students" — sending targeted messages to the
            // whole class.
            $sjson = [];
            foreach ($students as $s) {
                if (!empty($s['userid'])) {
                    $sjson[] = ['id' => (int)$s['userid'], 'name' => $s['fullname'] ?? ''];
                }
            }
            return [
                'cardid' => 'card' . $cardindex,
                'courseid' => $this->courseid,
                'metrics' => $metrics,
                'hasmetrics' => !empty($metrics),
                'thresholds' => $thresholds,
                'hasthresholds' => !empty($thresholds),
                'students' => $students,
                'hasstudents' => !empty($students),
                'studentsjson' => json_encode($sjson),
                'methodology' => get_string($methodologykey, $component),
                'rationale' => get_string($rationalekey, $component),
            ];
        };

        $cards = [];

        // Low social presence.
        $isolationpct = $usercount > 0 ? round(($lowisolation / $usercount) * 100) : 0;
        $trulydisengaged = $lowisolation - $silentlearners;
        if ($isolationpct >= $triggers['isolation']) {
            $isolationmetrics = [
                ['label' => get_string('detail_metric_totaldiscussions', $component), 'value' => (string)$totaldiscussions],
                ['label' => get_string('detail_metric_cohortsize', $component), 'value' => (string)$usercount],
                [
                    'label' => get_string('detail_metric_affected', $component),
                    'value' => $lowisolation . ' (' . $isolationpct . '%)',
                ],
                [
                    'label' => get_string('detail_metric_studentheading', $component),
                    'value' => get_string('detail_metric_sp_studentcol', $component),
                ],
            ];
            if ($silentlearners > 0) {
                $isolationmetrics[] = ['label' => get_string('detail_metric_silentlearners', $component),
                    'value' => $silentlearners . ' (' . get_string('detail_metric_silentlearners_desc', $component) . ')'];
                $isolationmetrics[] = ['label' => get_string('detail_metric_trulydisengaged', $component),
                    'value' => (string)$trulydisengaged];
            }
            $detail = $builddetail(
                $isolationmetrics,
                [
                    [
                        'label' => get_string('detail_threshold_trigger', $component),
                        'value' => get_string('detail_threshold_isolation_trigger', $component),
                    ],
                    [
                        'label' => get_string('detail_threshold_levels', $component),
                        'value' => get_string('detail_threshold_isolation_levels', $component),
                    ],
                    [
                        'label' => get_string('detail_threshold_escalation', $component),
                        'value' => get_string('detail_threshold_isolation_escalation', $component),
                    ],
                ],
                $isolatedstudents,
                'detail_method_isolation',
                'detail_rationale_isolation'
            );
            $isolationdiag = get_string('cohort_card_isolation_diagnostic', $component, (object)[
                'percent' => $isolationpct, 'count' => $lowisolation,
                'total' => $totaldiscussions, 'threshold' => $triggers['isolation'],
            ]);
            if ($silentlearners > 0) {
                $isolationdiag .= ' ' . get_string('cohort_card_isolation_silent', $component, (object)[
                    'silent' => $silentlearners, 'disengaged' => $trulydisengaged,
                ]);
            }
            $cards[] = array_merge([
                'icon' => 'users',
                'diagnostictype' => 'cohort_isolation',
                'severity' => $isolationpct >= 50 ? 'danger' : 'warning',
                'title' => get_string('cohort_card_isolation_title', $component),
                'diagnostic' => $isolationdiag,
                'action' => get_string('cohort_card_isolation_action', $component, (object)[
                    'count' => $lowisolation,
                ]),
            ], $detail);
        }

        // Low engagement.
        $engagementpct = $usercount > 0 ? round(($lowengagement / $usercount) * 100) : 0;
        if ($engagementpct >= $triggers['engagement']) {
            $detail = $builddetail(
                [
                    ['label' => get_string('detail_metric_totalactivities', $component), 'value' => (string)$totalactivities],
                    ['label' => get_string('detail_metric_cohortsize', $component), 'value' => (string)$usercount],
                    [
                        'label' => get_string('detail_metric_affected', $component),
                        'value' => $lowengagement . ' (' . $engagementpct . '%)',
                    ],
                    [
                        'label' => get_string('detail_metric_activitytypes', $component),
                        'value' => get_string('detail_metric_activitylist', $component),
                    ],
                ],
                [
                    [
                        'label' => get_string('detail_threshold_trigger', $component),
                        'value' => get_string('detail_threshold_engagement_trigger', $component),
                    ],
                    [
                        'label' => get_string('detail_threshold_levels', $component),
                        'value' => get_string('detail_threshold_engagement_levels', $component),
                    ],
                    [
                        'label' => get_string('detail_threshold_escalation', $component),
                        'value' => get_string('detail_threshold_engagement_escalation', $component),
                    ],
                ],
                $lowengagementstudents,
                'detail_method_engagement',
                'detail_rationale_engagement'
            );
            $cards[] = array_merge([
                'icon' => 'book',
                'diagnostictype' => 'cohort_engagement',
                'severity' => $engagementpct >= 50 ? 'danger' : 'warning',
                'title' => get_string('cohort_card_engagement_title', $component),
                'diagnostic' => get_string('cohort_card_engagement_diagnostic', $component, (object)[
                    'percent' => $engagementpct, 'count' => $lowengagement,
                    'activities' => $totalactivities, 'threshold' => $triggers['engagement'],
                ]),
                'action' => get_string('cohort_card_engagement_action', $component, (object)[
                    'count' => $lowengagement, 'activities' => $totalactivities,
                ]),
            ], $detail);
        }

        // Low feedback review.
        $feedbackpct = $usercount > 0 ? round(($lowfeedback / $usercount) * 100) : 0;
        if ($feedbackpct >= $triggers['feedback']) {
            $detail = $builddetail(
                [
                    ['label' => get_string('detail_metric_cohortsize', $component), 'value' => (string)$usercount],
                    [
                        'label' => get_string('detail_metric_affected', $component),
                        'value' => $lowfeedback . ' (' . $feedbackpct . '%)',
                    ],
                ],
                [
                    [
                        'label' => get_string('detail_threshold_trigger', $component),
                        'value' => get_string('detail_threshold_feedback_trigger', $component),
                    ],
                    [
                        'label' => get_string('detail_threshold_levels', $component),
                        'value' => get_string('detail_threshold_feedback_levels', $component),
                    ],
                    [
                        'label' => get_string('detail_threshold_escalation', $component),
                        'value' => get_string('detail_threshold_feedback_escalation', $component),
                    ],
                ],
                $lowfeedbackstudents,
                'detail_method_feedback',
                'detail_rationale_feedback'
            );
            $cards[] = array_merge([
                'icon' => 'comment-o',
                'diagnostictype' => 'cohort_feedback',
                'severity' => $feedbackpct >= 50 ? 'danger' : 'warning',
                'title' => get_string('cohort_card_feedback_title', $component),
                'diagnostic' => get_string('cohort_card_feedback_diagnostic', $component, (object)[
                    'percent' => $feedbackpct, 'count' => $lowfeedback,
                    'threshold' => $triggers['feedback'],
                ]),
                'action' => get_string('cohort_card_feedback_action', $component, $lowfeedback),
            ], $detail);
        }

        // Stale students.
        $stalecount = count($stale);
        if (
            $stalecount >= $triggers['stale_count']
            || ($usercount > 0 && ($stalecount / $usercount * 100) >= $triggers['stale_pct'])
        ) {
            // Build a short name list for the action (up to 3 names).
            $stalenames = array_column(array_slice($stale, 0, 3), 'fullname');
            $stalenamelist = implode(', ', $stalenames);
            if ($stalecount > 3) {
                $stalenamelist .= ' ' . get_string('cohort_and_others', $component, $stalecount - 3);
            }
            $avgstaledays = $stalecount > 0
                ? round(array_sum(array_column($stale, 'days')) / $stalecount) : 0;
            // Build stale student detail list with days as metric.
            $stalestudentdetail = [];
            foreach ($stale as $s) {
                $stalestudentdetail[] = [
                    'userid' => $s['userid'],
                    'fullname' => $s['fullname'],
                    'metric' => $s['days'] . ' ' . get_string('detail_metric_daysinactive', $component),
                    'viewurl' => $s['viewurl'],
                ];
            }
            $detail = $builddetail(
                [
                    ['label' => get_string('detail_metric_cohortsize', $component), 'value' => (string)$usercount],
                    ['label' => get_string('detail_metric_affected', $component), 'value' => (string)$stalecount],
                    [
                        'label' => get_string('detail_metric_avgdays', $component),
                        'value' => $avgstaledays . ' ' . get_string('detail_metric_days', $component),
                    ],
                ],
                [
                    [
                        'label' => get_string('detail_threshold_trigger', $component),
                        'value' => get_string('detail_threshold_stale_trigger', $component),
                    ],
                    [
                        'label' => get_string('detail_threshold_window', $component),
                        'value' => get_string('detail_threshold_stale_window', $component),
                    ],
                    [
                        'label' => get_string('detail_threshold_escalation', $component),
                        'value' => get_string('detail_threshold_stale_escalation', $component),
                    ],
                ],
                $stalestudentdetail,
                'detail_method_stale',
                'detail_rationale_stale'
            );
            $cards[] = array_merge([
                'icon' => 'clock-o',
                'diagnostictype' => 'cohort_stale',
                'severity' => $stalecount / max(1, $usercount) >= 0.3 ? 'danger' : 'warning',
                'title' => get_string('cohort_card_stale_title', $component),
                'diagnostic' => get_string('cohort_card_stale_diagnostic', $component, (object)[
                    'count' => $stalecount, 'avgdays' => $avgstaledays,
                    'stalewindow' => $staledays, 'threshold' => $triggers['stale_count'],
                    'thresholdpct' => $triggers['stale_pct'],
                ]),
                'action' => get_string('cohort_card_stale_action', $component, (object)[
                    'names' => $stalenamelist, 'count' => $stalecount,
                ]),
            ], $detail);
        }

        // High fail rate.
        $failpct = $usercount > 0 ? round(($belowpass / $usercount) * 100) : 0;
        if ($belowpass > 0 && $failpct >= $triggers['failing']) {
            $detail = $builddetail(
                [
                    ['label' => get_string('detail_metric_cohortsize', $component), 'value' => (string)$usercount],
                    ['label' => get_string('detail_metric_affected', $component), 'value' => $belowpass . ' (' . $failpct . '%)'],
                    ['label' => get_string('detail_metric_classavg', $component), 'value' => ($classaverage ?? 0) . '%'],
                    ['label' => get_string('detail_metric_classmedian', $component), 'value' => ($classmedian ?? 0) . '%'],
                ],
                [
                    [
                        'label' => get_string('detail_threshold_trigger', $component),
                        'value' => get_string('detail_threshold_failing_trigger', $component),
                    ],
                    ['label' => get_string('detail_threshold_passmark', $component), 'value' => $this->get_pass_threshold() . '%'],
                    [
                        'label' => get_string('detail_threshold_escalation', $component),
                        'value' => get_string('detail_threshold_failing_escalation', $component),
                    ],
                ],
                $belowpassstudents,
                'detail_method_failing',
                'detail_rationale_failing'
            );
            $cards[] = array_merge([
                'icon' => 'exclamation-triangle',
                'diagnostictype' => 'cohort_failing',
                'severity' => $failpct >= 40 ? 'danger' : 'warning',
                'title' => get_string('cohort_card_failing_title', $component),
                'diagnostic' => get_string('cohort_card_failing_diagnostic', $component, (object)[
                    'count' => $belowpass, 'percent' => $failpct,
                    'average' => $classaverage ?? 0, 'passmark' => $passthreshold,
                    'threshold' => $triggers['failing'],
                ]),
                'action' => get_string('cohort_card_failing_action', $component, $belowpass),
            ], $detail);
        }

        // Missed deadlines — overdue, unsubmitted, no override exception.
        $missedaffected = count($missedstudents);
        $missedpct = $usercount > 0 ? round(($missedaffected / $usercount) * 100) : 0;
        $missedtriggered = ($missedaffected >= (int)$triggers['missed_count'])
            || ($missedpct >= (int)$triggers['missed_pct']);
        if ($missedtriggered) {
            $missedtotal = 0;
            foreach ($missedstudents as $m) {
                $missedtotal += (int)$m['metric'];
            }
            $detail = $builddetail(
                [
                    ['label' => get_string('detail_metric_cohortsize', $component), 'value' => (string)$usercount],
                    [
                        'label' => get_string('detail_metric_affected', $component),
                        'value' => $missedaffected . ' (' . $missedpct . '%)',
                    ],
                    [
                        'label' => get_string('detail_metric_missed_total', $component),
                        'value' => (string)$missedtotal,
                    ],
                ],
                [
                    [
                        'label' => get_string('detail_threshold_trigger', $component),
                        'value' => get_string('detail_threshold_missed_trigger', $component, (object)[
                            'count' => $triggers['missed_count'], 'pct' => $triggers['missed_pct'],
                        ]),
                    ],
                ],
                $missedstudents,
                'detail_method_missed',
                'detail_rationale_missed'
            );
            $cards[] = array_merge([
                'icon' => 'calendar-times-o',
                'diagnostictype' => 'cohort_missed',
                'severity' => $missedpct >= 30 ? 'danger' : 'warning',
                'title' => get_string('cohort_card_missed_title', $component),
                'diagnostic' => get_string('cohort_card_missed_diagnostic', $component, (object)[
                    'count' => $missedaffected, 'percent' => $missedpct, 'total' => $missedtotal,
                ]),
                'action' => get_string('cohort_card_missed_action', $component, (object)[
                    'count' => $missedaffected,
                ]),
            ], $detail);
        }

        // Frequent extensions — chronic over-reliance on deadline overrides.
        $extaffected = count($extensionstudents);
        if ($extaffected >= 1) {
            $detail = $builddetail(
                [
                    ['label' => get_string('detail_metric_cohortsize', $component), 'value' => (string)$usercount],
                    [
                        'label' => get_string('detail_metric_affected', $component),
                        'value' => (string)$extaffected,
                    ],
                ],
                [
                    [
                        'label' => get_string('detail_threshold_trigger', $component),
                        'value' => get_string('detail_threshold_extensions_trigger', $component, $triggers['extensions_count']),
                    ],
                ],
                $extensionstudents,
                'detail_method_extensions',
                'detail_rationale_extensions'
            );
            $cards[] = array_merge([
                'icon' => 'clock-o',
                'diagnostictype' => 'cohort_extensions',
                'severity' => 'warning',
                'title' => get_string('cohort_card_extensions_title', $component),
                'diagnostic' => get_string('cohort_card_extensions_diagnostic', $component, (object)[
                    'count' => $extaffected, 'threshold' => $triggers['extensions_count'],
                ]),
                'action' => get_string('cohort_card_extensions_action', $component, $extaffected),
            ], $detail);
        }

        // Cross-reference: isolation + low grades — compound risk.
        $isolatedandfailing = 0;
        $compoundnames = [];
        $compoundstudents = [];
        foreach ($userids as $uid) {
            // Use the composite SP rate computed in the main loop.
            $sprate = $userratedata[$uid]['sprate'] ?? 0;
            $pct = $percentages[$uid];
            if ($sprate < $spthresholds[1] && $pct !== null && $pct < $passthreshold) {
                $isolatedandfailing++;
                if (count($compoundnames) < 3) {
                    $compoundnames[] = fullname($enrolledusers[$uid]);
                }
                $postcount = $userratedata[$uid]['posts'] ?? 0;
                $compoundstudents[] = [
                    'userid' => $uid,
                    'fullname' => fullname($enrolledusers[$uid]),
                    'metric' => $pct . '% · ' . $postcount . ' posts (SP: ' . $sprate . '%)',
                    'viewurl' => (new \moodle_url('/grade/report/coifish/index.php', [
                        'id' => $this->courseid, 'userid' => $uid, 'view' => 'insights',
                    ]))->out(false),
                ];
            }
        }
        if ($isolatedandfailing >= 2) {
            $compoundnamelist = implode(', ', $compoundnames);
            if ($isolatedandfailing > 3) {
                $compoundnamelist .= ' ' . get_string('cohort_and_others', $component, $isolatedandfailing - 3);
            }
            $detail = $builddetail(
                [
                    ['label' => get_string('detail_metric_cohortsize', $component), 'value' => (string)$usercount],
                    ['label' => get_string('detail_metric_affected', $component), 'value' => (string)$isolatedandfailing],
                ],
                [
                    [
                        'label' => get_string('detail_threshold_trigger', $component),
                        'value' => get_string('detail_threshold_compound_trigger', $component),
                    ],
                    [
                        'label' => get_string('detail_threshold_sp', $component),
                        'value' => get_string('detail_threshold_compound_sp', $component),
                    ],
                    [
                        'label' => get_string('detail_threshold_grade', $component),
                        'value' => get_string('detail_threshold_compound_grade', $component),
                    ],
                ],
                $compoundstudents,
                'detail_method_compound',
                'detail_rationale_compound'
            );
            $cards[] = array_merge([
                'icon' => 'chain-broken',
                'diagnostictype' => 'cohort_compound',
                'severity' => 'danger',
                'title' => get_string('cohort_card_compound_title', $component),
                'diagnostic' => get_string('cohort_card_compound_diagnostic', $component, (object)[
                    'count' => $isolatedandfailing, 'spthreshold' => $spthresholds[1],
                    'passmark' => $passthreshold,
                ]),
                'action' => get_string('cohort_card_compound_action', $component, (object)[
                    'names' => $compoundnamelist, 'count' => $isolatedandfailing,
                ]),
            ], $detail);
        }

        // Activity balance diagnostic (Dawson's 4-category framework).
        // Categorise cohort LMS activity into Engagement, Content, Assessment, Administration.
        [$insqlab, $inparamsab] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'ab');
        $activitybalance = $DB->get_records_sql(
            "SELECT l.component, COUNT(l.id) AS cnt
               FROM {logstore_standard_log} l
              WHERE l.courseid = :courseid
                AND l.userid $insqlab
                AND l.timecreated >= :starttime
           GROUP BY l.component",
            array_merge($inparamsab, [
                'courseid' => $this->courseid,
                'starttime' => $this->effective_now() - 30 * 86400, // Last 30 days (or pre-end-date window).
            ])
        );

        // Map components to Dawson's categories.
        $categories = ['engagement' => 0, 'content' => 0, 'assessment' => 0, 'administration' => 0];
        $engagementcomponents = ['mod_forum', 'mod_wiki', 'mod_glossary', 'mod_workshop', 'mod_data', 'mod_chat'];
        $contentcomponents = ['mod_page', 'mod_book', 'mod_resource', 'mod_url', 'mod_folder', 'mod_label', 'mod_h5pactivity'];
        $assessmentcomponents = ['mod_assign', 'mod_quiz', 'mod_lesson', 'mod_scorm', 'mod_feedback'];

        foreach ($activitybalance as $row) {
            $comp = $row->component;
            $cnt = (int)$row->cnt;
            if (in_array($comp, $engagementcomponents)) {
                $categories['engagement'] += $cnt;
            } else if (in_array($comp, $contentcomponents)) {
                $categories['content'] += $cnt;
            } else if (in_array($comp, $assessmentcomponents)) {
                $categories['assessment'] += $cnt;
            } else {
                $categories['administration'] += $cnt;
            }
        }

        $totalactions = array_sum($categories);
        if ($totalactions > 0) {
            $catpcts = [];
            foreach ($categories as $cat => $cnt) {
                $catpcts[$cat] = round(($cnt / $totalactions) * 100);
            }

            // Trigger: content >80% and engagement <5%, or engagement is 0% with content >60%.
            $isimbalanced = ($catpcts['content'] > 80 && $catpcts['engagement'] < 5)
                || ($catpcts['engagement'] === 0 && $catpcts['content'] > 60);

            if ($isimbalanced) {
                $detail = $builddetail(
                    [
                        ['label' => get_string('detail_metric_balance_engagement', $component),
                         'value' => $catpcts['engagement'] . '% (' . $categories['engagement'] . ' actions)'],
                        ['label' => get_string('detail_metric_balance_content', $component),
                         'value' => $catpcts['content'] . '% (' . $categories['content'] . ' actions)'],
                        ['label' => get_string('detail_metric_balance_assessment', $component),
                         'value' => $catpcts['assessment'] . '% (' . $categories['assessment'] . ' actions)'],
                        ['label' => get_string('detail_metric_balance_admin', $component),
                         'value' => $catpcts['administration'] . '% (' . $categories['administration'] . ' actions)'],
                        ['label' => get_string('detail_metric_balance_total', $component),
                         'value' => (string)$totalactions],
                    ],
                    [
                        ['label' => get_string('detail_threshold_trigger', $component),
                         'value' => get_string('detail_threshold_balance_trigger', $component)],
                        ['label' => get_string('detail_threshold_balance_framework', $component),
                         'value' => get_string('detail_threshold_balance_dawson', $component)],
                    ],
                    [],
                    'detail_method_balance',
                    'detail_rationale_balance'
                );
                $cards[] = array_merge([
                    'icon' => 'pie-chart',
                    'diagnostictype' => 'cohort_balance',
                    'severity' => 'warning',
                    'title' => get_string('cohort_card_balance_title', $component),
                    'diagnostic' => get_string('cohort_card_balance_diagnostic', $component, (object)[
                        'content' => $catpcts['content'], 'engagement' => $catpcts['engagement'],
                        'assessment' => $catpcts['assessment'], 'admin' => $catpcts['administration'],
                    ]),
                    'action' => get_string('cohort_card_balance_action', $component),
                ], $detail);
            }
        }

        // Quick stats.
        $stats = [];
        $stats[] = [
            'label' => get_string('cohort_stat_students', $component),
            'value' => (string)$usercount,
        ];
        if ($classaverage !== null) {
            $stats[] = [
                'label' => get_string('cohort_stat_average', $component),
                'value' => $classaverage . '%',
                'isrisk' => $classaverage < 50,
            ];
        }
        if ($classmedian !== null) {
            $stats[] = [
                'label' => get_string('cohort_stat_median', $component),
                'value' => $classmedian . '%',
            ];
        }
        if ($belowpass > 0) {
            $stats[] = [
                'label' => get_string('cohort_stat_belowpass', $component),
                'value' => (string)$belowpass,
                'isrisk' => true,
            ];
        }
        $stats[] = [
            'label' => get_string('cohort_stat_atrisk', $component),
            'value' => (string)$atrisktotal,
            'isrisk' => $atrisktotal > 0,
        ];

        // Overall risk level.
        $riskcount = count($cards);
        if ($riskcount === 0) {
            $risklevel = 'healthy';
            $risklabel = get_string('cohort_risk_healthy', $component);
        } else if ($riskcount <= 2) {
            $risklevel = 'moderate';
            $risklabel = get_string('cohort_risk_moderate', $component);
        } else {
            $risklevel = 'high';
            $risklabel = get_string('cohort_risk_high', $component);
        }

        // Course design awareness.
        // If COI social presence flags are firing but the course has few or no
        // social activity types, the issue is likely course design, not student behaviour.
        $coursedesign = $this->get_course_design_notice($totaldiscussions, $lowisolation, $usercount, $isolationpct, $triggers);

        // Risk quadrant scatter data (S3 model).
        // Engagement Index: weighted composite of SP, CP, TP rates.
        // Macfadyen & Dawson (2012): forum participation strongest predictor (r=.95).
        $scatterpoints = [];
        foreach ($userids as $uid) {
            $grade = $percentages[$uid];
            if ($grade === null) {
                continue; // Skip ungraded students.
            }
            $rates = $userratedata[$uid] ?? ['sprate' => 0, 'cprate' => 0, 'fbrate' => 0];
            $engagement = round($rates['sprate'] * 0.45 + $rates['cprate'] * 0.35 + $rates['fbrate'] * 0.20, 1);
            $scatterpoints[] = [
                'x' => $engagement,
                'y' => $grade,
                'name' => fullname($enrolledusers[$uid]),
            ];
        }
        $scatterjson = json_encode($scatterpoints);

        // Sociogram data (forum reply network).
        // Build directed edges from forum reply relationships.
        [$insqlsg, $inparamssg] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'sg');
        [$insqlsg2, $inparamssg2] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'sg2');
        $replyedges = $DB->get_records_sql(
            "SELECT CONCAT(fp.userid, '-', parent.userid) AS id,
                    fp.userid AS sourceuser,
                    parent.userid AS targetuser,
                    COUNT(fp.id) AS weight
               FROM {forum_posts} fp
               JOIN {forum_posts} parent ON parent.id = fp.parent
               JOIN {forum_discussions} fd ON fd.id = fp.discussion
              WHERE fd.course = :courseid
                AND fp.parent != 0
                AND fp.userid != parent.userid
                AND fp.userid $insqlsg
                AND parent.userid $insqlsg2
           GROUP BY fp.userid, parent.userid",
            array_merge(['courseid' => $this->courseid], $inparamssg, $inparamssg2)
        );

        // Build node data: all enrolled users with post counts.
        $sociogramnodes = [];
        foreach ($userids as $uid) {
            $posts = $userratedata[$uid]['posts'] ?? 0;
            $sociogramnodes[] = [
                'id' => (int)$uid,
                'label' => fullname($enrolledusers[$uid]),
                'grade' => $percentages[$uid],
                'posts' => $posts,
            ];
        }

        // Build edge data.
        $sociogramedges = [];
        foreach ($replyedges as $edge) {
            $sociogramedges[] = [
                'from' => (int)$edge->sourceuser,
                'to' => (int)$edge->targetuser,
                'weight' => (int)$edge->weight,
            ];
        }
        $sociogramnodesjson = json_encode($sociogramnodes);
        $sociogramedgesjson = json_encode($sociogramedges);

        // Tag each card with its template family so the intervention composer
        // can pre-fill warm student-facing copy rather than echoing the
        // teacher-facing analytics text back at the student.
        foreach ($cards as &$card) {
            $card['tplfamily'] = \gradereport_coifish\intervention_templates::family_for_diagnostic(
                $card['diagnostictype'] ?? ''
            );
        }
        unset($card);

        return [
            'hasdata' => true,
            'usercount' => $usercount,
            'presence' => $presence,
            'distribution' => $distribution,
            'classaverage' => $classaverage,
            'classmedian' => $classmedian,
            'cards' => $cards,
            'hascards' => !empty($cards),
            'nocards' => empty($cards),
            'atrisk' => $atrisk,
            'hasatrisk' => !empty($atrisk),
            'atriskcapped' => $atriskcapped,
            'atriskcapnote' => $atriskcapped
                ? get_string('cohort_atrisk_capped', $component, (object)[
                    'shown' => count($atrisk),
                    'total' => $atrisktotal,
                ])
                : '',
            // Whether ANY at-risk row has missed / extension counts >0 — used to
            // toggle these columns on/off in the at-risk table so we don't add
            // visual noise to courses where no deadlines have passed yet.
            'showmissedcolumn' => !empty(array_filter($atrisk, function ($r) {
                return ($r['missedcount'] ?? 0) > 0;
            })),
            'showextensionscolumn' => !empty(array_filter($atrisk, function ($r) {
                return ($r['extensionscount'] ?? 0) > 0;
            })),
            'stale' => $stale,
            'hasstale' => !empty($stale),
            'stats' => $stats,
            'hasstats' => !empty($stats),
            'riskcount' => $riskcount,
            'risklevel' => $risklevel,
            'risklabel' => $risklabel,
            'coursedesign' => $coursedesign,
            'hascoursedesign' => !empty($coursedesign),
            'scatterjson' => $scatterjson,
            'hasscatter' => !empty($scatterpoints),
            'engagementthreshold' => 50,
            'gradethreshold' => $passthreshold,
            'sociogramnodesjson' => $sociogramnodesjson,
            'sociogramedgesjson' => $sociogramedgesjson,
            'hassociogram' => !empty($sociogramedges),
        ];
    }

    /**
     * Compare key metrics across all course groups.
     *
     * Computes average grade, at-risk count, and COI presence health for every
     * group in the course so the teacher can spot group-level disparities.
     *
     * @return array Cross-group comparison data for template.
     */
    public function get_cross_group_data(): array {
        global $DB, $USER;

        $component = 'gradereport_coifish';
        $passthreshold = $this->get_pass_threshold();
        $spthresholds = $this->get_coi_thresholds('sp', [1, 20, 50, 80]);
        $course = get_course($this->courseid);

        // Scope to the viewer's allowed groups.
        $canviewallgroups = has_capability('gradereport/coifish:viewallgroups', $this->context);
        $teachergroups = groups_get_user_groups($this->courseid, $USER->id);
        $teachergroupids = $teachergroups[0] ?? [];
        $allgroups = groups_get_all_groups($this->courseid, 0, $course->defaultgroupingid);

        // Without the cross-group capability, restrict to the teacher's own groups.
        // With the capability, show every course group (unless the teacher belongs
        // to specific groups and has nothing else assigned).
        if (!$canviewallgroups && !empty($teachergroupids)) {
            $filteredgroups = [];
            foreach ($teachergroupids as $gid) {
                if (isset($allgroups[$gid])) {
                    $filteredgroups[$gid] = $allgroups[$gid];
                }
            }
            $allgroups = $filteredgroups;
        }

        if (count($allgroups) < 2) {
            return ['hasgroups' => false];
        }

        $grademax = (float)$this->courseitem->grademax;
        $rows = [];

        // Course-wide activity count is the same for every group; compute it once
        // here rather than re-running the same query inside the per-group loop.
        $totalactivities = (int)$DB->count_records_sql(
            "SELECT COUNT(cm.id)
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :cid AND cm.deletioninprogress = 0
                AND m.name IN ('assign', 'quiz', 'page', 'book', 'resource', 'url', 'folder')",
            ['cid' => $this->courseid]
        );

        foreach ($allgroups as $group) {
            $members = get_enrolled_users(
                $this->context,
                'moodle/course:isincompletionreports',
                $group->id,
                'u.id, u.firstname, u.lastname',
                'u.lastname, u.firstname',
                0,
                0,
                true
            );
            $uids = array_keys($members);
            if (empty($uids)) {
                continue;
            }
            $count = count($uids);
            [$insql, $inparams] = $DB->get_in_or_equal($uids, SQL_PARAMS_NAMED, 'cg');

            // Average grade.
            $grades = $DB->get_records_select(
                'grade_grades',
                "itemid = :itemid AND userid $insql",
                array_merge($inparams, ['itemid' => $this->courseitem->id]),
                '',
                'userid, finalgrade'
            );
            $pcts = [];
            $belowpass = 0;
            foreach ($uids as $uid) {
                $fg = isset($grades[$uid]) ? $grades[$uid]->finalgrade : null;
                if ($fg !== null && $grademax > 0) {
                    $pct = round(((float)$fg / $grademax) * 100, 1);
                    $pcts[] = $pct;
                    if ($pct < $passthreshold) {
                        $belowpass++;
                    }
                }
            }
            $avg = !empty($pcts) ? round(array_sum($pcts) / count($pcts), 1) : null;

            // COI presence health — multi-signal composite per student.
            $grpparticipations = $DB->get_records_sql(
                "SELECT fp.userid, COUNT(DISTINCT fd.id) AS threads, COUNT(fp.id) AS posts
                   FROM {forum_posts} fp
                   JOIN {forum_discussions} fd ON fd.id = fp.discussion
                  WHERE fd.course = :cid AND fp.userid $insql
               GROUP BY fp.userid",
                array_merge(['cid' => $this->courseid], $inparams)
            );
            $grpallposts = array_map(function ($p) {
                return (int)$p->posts;
            }, $grpparticipations);
            $grpavgposts = !empty($grpallposts) ? array_sum($grpallposts) / count($grpallposts) : 0;

            $sphealthy = 0;
            foreach ($uids as $uid) {
                $threads = isset($grpparticipations[$uid]) ? (int)$grpparticipations[$uid]->threads : 0;
                $posts = isset($grpparticipations[$uid]) ? (int)$grpparticipations[$uid]->posts : 0;
                // Use group-visible discussions if available, else all discussions.
                $myvisible = $count; // Approximate: at least group-level.
                $breadth = $myvisible > 0
                    ? min(100, round(($threads / max(1, $myvisible)) * 200))
                    : ($threads > 0 ? 50 : 0);
                $volume = $grpavgposts > 0
                    ? min(100, round(($posts / $grpavgposts) * 50))
                    : ($posts > 0 ? 50 : 0);
                $rate = round($breadth * 0.6 + $volume * 0.4);
                $level = $this->get_coi_level($rate, $spthresholds);
                if ($level['level'] >= 2) { // Developing or above.
                    $sphealthy++;
                }
            }
            $sphealthpct = $count > 0 ? round(($sphealthy / $count) * 100) : 0;

            // At-risk: count students with 2+ simple risk flags (low SP + below pass).
            $atriskcount = 0;
            foreach ($uids as $uid) {
                $flags = 0;
                $threads = isset($grpparticipations[$uid]) ? (int)$grpparticipations[$uid]->threads : 0;
                $posts = isset($grpparticipations[$uid]) ? (int)$grpparticipations[$uid]->posts : 0;
                $breadth = $count > 0 ? min(100, round(($threads / max(1, $count)) * 200)) : ($threads > 0 ? 50 : 0);
                $volume = $grpavgposts > 0 ? min(100, round(($posts / $grpavgposts) * 50)) : ($posts > 0 ? 50 : 0);
                $rate = round($breadth * 0.6 + $volume * 0.4);
                if ($rate < $spthresholds[1]) {
                    $flags++;
                }
                $fg = isset($grades[$uid]) ? $grades[$uid]->finalgrade : null;
                if ($fg !== null && $grademax > 0 && round(((float)$fg / $grademax) * 100, 1) < $passthreshold) {
                    $flags++;
                }
                if ($flags >= 2) {
                    $atriskcount++;
                }
            }

            // Cognitive Presence: engagement rate per group ($totalactivities is
            // course-wide and hoisted above the loop).
            // Per-user engagement for this group.
            [$insql2, $inparams2] = $DB->get_in_or_equal($uids, SQL_PARAMS_NAMED, 'ge');
            [$insql3, $inparams3] = $DB->get_in_or_equal($uids, SQL_PARAMS_NAMED, 'gq');
            [$insql4, $inparams4] = $DB->get_in_or_equal($uids, SQL_PARAMS_NAMED, 'gl');
            $engagements = $DB->get_records_sql(
                "SELECT sub.userid, SUM(sub.cnt) AS engaged FROM (
                    SELECT ag.userid, COUNT(ag.id) AS cnt
                      FROM {assign_grades} ag
                      JOIN {assign} a ON a.id = ag.assignment
                     WHERE a.course = :cid1 AND ag.userid $insql AND ag.grade >= 0
                  GROUP BY ag.userid
                  UNION ALL
                    SELECT qa.userid, COUNT(qa.id) AS cnt
                      FROM {quiz_attempts} qa
                      JOIN {quiz} q ON q.id = qa.quiz
                     WHERE q.course = :cid2 AND qa.userid $insql2 AND qa.state IN ('finished', 'abandoned')
                  GROUP BY qa.userid
                  UNION ALL
                    SELECT l.userid, COUNT(DISTINCT l.contextinstanceid) AS cnt
                      FROM {logstore_standard_log} l
                     WHERE l.courseid = :cid3 AND l.userid $insql3
                       AND l.action = 'viewed' AND l.target = 'course_module'
                       AND l.component IN ('mod_page', 'mod_book', 'mod_resource', 'mod_url', 'mod_folder')
                  GROUP BY l.userid
                 ) sub GROUP BY sub.userid",
                array_merge(
                    ['cid1' => $this->courseid, 'cid2' => $this->courseid, 'cid3' => $this->courseid],
                    $inparams,
                    $inparams2,
                    $inparams3
                )
            );
            $engagerates = [];
            foreach ($uids as $uid) {
                $engaged = isset($engagements[$uid]) ? (int)$engagements[$uid]->engaged : 0;
                $engagerates[] = $totalactivities > 0 ? round(($engaged / $totalactivities) * 100) : 0;
            }
            $avgengagement = !empty($engagerates) ? round(array_sum($engagerates) / count($engagerates)) : 0;

            // Teaching Presence: feedback review rate per group.
            $feedbacktotals = $DB->get_records_sql(
                "SELECT ag.userid, COUNT(ag.id) AS total
                   FROM {assign_grades} ag
                   JOIN {assign} a ON a.id = ag.assignment
                  WHERE a.course = :cid AND ag.userid $insql4 AND ag.grade >= 0
               GROUP BY ag.userid",
                array_merge(['cid' => $this->courseid], $inparams4)
            );
            [$insql5, $inparams5] = $DB->get_in_or_equal($uids, SQL_PARAMS_NAMED, 'gf');
            [$evsql, $evparams] = self::get_feedback_view_event_sql('fv5');
            $feedbackviews = $DB->get_records_sql(
                "SELECT l.userid, COUNT(DISTINCT l.contextinstanceid) AS viewed
                   FROM {logstore_standard_log} l
                  WHERE l.userid $insql5 AND l.courseid = :cid
                    AND l.eventname $evsql
               GROUP BY l.userid",
                array_merge($inparams5, ['cid' => $this->courseid], $evparams)
            );
            $fbreviewed = 0;
            $fbtotalcount = 0;
            foreach ($uids as $uid) {
                $ft = isset($feedbacktotals[$uid]) ? (int)$feedbacktotals[$uid]->total : 0;
                $fv = isset($feedbackviews[$uid]) ? (int)$feedbackviews[$uid]->viewed : 0;
                $fbtotalcount += $ft;
                $fbreviewed += min($fv, $ft);
            }
            $fbreviewpct = $fbtotalcount > 0 ? round(($fbreviewed / $fbtotalcount) * 100) : 0;

            // Stale count for this group.
            $stalecount = 0;
            $staledays = $this->get_stale_days();
            $lastposts = $DB->get_records_sql(
                "SELECT fp.userid, MAX(fp.created) AS lastpost
                   FROM {forum_posts} fp
                   JOIN {forum_discussions} fd ON fd.id = fp.discussion
                  WHERE fd.course = :cid AND fp.userid $insql
               GROUP BY fp.userid",
                array_merge(['cid' => $this->courseid], $inparams)
            );
            $effectivenow = $this->effective_now();
            foreach ($uids as $uid) {
                $threads = isset($participations[$uid]) ? (int)$participations[$uid]->threads : 0;
                $lp = isset($lastposts[$uid]) ? (int)$lastposts[$uid]->lastpost : 0;
                if ($threads > 0 && $lp > 0 && ($effectivenow - $lp) >= $staledays * 86400) {
                    $stalecount++;
                }
            }

            $iscurrent = ($group->id == $this->groupid);

            // Per-group teacher engagement metrics for the current user.
            // Forum posts in this group's discussions.
            $teacherforumposts = (int)$DB->count_records_sql(
                "SELECT COUNT(fp.id)
                   FROM {forum_posts} fp
                   JOIN {forum_discussions} fd ON fd.id = fp.discussion
                  WHERE fd.course = :cid AND fd.groupid = :gid AND fp.userid = :tid",
                ['cid' => $this->courseid, 'gid' => $group->id, 'tid' => $USER->id]
            );

            // Messages sent to students in this group — sums across every
            // messaging source the admin has selected (Moodle core + any
            // local_satsmail / local_mail style plugins).
            $teachermessages = 0;
            foreach ($this->get_selected_messaging_sources() as $source) {
                $msgrows = $this->query_messaging_source($source, [$USER->id], $uids);
                foreach ($msgrows as $row) {
                    $teachermessages += (int)$row->cnt;
                }
            }

            // Grading turnaround for this group's students. Uses the same
            // integrity-aware clock as the cohort and historical-trend surfaces.
            [$gclockend, $greferraljoin] = $this->get_assign_turnaround_clock();
            [$insqlgr, $inparamsgr] = $DB->get_in_or_equal($uids, SQL_PARAMS_NAMED, 'gg');
            $groupturnaround = $DB->get_record_sql(
                "SELECT AVG(($gclockend) - asub.timemodified) AS avg_turnaround
                   FROM {assign_grades} ag
                   JOIN {assign_submission} asub
                        ON asub.assignment = ag.assignment AND asub.userid = ag.userid
                        AND asub.status = 'submitted'
                   JOIN {assign} a ON a.id = ag.assignment
                   $greferraljoin
                  WHERE a.course = :cid
                    AND ag.grader = :tid
                    AND ag.userid $insqlgr
                    AND ag.grade >= 0
                    AND ($gclockend) > asub.timemodified",
                array_merge(['cid' => $this->courseid, 'tid' => $USER->id], $inparamsgr)
            );
            $groupturnarounddays = $groupturnaround && $groupturnaround->avg_turnaround > 0
                ? round($groupturnaround->avg_turnaround / 86400, 1)
                : null;

            // Feedback coverage for this group's students.
            [$insqlfb, $inparamsfb] = $DB->get_in_or_equal($uids, SQL_PARAMS_NAMED, 'gfc');
            $fbcoverage = $DB->get_record_sql(
                "SELECT COUNT(ag.id) AS total,
                        SUM(CASE WHEN fc.id IS NOT NULL THEN 1 ELSE 0 END) AS withfb
                   FROM {assign_grades} ag
                   JOIN {assign} a ON a.id = ag.assignment
                   LEFT JOIN {assignfeedback_comments} fc
                        ON fc.grade = ag.id AND fc.commenttext IS NOT NULL AND fc.commenttext != ''
                  WHERE a.course = :cid AND ag.grader = :tid AND ag.userid $insqlfb AND ag.grade >= 0",
                array_merge(['cid' => $this->courseid, 'tid' => $USER->id], $inparamsfb)
            );
            $groupfbcoverage = $fbcoverage && $fbcoverage->total > 0
                ? round(($fbcoverage->withfb / $fbcoverage->total) * 100)
                : null;

            $rows[] = [
                'groupname' => $group->name,
                'groupid' => $group->id,
                'studentcount' => $count,
                'average' => $avg !== null ? $avg . '%' : '–',
                'averageraw' => $avg,
                'belowpass' => $belowpass,
                'sphealthpct' => $sphealthpct,
                'sphealthy' => $sphealthpct >= 60,
                'spconcern' => $sphealthpct < 40,
                'avgengagement' => $avgengagement,
                'fbreviewpct' => $fbreviewpct,
                'stalecount' => $stalecount,
                'atriskcount' => $atriskcount,
                'hasatrisk' => $atriskcount > 0,
                'iscurrent' => $iscurrent,
                'viewurl' => (new \moodle_url('/grade/report/coifish/index.php', [
                    'id' => $this->courseid, 'group' => $group->id, 'view' => 'insights',
                ]))->out(false),
                // Teacher engagement per group.
                'teacherforumposts' => $teacherforumposts,
                'teachermessages' => $teachermessages,
                'teacherturnaround' => $groupturnarounddays,
                'teacherfbcoverage' => $groupfbcoverage,
            ];
        }

        if (count($rows) < 2) {
            return ['hasgroups' => false];
        }

        // Compute course-wide averages for comparison baseline.
        $allavgs = array_filter(array_column($rows, 'averageraw'), function ($v) {
            return $v !== null;
        });
        $coursewide = !empty($allavgs) ? round(array_sum($allavgs) / count($allavgs), 1) : null;

        // Cross-group diagnostic analytics.
        // Compare groups to identify significant disparities and generate explanations.
        $diagnostics = [];
        $avgsp = count($rows) > 0 ? round(array_sum(array_column($rows, 'sphealthpct')) / count($rows)) : 0;
        $avgeng = count($rows) > 0 ? round(array_sum(array_column($rows, 'avgengagement')) / count($rows)) : 0;
        $avgfb = count($rows) > 0 ? round(array_sum(array_column($rows, 'fbreviewpct')) / count($rows)) : 0;

        // Find best and worst groups by grade average.
        $graded = array_filter($rows, function ($r) {
            return $r['averageraw'] !== null;
        });
        if (count($graded) >= 2) {
            usort($graded, function ($a, $b) {
                return ($b['averageraw'] ?? 0) <=> ($a['averageraw'] ?? 0);
            });
            $best = $graded[0];
            $worst = end($graded);
            $gap = round($best['averageraw'] - $worst['averageraw'], 1);

            if ($gap >= 10) {
                // Significant gap — diagnose why.
                $reasons = [];
                $actions = [];

                // Social presence gap.
                $spgap = $best['sphealthpct'] - $worst['sphealthpct'];
                if ($spgap >= 15) {
                    $reasons[] = get_string('crossgroup_diag_sp_gap', $component, (object)[
                        'best' => $best['groupname'], 'bestpct' => $best['sphealthpct'],
                        'worst' => $worst['groupname'], 'worstpct' => $worst['sphealthpct'],
                    ]);
                    $actions[] = get_string('crossgroup_action_sp', $component, $worst['groupname']);
                }

                // Engagement gap.
                $enggap = $best['avgengagement'] - $worst['avgengagement'];
                if ($enggap >= 15) {
                    $reasons[] = get_string('crossgroup_diag_eng_gap', $component, (object)[
                        'best' => $best['groupname'], 'bestpct' => $best['avgengagement'],
                        'worst' => $worst['groupname'], 'worstpct' => $worst['avgengagement'],
                    ]);
                    $actions[] = get_string('crossgroup_action_eng', $component, $worst['groupname']);
                }

                // Feedback review gap.
                $fbgap = $best['fbreviewpct'] - $worst['fbreviewpct'];
                if ($fbgap >= 20) {
                    $reasons[] = get_string('crossgroup_diag_fb_gap', $component, (object)[
                        'best' => $best['groupname'], 'bestpct' => $best['fbreviewpct'],
                        'worst' => $worst['groupname'], 'worstpct' => $worst['fbreviewpct'],
                    ]);
                    $actions[] = get_string('crossgroup_action_fb', $component, $worst['groupname']);
                }

                // Teacher engagement correlation: compare your own engagement with the worst group.
                // Forum activity correlation.
                if ($best['teacherforumposts'] > 0 && $worst['teacherforumposts'] < $best['teacherforumposts'] * 0.5) {
                    $reasons[] = get_string('crossgroup_diag_teacher_forum', $component, (object)[
                        'best' => $best['groupname'], 'bestcount' => $best['teacherforumposts'],
                        'worst' => $worst['groupname'], 'worstcount' => $worst['teacherforumposts'],
                    ]);
                    $actions[] = get_string('crossgroup_action_teacher_forum', $component, $worst['groupname']);
                }

                // Messaging correlation.
                if ($best['teachermessages'] > 0 && $worst['teachermessages'] < $best['teachermessages'] * 0.5) {
                    $reasons[] = get_string('crossgroup_diag_teacher_msg', $component, (object)[
                        'best' => $best['groupname'], 'bestcount' => $best['teachermessages'],
                        'worst' => $worst['groupname'], 'worstcount' => $worst['teachermessages'],
                    ]);
                    $actions[] = get_string('crossgroup_action_teacher_msg', $component, $worst['groupname']);
                }

                // Grading turnaround correlation.
                if (
                    $best['teacherturnaround'] !== null && $worst['teacherturnaround'] !== null
                    && $worst['teacherturnaround'] > $best['teacherturnaround'] + 2
                ) {
                    $reasons[] = get_string('crossgroup_diag_teacher_grading', $component, (object)[
                        'best' => $best['groupname'], 'bestdays' => $best['teacherturnaround'],
                        'worst' => $worst['groupname'], 'worstdays' => $worst['teacherturnaround'],
                    ]);
                    $actions[] = get_string('crossgroup_action_teacher_grading', $component, $worst['groupname']);
                }

                // Feedback coverage correlation.
                if (
                    $best['teacherfbcoverage'] !== null && $worst['teacherfbcoverage'] !== null
                    && $best['teacherfbcoverage'] - $worst['teacherfbcoverage'] >= 20
                ) {
                    $reasons[] = get_string('crossgroup_diag_teacher_fb', $component, (object)[
                        'best' => $best['groupname'], 'bestpct' => $best['teacherfbcoverage'],
                        'worst' => $worst['groupname'], 'worstpct' => $worst['teacherfbcoverage'],
                    ]);
                    $actions[] = get_string('crossgroup_action_teacher_fb', $component, $worst['groupname']);
                }

                // Build the diagnostic card.
                $diagnostictext = get_string('crossgroup_diag_gap', $component, (object)[
                    'best' => $best['groupname'], 'bestavg' => $best['averageraw'],
                    'worst' => $worst['groupname'], 'worstavg' => $worst['averageraw'],
                    'gap' => $gap,
                ]);
                if (!empty($reasons)) {
                    $diagnostictext .= ' ' . implode(' ', $reasons);
                } else {
                    $diagnostictext .= ' ' . get_string('crossgroup_diag_no_clear_cause', $component);
                }

                $actiontext = !empty($actions)
                    ? implode(' ', $actions)
                    : get_string('crossgroup_action_investigate', $component, $worst['groupname']);

                $diagnostics[] = [
                    'icon' => 'balance-scale',
                    'severity' => $gap >= 20 ? 'danger' : 'warning',
                    'title' => get_string('crossgroup_diag_title_gap', $component),
                    'diagnostic' => $diagnostictext,
                    'action' => $actiontext,
                ];
            }

            // Check for a group with disproportionately high stale count.
            foreach ($rows as $r) {
                $stalepct = $r['studentcount'] > 0 ? round(($r['stalecount'] / $r['studentcount']) * 100) : 0;
                $avgstalepct = count($rows) > 0
                    ? round(array_sum(array_map(function ($x) {
                        return $x['studentcount'] > 0 ? ($x['stalecount'] / $x['studentcount'] * 100) : 0;
                    }, $rows)) / count($rows))
                    : 0;
                if ($stalepct >= 20 && $stalepct > $avgstalepct + 10) {
                    $diagnostics[] = [
                        'icon' => 'clock-o',
                        'severity' => 'warning',
                        'title' => get_string('crossgroup_diag_title_stale', $component),
                        'diagnostic' => get_string('crossgroup_diag_stale', $component, (object)[
                            'group' => $r['groupname'], 'count' => $r['stalecount'],
                            'pct' => $stalepct, 'avg' => $avgstalepct,
                        ]),
                        'action' => get_string('crossgroup_action_stale', $component, $r['groupname']),
                    ];
                    break; // Only flag the worst one.
                }
            }
        }

        return [
            'hasgroups' => true,
            'groups' => $rows,
            'groupcount' => count($rows),
            'courseaverage' => $coursewide !== null ? $coursewide . '%' : '–',
            'diagnostics' => $diagnostics,
            'hasdiagnostics' => !empty($diagnostics),
        ];
    }

    /**
     * Compare key metrics across teachers and co-teachers in the course.
     *
     * Maps each teacher/co-teacher to their groups and aggregates student
     * performance metrics so the teacher can compare across facilitators.
     *
     * @return array Cross-teacher comparison data for template.
     */
    public function get_cross_teacher_data(): array {
        global $DB;

        $component = 'gradereport_coifish';
        $passthreshold = $this->get_pass_threshold();
        $spthresholds = $this->get_coi_thresholds('sp', [1, 20, 50, 80]);

        // Get teacher and editing teacher role IDs.
        $teacherrole = $DB->get_record('role', ['shortname' => 'teacher']);
        $editingteacherrole = $DB->get_record('role', ['shortname' => 'editingteacher']);
        $roleids = [];
        if ($teacherrole) {
            $roleids[] = $teacherrole->id;
        }
        if ($editingteacherrole) {
            $roleids[] = $editingteacherrole->id;
        }
        if (empty($roleids)) {
            return ['hasteachers' => false];
        }

        // Get all teachers/co-teachers in this course.
        $teachers = get_role_users($roleids, $this->context, false, 'u.id, u.firstname, u.lastname', 'u.lastname ASC');
        if (count($teachers) < 2) {
            return ['hasteachers' => false];
        }

        $grademax = (float)$this->courseitem->grademax;
        $course = get_course($this->courseid);
        $allgroups = groups_get_all_groups($this->courseid, 0, $course->defaultgroupingid);
        $rows = [];

        foreach ($teachers as $teacher) {
            // Get groups this teacher belongs to.
            $teachergroups = groups_get_user_groups($this->courseid, $teacher->id);
            $groupids = $teachergroups[0] ?? []; // All groups (no grouping filter).

            // Get group names.
            $groupnames = [];
            foreach ($groupids as $gid) {
                if (isset($allgroups[$gid])) {
                    $groupnames[] = $allgroups[$gid]->name;
                }
            }

            // Collect all students across this teacher's groups.
            $studentids = [];
            foreach ($groupids as $gid) {
                $members = get_enrolled_users(
                    $this->context,
                    'moodle/course:isincompletionreports',
                    $gid,
                    'u.id',
                    'u.id',
                    0,
                    0,
                    true
                );
                $studentids = array_merge($studentids, array_keys($members));
            }
            $studentids = array_unique($studentids);

            if (empty($studentids)) {
                // Teacher has no groups or groups have no students — skip.
                $rows[] = [
                    'fullname' => fullname($teacher),
                    'grouplist' => !empty($groupnames) ? implode(', ', $groupnames) : '–',
                    'studentcount' => 0,
                    'average' => '–',
                    'belowpass' => 0,
                    'atriskcount' => 0,
                    'hasatrisk' => false,
                    'nogroups' => empty($groupnames),
                ];
                continue;
            }

            $count = count($studentids);
            [$insql, $inparams] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'ct');

            // Average grade.
            $grades = $DB->get_records_select(
                'grade_grades',
                "itemid = :itemid AND userid $insql",
                array_merge($inparams, ['itemid' => $this->courseitem->id]),
                '',
                'userid, finalgrade'
            );
            $pcts = [];
            $belowpass = 0;
            foreach ($studentids as $uid) {
                $fg = isset($grades[$uid]) ? $grades[$uid]->finalgrade : null;
                if ($fg !== null && $grademax > 0) {
                    $pct = round(((float)$fg / $grademax) * 100, 1);
                    $pcts[] = $pct;
                    if ($pct < $passthreshold) {
                        $belowpass++;
                    }
                }
            }
            $avg = !empty($pcts) ? round(array_sum($pcts) / count($pcts), 1) : null;

            // At-risk: students both isolated and failing.
            $ctparticipations = $DB->get_records_sql(
                "SELECT fp.userid, COUNT(DISTINCT fd.id) AS threads, COUNT(fp.id) AS posts
                   FROM {forum_posts} fp
                   JOIN {forum_discussions} fd ON fd.id = fp.discussion
                  WHERE fd.course = :cid AND fp.userid $insql
               GROUP BY fp.userid",
                array_merge(['cid' => $this->courseid], $inparams)
            );
            $ctallposts = array_map(function ($p) {
                return (int)$p->posts;
            }, $ctparticipations);
            $ctavgposts = !empty($ctallposts) ? array_sum($ctallposts) / count($ctallposts) : 0;
            $studentcount = count($studentids);
            $atriskcount = 0;
            foreach ($studentids as $uid) {
                $flags = 0;
                $threads = isset($ctparticipations[$uid]) ? (int)$ctparticipations[$uid]->threads : 0;
                $posts = isset($ctparticipations[$uid]) ? (int)$ctparticipations[$uid]->posts : 0;
                $breadth = $studentcount > 0 ? min(100, round(($threads / max(1, $studentcount)) * 200)) : ($threads > 0 ? 50 : 0);
                $volume = $ctavgposts > 0 ? min(100, round(($posts / $ctavgposts) * 50)) : ($posts > 0 ? 50 : 0);
                $rate = round($breadth * 0.6 + $volume * 0.4);
                if ($rate < $spthresholds[1]) {
                    $flags++;
                }
                $fg = isset($grades[$uid]) ? $grades[$uid]->finalgrade : null;
                if ($fg !== null && $grademax > 0 && round(((float)$fg / $grademax) * 100, 1) < $passthreshold) {
                    $flags++;
                }
                if ($flags >= 2) {
                    $atriskcount++;
                }
            }

            $rows[] = [
                'fullname' => fullname($teacher),
                'grouplist' => !empty($groupnames) ? implode(', ', $groupnames) : '–',
                'studentcount' => $count,
                'average' => $avg !== null ? $avg . '%' : '–',
                'belowpass' => $belowpass,
                'atriskcount' => $atriskcount,
                'hasatrisk' => $atriskcount > 0,
                'nogroups' => empty($groupnames),
            ];
        }

        return [
            'hasteachers' => count($rows) >= 2,
            'teachers' => $rows,
            'teachercount' => count($rows),
        ];
    }

    /**
     * Processes submitted data — not used in this read-only report.
     *
     * @param array $data The submitted data.
     */
    public function process_data($data) {
    }

    /**
     * Processes an action — not used in this read-only report.
     *
     * @param string $target The target of the action.
     * @param string $action The action to perform.
     */
    public function process_action($target, $action) {
    }

    /**
     * Gather coordinator-level analytics about teacher engagement in this course.
     *
     * Analyses facilitator activity across multiple dimensions: insights usage,
     * grading turnaround, forum engagement, live sessions (BBB), messaging
     * responsiveness, content updates, and grade monitoring frequency.
     *
     * @return array Coordinator analytics data keyed by teacher userid.
     */
    public function get_coordinator_teacher_data(): array {
        global $DB;

        $context = \context_course::instance($this->courseid);
        $now = $this->effective_now();
        $course = get_course($this->courseid);
        $coursestart = $course->startdate ?: ($now - 120 * 86400);
        $daysenrolled = max(1, ($now - $coursestart) / 86400);
        $weeksenrolled = max(1, $daysenrolled / 7);

        // Get all users with grading capability (teachers/editing teachers).
        $teachers = get_enrolled_users($context, 'moodle/grade:viewall', 0, 'u.*', 'u.lastname, u.firstname', 0, 0, true);
        if (empty($teachers)) {
            return ['teachers' => [], 'hasteachers' => false, 'summary' => []];
        }

        $teacherids = array_keys($teachers);
        [$insql, $inparams] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'tid');

        // 1. Insights tab visits (grade report views for this course).
        $insightsvisits = $DB->get_records_sql(
            "SELECT userid, COUNT(*) AS cnt
               FROM {logstore_standard_log}
              WHERE courseid = :courseid
                AND component = 'gradereport_coifish'
                AND action = 'viewed'
                AND userid $insql
           GROUP BY userid",
            array_merge(['courseid' => $this->courseid], $inparams)
        );

        // 2. Grading turnaround: average time from submission to grade. Uses the
        // same integrity-aware clock as the cohort and per-group surfaces.
        [$tclockend, $treferraljoin] = $this->get_assign_turnaround_clock();
        [$insql2, $inparams2] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'grd');
        $gradingturnaround = $DB->get_records_sql(
            "SELECT ag.grader AS userid,
                    COUNT(ag.id) AS graded_count,
                    AVG(($tclockend) - asub.timemodified) AS avg_turnaround
               FROM {assign_grades} ag
               JOIN {assign_submission} asub ON asub.assignment = ag.assignment
                    AND asub.userid = ag.userid AND asub.latest = 1
               JOIN {assign} a ON a.id = ag.assignment
               $treferraljoin
              WHERE a.course = :courseid
                AND ag.grader $insql2
                AND ag.grade >= 0
                AND asub.timemodified > 0
                AND ($tclockend) > asub.timemodified
           GROUP BY ag.grader",
            array_merge(['courseid' => $this->courseid], $inparams2)
        );

        // 3. Forum engagement: posts and replies by teachers.
        [$insql3, $inparams3] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'frm');
        $forumactivity = $DB->get_records_sql(
            "SELECT fp.userid,
                    COUNT(fp.id) AS total_posts,
                    SUM(CASE WHEN fp.parent != 0 THEN 1 ELSE 0 END) AS replies,
                    MAX(fp.created) AS last_post
               FROM {forum_posts} fp
               JOIN {forum_discussions} fd ON fd.id = fp.discussion
              WHERE fd.course = :courseid
                AND fp.userid $insql3
           GROUP BY fp.userid",
            array_merge(['courseid' => $this->courseid], $inparams3)
        );

        // 4. BigBlueButton sessions (if module is installed). We count distinct
        // BBB activity instances the teacher *touched* (created, joined, viewed
        // the recording of) rather than only sessions they explicitly created
        // — the `log = 'Create'` filter previously used here missed every
        // session a teacher attended without being the one to start the room,
        // which is common when sessions are pre-scheduled or co-taught.
        $bbbdata = [];
        $bbbinstalled = $DB->get_manager()->table_exists('bigbluebuttonbn_logs');
        if ($bbbinstalled) {
            [$insql4, $inparams4] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'bbb');
            $bbbdata = $DB->get_records_sql(
                "SELECT bl.userid,
                        COUNT(DISTINCT bl.bigbluebuttonbnid) AS sessions,
                        MAX(bl.timecreated) AS last_session
                   FROM {bigbluebuttonbn_logs} bl
                   JOIN {bigbluebuttonbn} bbn ON bbn.id = bl.bigbluebuttonbnid
                  WHERE bbn.course = :courseid
                    AND bl.userid $insql4
               GROUP BY bl.userid",
                array_merge(['courseid' => $this->courseid], $inparams4)
            );
        }

        // 5. Grade monitoring: how often teachers view the gradebook.
        [$insql5, $inparams5] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'gvm');
        $grademonitoring = $DB->get_records_sql(
            "SELECT userid, COUNT(*) AS cnt, MAX(timecreated) AS last_view
               FROM {logstore_standard_log}
              WHERE courseid = :courseid
                AND (component LIKE 'gradereport_%' OR component = 'core_grades')
                AND action = 'viewed'
                AND userid $insql5
           GROUP BY userid",
            array_merge(['courseid' => $this->courseid], $inparams5)
        );

        // 6. Content updates: course module created/updated events (if enabled).
        $contentenabled = get_config('gradereport_coifish', 'coordinator_content_enabled');
        $contentenabled = ($contentenabled === false || $contentenabled !== '0');
        $contentupdates = [];
        if ($contentenabled) {
            [$insql6, $inparams6] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'upd');
            $contentupdates = $DB->get_records_sql(
                "SELECT userid, COUNT(*) AS cnt, MAX(timecreated) AS last_update
                   FROM {logstore_standard_log}
                  WHERE courseid = :courseid
                    AND (action = 'created' OR action = 'updated')
                    AND target = 'course_module'
                    AND userid $insql6
               GROUP BY userid",
                array_merge(['courseid' => $this->courseid], $inparams6)
            );
        }

        // 7. Messaging responsiveness: messages sent to students (from configured sources).
        $students = get_enrolled_users($context, 'moodle/course:isincompletionreports', 0, 'u.id', null, 0, 0, true);
        $studentids = array_keys($students);
        $messagessent = [];
        $selectedsources = $this->get_selected_messaging_sources();
        if (!empty($studentids) && !empty($selectedsources)) {
            foreach ($selectedsources as $source) {
                $sourcecounts = $this->query_messaging_source($source, $teacherids, $studentids);
                foreach ($sourcecounts as $uid => $row) {
                    if (isset($messagessent[$uid])) {
                        $messagessent[$uid]->cnt += $row->cnt;
                        $messagessent[$uid]->last_message = max(
                            $messagessent[$uid]->last_message,
                            $row->last_message
                        );
                    } else {
                        $messagessent[$uid] = $row;
                    }
                }
            }
        }

        // 8. Distinct active days in the course (overall engagement).
        [$insql8, $inparams8] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'act');
        $activedays = $DB->get_records_sql(
            "SELECT userid, COUNT(DISTINCT FROM_UNIXTIME(timecreated, '%Y-%m-%d')) AS days
               FROM {logstore_standard_log}
              WHERE courseid = :courseid
                AND userid $insql8
           GROUP BY userid",
            array_merge(['courseid' => $this->courseid], $inparams8)
        );

        // 9. Feedback quality: read pre-computed metrics from cache table.
        $feedbackenabled = get_config('gradereport_coifish', 'coordinator_feedback_enabled');
        $feedbackenabled = ($feedbackenabled === false || $feedbackenabled !== '0');
        $feedbackcache = [];
        if ($feedbackenabled) {
            [$insql9, $inparams9] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'fb');
            $feedbackcache = $DB->get_records_sql(
                "SELECT userid, coverage, depth, personalisation, structured, composite,
                        totalgraded, withfeedback, avgwords, uniquepct, timemodified
                   FROM {gradereport_coifish_feedback}
                  WHERE courseid = :courseid AND userid $insql9",
                array_merge(['courseid' => $this->courseid], $inparams9)
            );
        }

        // Build per-teacher result.
        $teacherresults = [];
        $totalscore = 0;
        foreach ($teachers as $uid => $user) {
            $insights = $insightsvisits[$uid]->cnt ?? 0;
            $insightspw = round($insights / $weeksenrolled, 1);

            $grading = $gradingturnaround[$uid] ?? null;
            $gradedcount = $grading->graded_count ?? 0;
            $avgturnaroundsec = $grading->avg_turnaround ?? 0;
            $avgturnarounddays = $avgturnaroundsec > 0 ? round($avgturnaroundsec / 86400, 1) : null;

            $forum = $forumactivity[$uid] ?? null;
            $forumposts = $forum->total_posts ?? 0;
            $forumreplies = $forum->replies ?? 0;
            $forumlastpost = $forum->last_post ?? 0;
            $forumpostspw = round($forumposts / $weeksenrolled, 1);

            $bbb = $bbbdata[$uid] ?? null;
            $bbbsessions = $bbb->sessions ?? 0;
            $bbblast = $bbb->last_session ?? 0;
            $bbbpw = round($bbbsessions / $weeksenrolled, 1);

            $gradeview = $grademonitoring[$uid] ?? null;
            $gradeviews = $gradeview->cnt ?? 0;
            $gradeviewspw = round($gradeviews / $weeksenrolled, 1);
            $gradeviewlast = $gradeview->last_view ?? 0;

            $updates = $contentupdates[$uid] ?? null;
            $updatecount = $updates->cnt ?? 0;
            $updatelast = $updates->last_update ?? 0;

            $msgs = $messagessent[$uid] ?? null;
            $messagecount = $msgs->cnt ?? 0;
            $messagelast = $msgs->last_message ?? 0;
            $messagespw = round($messagecount / $weeksenrolled, 1);

            $days = $activedays[$uid]->days ?? 0;
            $dayspw = round($days / $weeksenrolled, 1);

            // Composite engagement score (0-100).
            $insightscore = min(100, round($insightspw / 1.0 * 100)); // 1 visit/week = 100%.
            $gradingtarget = (int)get_config('gradereport_coifish', 'grading_target_days') ?: 3;
            $gradingmax = (int)get_config('gradereport_coifish', 'grading_max_days') ?: 7;
            if ($gradingmax <= $gradingtarget) {
                $gradingmax = $gradingtarget + 1;
            }
            if ($avgturnarounddays !== null) {
                if ($avgturnarounddays <= $gradingtarget) {
                    $gradingscore = 100;
                } else if ($avgturnarounddays >= $gradingmax) {
                    $gradingscore = 0;
                } else {
                    $gradingscore = round(($gradingmax - $avgturnarounddays) / ($gradingmax - $gradingtarget) * 100);
                }
            } else {
                $gradingscore = 50; // No grading data — neutral.
            }
            $forumscore = min(100, round($forumpostspw / 3.0 * 100)); // 3 posts/week = 100%.
            $bbbscore = $bbbinstalled ? min(100, round($bbbpw / 0.5 * 100)) : 50; // 0.5 sessions/week = 100%.
            $grademonitoringscore = min(100, round($gradeviewspw / 2.0 * 100)); // 2 views/week = 100%.
            $contentscore = $contentenabled
                ? min(100, round($updatecount / max(1, $weeksenrolled) * 10)) // 10 updates/course = 100%.
                : 50; // Neutral when content tracking is disabled.
            $messagescore = min(100, round($messagespw / 2.0 * 100)); // 2 messages/week = 100%.
            $activescore = min(100, round($dayspw / 4.0 * 100)); // 4 active days/week = 100%.

            // Feedback quality from cache (or neutral if disabled/no data).
            $fbcache = $feedbackcache[$uid] ?? null;
            $feedbackscore = $feedbackenabled && $fbcache ? (int)$fbcache->composite : 50;
            $feedbackcoverage = $fbcache->coverage ?? 0;
            $feedbacktotalgraded = $fbcache->totalgraded ?? 0;
            $feedbackwithfb = $fbcache->withfeedback ?? 0;
            $feedbackavgwords = $fbcache->avgwords ?? 0;
            $feedbackuniquepct = $fbcache->uniquepct ?? 0;
            $feedbackcoveragepct = $feedbacktotalgraded > 0
                ? round($feedbackwithfb / $feedbacktotalgraded * 100)
                : 0;

            // Weighted composite: insights 12%, grading 15%, feedback 15%, forum 13%,
            // BBB 8%, monitoring 10%, content 10%, messaging 9%, active 8%.
            $composite = round(
                $insightscore * 0.12 +
                $gradingscore * 0.15 +
                $feedbackscore * 0.15 +
                $forumscore * 0.13 +
                $bbbscore * 0.08 +
                $grademonitoringscore * 0.10 +
                $contentscore * 0.10 +
                $messagescore * 0.09 +
                $activescore * 0.08
            );

            $totalscore += $composite;

            // Rating.
            if ($composite >= 70) {
                $rating = 'high';
            } else if ($composite >= 40) {
                $rating = 'moderate';
            } else {
                $rating = 'low';
            }

            // Last activity.
            $lastactivity = max($forumlastpost, $bbblast, $gradeviewlast, $updatelast, $messagelast);
            $daysincelast = $lastactivity > 0 ? round(($now - $lastactivity) / 86400, 0) : null;

            $teacherresults[] = [
                'userid' => $uid,
                'fullname' => fullname($user),
                'email' => $user->email,
                'composite' => $composite,
                'rating' => $rating,
                'islow' => $rating === 'low',
                'ismoderate' => $rating === 'moderate',
                'ishigh' => $rating === 'high',

                // Individual metrics.
                'insightsvisits' => $insights,
                'insightspw' => $insightspw,
                'insightscore' => $insightscore,

                'gradedcount' => $gradedcount,
                'avgturnarounddays' => $avgturnarounddays,
                'gradingscore' => $gradingscore,

                'forumposts' => $forumposts,
                'forumreplies' => $forumreplies,
                'forumpostspw' => $forumpostspw,
                'forumscore' => $forumscore,
                'forumlastpost' => $forumlastpost ? userdate($forumlastpost, get_string('strftimedatetime')) : '-',

                'bbbsessions' => $bbbsessions,
                'bbbpw' => $bbbpw,
                'bbbscore' => $bbbscore,
                'hasbbb' => $bbbinstalled,

                'gradeviews' => $gradeviews,
                'gradeviewspw' => $gradeviewspw,
                'grademonitoringscore' => $grademonitoringscore,

                'feedbackscore' => $feedbackscore,
                'feedbackcoverage' => $feedbackcoverage,
                'feedbackcoveragepct' => $feedbackcoveragepct,
                'feedbacktotalgraded' => $feedbacktotalgraded,
                'feedbackwithfb' => $feedbackwithfb,
                'feedbackavgwords' => $feedbackavgwords,
                'feedbackuniqueness' => $feedbackuniquepct,

                'contentupdates' => $updatecount,
                'contentscore' => $contentscore,

                'messagessent' => $messagecount,
                'messagespw' => $messagespw,
                'messagescore' => $messagescore,

                'activedays' => $days,
                'activedayspw' => $dayspw,
                'activescore' => $activescore,

                'lastactivity' => $lastactivity ? userdate($lastactivity, get_string('strftimedatetime')) : '-',
                'daysincelast' => $daysincelast !== null ? (int)$daysincelast : null,
                'haslastactivity' => $daysincelast !== null,
                'isstale' => $daysincelast !== null && $daysincelast > 14,
            ];
        }

        // Sort by composite descending.
        usort($teacherresults, function ($a, $b) {
            return $b['composite'] <=> $a['composite'];
        });

        // Summary stats.
        $teachercount = count($teacherresults);
        $avgscore = $teachercount > 0 ? round($totalscore / $teachercount) : 0;
        $lowcount = count(array_filter($teacherresults, function ($t) {
            return $t['rating'] === 'low';
        }));
        $moderatecount = count(array_filter($teacherresults, function ($t) {
            return $t['rating'] === 'moderate';
        }));
        $highcount = count(array_filter($teacherresults, function ($t) {
            return $t['rating'] === 'high';
        }));

        // Recommendations.
        $recommendations = [];
        if ($lowcount > 0) {
            $recommendations[] = [
                'severity' => 'danger',
                'icon' => 'exclamation-triangle',
                'text' => get_string('coord_rec_low_engagement', 'gradereport_coifish', $lowcount),
            ];
        }

        // Check for teachers not using insights.
        $noinsights = count(array_filter($teacherresults, function ($t) {
            return $t['insightsvisits'] === 0;
        }));
        if ($noinsights > 0) {
            $recommendations[] = [
                'severity' => 'warning',
                'icon' => 'eye-slash',
                'text' => get_string('coord_rec_no_insights', 'gradereport_coifish', $noinsights),
            ];
        }

        // Check for slow grading.
        $slowgraders = count(array_filter($teacherresults, function ($t) {
            return $t['avgturnarounddays'] !== null && $t['avgturnarounddays'] > 7;
        }));
        if ($slowgraders > 0) {
            $recommendations[] = [
                'severity' => 'warning',
                'icon' => 'clock-o',
                'text' => get_string('coord_rec_slow_grading', 'gradereport_coifish', $slowgraders),
            ];
        }

        // Check for stale teachers.
        $staleteachers = count(array_filter($teacherresults, function ($t) {
            return $t['isstale'];
        }));
        if ($staleteachers > 0) {
            $recommendations[] = [
                'severity' => 'danger',
                'icon' => 'user-times',
                'text' => get_string('coord_rec_stale_teacher', 'gradereport_coifish', $staleteachers),
            ];
        }

        // Check for low feedback coverage.
        if ($feedbackenabled) {
            $lowfeedback = count(array_filter($teacherresults, function ($t) {
                return $t['feedbackcoveragepct'] < 30 && $t['feedbacktotalgraded'] > 0;
            }));
            if ($lowfeedback > 0) {
                $recommendations[] = [
                    'severity' => 'warning',
                    'icon' => 'comment-o',
                    'text' => get_string('coord_rec_low_feedback', 'gradereport_coifish', $lowfeedback),
                ];
            }

            // Check for generic/copy-pasted feedback.
            $genericfeedback = count(array_filter($teacherresults, function ($t) {
                return $t['feedbackuniqueness'] < 30 && $t['feedbacktotalgraded'] > 0;
            }));
            if ($genericfeedback > 0) {
                $recommendations[] = [
                    'severity' => 'warning',
                    'icon' => 'clone',
                    'text' => get_string('coord_rec_generic_feedback', 'gradereport_coifish', $genericfeedback),
                ];
            }
        }

        return [
            'teachers' => $teacherresults,
            'hasteachers' => !empty($teacherresults),
            'hasbbb' => $bbbinstalled,
            'hascontent' => $contentenabled,
            'hasfeedback' => $feedbackenabled,
            'summary' => [
                'teachercount' => $teachercount,
                'avgscore' => $avgscore,
                'lowcount' => $lowcount,
                'moderatecount' => $moderatecount,
                'highcount' => $highcount,
                'weeksenrolled' => round($weeksenrolled, 1),
            ],
            'recommendations' => $recommendations,
            'hasrecommendations' => !empty($recommendations),
        ];
    }

    /**
     * Get the list of messaging sources selected by the admin.
     *
     * @return array List of source keys (e.g. ['core', 'local_satsmail']).
     */
    protected function get_selected_messaging_sources(): array {
        $config = get_config('gradereport_coifish', 'coordinator_messaging_sources');
        if ($config === false || $config === '') {
            return ['core'];
        }
        $sources = [];
        foreach (explode(',', $config) as $source) {
            $source = trim($source);
            if ($source !== '') {
                $sources[] = $source;
            }
        }
        return $sources ?: ['core'];
    }

    /**
     * Query a specific messaging source for teacher-to-student message counts.
     *
     * @param string $source The source key (e.g. 'core', 'local_satsmail').
     * @param array $teacherids Teacher user IDs.
     * @param array $studentids Student user IDs.
     * @return array Keyed by userid with ->cnt and ->last_message properties.
     */
    protected function query_messaging_source(string $source, array $teacherids, array $studentids): array {
        global $DB;

        if ($source === 'core') {
            return $this->query_core_messaging($teacherids, $studentids);
        }

        // For local plugins, query logstore for message-sent events from that component.
        [$insqlteach, $inparamsteach] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'mst');
        [$insqlstu, $inparamsstu] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'mss');
        return $DB->get_records_sql(
            "SELECT userid,
                    COUNT(*) AS cnt,
                    MAX(timecreated) AS last_message
               FROM {logstore_standard_log}
              WHERE component = :component
                AND (action = 'sent' OR action = 'created')
                AND target LIKE '%message%'
                AND userid $insqlteach
                AND relateduserid $insqlstu
           GROUP BY userid",
            array_merge(
                ['component' => $source],
                $inparamsteach,
                $inparamsstu
            )
        );
    }

    /**
     * Query Moodle core messaging for teacher-to-student message counts.
     *
     * @param array $teacherids Teacher user IDs.
     * @param array $studentids Student user IDs.
     * @return array Keyed by userid with ->cnt and ->last_message properties.
     */
    protected function query_core_messaging(array $teacherids, array $studentids): array {
        global $DB;

        [$insqlstu, $inparamsstu] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'stu');
        [$insqlteach, $inparamsteach] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'msg');
        return $DB->get_records_sql(
            "SELECT useridfrom AS userid,
                    COUNT(*) AS cnt,
                    MAX(timecreated) AS last_message
               FROM {messages}
              WHERE useridfrom $insqlteach
                AND conversationid IN (
                    SELECT mc.id
                      FROM {message_conversations} mc
                      JOIN {message_conversation_members} mcm ON mcm.conversationid = mc.id
                     WHERE mcm.userid $insqlstu
                )
           GROUP BY useridfrom",
            array_merge($inparamsteach, $inparamsstu)
        );
    }

    /**
     * Get the intervention history for a specific student in this course.
     *
     * @param int $studentid The student user ID.
     * @return array Intervention records with outcomes, formatted for template rendering.
     */
    public function get_intervention_history(int $studentid): array {
        global $DB;

        $records = $DB->get_records_sql(
            "SELECT s.id AS intvstudentid, i.id AS interventionid, i.teacherid, i.diagnostictype,
                    i.scope, i.actiontype, i.customaction, i.notes, i.timecreated,
                    s.snap_grade, s.snap_engagement, s.snap_social, s.snap_feedbackpct, s.snap_daysinactive
               FROM {gradereport_coifish_intv_stu} s
               JOIN {gradereport_coifish_intv} i ON i.id = s.interventionid
              WHERE i.courseid = :courseid AND s.studentid = :studentid
           ORDER BY i.timecreated DESC",
            ['courseid' => $this->courseid, 'studentid' => $studentid]
        );

        if (empty($records)) {
            return ['hashistory' => false, 'interventions' => []];
        }

        $component = 'gradereport_coifish';

        // Bulk-load the latest outcome per intervention-student and all teacher
        // names up front, so the loop below issues no per-row queries.
        $intvstudentids = array_map('intval', array_column(array_values($records), 'intvstudentid'));
        [$oinsql, $oinparams] = $DB->get_in_or_equal($intvstudentids, SQL_PARAMS_NAMED, 'iso');
        $outcomerows = $DB->get_records_sql(
            "SELECT id, intvstudentid, outcome, grade, engagement, social, feedbackpct, daysinactive, checkdays
               FROM {gradereport_coifish_intv_out}
              WHERE intvstudentid $oinsql
           ORDER BY checkdays ASC",
            $oinparams
        );
        // ORDER BY checkdays ASC means the last write per intvstudentid wins → latest.
        $latestoutcome = [];
        foreach ($outcomerows as $orow) {
            $latestoutcome[(int)$orow->intvstudentid] = $orow;
        }

        $teacherids = array_map('intval', array_column(array_values($records), 'teacherid'));
        [$tinsql, $tinparams] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'tch');
        $namefields = implode(', ', \core_user\fields::for_name()->get_required_fields());
        $teachers = $DB->get_records_select('user', "id $tinsql", $tinparams, '', 'id, ' . $namefields);

        $interventions = [];
        foreach ($records as $rec) {
            $outcome = $latestoutcome[(int)$rec->intvstudentid] ?? null;
            $teacher = $teachers[$rec->teacherid] ?? null;
            $actionlabel = $rec->actiontype === 'custom'
                ? $rec->customaction
                : get_string('intervention_action_' . $rec->actiontype, $component);

            $outcomelabel = $outcome ? $outcome->outcome : 'pending';

            $interventions[] = [
                'date' => userdate($rec->timecreated, get_string('strftimedateshort')),
                'teachername' => $teacher ? fullname($teacher) : '?',
                'diagnostictype' => $rec->diagnostictype,
                'scope' => $rec->scope,
                'scopelabel' => get_string('intervention_scope_' . $rec->scope, $component),
                'actionlabel' => $actionlabel,
                'notes' => $rec->notes,
                'hasnotes' => !empty($rec->notes),
                'outcome' => $outcomelabel,
                'isimproved' => $outcomelabel === 'improved',
                'isstable' => $outcomelabel === 'stable',
                'isdeclined' => $outcomelabel === 'declined',
                'ispending' => $outcomelabel === 'pending',
                'outcomelabel' => get_string('intervention_outcome_' . $outcomelabel, $component),
                // Snapshot vs current comparison (if outcome exists).
                'hascomparison' => !empty($outcome),
                'snap_grade' => $rec->snap_grade !== null ? $rec->snap_grade . '%' : '-',
                'now_grade' => $outcome && $outcome->grade !== null ? $outcome->grade . '%' : '-',
                'snap_engagement' => $rec->snap_engagement !== null ? $rec->snap_engagement . '%' : '-',
                'now_engagement' => $outcome && $outcome->engagement !== null ? $outcome->engagement . '%' : '-',
                'snap_social' => $rec->snap_social !== null ? $rec->snap_social . '%' : '-',
                'now_social' => $outcome && $outcome->social !== null ? $outcome->social . '%' : '-',
            ];
        }

        return ['hashistory' => true, 'interventions' => $interventions];
    }

    /**
     * Get aggregated intervention data for the coordinator report.
     *
     * @return array Intervention analytics for coordinator template.
     */
    public function get_coordinator_intervention_data(): array {
        global $DB;

        $component = 'gradereport_coifish';

        // Per-teacher intervention counts.
        $teachercounts = $DB->get_records_sql(
            "SELECT teacherid, COUNT(*) AS cnt, MIN(timecreated) AS firstintv
               FROM {gradereport_coifish_intv}
              WHERE courseid = :courseid
           GROUP BY teacherid",
            ['courseid' => $this->courseid]
        );

        // All outcomes for this course.
        $outcomes = $DB->get_records_sql(
            "SELECT o.outcome, COUNT(*) AS cnt
               FROM {gradereport_coifish_intv_out} o
               JOIN {gradereport_coifish_intv_stu} s ON s.id = o.intvstudentid
               JOIN {gradereport_coifish_intv} i ON i.id = s.interventionid
              WHERE i.courseid = :courseid AND o.outcome != 'pending'
           GROUP BY o.outcome",
            ['courseid' => $this->courseid]
        );
        $outcomecounts = [];
        $totalresolved = 0;
        foreach ($outcomes as $o) {
            $outcomecounts[$o->outcome] = (int)$o->cnt;
            $totalresolved += (int)$o->cnt;
        }

        // Effectiveness by diagnostic type.
        $bytype = $DB->get_records_sql(
            "SELECT i.diagnostictype,
                    COUNT(DISTINCT i.id) AS interventions,
                    SUM(CASE WHEN o.outcome = 'improved' THEN 1 ELSE 0 END) AS improved,
                    SUM(CASE WHEN o.outcome = 'stable' THEN 1 ELSE 0 END) AS stable,
                    SUM(CASE WHEN o.outcome = 'declined' THEN 1 ELSE 0 END) AS declined,
                    COUNT(o.id) AS total_outcomes
               FROM {gradereport_coifish_intv} i
               JOIN {gradereport_coifish_intv_stu} s ON s.interventionid = i.id
               LEFT JOIN {gradereport_coifish_intv_out} o ON o.intvstudentid = s.id AND o.outcome != 'pending'
              WHERE i.courseid = :courseid
           GROUP BY i.diagnostictype
           ORDER BY interventions DESC",
            ['courseid' => $this->courseid]
        );
        $typestats = [];
        foreach ($bytype as $row) {
            $total = (int)$row->total_outcomes;
            $typestats[] = [
                'diagnostictype' => $row->diagnostictype,
                'interventions' => (int)$row->interventions,
                'improvedpct' => $total > 0 ? round(($row->improved / $total) * 100) : 0,
                'stablepct' => $total > 0 ? round(($row->stable / $total) * 100) : 0,
                'declinedpct' => $total > 0 ? round(($row->declined / $total) * 100) : 0,
                'hastotal' => $total > 0,
            ];
        }

        // Students needing escalation: 3+ interventions with no improvement.
        $escalation = $DB->get_records_sql(
            "SELECT s.studentid, COUNT(DISTINCT s.interventionid) AS intv_count,
                    SUM(CASE WHEN o.outcome = 'improved' THEN 1 ELSE 0 END) AS improved_count
               FROM {gradereport_coifish_intv_stu} s
               JOIN {gradereport_coifish_intv} i ON i.id = s.interventionid
               LEFT JOIN {gradereport_coifish_intv_out} o ON o.intvstudentid = s.id
              WHERE i.courseid = :courseid
           GROUP BY s.studentid
             HAVING COUNT(DISTINCT s.interventionid) >= 3
                AND SUM(CASE WHEN o.outcome = 'improved' THEN 1 ELSE 0 END) = 0",
            ['courseid' => $this->courseid]
        );
        $escalationlist = [];
        if (!empty($escalation)) {
            // Bulk-load the escalated students in one query rather than per row.
            $namefields = implode(', ', \core_user\fields::for_name()->get_required_fields());
            [$einsql, $einparams] = $DB->get_in_or_equal(
                array_map('intval', array_keys($escalation)),
                SQL_PARAMS_NAMED,
                'esc'
            );
            $escusers = $DB->get_records_select('user', "id $einsql", $einparams, '', 'id, ' . $namefields);
            foreach ($escalation as $row) {
                $user = $escusers[$row->studentid] ?? null;
                if ($user) {
                    $escalationlist[] = [
                        'fullname' => fullname($user),
                        'interventioncount' => (int)$row->intv_count,
                    ];
                }
            }
        }

        $totalinterventions = array_sum(array_column(array_values((array)$teachercounts), 'cnt'));

        return [
            'hasdata' => $totalinterventions > 0,
            'totalinterventions' => $totalinterventions,
            'teachercounts' => $teachercounts,
            'improvedpct' => $totalresolved > 0 ? round(($outcomecounts['improved'] ?? 0) / $totalresolved * 100) : 0,
            'stablepct' => $totalresolved > 0 ? round(($outcomecounts['stable'] ?? 0) / $totalresolved * 100) : 0,
            'declinedpct' => $totalresolved > 0 ? round(($outcomecounts['declined'] ?? 0) / $totalresolved * 100) : 0,
            'pendingcount' => $totalinterventions - $totalresolved,
            'typestats' => $typestats,
            'hastypestats' => !empty($typestats),
            'escalationlist' => $escalationlist,
            'hasescalation' => !empty($escalationlist),
        ];
    }
}
