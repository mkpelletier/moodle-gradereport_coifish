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
 * CLI script to generate a demo course for the Grade Tracker report.
 *
 * Creates a course with 3 grade categories, 10 assignments, 20 students,
 * and realistic grade distributions to showcase the dashboard.
 *
 * Usage: php generate_demo.php
 *
 * @package    gradereport_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/mod/assign/lib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/group/lib.php');

// Use the component_class_callback pattern to get the data generator in non-test CLI.
require_once($CFG->libdir . '/testing/generator/lib.php');
require_once($CFG->libdir . '/testing/generator/data_generator.php');
$generator = new \testing_data_generator();

cli_heading('Grade Tracker Demo Data Generator');

// 1. Create the course.
$course = create_course((object)[
    'fullname' => 'Grade Tracker Demo Course',
    'shortname' => 'GT-DEMO-' . time(),
    'category' => 1,
    'format' => 'topics',
    'numsections' => 4,
    'groupmode' => SEPARATEGROUPS,
]);
cli_writeln("Created course: {$course->fullname} (ID: {$course->id})");

// 2. Create groups.
$group1id = groups_create_group((object)[
    'courseid' => $course->id,
    'name' => 'Group A',
]);
$group2id = groups_create_group((object)[
    'courseid' => $course->id,
    'name' => 'Group B',
]);
cli_writeln("Created 2 groups");

// 3. Create grade categories with weights.
// Set course to weighted mean of grade categories.
$coursegradecat = grade_category::fetch_course_category($course->id);
$coursegradecat->aggregation = GRADE_AGGREGATE_WEIGHTED_MEAN; // Weighted mean with explicit weights.
$coursegradecat->update();

$categories = [];

// Assignments category — 40% weight.
$cat1 = new grade_category(['courseid' => $course->id], false);
$cat1->fullname = 'Assignments';
$cat1->aggregation = GRADE_AGGREGATE_WEIGHTED_MEAN2;
$cat1->insert();
$cat1item = $cat1->load_grade_item();
$cat1item->aggregationcoef = 40;
$cat1item->update();
$categories['assignments'] = $cat1;
cli_writeln("Created category: Assignments (40%)");

// Quizzes category — 25% weight.
$cat2 = new grade_category(['courseid' => $course->id], false);
$cat2->fullname = 'Quizzes';
$cat2->aggregation = GRADE_AGGREGATE_WEIGHTED_MEAN2;
$cat2->insert();
$cat2item = $cat2->load_grade_item();
$cat2item->aggregationcoef = 25;
$cat2item->update();
$categories['quizzes'] = $cat2;
cli_writeln("Created category: Quizzes (25%)");

// Exams category — 35% weight.
$cat3 = new grade_category(['courseid' => $course->id], false);
$cat3->fullname = 'Exams';
$cat3->aggregation = GRADE_AGGREGATE_WEIGHTED_MEAN2;
$cat3->insert();
$cat3item = $cat3->load_grade_item();
$cat3item->aggregationcoef = 35;
$cat3item->update();
$categories['exams'] = $cat3;
cli_writeln("Created category: Exams (35%)");

// 4. Create assignments.

/**
 * Helper to create an assignment in a specific section and grade category.
 *
 * @param stdClass $course The course object.
 * @param string $name The assignment name.
 * @param int $maxgrade The maximum grade for the assignment.
 * @param grade_category $category The grade category to place the assignment in.
 * @param int $section The course section number.
 * @param int $dueoffset Number of days in the past for the due date.
 * @param testing_data_generator $generator The data generator instance.
 * @return stdClass The created assignment module record.
 */
function create_demo_assignment($course, $name, $maxgrade, $category, $section, $dueoffset, $generator) {
    global $DB;

    $duetime = time() - ($dueoffset * DAYSECS);

    $assign = $generator->create_module('assign', [
        'course' => $course->id,
        'name' => $name,
        'grade' => $maxgrade,
        'section' => $section,
        'duedate' => $duetime,
        'submissiondrafts' => 0,
        'assignsubmission_onlinetext_enabled' => 1,
    ]);

    // Move grade item into the correct category.
    $gradeitem = grade_item::fetch([
        'itemtype' => 'mod',
        'itemmodule' => 'assign',
        'iteminstance' => $assign->id,
        'courseid' => $course->id,
    ]);

    if ($gradeitem) {
        $gradeitem->categoryid = $category->id;
        $gradeitem->update();
    }

    cli_writeln("  Created assignment: {$name} (max: {$maxgrade}, due: " . date('Y-m-d', $duetime) . ")");

    return $assign;
}

