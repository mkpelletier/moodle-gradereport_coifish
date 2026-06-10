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
 * External function: send a message or post an announcement and auto-log the intervention.
 *
 * Used by the diagnostic-card "Send message" and "Post announcement" launchers
 * so the teacher only completes one form to both act and record the action.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gradereport_coifish\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Dispatches an intervention action (message or announcement) and records it.
 */
class dispatch_intervention extends external_api {
    /**
     * Define parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'studentids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Student user ID')
            ),
            'diagnostictype' => new external_value(PARAM_ALPHANUMEXT, 'Diagnostic card type key'),
            'scope' => new external_value(PARAM_ALPHA, 'individual or cohort'),
            'actionkind' => new external_value(PARAM_ALPHA, 'message or announcement'),
            'subject' => new external_value(PARAM_TEXT, 'Subject / discussion name'),
            'body' => new external_value(PARAM_RAW, 'Message or announcement body'),
            'notes' => new external_value(PARAM_TEXT, 'Optional teacher notes', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Execute the dispatch + log.
     *
     * @param int $courseid
     * @param array $studentids
     * @param string $diagnostictype
     * @param string $scope
     * @param string $actionkind
     * @param string $subject
     * @param string $body
     * @param string $notes
     * @return array
     */
    public static function execute(
        int $courseid,
        array $studentids,
        string $diagnostictype,
        string $scope,
        string $actionkind,
        string $subject,
        string $body,
        string $notes = ''
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'studentids' => $studentids,
            'diagnostictype' => $diagnostictype,
            'scope' => $scope,
            'actionkind' => $actionkind,
            'subject' => $subject,
            'body' => $body,
            'notes' => $notes,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('gradereport/coifish:intervene', $context);

        if (trim($params['subject']) === '' || trim($params['body']) === '') {
            throw new \invalid_parameter_exception('subject and body must not be empty');
        }
        if (!in_array($params['actionkind'], ['message', 'announcement'], true)) {
            throw new \invalid_parameter_exception('actionkind must be message or announcement');
        }

        // Resolve recipients. An explicit student list always wins. When a
        // cohort-scope action arrives with no explicit recipients, only an
        // ANNOUNCEMENT may fan out to every enrolled student — it posts to the
        // course forum, which the whole class sees anyway. A targeted MESSAGE
        // must never silently blast the entire class: that was the cause of
        // "missed deadline" reminders reaching all enrolled students. The empty
        // list normally only arrives if the client failed to resolve the card's
        // affected-student set, so we fail loudly rather than over-deliver.
        $recipientids = $params['studentids'];
        if (empty($recipientids) && $params['scope'] === 'cohort') {
            if ($params['actionkind'] !== 'announcement') {
                throw new \invalid_parameter_exception(
                    'a message action requires at least one explicit recipient'
                );
            }
            $enrolled = get_enrolled_users(
                $context,
                'moodle/course:isincompletionreports',
                0,
                'u.id',
                null,
                0,
                0,
                true
            );
            $recipientids = array_keys($enrolled);
        }

        // Resolve any per-recipient placeholders the body uses (currently
        // {missedwork} → each student's own past-due assessment names).
        $replacements = [];
        if ($params['actionkind'] === 'message' && strpos($params['body'], '{missedwork}') !== false) {
            $replacements = self::resolve_missedwork($params['courseid'], $context, $recipientids);
        }

        // Dispatch.
        $delivered = 0;
        $announcementdiscussionid = 0;
        if ($params['actionkind'] === 'message') {
            $delivered = \gradereport_coifish\messaging_dispatcher::send_to_recipients(
                $params['courseid'],
                $recipientids,
                $params['subject'],
                $params['body'],
                $replacements
            );
        } else {
            $announcementdiscussionid = \gradereport_coifish\announcement_poster::post(
                $params['courseid'],
                $params['subject'],
                $params['body']
            );
            // The announcement reaches every enrolled student; count them so the
            // logged intervention has an accurate "students touched" record.
            if ($announcementdiscussionid > 0) {
                $delivered = count($recipientids);
            }
        }

        // Auto-log the intervention so the teacher doesn't have to record it separately.
        // Notes capture the subject and a snippet of the body for the intervention
        // history view; the body itself isn't persisted in full to keep PII out of logs.
        $actiontype = ($params['actionkind'] === 'message')
            ? ($params['scope'] === 'cohort' ? 'message_group' : 'message_student')
            : 'announcement_posted';
        $autonotes = '[' . $params['subject'] . '] ' . shorten_text($params['body'], 240);
        if ($params['notes'] !== '') {
            $autonotes = $params['notes'] . "\n" . $autonotes;
        }
        $now = time();
        $intervention = (object)[
            'courseid' => $params['courseid'],
            'teacherid' => $USER->id,
            'diagnostictype' => $params['diagnostictype'],
            'scope' => $params['scope'],
            'actiontype' => $actiontype,
            'customaction' => null,
            'notes' => $autonotes,
            'timecreated' => $now,
        ];
        $interventionid = $DB->insert_record('gradereport_coifish_intv', $intervention);

        // Per-student snapshot rows — reuse the existing helper.
        $studentrecords = [];
        foreach ($recipientids as $studentid) {
            $snapshot = \gradereport_coifish\external\log_intervention::capture_snapshot(
                $params['courseid'],
                $studentid
            );
            $record = (object)[
                'interventionid' => $interventionid,
                'studentid' => $studentid,
                'snap_grade' => $snapshot['grade'],
                'snap_engagement' => $snapshot['engagement'],
                'snap_social' => $snapshot['social'],
                'snap_feedbackpct' => $snapshot['feedbackpct'],
                'snap_daysinactive' => $snapshot['daysinactive'],
            ];
            $record->id = $DB->insert_record('gradereport_coifish_intv_stu', $record);
            $studentrecords[] = ['id' => (int)$record->id, 'studentid' => $studentid];
        }

        return [
            'interventionid' => (int)$interventionid,
            'delivered' => $delivered,
            'announcementdiscussionid' => $announcementdiscussionid,
            'students' => $studentrecords,
        ];
    }

