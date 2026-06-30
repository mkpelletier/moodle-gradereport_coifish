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
 * Renderable for the student-facing fortnightly pulse modal.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gradereport_coifish\output;

use renderable;
use templatable;
use renderer_base;
use gradereport_coifish\pulse;

/**
 * Builds the self-referenced progress data shown to a student in the pulse
 * modal: per-metric current values and deltas versus the previous period, an
 * overall momentum/streak, a personal-best flag and one or two improvement
 * prescriptions. Strictly self-referenced — no peer comparison is computed.
 */
class student_pulse implements renderable, templatable {
    /** @var int The course ID. */
    protected int $courseid;

    /** @var int The student's user ID. */
    protected int $userid;

    /** @var string[] The 0-100 metric columns, in display order. */
    protected const METRICS = ['grade', 'engagement', 'social', 'selfregulation', 'feedbackpct'];

    /** @var int Below this current value a metric is a candidate for a prescription. */
    protected const PRESCRIBE_BELOW = 60;

    /** @var array<string,string> FontAwesome icon name (no fa- prefix) per metric. */
    protected const ICONS = [
        'grade' => 'pencil',
        'engagement' => 'bolt',
        'social' => 'comments',
        'selfregulation' => 'calendar-check-o',
        'feedbackpct' => 'comment-o',
    ];

    /**
     * Constructor.
     *
     * @param int $courseid The course ID.
     * @param int $userid The student's user ID.
     */
    public function __construct(int $courseid, int $userid) {
        $this->courseid = $courseid;
        $this->userid = $userid;
    }

    /**
     * Mean of the available 0-100 metrics on a pulse row, or null if none set.
     *
     * @param \stdClass $row A pulse row.
     * @return int|null The overall score, or null.
     */
    protected static function overall(\stdClass $row): ?int {
        $vals = [];
        foreach (self::METRICS as $key) {
            if ($row->$key !== null) {
                $vals[] = (float)$row->$key;
            }
        }
        return $vals ? (int)round(array_sum($vals) / count($vals)) : null;
    }

    /**
     * Inline conic-gradient style filling a ring to the given percentage.
     *
     * @param int $pct Fill percentage (0-100).
     * @return string CSS for the style attribute.
     */
    protected static function ring_style(int $pct): string {
        $pct = max(0, min(100, $pct));
        return sprintf(
            'background: conic-gradient(var(--coifish-pulse-accent) 0%% %d%%, var(--coifish-pulse-track) %d%% 100%%);',
            $pct,
            $pct
        );
    }

    /**
     * Map a 0-100 value to a five-step level: a friendly label and the ring fill
     * (in fifths) that represents it. Students see this level, never the raw
     * composite number, which reads as arbitrary.
     *
     * @param int $value The metric value (0-100).
     * @return array Keys: 'label' (string) and 'fill' (int percentage).
     */
    protected static function band(int $value): array {
        $index = (int)min(5, max(1, intdiv(max(0, $value), 20) + 1));
        return [
            'label' => get_string('pulse_band_' . $index, 'gradereport_coifish'),
            'fill' => $index * 20,
        ];
    }

    /**
     * Whether the course has a forum students can actually post in, so a "join a
     * discussion" suggestion is actionable. The auto-created Announcements
     * (news) forum is excluded — students cannot start discussions there.
     *
     * @return bool
     */
    protected function course_has_forum(): bool {
        global $DB;
        return $DB->record_exists_select('forum', 'course = ? AND type <> ?', [$this->courseid, 'news']);
    }

