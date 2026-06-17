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
 * Reusable feedback-quality scoring for the assignment-level breakdown.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gradereport_coifish;

use gradereport_coifish\task\calculate_feedback_metrics;

defined('MOODLE_INTERNAL') || die();

/**
 * Scores a bucket of feedback artifacts (depth / quality / personalisation).
 *
 * The cohort-level {@see calculate_feedback_metrics} task owns the scoring
 * formulas as public primitives; this helper applies them at the assignment
 * grain so the cohort and assignment-level scores cannot drift.
 */
class feedback_scorer {
    /**
     * Course-level structured-grading score (% of assigns with rubric/marking guide).
     *
     * @param int $courseid The course ID.
     * @return int Score 0-100.
     */
    public static function structured_score(int $courseid): int {
        return calculate_feedback_metrics::get_structured_grading_score($courseid);
    }

    /**
     * Depth, quality and personalisation for one bucket of feedback artifacts.
     *
     * Applies the exact cohort formulas to a single assignment's artifacts:
     *  - depth           = round(avgwords / 50 * 100), capped 100
     *  - quality         = round(avgqualitypoints / 2.0 * 100), capped 100
     *  - personalisation = round(uniquepct / 70 * 100), capped 100
     * Recorded (audio/video) artifacts contribute a size-scaled word equivalent,
     * a full 3/3 quality, and are always treated as unique for personalisation.
     *
     * @param array $artifacts List of ['text' => string, 'media' => int|null]
     *                         (media = file size in bytes for recorded feedback).
     * @return array ['depth' => int, 'quality' => int, 'personalisation' => int].
     */
    public static function score_bucket(array $artifacts): array {
        $count = count($artifacts);
        if ($count === 0) {
            return ['depth' => 0, 'quality' => 0, 'personalisation' => 0];
        }

        $totalwords = 0;
        $totalquality = 0;
        $normalised = [];
        $mediaidx = 0;

        foreach ($artifacts as $artifact) {
            $html = (string)($artifact['text'] ?? '');
            $mediabytes = $artifact['media'] ?? null;
            $plaintext = strip_tags($html);
            $wordcount = str_word_count($plaintext);

            $hasmedia = ($mediabytes !== null)
                || calculate_feedback_metrics::has_multimedia_feedback($html);
            if ($hasmedia) {
                $totalwords += calculate_feedback_metrics::media_word_equivalent($mediabytes, $wordcount);
                $totalquality += 3;
                // Recorded feedback is made for one student, so always unique.
                $normalised[] = 'media:' . ($mediaidx++);
            } else {
                $totalwords += $wordcount;
                $totalquality += calculate_feedback_metrics::score_comment_quality($plaintext);
                $normalised[] = strtolower(trim($plaintext));
            }
        }

        $avgwords = round($totalwords / $count, 1);
        $depth = min(100, (int)round($avgwords / 50 * 100));

        $uniquepct = round(count(array_unique($normalised)) / $count * 100, 1);
        $personalisation = min(100, (int)round($uniquepct / 70 * 100));

        $avgquality = $totalquality / $count;
        $quality = min(100, (int)round($avgquality / 2.0 * 100));

        return ['depth' => $depth, 'quality' => $quality, 'personalisation' => $personalisation];
    }
}
