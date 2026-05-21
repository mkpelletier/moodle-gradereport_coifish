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
 * Cross-channel messaging dispatcher.
 *
 * Sends a teacher message to one or more students through whichever messaging
 * channel the institution has selected as default in the CoIFish admin
 * settings — Moodle core messaging, SATS Mail, or Local Mail. Used by the
 * intervention "Send group/personal message" launcher so logging an
 * intervention and actually sending the message happen in one round trip.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gradereport_coifish;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/message/lib.php');

/**
 * Sends messages via the configured default channel.
 */
class messaging_dispatcher {
    /**
     * Resolve the default messaging source for outgoing dispatches.
     *
     * The admin can pick multiple sources for analytics (so the report sums
     * across channels), but for *sending* we use the first one as the
     * institution's chosen channel. Falls back to Moodle core if nothing is
     * configured.
     *
     * @return string Source key, e.g. 'core', 'local_satsmail'.
     */
    public static function get_default_source(): string {
        $config = get_config('gradereport_coifish', 'coordinator_messaging_sources');
        if ($config === false || $config === '') {
            return 'core';
        }
        foreach (explode(',', $config) as $source) {
            $source = trim($source);
            if ($source !== '') {
                return $source;
            }
        }
        return 'core';
    }

    /**
     * Human-readable label for the default source (used in composer UI hints).
     *
     * @return string Plain-text label such as "Moodle messaging" or "SATS Mail".
     */
    public static function get_default_source_label(): string {
        $source = self::get_default_source();
        if ($source === 'core') {
            return get_string('setting_msgsource_core', 'gradereport_coifish');
        }
        // Plugin sources expose their own pluginname string.
        $component = str_replace('local_', '', $source);
        if (get_string_manager()->string_exists('pluginname', 'local_' . $component)) {
            return get_string('pluginname', 'local_' . $component);
        }
        return $source;
    }

    /**
     * Send a message from the current user to each recipient via the default channel.
     *
     * Each recipient receives their own copy — the dispatcher does not create a
     * group conversation, since cohort-level interventions are usually meant to
     * read as personal outreach rather than a broadcast.
     *
     * @param int $courseid The course context the message belongs to.
     * @param int[] $recipientids Student user IDs.
     * @param string $subject Message subject (plain text).
     * @param string $body Message body (HTML or plain text — newlines preserved).
     * @return int Number of recipients successfully delivered to.
     * @throws \moodle_exception When the source is not supported or fails to send.
     */
    public static function send_to_recipients(
        int $courseid,
        array $recipientids,
        string $subject,
        string $body
    ): int {
        if (empty($recipientids)) {
            return 0;
        }
        $source = self::get_default_source();
        switch ($source) {
            case 'local_satsmail':
                return self::send_via_satsmail($courseid, $recipientids, $subject, $body);
            case 'local_mail':
                // Local Mail's API mirrors satsmail's; if the class isn't there, fall through.
                if (class_exists('\\local_mail\\message') && class_exists('\\local_mail\\message_data')) {
                    return self::send_via_localmail($courseid, $recipientids, $subject, $body);
                }
                return self::send_via_core($recipientids, $subject, $body);
            case 'core':
            default:
                return self::send_via_core($recipientids, $subject, $body);
        }
    }

    /**
     * Personalise the body for one recipient by substituting {firstname}.
     *
     * Kept central so every channel (core, satsmail, local_mail) handles the
     * placeholder identically and templates can be authored once.
     *
     * @param string $body Template body, possibly containing {firstname}.
     * @param \stdClass $user Recipient user record.
     * @return string
     */
    protected static function personalise(string $body, \stdClass $user): string {
        return str_replace('{firstname}', $user->firstname ?? '', $body);
    }

    /**
     * Send via Moodle core messaging.
     *
     * @param int[] $recipientids
     * @param string $subject
     * @param string $body
     * @return int Successful deliveries.
     */
    protected static function send_via_core(array $recipientids, string $subject, string $body): int {
        global $USER, $CFG;
        require_once($CFG->dirroot . '/message/lib.php');

        $sent = 0;
        foreach ($recipientids as $uid) {
            $recipient = \core_user::get_user((int)$uid);
            if (!$recipient) {
                continue;
            }
            $personalbody = self::personalise($body, $recipient);
            $eventdata = new \core\message\message();
            $eventdata->component = 'moodle';
            $eventdata->name = 'instantmessage';
            $eventdata->userfrom = $USER;
            $eventdata->userto = $recipient;
            $eventdata->subject = $subject;
            $eventdata->fullmessage = $personalbody;
            $eventdata->fullmessageformat = FORMAT_PLAIN;
            $eventdata->fullmessagehtml = nl2br(s($personalbody));
            $eventdata->smallmessage = shorten_text($personalbody, 200);
            $eventdata->notification = 0;
            $eventdata->courseid = SITEID;
            if (message_send($eventdata)) {
                $sent++;
            }
        }
        return $sent;
    }

    /**
     * Send via SATS Mail. One message per recipient so it threads as personal outreach.
     *
     * @param int $courseid
     * @param int[] $recipientids
     * @param string $subject
     * @param string $body
     * @return int Successful deliveries.
     */
    protected static function send_via_satsmail(int $courseid, array $recipientids, string $subject, string $body): int {
        global $USER;

        if (!class_exists('\\local_satsmail\\message') || !class_exists('\\local_satsmail\\message_data')) {
            // Plugin not actually loaded — fall back to core.
            return self::send_via_core($recipientids, $subject, $body);
        }
        $course = \local_satsmail\course::get($courseid);
        $sender = \local_satsmail\user::get($USER->id);
        $now = time();

        $sent = 0;
        foreach ($recipientids as $uid) {
            $recipient = \local_satsmail\user::get((int)$uid);
            if (!$recipient) {
                continue;
            }
            $userrecord = \core_user::get_user((int)$uid);
            $personalbody = $userrecord ? self::personalise($body, $userrecord) : $body;
            // The message_data constructor is private — the factory method is the
            // only entry point.
            $data = \local_satsmail\message_data::new($course, $sender);
            $data->subject = $subject;
            $data->content = nl2br(s($personalbody));
            $data->format = FORMAT_HTML;
            $data->to = [$recipient];
            $message = \local_satsmail\message::create($data);
            $message->send($now);
            $sent++;
        }
        return $sent;
    }

    /**
     * Send via Local Mail. Mirrors the satsmail path; only used when the class API is present.
     *
     * @param int $courseid
     * @param int[] $recipientids
     * @param string $subject
     * @param string $body
     * @return int Successful deliveries.
     */
    protected static function send_via_localmail(int $courseid, array $recipientids, string $subject, string $body): int {
        global $USER;

        $course = \local_mail\course::get($courseid);
        $sender = \local_mail\user::get($USER->id);
        $now = time();

        $sent = 0;
        foreach ($recipientids as $uid) {
            $recipient = \local_mail\user::get((int)$uid);
            if (!$recipient) {
                continue;
            }
            $userrecord = \core_user::get_user((int)$uid);
            $personalbody = $userrecord ? self::personalise($body, $userrecord) : $body;
            // Local Mail mirrors satsmail's factory shape.
            $data = \local_mail\message_data::new($course, $sender);
            $data->subject = $subject;
            $data->content = nl2br(s($personalbody));
            $data->format = FORMAT_HTML;
            $data->to = [$recipient];
            $message = \local_mail\message::create($data);
            $message->send($now);
            $sent++;
        }
        return $sent;
    }
}