    /**
     * Upcoming assignment due dates and quiz close dates the student has not yet
     * completed, soonest first across both. Used as a "Coming up" fallback when
     * no metric-based suggestion is actionable. Per-user/group date overrides are
     * not resolved here.
     *
     * @param int $now Reference time.
     * @param int $limit Maximum rows.
     * @return array List of ['name', 'url', 'icon', 'datestr'].
     */
    protected function upcoming_due_dates(int $now, int $horizondays, int $limit = 3): array {
        global $DB;

        $horizon = $now + ($horizondays * DAYSECS);
        $items = [];

        // Assignments due within the horizon that the student has not submitted.
        $assigns = $DB->get_records_sql(
            "SELECT a.id, a.name, a.duedate, cm.id AS cmid
               FROM {assign} a
               JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = a.course
               JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
              WHERE a.course = :courseid AND a.duedate > :now AND a.duedate <= :horizon AND cm.visible = 1
                AND NOT EXISTS (
                    SELECT 1 FROM {assign_submission} s
                     WHERE s.assignment = a.id AND s.userid = :userid AND s.status = :submitted
                )",
            ['courseid' => $this->courseid, 'now' => $now, 'horizon' => $horizon,
                'userid' => $this->userid, 'submitted' => 'submitted']
        );
        foreach ($assigns as $a) {
            $items[] = ['due' => (int)$a->duedate, 'name' => $a->name, 'mod' => 'assign', 'cmid' => (int)$a->cmid];
        }

        // Quizzes/exams closing within the horizon with no finished attempt yet.
        $quizzes = $DB->get_records_sql(
            "SELECT q.id, q.name, q.timeclose, cm.id AS cmid
               FROM {quiz} q
               JOIN {course_modules} cm ON cm.instance = q.id AND cm.course = q.course
               JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
              WHERE q.course = :courseid AND q.timeclose > :now AND q.timeclose <= :horizon AND cm.visible = 1
                AND NOT EXISTS (
                    SELECT 1 FROM {quiz_attempts} qa
                     WHERE qa.quiz = q.id AND qa.userid = :userid AND qa.state = :finished
                )",
            ['courseid' => $this->courseid, 'now' => $now, 'horizon' => $horizon,
                'userid' => $this->userid, 'finished' => 'finished']
        );
        foreach ($quizzes as $q) {
            $items[] = ['due' => (int)$q->timeclose, 'name' => $q->name, 'mod' => 'quiz', 'cmid' => (int)$q->cmid];
        }

        usort($items, function ($x, $y) {
            return $x['due'] <=> $y['due'];
        });

        $out = [];
        foreach (array_slice($items, 0, $limit) as $it) {
            $url = new \moodle_url('/mod/' . $it['mod'] . '/view.php', ['id' => $it['cmid']]);
            $out[] = [
                'name' => format_string($it['name']),
                'url' => $url->out(false),
                'icon' => ($it['mod'] === 'quiz') ? 'question-circle-o' : 'file-text-o',
                'datestr' => self::due_label($it['due'], $now),
            ];
        }
        return $out;
    }

    /**
     * Urgency-aware label for a deadline: "due today", "due tomorrow", "due in N
     * days" for the coming week, otherwise the dated form.
     *
     * @param int $due Deadline timestamp.
     * @param int $now Reference time.
     * @return string
     */
    protected static function due_label(int $due, int $now): string {
        $days = (int)floor(($due - $now) / DAYSECS);
        if ($days <= 0) {
            return get_string('pulse_due_today', 'gradereport_coifish');
        }
        if ($days === 1) {
            return get_string('pulse_due_tomorrow', 'gradereport_coifish');
        }
        if ($days <= 7) {
            return get_string('pulse_due_in_days', 'gradereport_coifish', $days);
        }
        return get_string('pulse_due_on', 'gradereport_coifish', userdate($due, get_string('strftimedaydate')));
    }

