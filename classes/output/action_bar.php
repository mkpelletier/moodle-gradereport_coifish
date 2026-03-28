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
 * Action bar renderable for teacher filter controls.
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gradereport_coifish\output;

use renderable;
use templatable;
use renderer_base;
use moodle_url;

/**
 * Renderable for the teacher action bar with group and user selectors.
 */
class action_bar implements renderable, templatable {
    /** @var \context_course The course context. */
    protected \context_course $context;

    /** @var int The course ID. */
    protected int $courseid;

    /** @var int The currently selected user ID (0 = none). */
    protected int $userid;

    /** @var int The currently selected group ID (0 = all). */
    protected int $groupid;

    /** @var string The currently active view (for preserving across navigation). */
    protected string $activeview;

    /** @var bool Whether the user can view the coordinator tab. */
    protected bool $canviewcoordinator;

    /**
     * Constructor.
     *
     * @param \context_course $context The course context.
     * @param int $courseid The course ID.
     * @param int $userid The selected user ID.
     * @param int $groupid The selected group ID.
     * @param string $activeview The currently active view tab.
     * @param bool $canviewcoordinator Whether the coordinator tab is accessible.
     */
    public function __construct(
        \context_course $context,
        int $courseid,
        int $userid = 0,
        int $groupid = 0,
        string $activeview = '',
        bool $canviewcoordinator = false
    ) {
        $this->context = $context;
        $this->courseid = $courseid;
        $this->userid = $userid;
        $this->groupid = $groupid;
        $this->activeview = $activeview;
        $this->canviewcoordinator = $canviewcoordinator;
    }

    /**
     * Export data for the mustache template.
     *
     * @param renderer_base $output The renderer.
     * @return \stdClass Template data.
     */
    public function export_for_template(renderer_base $output): \stdClass {
        global $USER;

        $data = new \stdClass();
        $course = get_course($this->courseid);

        // Group selector — only if groups are enabled in the course.
        $data->hasgroups = false;
        if ($course->groupmode != NOGROUPS) {
            $accessallgroups = has_capability('moodle/site:accessallgroups', $this->context);
            if ($accessallgroups) {
                $groups = groups_get_all_groups($this->courseid, 0, $course->defaultgroupingid);
            } else {
                $groups = groups_get_all_groups($this->courseid, $USER->id, $course->defaultgroupingid);
            }

            if (!empty($groups)) {
                $data->hasgroups = true;
                $data->groups = [];
                $data->groups[] = [
                    'groupid' => 0,
                    'groupname' => get_string('allparticipants'),
                    'selected' => ($this->groupid == 0),
                ];
                foreach ($groups as $group) {
                    $data->groups[] = [
                        'groupid' => $group->id,
                        'groupname' => format_string($group->name),
                        'selected' => ($this->groupid == $group->id),
                    ];
                }
            }
        }

        // User selector — only students (not teachers/managers).
        $enrolledusers = get_enrolled_users(
            $this->context,
            'moodle/course:isincompletionreports',
            $this->groupid ?: 0,
            'u.*',
            'u.lastname, u.firstname'
        );

        $data->users = [];
        $data->users[] = [
            'userid' => 0,
            'fullname' => get_string('allparticipants'),
            'selected' => ($this->userid == 0),
        ];
        foreach ($enrolledusers as $user) {
            $data->users[] = [
                'userid' => $user->id,
                'fullname' => fullname($user),
                'selected' => ($this->userid == $user->id),
            ];
        }

        $data->actionurl = (new moodle_url('/grade/report/coifish/index.php'))->out(false);
        $data->courseid = $this->courseid;
        $data->activeview = $this->activeview;

        // Course gamification settings link.
        $data->settingsurl = (new moodle_url(
            '/grade/report/coifish/coursesettings.php',
            ['id' => $this->courseid]
        ))->out(false);

        // Coordinator tab.
        $iscoordinatorview = ($this->activeview === 'coordinator');
        $data->showcoordinatortab = $this->canviewcoordinator;
        $data->iscoordinatorview = $iscoordinatorview;
        $data->coordinatorurl = (new moodle_url('/grade/report/coifish/index.php', [
            'id' => $this->courseid,
            'view' => 'coordinator',
        ]))->out(false);
        $data->studentsurl = (new moodle_url('/grade/report/coifish/index.php', [
            'id' => $this->courseid,
        ]))->out(false);

        // Hide student-specific controls on coordinator view.
        $data->showstudentcontrols = !$iscoordinatorview;

        // Display widgets toggle (only when viewing a specific student).
        $data->showwidgetstoggle = ($this->userid > 0 && !$iscoordinatorview);
        if ($data->showwidgetstoggle) {
            $raw = get_config('gradereport_coifish', 'course_' . $this->courseid);
            $coursesettings = $raw ? (json_decode($raw, true) ?: []) : [];
            $data->widgetsenabled = !empty($coursesettings['gamification_enabled']);
            $data->togglewidgetsurl = (new moodle_url('/grade/report/coifish/index.php', [
                'id' => $this->courseid,
                'userid' => $this->userid,
                'togglewidgets' => 1,
                'sesskey' => sesskey(),
            ]))->out(false);
        }

        return $data;
    }
}