$assignments = [];

// Assignments category (4 assignments, past due).
$assignments[] = create_demo_assignment($course, 'Essay: Course Introduction', 100, $categories['assignments'], 1, 42, $generator);
$assignments[] = create_demo_assignment($course, 'Research Paper Draft', 100, $categories['assignments'], 1, 28, $generator);
$assignments[] = create_demo_assignment($course, 'Case Study Analysis', 100, $categories['assignments'], 2, 14, $generator);
$assignments[] = create_demo_assignment($course, 'Final Research Paper', 100, $categories['assignments'], 2, 3, $generator);

// Quizzes category (4 quizzes, past due).
$assignments[] = create_demo_assignment($course, 'Quiz 1: Foundations', 50, $categories['quizzes'], 1, 38, $generator);
$assignments[] = create_demo_assignment($course, 'Quiz 2: Core Concepts', 50, $categories['quizzes'], 2, 24, $generator);
$assignments[] = create_demo_assignment($course, 'Quiz 3: Applications', 50, $categories['quizzes'], 3, 10, $generator);
// One quiz not yet due (future).
$quizfuture = $generator->create_module('assign', [
    'course' => $course->id,
    'name' => 'Quiz 4: Integration',
    'grade' => 50,
    'section' => 4,
    'duedate' => time() + (14 * DAYSECS),
    'submissiondrafts' => 0,
    'assignsubmission_onlinetext_enabled' => 1,
]);
$giquiz4 = grade_item::fetch([
    'itemtype' => 'mod', 'itemmodule' => 'assign',
    'iteminstance' => $quizfuture->id, 'courseid' => $course->id,
]);
if ($giquiz4) {
    $giquiz4->categoryid = $categories['quizzes']->id;
    $giquiz4->update();
}
$assignments[] = $quizfuture;
cli_writeln("  Created assignment: Quiz 4: Integration (max: 50, due: " . date('Y-m-d', time() + 14 * DAYSECS) . ") [future]");

// Exams category (2 exams — midterm done, final still upcoming).
$assignments[] = create_demo_assignment($course, 'Midterm Exam', 100, $categories['exams'], 3, 7, $generator);
// Final exam not yet due.
$examfuture = $generator->create_module('assign', [
    'course' => $course->id,
    'name' => 'Final Exam',
    'grade' => 100,
    'section' => 4,
    'duedate' => time() + (28 * DAYSECS),
    'submissiondrafts' => 0,
    'assignsubmission_onlinetext_enabled' => 1,
]);
$giexam = grade_item::fetch([
    'itemtype' => 'mod', 'itemmodule' => 'assign',
    'iteminstance' => $examfuture->id, 'courseid' => $course->id,
]);
if ($giexam) {
    $giexam->categoryid = $categories['exams']->id;
    $giexam->update();
}
$assignments[] = $examfuture;
cli_writeln("  Created assignment: Final Exam (max: 100, due: " . date('Y-m-d', time() + 28 * DAYSECS) . ") [future]");

cli_writeln("Created 10 assignments across 3 categories");

// 5. Create 20 students.

$firstnames = [
    'Emma', 'Liam', 'Olivia', 'Noah', 'Ava', 'Ethan', 'Sophia', 'Mason',
    'Isabella', 'James', 'Mia', 'Benjamin', 'Charlotte', 'Lucas', 'Amelia',
    'Alexander', 'Harper', 'Daniel', 'Evelyn', 'Michael',
];

$lastnames = [
    'Anderson', 'Brown', 'Clark', 'Davis', 'Evans', 'Fisher', 'Garcia',
    'Harris', 'Ibrahim', 'Johnson', 'King', 'Lewis', 'Martinez', 'Nelson',
    'Olsen', 'Patel', 'Quinn', 'Roberts', 'Smith', 'Thompson',
];

$students = [];
$studentplugin = enrol_get_plugin('manual');
$manualinstance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);

for ($i = 0; $i < 20; $i++) {
    $user = $generator->create_user([
        'firstname' => $firstnames[$i],
        'lastname' => $lastnames[$i],
        'email' => strtolower($firstnames[$i]) . '.' . strtolower($lastnames[$i]) . '@demo.example.com',
    ]);

    // Enrol as student.
    $studentplugin->enrol_user($manualinstance, $user->id, $DB->get_field('role', 'id', ['shortname' => 'student']));

    // Assign to a group.
    groups_add_member($i < 10 ? $group1id : $group2id, $user->id);

    $students[] = $user;
}
cli_writeln("Created and enrolled 20 students (10 per group)");

