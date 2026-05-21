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
 * Post a discussion to the course's Announcements forum.
 *
 * Used by the intervention "Post course announcement" launcher so the teacher
 * can broadcast a cohort-scope intervention to every enrolled student without
 * leaving the diagnostic card.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gradereport_coifish;

/**
 * Posts new discussions to the course Announcements (News) forum.
 */
class announcement_poster {
    /**
     * Post a new discussion to the course's announcements forum.
     *
     * Looks up the news forum (Moodle creates one automatically per course with
     * `type='news'`). Returns the new discussion ID, or 0 if the forum is
     * missing or the current user lacks permission.
     *
     * @param int $courseid The course ID.
     * @param string $subject Discussion subject.
     * @param string $body Discussion body (newlines preserved).
     * @return int New discussion ID or 0 on failure.
     */
    public static function post(int $courseid, string $subject, string $body): int {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/mod/forum/lib.php');

        $forum = $DB->get_record('forum', ['course' => $courseid, 'type' => 'news']);
        if (!$forum) {
            return 0;
        }
        $cm = get_coursemodule_from_instance('forum', $forum->id, $courseid);
        if (!$cm) {
            return 0;
        }
        $context = \context_module::instance($cm->id);
        // Posting to news forum requires startdiscussion capability.
        if (!has_capability('mod/forum:addnews', $context)) {
            return 0;
        }

        $discussion = new \stdClass();
        $discussion->course = $courseid;
        $discussion->forum = $forum->id;
        $discussion->name = shorten_text($subject, 255);
        $discussion->message = nl2br(s($body));
        $discussion->messageformat = FORMAT_HTML;
        $discussion->messagetrust = trusttext_trusted($context);
        $discussion->mailnow = 0;
        $discussion->groupid = -1;
        $discussion->attachments = null;
        $discussion->pinned = FORUM_DISCUSSION_UNPINNED;
        $discussion->userid = $USER->id;
        $discussion->timestart = 0;
        $discussion->timeend = 0;

        $discussionid = forum_add_discussion($discussion, null, null, $USER->id);
        return $discussionid ? (int)$discussionid : 0;
    }
}