    /**
     * Build the {missedwork} placeholder value for each recipient: a
     * comma-separated list of that student's own past-due assessment names.
     *
     * Students with nothing outstanding (an edge case if a teacher hand-picks
     * recipients) get a neutral fallback so the sentence still reads naturally.
     *
     * @param int $courseid
     * @param \context_course $context
     * @param int[] $recipientids
     * @return array Map of userid => ['missedwork' => string].
     */
    protected static function resolve_missedwork(int $courseid, \context_course $context, array $recipientids): array {
        global $CFG;
        require_once($CFG->dirroot . '/grade/lib.php');

        $replacements = [];
        if (empty($recipientids)) {
            return $replacements;
        }

        // A user-id-less report instance is enough: the constructor skips the
        // heavy per-user grade loading when userid is 0, and get_cohort_missed_deadlines
        // takes the recipient list explicitly.
        $gpr = new \grade_plugin_return(['type' => 'report', 'plugin' => 'coifish', 'courseid' => $courseid]);
        $report = new \gradereport_coifish\report($courseid, $gpr, $context, 0, 0, false);
        $missed = $report->get_cohort_missed_deadlines(array_map('intval', $recipientids));

        $fallback = get_string('intervention_missedwork_fallback', 'gradereport_coifish');
        foreach ($recipientids as $uid) {
            // Use the raw (un-format_string'd) names: the message body is escaped
            // once by the messaging channel, so passing already-escaped names here
            // would double-encode characters like & in activity titles.
            $list = $missed[(int)$uid]['missedlistraw'] ?? [];
            $replacements[(int)$uid] = [
                'missedwork' => !empty($list) ? implode(', ', $list) : $fallback,
            ];
        }
        return $replacements;
    }

    /**
     * Define return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'interventionid' => new external_value(PARAM_INT, 'The new intervention ID'),
            'delivered' => new external_value(PARAM_INT, 'Number of recipients reached'),
            'announcementdiscussionid' => new external_value(PARAM_INT, 'Discussion ID when announcement, else 0'),
            'students' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Student intervention record ID'),
                    'studentid' => new external_value(PARAM_INT, 'Student user ID'),
                ])
            ),
        ]);
    }
}