// 6. Define student performance profiles.
// Each student gets a "base ability" that determines their general performance,
// with per-assignment variance to create realistic distributions.

// Profiles: mix of high, medium, low performers with different trajectories.
// [base_pct, variance, trend] where trend > 0 means improving, < 0 declining.
$profiles = [
    ['base' => 92, 'var' => 5, 'trend' => 1], // Emma — top performer, steady.
    ['base' => 85, 'var' => 8, 'trend' => 3], // Liam — strong, improving.
    ['base' => 78, 'var' => 10, 'trend' => 0], // Olivia — solid, steady.
    ['base' => 88, 'var' => 6, 'trend' => -2], // Noah — was great, slight dip.
    ['base' => 72, 'var' => 12, 'trend' => 5], // Ava — started low, big improvement.
    ['base' => 65, 'var' => 10, 'trend' => 2], // Ethan — below average, improving.
    ['base' => 95, 'var' => 3, 'trend' => 0], // Sophia — near perfect.
    ['base' => 55, 'var' => 15, 'trend' => 4], // Mason — struggled, now climbing.
    ['base' => 80, 'var' => 7, 'trend' => 1], // Isabella — above average.
    ['base' => 70, 'var' => 10, 'trend' => -1], // James — average, slight decline.
    ['base' => 60, 'var' => 12, 'trend' => 3], // Mia — below avg, getting better.
    ['base' => 50, 'var' => 15, 'trend' => 6], // Benjamin — started poorly, huge improvement.
    ['base' => 83, 'var' => 8, 'trend' => 0], // Charlotte — good, steady.
    ['base' => 75, 'var' => 10, 'trend' => -3], // Lucas — was decent, declining.
    ['base' => 68, 'var' => 9, 'trend' => 2], // Amelia — improving.
    ['base' => 90, 'var' => 5, 'trend' => 1], // Alexander — top tier.
    ['base' => 45, 'var' => 12, 'trend' => 7], // Harper — struggled a lot, big turnaround.
    ['base' => 58, 'var' => 14, 'trend' => 0], // Daniel — inconsistent.
    ['base' => 77, 'var' => 8, 'trend' => 2], // Evelyn — above average, improving.
    ['base' => 62, 'var' => 11, 'trend' => 1], // Michael — below average.
];

// 7. Grade the assignments.
// Only grade past-due assignments (index 0-7 and 8 = midterm).
// Indices 8 (Quiz 4 future) and 10 (Final Exam future) are NOT graded.
// Index mapping:
// 0: Essay (assignments) — due 42 days ago.
// 1: Research Draft (assign) — due 28 days ago.
// 2: Case Study (assign) — due 14 days ago.
// 3: Final Paper (assign) — due 3 days ago.
// 4: Quiz 1 (quizzes) — due 38 days ago.
// 5: Quiz 2 (quizzes) — due 24 days ago.
// 6: Quiz 3 (quizzes) — due 10 days ago.
// 7: Quiz 4 (quizzes) — future, no grades.
// 8: Midterm (exams) — due 7 days ago.
// 9: Final Exam (exams) — future, no grades.

$gradedindices = [0, 1, 2, 3, 4, 5, 6, 8]; // 8 graded items, 2 future.
$maxgrades = [100, 100, 100, 100, 50, 50, 50, 100]; // Matching max grades.

// Chronological order index for trend calculation (0 = earliest).
$chronoorder = [0, 4, 1, 5, 2, 6, 3, 8]; // Interleaved by due date.

cli_writeln("\nGrading assignments...");

