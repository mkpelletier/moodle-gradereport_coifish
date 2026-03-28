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
            // Item type at the top level (uncategorised items) - wrap in a virtual category.
            if ($type === 'item') {
                $item = $child['object'];
                // Skip the course total item.
                if ($item->is_course_item()) {
                    continue;
                }
                // Add to an "Uncategorised" bucket (handled below).
            }
        }

        // Collect uncategorised items only when there are no real categories.
        // When categories exist, these loose items are already accounted for in the course total.
        if (empty($categories)) {
            $uncategoriseditems = [];
            foreach ($element['children'] as $child) {
                if (
                    $child['type'] === 'item' && !$child['object']->is_course_item()
                        && !$child['object']->is_category_item()
                ) {
                    $childitem = $child['object'];
                    // Skip items with zero weight that aren't extra credit.
                    $itemweight = $this->get_item_weight($childitem);
                    if ($itemweight == 0 && !$this->is_extra_credit($childitem)) {
                        continue;
                    }
                    $ishidden = $childitem->is_hidden() && !$this->canviewhidden;
                    $uncategoriseditems[] = $this->process_grade_item($childitem, 1.0, $parenteffectiveweight, $ishidden);
                }
            }

            if (!empty($uncategoriseditems)) {
                $categories[] = [
                    'categoryname' => get_string('course'),
                    'categoryweight' => $this->format_percentage(1.0),
                    'categoryweight_raw' => 1.0,
                    'hasweight' => false,
                    'iscoursecategory' => true,
                    'items' => $uncategoriseditems,
                    'hasitems' => true,
                    'subcategories' => [],
                    'hassubcategories' => false,
                    'categorytotal' => $this->get_category_total_data($this->courseitem),
                ];
            }
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
        ];
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
        $gradedweightsum = 0;
        $weightedscoresum = 0;

        // Process direct items.
        if (!empty($cat['items'])) {
            foreach ($cat['items'] as $item) {
                if (!$item['graded'] || $item['isextracredit'] || $item['ishidden']) {
                    continue;
                }
                $weight = $item['weight_raw'];
                if ($weight <= 0 || $item['grademax_raw'] <= 0) {
                    continue;
                }
                $gradedweightsum += $weight;
                $weightedscoresum += $weight * ($item['grade_raw'] / $item['grademax_raw']);
            }
        }

        // Recurse into subcategories.
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
            if (!empty($cat['iscoursecategory'])) {
                continue;
            }
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

        // Pre-render goal messages for the template.
        foreach ($goals as &$goal) {
            if ($goal['already_met']) {
                $goal['message'] = get_string('goal_achieved', 'gradereport_coifish');
            } else if (!$goal['achievable']) {
                $goal['message'] = get_string(
                    'goal_notpossible',
                    'gradereport_coifish',
                    (object)['label' => $goal['label']]
                );
            } else {
                $goal['message'] = get_string(
                    'goal_target',
                    'gradereport_coifish',
                    (object)['label' => $goal['label'], 'required' => $goal['required']]
                );
            }
        }
        unset($goal);

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
        $totalgraded = 0;
        $totalitems = 0;
        $weightedscoreall = 0;
        $totalweightall = 0;
        $items = $cat['items'] ?? [];

        // Flatten subcategory items into this bar.
        if (!empty($cat['subcategories'])) {
            $items = array_merge($items, $this->flatten_subcategory_items($cat['subcategories']));
        }

        foreach ($items as $item) {
            if ($item['isextracredit'] || $item['ishidden']) {
                continue;
            }
            $totalitems++;
            $weight = $item['weight_raw'] ?? 0;
            if ($weight > 0 && $item['grademax_raw'] > 0) {
                $totalweightall += $weight;
                if ($item['graded'] && $item['grade_raw'] !== null) {
                    $totalgraded++;
                    $weightedscoreall += $weight * ($item['grade_raw'] / $item['grademax_raw']);
                }
                // Ungraded items contribute 0 to the numerator but their weight is still counted.
            }
        }

        // Category percentage: weighted average where ungraded items count as 0%.
        $catpercent = ($totalweightall > 0)
            ? round(($weightedscoreall / $totalweightall) * 100, 1)
            : 0;

        // Running total: percentage based on graded items only.
        $runningresult = $this->calculate_category_running_total($cat);
        $runningpercent = ($runningresult !== null) ? round($runningresult * 100, 1) : $catpercent;

        return [
            'name' => $cat['categoryname'],
            'weight' => $cat['categoryweight'] ?? '',
            'percentage' => $catpercent,
            'running_percentage' => $runningpercent,
            'graded_count' => $totalgraded,
            'total_count' => $totalitems,
        ];
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
                continue;
            }
            $catweight = $cat['categoryweight_raw'] ?? 1.0;
            $totalweight += $catweight;

            $items = $cat['items'] ?? [];
            if (!empty($cat['subcategories'])) {
                $items = array_merge($items, $this->flatten_subcategory_items($cat['subcategories']));
            }

            $itemweightsum = 0;
            $weightedscoresum = 0;
            foreach ($items as $item) {
                if ($item['isextracredit'] || $item['ishidden']) {
                    continue;
                }
                $weight = $item['weight_raw'];
                if ($weight <= 0 || $item['grademax_raw'] <= 0) {
                    continue;
                }
                $itemweightsum += $weight;
                if ($item['graded'] && $item['grade_raw'] !== null) {
                    $weightedscoresum += $weight * ($item['grade_raw'] / $item['grademax_raw']);
                } else {
                    // Assume 100% on ungraded items.
                    $weightedscoresum += $weight * 1.0;
                }
            }

            if ($itemweightsum > 0) {
                $totalweightedachieved += $catweight * ($weightedscoresum / $itemweightsum);
            } else {
                $totalweightedachieved += $catweight;
            }
        }

        if ($totalweight <= 0) {
            return 100;
        }

        return round(($totalweightedachieved / $totalweight) * 100, 1);
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
        // First pass: collect graded contribution and ungraded weight per category.
        $totalweight = 0;
        $gradedcontribution = 0;
        $ungradedweight = 0;

        foreach ($this->gradedata as $cat) {
            if (!empty($cat['iscoursecategory'])) {
                continue;
            }
            $catweight = $cat['categoryweight_raw'] ?? 1.0;
            $totalweight += $catweight;

            $items = $cat['items'] ?? [];
            if (!empty($cat['subcategories'])) {
                $items = array_merge($items, $this->flatten_subcategory_items($cat['subcategories']));
            }

            $itemweightsum = 0;
            $gradedscoresum = 0;
            $ungradedweightsum = 0;
            foreach ($items as $item) {
                if ($item['isextracredit'] || $item['ishidden']) {
                    continue;
                }
                $weight = $item['weight_raw'];
                if ($weight <= 0 || $item['grademax_raw'] <= 0) {
                    continue;
                }
                $itemweightsum += $weight;
                if ($item['graded'] && $item['grade_raw'] !== null) {
                    $gradedscoresum += $weight * ($item['grade_raw'] / $item['grademax_raw']);
                } else {
                    $ungradedweightsum += $weight;
                }
            }

            if ($itemweightsum > 0) {
                $gradedcontribution += $catweight * ($gradedscoresum / $itemweightsum);
                $ungradedweight += $catweight * ($ungradedweightsum / $itemweightsum);
            }
        }

        if ($totalweight <= 0) {
            return [];
        }

        // For each threshold, solve: (gradedcontribution + ungradedweight * x) / totalweight * 100 = target.
        $goals = [];
        foreach ($thresholds as $threshold) {
            $target = (float)$threshold['value'];
            $currentpercent = ($gradedcontribution / $totalweight) * 100;

            if ($currentpercent >= $target) {
                // Already met this threshold.
                $goals[] = [
                    'label' => $threshold['label'],
                    'value' => $threshold['value'],
                    'required' => 0,
                    'achievable' => true,
                    'already_met' => true,
                ];
                continue;
            }

            if ($ungradedweight <= 0) {
                // No remaining items — cannot improve.
                $goals[] = [
                    'label' => $threshold['label'],
                    'value' => $threshold['value'],
                    'required' => 0,
                    'achievable' => false,
                    'already_met' => false,
                ];
                continue;
            }

            $requiredx = (($target / 100) * $totalweight - $gradedcontribution) / $ungradedweight;
            $requiredpercent = round($requiredx * 100, 1);
            $achievable = $bestpossible >= $target;

            $goals[] = [
                'label' => $threshold['label'],
                'value' => $threshold['value'],
                'required' => min($requiredpercent, 100),
                'achievable' => $achievable,
                'already_met' => false,
            ];
        }

        return $goals;
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
            $items = $cat['items'] ?? [];
            if (!empty($cat['subcategories'])) {
                $items = array_merge($items, $this->flatten_subcategory_items($cat['subcategories']));
            }
            foreach ($items as $item) {
                if ($item['isextracredit'] || $item['ishidden']) {
                    continue;
                }
                if (!$item['graded'] || $item['grade_raw'] === null || $item['grademax_raw'] <= 0) {
                    continue;
                }
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
        }
        // Sort by time graded.
        usort($scores, function ($a, $b) {
            return $a['time'] <=> $b['time'];
        });
        return $scores;
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
        $now = time();
        $weeksenrolled = max(1, ceil(($now - $coursestart) / (7 * 86400)));

        // Need at least 2 weeks of enrolment for meaningful data.
        if ($weeksenrolled < 2) {
            return null;
        }

        // ── Indicator 1: Progress monitoring (grade report views) ──
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

        // ── Indicator 2: Feedback utilisation ──
        // Weight: 25% — reviewing graded feedback shows reflective behaviour.
        $gradedcount = (int)$DB->count_records_sql(
            "SELECT COUNT(ag.id)
               FROM {assign_grades} ag
               JOIN {assign} a ON a.id = ag.assignment
              WHERE a.course = :courseid AND ag.userid = :userid AND ag.grade >= 0",
            ['courseid' => $this->courseid, 'userid' => $userid]
        );
        $feedbackviewed = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT l.contextinstanceid)
               FROM {logstore_standard_log} l
              WHERE l.userid = :userid AND l.courseid = :courseid
                AND l.eventname IN (:ev1, :ev2)",
            [
                'userid' => $userid,
                'courseid' => $this->courseid,
                'ev1' => '\\mod_assign\\event\\feedback_viewed',
                'ev2' => '\\mod_assign\\event\\submission_status_viewed',
            ]
        );
        $feedbackrate = $gradedcount > 0 ? round(($feedbackviewed / $gradedcount) * 100) : 0;
        $feedbackscore = min(100, $feedbackrate);

        // ── Indicator 3: Resource revisiting ──
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

        // ── Indicator 4: Planning behaviour (early assignment views) ──
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

        // ── Composite score ──
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

        // Relative thresholds: use participation rate (threads engaged / total threads).
        $totaldiscussions = (int)$DB->count_records_sql(
            "SELECT COUNT(fd.id) FROM {forum_discussions} fd WHERE fd.course = :courseid",
            ['courseid' => $this->courseid]
        );
        $threadsparticipated = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT fd.id)
               FROM {forum_posts} fp
               JOIN {forum_discussions} fd ON fd.id = fp.discussion
              WHERE fd.course = :courseid AND fp.userid = :userid",
            ['courseid' => $this->courseid, 'userid' => $userid]
        );
        $participationrate = $totaldiscussions > 0
            ? round(($threadsparticipated / $totaldiscussions) * 100)
            : ($total > 0 ? 50 : 0);

        $level = $this->get_coi_level($participationrate, $this->get_coi_thresholds('sp', [1, 20, 50, 80]));

        // Recency: when was the student last active in discussions?
        $lastactive = $DB->get_field_sql(
            "SELECT MAX(fp.created)
               FROM {forum_posts} fp
               JOIN {forum_discussions} fd ON fd.id = fp.discussion
              WHERE fd.course = :courseid AND fp.userid = :userid",
            ['courseid' => $this->courseid, 'userid' => $userid]
        );
        $daysinactive = $lastactive ? round((time() - (int)$lastactive) / 86400) : null;
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

        // Relative thresholds: count distinct peers this student has interacted with.
        $peersengaged = (int)$DB->count_records_sql(
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
        // Compare to total active posters in the course.
        $activeposters = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT fp.userid)
               FROM {forum_posts} fp
               JOIN {forum_discussions} fd ON fd.id = fp.discussion
              WHERE fd.course = :courseid
                AND fp.userid != :userid",
            ['courseid' => $this->courseid, 'userid' => $userid]
        );
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
        $daysinactive = $lastactive ? round((time() - (int)$lastactive) / 86400) : null;
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
        $daysinactive = $lastactive > 0 ? round((time() - $lastactive) / 86400) : null;
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
            $action = get_string('widget_coi_feedbackloop_action', 'gradereport_coifish');
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
        $viewedfeedback = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT l.contextinstanceid)
               FROM {logstore_standard_log} l
              WHERE l.userid = :userid
                AND l.courseid = :courseid
                AND l.eventname IN (:ev1, :ev2)",
            [
                'userid' => $userid,
                'courseid' => $this->courseid,
                'ev1' => '\\mod_assign\\event\\feedback_viewed',
                'ev2' => '\\mod_assign\\event\\submission_status_viewed',
            ]
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
        ];
        if ($sensitivity === 'high') {
            // Lower thresholds = more sensitive.
            $triggers['isolation'] = 20;
            $triggers['engagement'] = 20;
            $triggers['feedback'] = 15;
            $triggers['stale_count'] = 2;
            $triggers['stale_pct'] = 15;
            $triggers['failing'] = 15;
        } else if ($sensitivity === 'low') {
            // Higher thresholds = less sensitive.
            $triggers['isolation'] = 40;
            $triggers['engagement'] = 40;
            $triggers['feedback'] = 35;
            $triggers['stale_count'] = 5;
            $triggers['stale_pct'] = 30;
            $triggers['failing'] = 30;
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

        // ── Gather student log data for detail modals ──
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
            0, 15
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
            0, 15
        );

        // Grade report views (for self-regulation).
        $gradereportlogs = $DB->get_records_sql(
            "SELECT l.id, l.timecreated, l.component, l.action
               FROM {logstore_standard_log} l
              WHERE l.userid = :userid AND l.courseid = :courseid
                AND l.component LIKE 'gradereport_%' AND l.action = 'viewed'
           ORDER BY l.timecreated DESC",
            ['userid' => $userid, 'courseid' => $courseid],
            0, 15
        );

        // Feedback view events (per assignment).
        $feedbacklogs = $DB->get_records_sql(
            "SELECT l.id, l.timecreated, l.objectid, l.eventname,
                    a.name AS assignname
               FROM {logstore_standard_log} l
          LEFT JOIN {assign} a ON a.id = l.objectid
              WHERE l.userid = :userid AND l.courseid = :courseid
                AND l.eventname IN (:ev1, :ev2)
           ORDER BY l.timecreated DESC",
            [
                'userid' => $userid, 'courseid' => $courseid,
                'ev1' => '\\mod_assign\\event\\feedback_viewed',
                'ev2' => '\\mod_assign\\event\\submission_status_viewed',
            ],
            0, 15
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
            0, 15
        );

        // ── Build formatted log data arrays for each card type ──
        $logcol_date = get_string('logcol_date', $component);
        $logcol_event = get_string('logcol_event', $component);
        $logcol_detail = get_string('logcol_detail', $component);
        $logcol_assessment = get_string('logcol_assessment', $component);
        $logcol_score = get_string('logcol_score', $component);
        $logcol_due = get_string('logcol_due', $component);
        $logcol_submitted = get_string('logcol_submitted', $component);
        $logcol_offset = get_string('logcol_offset', $component);
        $logcol_status = get_string('logcol_status', $component);
        $logcol_component = get_string('logcol_component', $component);

        // Trend & Streak: recent graded items.
        $itemscores = $this->get_student_item_scores();
        $trendlogdata = [];
        $passthreshold_val = $this->get_pass_threshold();
        foreach (array_reverse(array_slice($itemscores, -8)) as $item) {
            $trendlogdata[] = [
                'cells' => [
                    $item['time'] > 0 ? userdate($item['time'], $datefmt) : '–',
                    $item['name'],
                    $item['percent'] . '%',
                ],
                'highlight' => ($item['percent'] < $passthreshold_val),
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
            0, 15
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
                'cells' => [
                    $ga->assignname,
                    userdate($ga->gradedat, $datefmt),
                    $viewed ? userdate($fbviewbyassign[$aid], $datefmt)
                        : '<span class="text-danger fw-bold">' . get_string('log_not_viewed', $component) . '</span>',
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
        $buildstudentdetail = function(array $metrics, array $thresholds,
                string $methodologykey, string $rationalekey,
                array $logcolumns = [], array $logdata = []) use ($component, &$studentcardindex) {
            $studentcardindex++;
            return [
                'cardid' => 'scard' . $studentcardindex,
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
                    $trendmetrics[] = ['label' => get_string('detail_student_metric_feedbackpct', $component), 'value' => $fbpct . '%'];
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
                    [$logcol_date, $logcol_assessment, $logcol_score],
                    $trendlogdata
                );
                $cards[] = array_merge([
                    'icon' => 'line-chart',
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
                        ['label' => get_string('detail_threshold_passmark', $component), 'value' => $this->get_pass_threshold() . '%'],
                    ],
                    'detail_student_method_streak',
                    'detail_student_rationale_streak',
                    [$logcol_date, $logcol_assessment, $logcol_score],
                    $trendlogdata
                );
                $cards[] = array_merge([
                    'icon' => 'fire-extinguisher',
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
                $isolationmetrics[] = ['label' => get_string('detail_student_metric_daysinactive', $component),
                    'value' => max($widgets['coi_community']['daysinactive'] ?? 0,
                                    $widgets['coi_peerconnection']['daysinactive'] ?? 0) . ' ' .
                               get_string('detail_metric_days', $component)];
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
                [$logcol_date, $logcol_event, $logcol_detail],
                $isolationlogdata
            );
            $cards[] = array_merge([
                'icon' => 'user-times',
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
                    [$logcol_assessment, $logcol_date, $logcol_status],
                    $feedbacklogdata
                );
                $cards[] = array_merge([
                    'icon' => 'comment-o',
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
                    [$logcol_date, $logcol_component, $logcol_event],
                    $engagementlogdata
                );
                $cards[] = array_merge([
                    'icon' => 'book',
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
                    [$logcol_assessment, $logcol_due, $logcol_submitted, $logcol_offset],
                    $timinglogdata
                );
                $cards[] = array_merge([
                    'icon' => 'clock-o',
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
                    [$logcol_assessment, $logcol_submitted],
                    $consistencylogdata
                );
                $cards[] = array_merge([
                    'icon' => 'calendar',
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
                    [$logcol_date, $logcol_component],
                    $selfreglogdata
                );
                $cards[] = array_merge([
                    'icon' => 'dashboard',
                    'severity' => 'warning',
                    'title' => get_string('insight_selfregulation_title', $component),
                    'diagnostic' => get_string('insight_selfregulation_diagnostic', $component, $selfregcomposite),
                    'action' => get_string('insight_selfregulation_action', $component),
                ], $detail);
            }
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

        $context = $this->context;
        $enrolledusers = get_enrolled_users(
            $context,
            'moodle/course:isincompletionreports',
            $this->groupid ?: 0,
            'u.*',
            'u.lastname, u.firstname'
        );

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

        $summary = [];
        foreach ($enrolledusers as $user) {
            $finalgrade = isset($grades[$user->id]) ? $grades[$user->id]->finalgrade : null;
            $percentage = '–';
            if ($finalgrade !== null && (float)$this->courseitem->grademax > 0) {
                $percentage = $this->format_percentage(
                    (float)$finalgrade / (float)$this->courseitem->grademax
                );
            }
            $summary[] = [
                'userid' => $user->id,
                'fullname' => fullname($user),
                'grade' => ($finalgrade !== null)
                    ? $this->format_grade((float)$finalgrade, $this->courseitem) : '–',
                'grademax' => $this->format_grademax((float)$this->courseitem->grademax, $this->courseitem),
                'percentage' => $percentage,
                'viewurl' => (new \moodle_url('/grade/report/coifish/index.php', [
                    'id' => $this->courseid,
                    'userid' => $user->id,
                ]))->out(false),
            ];
        }

        return $summary;
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
        $enrolledusers = get_enrolled_users(
            $this->context,
            'moodle/course:isincompletionreports',
            $this->groupid ?: 0,
            'u.*',
            'u.lastname, u.firstname'
        );
        $userids = array_keys($enrolledusers);
        if (empty($userids)) {
            return ['hasdata' => false];
        }
        $usercount = count($userids);
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');

        // ── 1. Grade distribution ──
        $params = array_merge($inparams, ['itemid' => $this->courseitem->id]);
        $grades = $DB->get_records_select(
            'grade_grades',
            "itemid = :itemid AND userid $insql",
            $params,
            '',
            'userid, finalgrade'
        );
        $grademax = (float)$this->courseitem->grademax;
        $percentages = [];
        $graded = 0;
        $ungraded = 0;
        foreach ($userids as $uid) {
            $fg = isset($grades[$uid]) ? $grades[$uid]->finalgrade : null;
            if ($fg !== null && $grademax > 0) {
                $pct = round(((float)$fg / $grademax) * 100, 1);
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
        $validpcts = array_filter($percentages, fn($p) => $p !== null);
        $classaverage = !empty($validpcts) ? round(array_sum($validpcts) / count($validpcts), 1) : null;
        $classmedian = null;
        if (!empty($validpcts)) {
            sort($validpcts);
            $mid = floor(count($validpcts) / 2);
            $classmedian = (count($validpcts) % 2 === 0)
                ? round(($validpcts[$mid - 1] + $validpcts[$mid]) / 2, 1)
                : $validpcts[$mid];
        }

        // ── 2. COI presence aggregation ──
        // Social Presence: forum participation rate per student.
        $totaldiscussions = (int)$DB->count_records_sql(
            "SELECT COUNT(fd.id) FROM {forum_discussions} fd WHERE fd.course = :courseid",
            ['courseid' => $this->courseid]
        );

        // Per-user thread participation counts.
        $participationsql = "SELECT fp.userid, COUNT(DISTINCT fd.id) AS threads
                               FROM {forum_posts} fp
                               JOIN {forum_discussions} fd ON fd.id = fp.discussion
                              WHERE fd.course = :courseid AND fp.userid $insql
                           GROUP BY fp.userid";
        $participations = $DB->get_records_sql($participationsql, array_merge(
            ['courseid' => $this->courseid], $inparams
        ));

        // Per-user last forum activity.
        $lastpostsql = "SELECT fp.userid, MAX(fp.created) AS lastpost
                          FROM {forum_posts} fp
                          JOIN {forum_discussions} fd ON fd.id = fp.discussion
                         WHERE fd.course = :courseid AND fp.userid $insql
                      GROUP BY fp.userid";
        $lastposts = $DB->get_records_sql($lastpostsql, array_merge(
            ['courseid' => $this->courseid], $inparams
        ));

        // Cognitive Presence: engagement rate per student.
        $totalactivities = (int)$DB->count_records_sql(
            "SELECT COUNT(cm.id)
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid AND cm.deletioninprogress = 0
                AND m.name IN ('assign', 'quiz', 'page', 'book', 'resource', 'url', 'folder')",
            ['courseid' => $this->courseid]
        );

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
            $inparams, $inparams2, $inparams3
        );
        $engagements = $DB->get_records_sql($engagementsql, $engageparams);

        // Teaching Presence: feedback review rate per student.
        $feedbacktotalsql = "SELECT ag.userid, COUNT(ag.id) AS total
                               FROM {assign_grades} ag
                               JOIN {assign} a ON a.id = ag.assignment
                              WHERE a.course = :courseid AND ag.userid $insql AND ag.grade >= 0
                           GROUP BY ag.userid";
        $feedbacktotals = $DB->get_records_sql($feedbacktotalsql, array_merge(
            ['courseid' => $this->courseid], $inparams
        ));

        $feedbackviewsql = "SELECT l.userid, COUNT(DISTINCT l.contextinstanceid) AS viewed
                              FROM {logstore_standard_log} l
                             WHERE l.userid $insql AND l.courseid = :courseid
                               AND l.eventname IN (:ev1, :ev2)
                          GROUP BY l.userid";
        $feedbackviews = $DB->get_records_sql($feedbackviewsql, array_merge(
            $inparams,
            [
                'courseid' => $this->courseid,
                'ev1' => '\\mod_assign\\event\\feedback_viewed',
                'ev2' => '\\mod_assign\\event\\submission_status_viewed',
            ]
        ));

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
        $discussionviews = $DB->get_records_sql($discussionviewsql, array_merge(
            $inparamsdv,
            ['courseid' => $this->courseid]
        ));

        // ── 3. Classify each student and build presence summaries ──
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

        foreach ($userids as $uid) {
            $riskflags = 0;
            $flags = [];

            // Social Presence — participation rate.
            $threads = isset($participations[$uid]) ? (int)$participations[$uid]->threads : 0;
            $sprate = $totaldiscussions > 0 ? round(($threads / $totaldiscussions) * 100) : ($threads > 0 ? 50 : 0);
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
                    'fullname' => fullname($enrolledusers[$uid]),
                    'metric' => $threads . ' / ' . $totaldiscussions . ' (' . $sprate . '%)'
                        . ($issilent ? ' · ' . get_string('cohort_silent_label', $component, $dvcount) : ''),
                    'viewurl' => (new \moodle_url('/grade/report/coifish/index.php', [
                        'id' => $this->courseid, 'userid' => $uid, 'view' => 'insights',
                    ]))->out(false),
                ];
            }

            // Social stale check.
            $lastpost = isset($lastposts[$uid]) ? (int)$lastposts[$uid]->lastpost : 0;
            $isstale = ($threads > 0 && $lastpost > 0 && (time() - $lastpost) >= $staledays * 86400);
            if ($isstale) {
                $daysinactive = round((time() - $lastpost) / 86400);
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
                    'fullname' => fullname($enrolledusers[$uid]),
                    'metric' => $pct . '%',
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
                'posts' => $threads,
            ];

            // Collect at-risk students (2+ flags).
            if ($riskflags >= 2) {
                $atrisk[] = [
                    'userid' => $uid,
                    'fullname' => fullname($enrolledusers[$uid]),
                    'riskflags' => $riskflags,
                    'percentage' => $pct !== null ? $pct . '%' : '–',
                    'splevel' => $splevel['label'],
                    'spclass' => $splevel['class'],
                    'cplevel' => $cplevel['label'],
                    'cpclass' => $cplevel['class'],
                    'tplevel' => $tplevel['label'],
                    'tpclass' => $tplevel['class'],
                    'flaglist' => implode(', ', $flags),
                    'viewurl' => (new \moodle_url('/grade/report/coifish/index.php', [
                        'id' => $this->courseid, 'userid' => $uid, 'view' => 'insights',
                    ]))->out(false),
                ];
            }
        }

        // Sort at-risk by risk flag count descending.
        usort($atrisk, fn($a, $b) => $b['riskflags'] - $a['riskflags']);

        // ── 4. Build presence breakdown for template ──
        $presencelevels = ['none', 'emerging', 'developing', 'established', 'exemplary'];
        $presencelabels = [];
        $presenceshort = [];
        foreach ($presencelevels as $lv) {
            $presencelabels[$lv] = get_string('coi_level_' . $lv, $component);
            $presenceshort[$lv] = get_string('coi_level_short_' . $lv, $component);
        }

        $buildpresence = function(array $counts, string $title) use ($usercount, $presencelabels, $presenceshort, $presencelevels) {
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

        // ── 5. Diagnostic cards ──
        // Helper: build a detail block for "read more" modals.
        $cardindex = 0;
        $builddetail = function(array $metrics, array $thresholds, array $students,
                string $methodologykey, string $rationalekey) use ($component, &$cardindex) {
            $cardindex++;
            return [
                'cardid' => 'card' . $cardindex,
                'metrics' => $metrics,
                'hasmetrics' => !empty($metrics),
                'thresholds' => $thresholds,
                'hasthresholds' => !empty($thresholds),
                'students' => $students,
                'hasstudents' => !empty($students),
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
                ['label' => get_string('detail_metric_affected', $component), 'value' => $lowisolation . ' (' . $isolationpct . '%)'],
                ['label' => get_string('detail_metric_studentheading', $component), 'value' => get_string('detail_metric_sp_studentcol', $component)],
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
                    ['label' => get_string('detail_threshold_trigger', $component), 'value' => get_string('detail_threshold_isolation_trigger', $component)],
                    ['label' => get_string('detail_threshold_levels', $component), 'value' => get_string('detail_threshold_isolation_levels', $component)],
                    ['label' => get_string('detail_threshold_escalation', $component), 'value' => get_string('detail_threshold_isolation_escalation', $component)],
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
                'severity' => $isolationpct >= 50 ? 'danger' : 'warning',
                'title' => get_string('cohort_card_isolation_title', $component),
                'diagnostic' => $isolationdiag,
                'action' => get_string('cohort_card_isolation_action', $component, (object)[
                    'count' => $lowisolation, 'threads' => $totaldiscussions,
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
                    ['label' => get_string('detail_metric_affected', $component), 'value' => $lowengagement . ' (' . $engagementpct . '%)'],
                    ['label' => get_string('detail_metric_activitytypes', $component), 'value' => get_string('detail_metric_activitylist', $component)],
                ],
                [
                    ['label' => get_string('detail_threshold_trigger', $component), 'value' => get_string('detail_threshold_engagement_trigger', $component)],
                    ['label' => get_string('detail_threshold_levels', $component), 'value' => get_string('detail_threshold_engagement_levels', $component)],
                    ['label' => get_string('detail_threshold_escalation', $component), 'value' => get_string('detail_threshold_engagement_escalation', $component)],
                ],
                $lowengagementstudents,
                'detail_method_engagement',
                'detail_rationale_engagement'
            );
            $cards[] = array_merge([
                'icon' => 'book',
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
                    ['label' => get_string('detail_metric_affected', $component), 'value' => $lowfeedback . ' (' . $feedbackpct . '%)'],
                ],
                [
                    ['label' => get_string('detail_threshold_trigger', $component), 'value' => get_string('detail_threshold_feedback_trigger', $component)],
                    ['label' => get_string('detail_threshold_levels', $component), 'value' => get_string('detail_threshold_feedback_levels', $component)],
                    ['label' => get_string('detail_threshold_escalation', $component), 'value' => get_string('detail_threshold_feedback_escalation', $component)],
                ],
                $lowfeedbackstudents,
                'detail_method_feedback',
                'detail_rationale_feedback'
            );
            $cards[] = array_merge([
                'icon' => 'comment-o',
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
        if ($stalecount >= $triggers['stale_count'] || ($usercount > 0 && ($stalecount / $usercount * 100) >= $triggers['stale_pct'])) {
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
                    'fullname' => $s['fullname'],
                    'metric' => $s['days'] . ' ' . get_string('detail_metric_daysinactive', $component),
                    'viewurl' => $s['viewurl'],
                ];
            }
            $detail = $builddetail(
                [
                    ['label' => get_string('detail_metric_cohortsize', $component), 'value' => (string)$usercount],
                    ['label' => get_string('detail_metric_affected', $component), 'value' => (string)$stalecount],
                    ['label' => get_string('detail_metric_avgdays', $component), 'value' => $avgstaledays . ' ' . get_string('detail_metric_days', $component)],
                ],
                [
                    ['label' => get_string('detail_threshold_trigger', $component), 'value' => get_string('detail_threshold_stale_trigger', $component)],
                    ['label' => get_string('detail_threshold_window', $component), 'value' => get_string('detail_threshold_stale_window', $component)],
                    ['label' => get_string('detail_threshold_escalation', $component), 'value' => get_string('detail_threshold_stale_escalation', $component)],
                ],
                $stalestudentdetail,
                'detail_method_stale',
                'detail_rationale_stale'
            );
            $cards[] = array_merge([
                'icon' => 'clock-o',
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
                    ['label' => get_string('detail_threshold_trigger', $component), 'value' => get_string('detail_threshold_failing_trigger', $component)],
                    ['label' => get_string('detail_threshold_passmark', $component), 'value' => $this->get_pass_threshold() . '%'],
                    ['label' => get_string('detail_threshold_escalation', $component), 'value' => get_string('detail_threshold_failing_escalation', $component)],
                ],
                $belowpassstudents,
                'detail_method_failing',
                'detail_rationale_failing'
            );
            $cards[] = array_merge([
                'icon' => 'exclamation-triangle',
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

        // Cross-reference: isolation + low grades — compound risk.
        $isolatedandfailing = 0;
        $compoundnames = [];
        $compoundstudents = [];
        foreach ($userids as $uid) {
            $threads = isset($participations[$uid]) ? (int)$participations[$uid]->threads : 0;
            $sprate = $totaldiscussions > 0 ? round(($threads / $totaldiscussions) * 100) : ($threads > 0 ? 50 : 0);
            $pct = $percentages[$uid];
            if ($sprate < $spthresholds[1] && $pct !== null && $pct < $passthreshold) {
                $isolatedandfailing++;
                if (count($compoundnames) < 3) {
                    $compoundnames[] = fullname($enrolledusers[$uid]);
                }
                $compoundstudents[] = [
                    'fullname' => fullname($enrolledusers[$uid]),
                    'metric' => $pct . '% · ' . $threads . '/' . $totaldiscussions . ' threads',
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
                    ['label' => get_string('detail_threshold_trigger', $component), 'value' => get_string('detail_threshold_compound_trigger', $component)],
                    ['label' => get_string('detail_threshold_sp', $component), 'value' => get_string('detail_threshold_compound_sp', $component)],
                    ['label' => get_string('detail_threshold_grade', $component), 'value' => get_string('detail_threshold_compound_grade', $component)],
                ],
                $compoundstudents,
                'detail_method_compound',
                'detail_rationale_compound'
            );
            $cards[] = array_merge([
                'icon' => 'chain-broken',
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
                'starttime' => time() - 30 * 86400, // Last 30 days.
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
            'value' => (string)count($atrisk),
            'isrisk' => count($atrisk) > 0,
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

        // ── Course design awareness ──
        // If COI social presence flags are firing but the course has few or no
        // social activity types, the issue is likely course design, not student behaviour.
        $coursedesign = $this->get_course_design_notice($totaldiscussions, $lowisolation, $usercount, $isolationpct, $triggers);

        // ── Risk quadrant scatter data (S3 model) ──
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

        // ── Sociogram data (forum reply network) ──
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
        global $DB;

        $component = 'gradereport_coifish';
        $passthreshold = $this->get_pass_threshold();
        $spthresholds = $this->get_coi_thresholds('sp', [1, 20, 50, 80]);
        $course = get_course($this->courseid);
        $allgroups = groups_get_all_groups($this->courseid, 0, $course->defaultgroupingid);

        if (count($allgroups) < 2) {
            return ['hasgroups' => false];
        }

        $grademax = (float)$this->courseitem->grademax;
        $rows = [];

        foreach ($allgroups as $group) {
            $members = get_enrolled_users(
                $this->context,
                'moodle/course:isincompletionreports',
                $group->id,
                'u.id, u.firstname, u.lastname',
                'u.lastname, u.firstname'
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

            // COI presence health — lightweight: count forum threads per user.
            $totaldiscussions = (int)$DB->count_records_sql(
                "SELECT COUNT(fd.id) FROM {forum_discussions} fd WHERE fd.course = :cid",
                ['cid' => $this->courseid]
            );
            $participations = $DB->get_records_sql(
                "SELECT fp.userid, COUNT(DISTINCT fd.id) AS threads
                   FROM {forum_posts} fp
                   JOIN {forum_discussions} fd ON fd.id = fp.discussion
                  WHERE fd.course = :cid AND fp.userid $insql
               GROUP BY fp.userid",
                array_merge(['cid' => $this->courseid], $inparams)
            );
            $sphealthy = 0;
            foreach ($uids as $uid) {
                $threads = isset($participations[$uid]) ? (int)$participations[$uid]->threads : 0;
                $rate = $totaldiscussions > 0 ? round(($threads / $totaldiscussions) * 100) : ($threads > 0 ? 50 : 0);
                $level = $this->get_coi_level($rate, $spthresholds);
                if ($level['level'] >= 2) { // developing or above.
                    $sphealthy++;
                }
            }
            $sphealthpct = $count > 0 ? round(($sphealthy / $count) * 100) : 0;

            // At-risk: count students with 2+ simple risk flags (low SP + below pass).
            $atriskcount = 0;
            foreach ($uids as $uid) {
                $flags = 0;
                $threads = isset($participations[$uid]) ? (int)$participations[$uid]->threads : 0;
                $rate = $totaldiscussions > 0 ? round(($threads / $totaldiscussions) * 100) : ($threads > 0 ? 50 : 0);
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

            // Cognitive Presence: engagement rate per group.
            $totalactivities = (int)$DB->count_records_sql(
                "SELECT COUNT(cm.id)
                   FROM {course_modules} cm
                   JOIN {modules} m ON m.id = cm.module
                  WHERE cm.course = :cid AND cm.deletioninprogress = 0
                    AND m.name IN ('assign', 'quiz', 'page', 'book', 'resource', 'url', 'folder')",
                ['cid' => $this->courseid]
            );
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
                array_merge(['cid1' => $this->courseid, 'cid2' => $this->courseid, 'cid3' => $this->courseid],
                    $inparams, $inparams2, $inparams3)
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
            $feedbackviews = $DB->get_records_sql(
                "SELECT l.userid, COUNT(DISTINCT l.contextinstanceid) AS viewed
                   FROM {logstore_standard_log} l
                  WHERE l.userid $insql5 AND l.courseid = :cid
                    AND l.eventname IN (:ev1, :ev2)
               GROUP BY l.userid",
                array_merge($inparams5, [
                    'cid' => $this->courseid,
                    'ev1' => '\\mod_assign\\event\\feedback_viewed',
                    'ev2' => '\\mod_assign\\event\\submission_status_viewed',
                ])
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
            foreach ($uids as $uid) {
                $threads = isset($participations[$uid]) ? (int)$participations[$uid]->threads : 0;
                $lp = isset($lastposts[$uid]) ? (int)$lastposts[$uid]->lastpost : 0;
                if ($threads > 0 && $lp > 0 && (time() - $lp) >= $staledays * 86400) {
                    $stalecount++;
                }
            }

            $iscurrent = ($group->id == $this->groupid);

            $rows[] = [
                'groupname' => $group->name,
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
            ];
        }

        if (count($rows) < 2) {
            return ['hasgroups' => false];
        }

        // Compute course-wide averages for comparison baseline.
        $allavgs = array_filter(array_column($rows, 'averageraw'), fn($v) => $v !== null);
        $coursewide = !empty($allavgs) ? round(array_sum($allavgs) / count($allavgs), 1) : null;

        // ── Cross-group diagnostic analytics ──
        // Compare groups to identify significant disparities and generate explanations.
        $diagnostics = [];
        $avgsp = count($rows) > 0 ? round(array_sum(array_column($rows, 'sphealthpct')) / count($rows)) : 0;
        $avgeng = count($rows) > 0 ? round(array_sum(array_column($rows, 'avgengagement')) / count($rows)) : 0;
        $avgfb = count($rows) > 0 ? round(array_sum(array_column($rows, 'fbreviewpct')) / count($rows)) : 0;

        // Find best and worst groups by grade average.
        $graded = array_filter($rows, fn($r) => $r['averageraw'] !== null);
        if (count($graded) >= 2) {
            usort($graded, fn($a, $b) => ($b['averageraw'] ?? 0) <=> ($a['averageraw'] ?? 0));
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

                $actiontext = !empty($actions) ? implode(' ', $actions) : get_string('crossgroup_action_investigate', $component, $worst['groupname']);

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
                    ? round(array_sum(array_map(fn($x) => $x['studentcount'] > 0 ? ($x['stalecount'] / $x['studentcount'] * 100) : 0, $rows)) / count($rows))
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
                    'u.id'
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
            $totaldiscussions = (int)$DB->count_records_sql(
                "SELECT COUNT(fd.id) FROM {forum_discussions} fd WHERE fd.course = :cid",
                ['cid' => $this->courseid]
            );
            $participations = $DB->get_records_sql(
                "SELECT fp.userid, COUNT(DISTINCT fd.id) AS threads
                   FROM {forum_posts} fp
                   JOIN {forum_discussions} fd ON fd.id = fp.discussion
                  WHERE fd.course = :cid AND fp.userid $insql
               GROUP BY fp.userid",
                array_merge(['cid' => $this->courseid], $inparams)
            );
            $atriskcount = 0;
            foreach ($studentids as $uid) {
                $flags = 0;
                $threads = isset($participations[$uid]) ? (int)$participations[$uid]->threads : 0;
                $rate = $totaldiscussions > 0 ? round(($threads / $totaldiscussions) * 100) : ($threads > 0 ? 50 : 0);
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
        $now = time();
        $course = get_course($this->courseid);
        $coursestart = $course->startdate ?: ($now - 120 * 86400);
        $daysenrolled = max(1, ($now - $coursestart) / 86400);
        $weeksenrolled = max(1, $daysenrolled / 7);

        // Get all users with grading capability (teachers/editing teachers).
        $teachers = get_enrolled_users($context, 'moodle/grade:viewall', 0, 'u.*', 'u.lastname, u.firstname');
        if (empty($teachers)) {
            return ['teachers' => [], 'hasteachers' => false, 'summary' => []];
        }

        $teacherids = array_keys($teachers);
        [$insql, $inparams] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'tid');

        // ── 1. Insights tab visits (grade report views for this course). ──
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

        // ── 2. Grading turnaround: average time from submission to grade. ──
        [$insql2, $inparams2] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'grd');
        $gradingturnaround = $DB->get_records_sql(
            "SELECT ag.grader AS userid,
                    COUNT(ag.id) AS graded_count,
                    AVG(ag.timemodified - asub.timemodified) AS avg_turnaround
               FROM {assign_grades} ag
               JOIN {assign_submission} asub ON asub.assignment = ag.assignment
                    AND asub.userid = ag.userid AND asub.latest = 1
               JOIN {assign} a ON a.id = ag.assignment
              WHERE a.course = :courseid
                AND ag.grader $insql2
                AND ag.grade >= 0
                AND asub.timemodified > 0
                AND ag.timemodified > asub.timemodified
           GROUP BY ag.grader",
            array_merge(['courseid' => $this->courseid], $inparams2)
        );

        // ── 3. Forum engagement: posts and replies by teachers. ──
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

        // ── 4. BigBlueButton sessions (if module is installed). ──
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
                    AND bl.log = 'Create'
                    AND bl.userid $insql4
               GROUP BY bl.userid",
                array_merge(['courseid' => $this->courseid], $inparams4)
            );
        }

        // ── 5. Grade monitoring: how often teachers view the gradebook. ──
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

        // ── 6. Content updates: course module created/updated events. ──
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

        // ── 7. Messaging responsiveness: messages sent to students. ──
        // Get student user IDs.
        $students = get_enrolled_users($context, 'moodle/course:isincompletionreports', 0, 'u.id');
        $studentids = array_keys($students);
        $messagessent = [];
        if (!empty($studentids)) {
            [$insqlstu, $inparamsstu] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'stu');
            [$insql7, $inparams7] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'msg');
            $messagessent = $DB->get_records_sql(
                "SELECT useridfrom AS userid,
                        COUNT(*) AS cnt,
                        MAX(timecreated) AS last_message
                   FROM {messages}
                  WHERE useridfrom $insql7
                    AND conversationid IN (
                        SELECT mc.id
                          FROM {message_conversations} mc
                          JOIN {message_conversation_members} mcm ON mcm.conversationid = mc.id
                         WHERE mcm.userid $insqlstu
                    )
               GROUP BY useridfrom",
                array_merge($inparams7, $inparamsstu)
            );
        }

        // ── 8. Distinct active days in the course (overall engagement). ──
        [$insql8, $inparams8] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'act');
        $activedays = $DB->get_records_sql(
            "SELECT userid, COUNT(DISTINCT FROM_UNIXTIME(timecreated, '%Y-%m-%d')) AS days
               FROM {logstore_standard_log}
              WHERE courseid = :courseid
                AND userid $insql8
           GROUP BY userid",
            array_merge(['courseid' => $this->courseid], $inparams8)
        );

        // ── Build per-teacher result. ──
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
            // Weight: insights 15%, grading speed 20%, forum 15%, BBB 10%,
            //         grade monitoring 10%, content 10%, messaging 10%, active days 10%.
            $insightscore = min(100, round($insightspw / 1.0 * 100)); // 1 visit/week = 100%.
            $gradingscore = $avgturnarounddays !== null
                ? max(0, min(100, round((7 - $avgturnarounddays) / 7 * 100))) // 0 days = 100%, 7+ = 0%.
                : 50; // No grading data — neutral.
            $forumscore = min(100, round($forumpostspw / 3.0 * 100)); // 3 posts/week = 100%.
            $bbbscore = $bbbinstalled ? min(100, round($bbbpw / 0.5 * 100)) : 50; // 0.5 sessions/week = 100%.
            $grademonitoringscore = min(100, round($gradeviewspw / 2.0 * 100)); // 2 views/week = 100%.
            $contentscore = min(100, round($updatecount / max(1, $weeksenrolled) * 10)); // ~10 updates/course = 100%.
            $messagescore = min(100, round($messagespw / 2.0 * 100)); // 2 messages/week = 100%.
            $activescore = min(100, round($dayspw / 4.0 * 100)); // 4 active days/week = 100%.

            $composite = round(
                $insightscore * 0.15 +
                $gradingscore * 0.20 +
                $forumscore * 0.15 +
                $bbbscore * 0.10 +
                $grademonitoringscore * 0.10 +
                $contentscore * 0.10 +
                $messagescore * 0.10 +
                $activescore * 0.10
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
        usort($teacherresults, fn($a, $b) => $b['composite'] <=> $a['composite']);

        // Summary stats.
        $teachercount = count($teacherresults);
        $avgscore = $teachercount > 0 ? round($totalscore / $teachercount) : 0;
        $lowcount = count(array_filter($teacherresults, fn($t) => $t['rating'] === 'low'));
        $moderatecount = count(array_filter($teacherresults, fn($t) => $t['rating'] === 'moderate'));
        $highcount = count(array_filter($teacherresults, fn($t) => $t['rating'] === 'high'));

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
        $noinsights = count(array_filter($teacherresults, fn($t) => $t['insightsvisits'] === 0));
        if ($noinsights > 0) {
            $recommendations[] = [
                'severity' => 'warning',
                'icon' => 'eye-slash',
                'text' => get_string('coord_rec_no_insights', 'gradereport_coifish', $noinsights),
            ];
        }

        // Check for slow grading.
        $slowgraders = count(array_filter($teacherresults, fn($t) =>
            $t['avgturnarounddays'] !== null && $t['avgturnarounddays'] > 7
        ));
        if ($slowgraders > 0) {
            $recommendations[] = [
                'severity' => 'warning',
                'icon' => 'clock-o',
                'text' => get_string('coord_rec_slow_grading', 'gradereport_coifish', $slowgraders),
            ];
        }

        // Check for stale teachers.
        $staleteachers = count(array_filter($teacherresults, fn($t) => $t['isstale']));
        if ($staleteachers > 0) {
            $recommendations[] = [
                'severity' => 'danger',
                'icon' => 'user-times',
                'text' => get_string('coord_rec_stale_teacher', 'gradereport_coifish', $staleteachers),
            ];
        }

        return [
            'teachers' => $teacherresults,
            'hasteachers' => !empty($teacherresults),
            'hasbbb' => $bbbinstalled,
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
}
