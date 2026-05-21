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
 * Student-facing message and announcement templates for the intervention composer.
 *
 * Diagnostic cards expose teacher-facing analytics — counts, percentages,
 * research citations. None of that belongs in the message a teacher sends to
 * the student. This class maps each diagnostic type to a warm, personal
 * student-facing template so the composer opens with a usable draft rather
 * than a copy-pasted analytics summary.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gradereport_coifish;

/**
 * Resolves diagnostic-type → template family → student-facing subject + body.
 */
class intervention_templates {
    /**
     * Map a diagnostic type key to a template family. Anything not listed here
     * falls through to the 'generic' warm-check-in template.
     *
     * @var array<string, string>
     */
    protected const FAMILY_MAP = [
        // Outstanding / missed work.
        'cohort_missed' => 'missing',
        'missed_deadlines' => 'missing',
        // Low engagement, isolation, peer disconnect.
        'cohort_engagement' => 'engagement',
        'engagement_low' => 'engagement',
        'cohort_isolation' => 'engagement',
        'coi_community_low' => 'engagement',
        'coi_peerconnection_low' => 'engagement',
        // Inactive for a while.
        'cohort_stale' => 'stale',
        // Feedback not opened.
        'cohort_feedback' => 'feedback',
        'feedback_low' => 'feedback',
        'coi_feedbackloop_low' => 'feedback',
        // Trend / performance concerns.
        'trend_declining' => 'performance',
        'streak_broken' => 'performance',
        'cohort_failing' => 'performance',
        'cohort_compound' => 'performance',
        // Self-regulation behaviours.
        'selfregulation_low' => 'selfreg',
        // Submission timing and pacing.
        'timing_late' => 'timing',
        'consistency_uneven' => 'timing',
        // Chronic extension requests — sensitive; soft tone.
        'cohort_extensions' => 'extensions',
    ];

    /**
     * Template families. Order matters for the JSON map sent to the modal.
     */
    public const FAMILIES = [
        'generic', 'missing', 'engagement', 'stale', 'feedback',
        'performance', 'selfreg', 'timing', 'extensions',
    ];

    /**
     * Resolve the template family for a given diagnostic type.
     *
     * @param string $diagnostictype
     * @return string Family key.
     */
    public static function family_for_diagnostic(string $diagnostictype): string {
        return self::FAMILY_MAP[$diagnostictype] ?? 'generic';
    }

    /**
     * Return a pre-fill draft for a specific family.
     *
     * Bodies contain a literal `{firstname}` placeholder which the messaging
     * dispatcher substitutes per recipient. Announcement bodies use a cohort
     * salutation ("Hi everyone,") and have no placeholder.
     *
     * @param string $family Template family key.
     * @param string $kind 'message' or 'announcement'.
     * @return array{subject:string, body:string}
     */
    public static function get_family(string $family, string $kind): array {
        $component = 'gradereport_coifish';
        $subjectkey = "template_{$kind}_{$family}_subject";
        $bodykey = "template_{$kind}_{$family}_body";

        $sm = get_string_manager();
        if (!$sm->string_exists($subjectkey, $component) || !$sm->string_exists($bodykey, $component)) {
            $subjectkey = "template_{$kind}_generic_subject";
            $bodykey = "template_{$kind}_generic_body";
        }
        return [
            'subject' => get_string($subjectkey, $component),
            'body' => get_string($bodykey, $component),
        ];
    }

    /**
     * Build the full template map (all families × both kinds) for client-side
     * lookup. The intervention modal embeds this once as a data attribute so
     * the composer can pre-fill without round-trips.
     *
     * @return array Nested map: [kind][family] => ['subject', 'body'].
     */
    public static function get_all_for_client(): array {
        $out = [];
        foreach (['message', 'announcement'] as $kind) {
            $out[$kind] = [];
            foreach (self::FAMILIES as $family) {
                $out[$kind][$family] = self::get_family($family, $kind);
            }
        }
        return $out;
    }
}