foreach ($students as $si => $student) {
    $profile = $profiles[$si];
    $base = $profile['base'];
    $variance = $profile['var'];
    $trend = $profile['trend'];

    $gradecount = 0;
    foreach ($gradedindices as $gi => $assignindex) {
        $assign = $assignments[$assignindex];
        $maxgrade = $maxgrades[$gi];

        // Calculate chronological position for trend.
        $chronopos = array_search($assignindex, $chronoorder);
        $trendbonus = ($chronopos / 7) * $trend * 3; // Spread trend across timeline.

        // Random variance with trend.
        $rawpct = $base + $trendbonus + (mt_rand(-$variance * 100, $variance * 100) / 100);
        $rawpct = max(10, min(100, $rawpct)); // Clamp 10-100%.

        $grade = round($rawpct / 100 * $maxgrade, 1);

        // Set the grade via grade_item API.
        $gradeitem = grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $assign->id,
            'courseid' => $course->id,
        ]);

        if ($gradeitem) {
            $gradeitem->update_final_grade($student->id, $grade, 'import');
            $gradecount++;
        }
    }

    // Make 2-3 students have a late submission on the most recent assignment (index 3).
    if (in_array($si, [4, 10, 17])) {
        $sub = $DB->get_record('assign_submission', [
            'assignment' => $assignments[3]->id,
            'userid' => $student->id,
        ]);
        if ($sub) {
            $sub->status = ASSIGN_SUBMISSION_STATUS_SUBMITTED;
            $sub->timemodified = $assignments[3]->duedate + (1 * DAYSECS); // 1 day late.
            $DB->update_record('assign_submission', $sub);
        } else {
            $DB->insert_record('assign_submission', (object)[
                'assignment' => $assignments[3]->id,
                'userid' => $student->id,
                'status' => ASSIGN_SUBMISSION_STATUS_SUBMITTED,
                'timecreated' => $assignments[3]->duedate + (1 * DAYSECS),
                'timemodified' => $assignments[3]->duedate + (1 * DAYSECS),
                'attemptnumber' => 0,
                'latest' => 1,
            ]);
        }
    }

    cli_writeln("  Graded {$gradecount} items for {$firstnames[$si]} {$lastnames[$si]} " .
        "(base: {$base}%, trend: " . ($trend >= 0 ? '+' : '') . "{$trend})");
}

// 8. Give one student an extension.
$extensionstudent = $students[7]; // Mason.
$assigncm = get_coursemodule_from_instance('assign', $assignments[3]->id, $course->id);
$context = context_module::instance($assigncm->id);
$assignobj = new assign($context, $assigncm, $course);

$existingflags = $DB->get_record('assign_user_flags', [
    'assignment' => $assignments[3]->id,
    'userid' => $extensionstudent->id,
]);
if ($existingflags) {
    $existingflags->extensionduedate = time() + (7 * DAYSECS);
    $DB->update_record('assign_user_flags', $existingflags);
} else {
    $DB->insert_record('assign_user_flags', (object)[
        'assignment' => $assignments[3]->id,
        'userid' => $extensionstudent->id,
        'extensionduedate' => time() + (7 * DAYSECS),
    ]);
}
cli_writeln("\nGranted extension to Mason Harris on Final Research Paper");

// 9. Hide one assignment (to demo hidden items toggle).
$hiddenitem = grade_item::fetch([
    'itemtype' => 'mod',
    'itemmodule' => 'assign',
    'iteminstance' => $assignments[6]->id, // Quiz 3.
    'courseid' => $course->id,
]);
if ($hiddenitem) {
    $hiddenitem->set_hidden(1);
    cli_writeln("Hidden grade item: Quiz 3: Applications");
}

// 10. Configure plugin settings.
// Set thresholds.
set_config('threshold_pass', '50', 'gradereport_coifish');
set_config('threshold_merit', '65', 'gradereport_coifish');
set_config('threshold_distinction', '75', 'gradereport_coifish');

// Enable all widgets at site level.
set_config('widget_overall', '1', 'gradereport_coifish');
set_config('widget_neighbours', '1', 'gradereport_coifish');
set_config('widget_improvement', '1', 'gradereport_coifish');
set_config('widget_trend', '1', 'gradereport_coifish');
set_config('widget_streak', '1', 'gradereport_coifish');
set_config('widget_milestones', '1', 'gradereport_coifish');
set_config('leaderboard_min_enrolment', '10', 'gradereport_coifish');

// Enable gamification for this course.
$coursesettings = [
    'gamification_enabled' => true,
    'widgets' => [
        'overall' => true,
        'neighbours' => true,
        'improvement' => true,
        'trend' => true,
        'streak' => true,
        'milestones' => true,
    ],
];
set_config('course_' . $course->id, json_encode($coursesettings), 'gradereport_coifish');

cli_writeln("\nConfigured thresholds: Pass 50%, Merit 65%, Distinction 75%");
cli_writeln("Enabled all gamification widgets (site + course level)");

// 11. Regrade.
grade_regrade_final_grades($course->id);
cli_writeln("\nRecomputed course grades");

// Done.
$url = new moodle_url('/grade/report/coifish/index.php', ['id' => $course->id]);
cli_writeln("\n" . str_repeat('=', 60));
cli_writeln("Demo course ready!");
cli_writeln("Course ID: {$course->id}");
cli_writeln("Students:  20 (10 in Group A, 10 in Group B)");
cli_writeln("Graded:    8 of 10 assignments (2 future/ungraded)");
cli_writeln("URL:       {$url->out(false)}");
cli_writeln(str_repeat('=', 60));
