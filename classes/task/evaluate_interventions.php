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
 * Scheduled task to evaluate intervention outcomes at configured follow-up intervals.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gradereport_coifish\task;

use core\task\scheduled_task;
use gradereport_coifish\external\log_intervention;

/**
 * Evaluate intervention outcomes by comparing current metrics to snapshots.
 */
class evaluate_interventions extends scheduled_task {
    /**
     * Return the task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_evaluate_interventions', 'gradereport_coifish');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        global $DB;

        $enabled = get_config('gradereport_coifish', 'intervention_enabled');
        if ($enabled === '0') {
            return;
        }

        // Get configured follow-up intervals.
        $followupconfig = get_config('gradereport_coifish', 'intervention_followup_days');
        if ($followupconfig === false || $followupconfig === '') {
            $intervals = [7, 14, 28];
        } else {
            $intervals = array_map('intval', array_filter(explode(',', $followupconfig)));
        }

        if (empty($intervals)) {
            return;
        }

        $now = time();

        // For each interval, find intervention-student records that are due for a check.
        foreach ($intervals as $days) {
            $cutoff = $now - ($days * 86400);

            // Find intervention-student records where:
            // 1. The intervention was created at least $days ago.
            // 2. No outcome record exists for this interval yet.
            $due = $DB->get_records_sql(
                "SELECT s.id AS intvstudentid, s.studentid, s.interventionid,
                        s.snap_grade, s.snap_engagement, s.snap_social,
                        s.snap_feedbackpct, s.snap_daysinactive,
                        i.courseid, i.diagnostictype
                   FROM {gradereport_coifish_intv_stu} s
                   JOIN {gradereport_coifish_intv} i ON i.id = s.interventionid
                  WHERE i.timecreated <= :cutoff
                    AND NOT EXISTS (
                        SELECT 1 FROM {gradereport_coifish_intv_out} o
                         WHERE o.intvstudentid = s.id AND o.checkdays = :checkdays
                    )",
                ['cutoff' => $cutoff, 'checkdays' => $days]
            );

            foreach ($due as $rec) {
                $current = log_intervention::capture_snapshot($rec->courseid, $rec->studentid);
                $outcome = $this->classify_outcome($rec, $current);

                $DB->insert_record('gradereport_coifish_intv_out', (object)[
                    'intvstudentid' => $rec->intvstudentid,
                    'checkdays' => $days,
                    'timechecked' => $now,
                    'grade' => $current['grade'],
                    'engagement' => $current['engagement'],
                    'social' => $current['social'],
                    'feedbackpct' => $current['feedbackpct'],
                    'daysinactive' => $current['daysinactive'],
                    'outcome' => $outcome,
                ]);
            }
        }
    }

    /**
     * Classify whether the student improved, stayed stable, or declined.
     *
     * The classification is weighted by the diagnostic type that triggered
     * the intervention — e.g. a social_isolation intervention primarily
     * evaluates social presence change.
     *
     * @param object $snapshot The original intervention-student record with snap_ fields.
     * @param array $current The current metrics from capture_snapshot().
     * @return string 'improved', 'stable', or 'declined'.
     */
    protected function classify_outcome(object $snapshot, array $current): string {
        $signals = [];

        // Grade change.
        if ($snapshot->snap_grade !== null && $current['grade'] !== null) {
            $gradedelta = $current['grade'] - (float)$snapshot->snap_grade;
            if ($gradedelta >= 3) {
                $signals['grade'] = 1;
            } else if ($gradedelta <= -3) {
                $signals['grade'] = -1;
            } else {
                $signals['grade'] = 0;
            }
        }

        // Engagement change.
        if ($snapshot->snap_engagement !== null && $current['engagement'] !== null) {
            $engdelta = $current['engagement'] - (int)$snapshot->snap_engagement;
            if ($engdelta >= 10) {
                $signals['engagement'] = 1;
            } else if ($engdelta <= -10) {
                $signals['engagement'] = -1;
            } else {
                $signals['engagement'] = 0;
            }
        }

        // Social presence change.
        if ($snapshot->snap_social !== null && $current['social'] !== null) {
            $socialdelta = $current['social'] - (int)$snapshot->snap_social;
            if ($socialdelta >= 10) {
                $signals['social'] = 1;
            } else if ($socialdelta <= -10) {
                $signals['social'] = -1;
            } else {
                $signals['social'] = 0;
            }
        }

        // Feedback review change.
        if ($snapshot->snap_feedbackpct !== null && $current['feedbackpct'] !== null) {
            $fbdelta = $current['feedbackpct'] - (int)$snapshot->snap_feedbackpct;
            if ($fbdelta >= 20) {
                $signals['feedback'] = 1;
            } else if ($fbdelta <= -20) {
                $signals['feedback'] = -1;
            } else {
                $signals['feedback'] = 0;
            }
        }

        // Days inactive change (lower is better).
        if ($snapshot->snap_daysinactive !== null && $current['daysinactive'] !== null) {
            $inactivedelta = $current['daysinactive'] - (int)$snapshot->snap_daysinactive;
            if ($inactivedelta <= -3) {
                $signals['inactive'] = 1; // Became more active.
            } else if ($inactivedelta >= 5) {
                $signals['inactive'] = -1; // Became more inactive.
            } else {
                $signals['inactive'] = 0;
            }
        }

        if (empty($signals)) {
            return 'stable';
        }

        // Weight by diagnostic type — give the primary metric double weight.
        $primarymetric = $this->get_primary_metric($snapshot->diagnostictype);
        $score = 0;
        $totalweight = 0;
        foreach ($signals as $metric => $signal) {
            $weight = ($metric === $primarymetric) ? 2 : 1;
            $score += $signal * $weight;
            $totalweight += $weight;
        }

        $normalised = $totalweight > 0 ? ($score / $totalweight) : 0;

        if ($normalised >= 0.3) {
            return 'improved';
        } else if ($normalised <= -0.3) {
            return 'declined';
        }
        return 'stable';
    }

    /**
     * Map a diagnostic type to its primary metric for outcome weighting.
     *
     * @param string $diagnostictype The diagnostic card type.
     * @return string The metric key to double-weight.
     */
    protected function get_primary_metric(string $diagnostictype): string {
        $map = [
            'trend_declining' => 'grade',
            'streak_broken' => 'grade',
            'social_isolation' => 'social',
            'feedback_unreviewed' => 'feedback',
            'engagement_low' => 'engagement',
            'timing_late' => 'grade',
            'consistency_poor' => 'grade',
            'selfregulation_low' => 'engagement',
            'cohort_isolation' => 'social',
            'cohort_engagement' => 'engagement',
            'cohort_feedback' => 'feedback',
            'cohort_stale' => 'inactive',
            'cohort_failing' => 'grade',
            'cohort_compound' => 'grade',
            'cohort_balance' => 'engagement',
        ];
        return $map[$diagnostictype] ?? 'grade';
    }
}