    /**
     * Build the modal context.
     *
     * @param renderer_base $output The renderer.
     * @return array Template context; ['hasdata' => false] when nothing to show.
     */
    public function export_for_template(renderer_base $output): array {
        $component = 'gradereport_coifish';
        $rows = pulse::recent_rows($this->courseid, $this->userid, 12);
        if (empty($rows)) {
            return ['hasdata' => false];
        }

        $latest = $rows[0];
        $previous = $rows[1] ?? null;
        $isfirst = ($previous === null);

        // Per-metric current value and delta versus the previous period.
        $metrics = [];
        foreach (self::METRICS as $key) {
            if ($latest->$key === null) {
                continue;
            }
            $value = (int)round((float)$latest->$key);
            $band = self::band($value);
            // Marks are a real, externally-meaningful percentage, so they are
            // shown as the actual figure and point change, not a level band.
            $isgrade = ($key === 'grade');
            $entry = [
                'key' => $key,
                'friendlylabel' => get_string('pulse_friendly_' . $key, $component),
                'icon' => self::ICONS[$key] ?? 'circle-o',
                'descriptor' => get_string('pulse_desc_' . $key, $component),
                'meaning' => get_string('pulse_meaning_' . $key, $component),
                'howcalculated' => get_string('pulse_how_' . $key, $component),
                'collapseid' => 'gradereport-coifish-pulse-m-' . $key,
                'value' => $value,
                'levellabel' => $isgrade ? $value . '%' : $band['label'],
                'ringstyle' => self::ring_style($isgrade ? $value : $band['fill']),
                'hasdelta' => false,
                'up' => false,
                'down' => false,
                'same' => false,
                'deltaabs' => 0,
                'trendclass' => 'gradereport-coifish-pulse-trend-same',
                'trendicon' => 'minus',
                'trendlabel' => '',
            ];
            if ($previous !== null && $previous->$key !== null) {
                $delta = $value - (int)round((float)$previous->$key);
                $entry['hasdelta'] = true;
                $entry['deltaabs'] = abs($delta);
                if ($delta > 0) {
                    $entry['up'] = true;
                    $entry['trendclass'] = 'gradereport-coifish-pulse-trend-up';
                    $entry['trendicon'] = 'arrow-up';
                    $entry['trendlabel'] = $isgrade ? '+' . abs($delta) : get_string('pulse_trend_up', $component);
                } else if ($delta < 0) {
                    $entry['down'] = true;
                    $entry['trendclass'] = 'gradereport-coifish-pulse-trend-down';
                    $entry['trendicon'] = 'arrow-down';
                    $entry['trendlabel'] = $isgrade ? '-' . abs($delta) : get_string('pulse_trend_down', $component);
                } else {
                    $entry['same'] = true;
                    $entry['trendlabel'] = get_string('pulse_trend_same', $component);
                }
            }
            $metrics[] = $entry;
        }

        // Overall momentum, streak and personal best across captured periods.
        $overall = self::overall($latest);
        $prevoverall = $previous !== null ? self::overall($previous) : null;
        $overalldelta = ($overall !== null && $prevoverall !== null) ? $overall - $prevoverall : 0;

        $overalls = [];
        foreach ($rows as $row) {
            $o = self::overall($row);
            if ($o !== null) {
                $overalls[] = $o;
            }
        }
        // Consecutive improving periods, newest first.
        $streak = 0;
        for ($i = 0; $i < count($overalls) - 1; $i++) {
            if ($overalls[$i] > $overalls[$i + 1]) {
                $streak++;
            } else {
                break;
            }
        }
        $bestprev = count($overalls) > 1 ? max(array_slice($overalls, 1)) : null;
        $ispersonalbest = ($overall !== null && $bestprev !== null && $overall > $bestprev);

        // Suggestions: the one or two lowest current metrics that are below the
        // bar AND actually actionable in this course (only suggest joining a
        // discussion when the course has a forum). When nothing is actionable,
        // fall back to upcoming due dates with a finish-early nudge; only if
        // there are none of those, a simple "keep it up".
        $now = time();
        $hasforum = null;
        $candidates = array_filter($metrics, function ($m) {
            return $m['value'] < self::PRESCRIBE_BELOW;
        });
        usort($candidates, function ($a, $b) {
            return $a['value'] <=> $b['value'];
        });
        $prescriptions = [];
        foreach ($candidates as $m) {
            if ($m['key'] === 'social') {
                $hasforum = $hasforum ?? $this->course_has_forum();
                if (!$hasforum) {
                    continue;
                }
            }
            $prescriptions[] = ['text' => get_string('pulse_rx_' . $m['key'], $component)];
            if (count($prescriptions) >= 2) {
                break;
            }
        }

        // The pulse interval drives both the deadline horizon and the help text.
        // Deadlines are only surfaced within a relevance horizon that scales with
        // how often the student sees this modal (a week beyond the next showing),
        // so a deadline months away does not crowd out the near ones.
        $interval = (int)pulse::course_config($this->courseid)['interval'];
        $horizondays = max(14, $interval + 7);
        $duedates = empty($prescriptions) ? $this->upcoming_due_dates($now, $horizondays) : [];
        if (empty($prescriptions) && empty($duedates)) {
            $prescriptions[] = ['text' => get_string('pulse_rx_maintain', $component)];
        }

        // Help text, with the cadence so the student knows how often it appears.
        if ($interval === 7) {
            $cadence = get_string('pulse_cadence_weekly', $component);
        } else if ($interval === 14) {
            $cadence = get_string('pulse_cadence_fortnightly', $component);
        } else {
            $cadence = get_string('pulse_cadence_days', $component, $interval);
        }
        $helpbody = get_string('pulse_help_body', $component, $cadence);

        // Headline framing.
        if ($isfirst) {
            $headline = get_string('pulse_headline_first', $component);
        } else if ($ispersonalbest) {
            $headline = get_string('pulse_headline_best', $component);
        } else if ($streak >= 2) {
            $headline = get_string('pulse_headline_streak', $component, $streak);
        } else if ($overalldelta > 0) {
            $headline = get_string('pulse_headline_up', $component);
        } else if ($overalldelta < 0) {
            $headline = get_string('pulse_headline_down', $component);
        } else {
            $headline = get_string('pulse_headline_steady', $component);
        }

        // Hero trend chip (overall direction), with first-period framing.
        if ($isfirst) {
            $herotrendclass = 'gradereport-coifish-pulse-trend-first';
            $herotrendicon = 'flag-o';
            $herotrendlabel = get_string('pulse_hero_first', $component);
        } else if ($overalldelta > 0) {
            $herotrendclass = 'gradereport-coifish-pulse-trend-up';
            $herotrendicon = 'arrow-up';
            $herotrendlabel = get_string('pulse_hero_up', $component);
        } else if ($overalldelta < 0) {
            $herotrendclass = 'gradereport-coifish-pulse-trend-down';
            $herotrendicon = 'arrow-down';
            $herotrendlabel = get_string('pulse_hero_down', $component);
        } else {
            $herotrendclass = 'gradereport-coifish-pulse-trend-same';
            $herotrendicon = 'minus';
            $herotrendlabel = get_string('pulse_hero_same', $component);
        }
        $heroband = ($overall !== null) ? self::band((int)$overall) : ['label' => '', 'fill' => 0];

        // Course-access note (behavioural engagement, framed encouragingly).
        $days = $latest->daysoffline;
        if ($days === null) {
            $accesslabel = get_string('pulse_access_never', $component);
            $accessok = false;
        } else if ((int)$days <= 3) {
            $accesslabel = get_string('pulse_access_ok', $component);
            $accessok = true;
        } else {
            $accesslabel = get_string('pulse_access_stale', $component, (int)$days);
            $accessok = false;
        }

        return [
            'hasdata' => true,
            'courseid' => $this->courseid,
            'coursename' => format_string(get_course($this->courseid)->fullname),
            'helpbody' => $helpbody,
            'headline' => $headline,
            'isfirst' => $isfirst,
            'overall' => $overall,
            'hasoverall' => ($overall !== null),
            'herobandlabel' => $heroband['label'],
            'heroringstyle' => self::ring_style($heroband['fill']),
            'herotrendclass' => $herotrendclass,
            'herotrendicon' => $herotrendicon,
            'herotrendlabel' => $herotrendlabel,
            'streak' => $streak,
            'hasstreak' => ($streak >= 2),
            'streaklabel' => get_string('pulse_streak_sentence', $component, $streak),
            'ispersonalbest' => $ispersonalbest,
            'metrics' => $metrics,
            'prescriptions' => $prescriptions,
            'hasprescriptions' => !empty($prescriptions),
            'duedates' => $duedates,
            'hasduedates' => !empty($duedates),
            'accesslabel' => $accesslabel,
            'accessok' => $accessok,
        ];
    }
}
