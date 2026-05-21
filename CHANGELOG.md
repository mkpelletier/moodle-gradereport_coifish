# Changelog

## [2.6.0] - 2026-05-20

### Added
- **Active intervention launchers** — three actions in the intervention dialog now perform the action AND auto-log the intervention in one round trip:
  - *Send personal / group message* dispatches through the institution's configured default messaging channel (Moodle core, SATS Mail, or Local Mail) with one personalised copy per recipient.
  - *Send feedback reminder* uses the same dispatch flow with a feedback-specific template family.
  - *Post course announcement* posts a new discussion to the course's Announcements (News) forum on the cohort's behalf.
  - All four are grouped under a new "Send and log" optgroup at the top of the action dropdown; passive (record-only) action types sit beneath in a "Record only" group.
- **Student-facing message + announcement templates** — nine diagnostic-family templates (`missing`, `engagement`, `stale`, `feedback`, `performance`, `selfreg`, `timing`, `extensions`, `generic`) × `message` and `announcement` variants. The composer pre-fills with warm, personal copy that addresses the student directly — no analytics text, counts, percentages, or research citations leak into the message body. Bodies use `{firstname}` which the dispatcher substitutes per recipient.
- **Unified Grader integration** in the feedback metrics. The teacher feedback-quality task now counts submission comments (`local_unifiedgrader_scomm`), annotations (`local_unifiedgrader_annot`), and per-attempt quiz feedback (`local_unifiedgrader_qfb`) as feedback signals — including for forum, quiz, and BBB activities that have no native feedback table. The student-side feedback-review metric counts the new `\local_unifiedgrader\event\feedback_viewed` event alongside the native `mod_assign` events. All UG queries are gated by the admin's per-activity-type `enable_<modname>` settings AND `table_exists()` so installs without UG are unaffected.
- **Missed-deadline diagnostic** at cohort and per-student level. Counts past-due assessments (assign / quiz / graded forum) that the student hasn't submitted and doesn't hold a user or group override on. Surfaces as a new risk flag in the at-risk pipeline, a sortable "Missed" column on the at-risk table, a `cohort_missed` diagnostic card (triggered at ≥3 students or ≥15% of cohort at normal sensitivity), and a `missed_deadlines` card on per-student insights that lists the activities by name. A companion `cohort_extensions` card flags students with chronic deadline overrides (≥3 user-level extensions at normal sensitivity).
- **Group filters on cohort overview**. Group dropdown now appears whenever a course has any groups defined, regardless of `$course->groupmode` (Moodle's NOGROUPS only disables activity separation; groups can still exist as a tool for tutorial cohorts, marking pools, etc.).
- **Group memberships on the single-student report header** — group badges sit beside the student's name in the teacher view, visible across all sub-views (table, progress, insights).
- **Group column + sortable headers on "Students requiring attention"**. New generic `sortable_table.js` AMD module — any `<table data-sortable="true">` with `<th class="gradetracker-sortable" data-sorttype="text|number">` picks up click-to-sort with stable ordering. Numeric sort honors `data-sortvalue` so percentages and badge labels sort by their underlying value, not their displayed text.

### Changed
- **Withdrawn / suspended enrolments are now excluded from the live report** (group dropdown, user dropdown, summary table, cohort insights, cross-group comparison, coordinator tab, intervention recipient lists, feedback-metrics task). Every `get_enrolled_users()` call passes `$onlyactive=true`. Longitudinal data in `local_coifish` continues to capture snapshots taken while the student was active.
- **Cross-group teacher comparison now respects every configured messaging source.** Previously it queried Moodle core `{messages}` directly, missing teachers who communicate primarily via SATS Mail or Local Mail. Now routes through the existing `query_messaging_source()` helper.
- Engagement-metric denominator in cohort insights, the at-risk modal, intervention snapshots, and `local_coifish` longitudinal snapshots now subtracts optional assignments/quizzes that the category's drop/keep rule discards.

### Fixed
- **Group-filter gating leak**: a teacher without `gradereport/coifish:viewallgroups` who wasn't in any course group was being shown the entire course roster because `get_scoped_groupids()` returned `[]` for both the cap-holder "show everyone" case and the no-cap-no-group "show nobody" case. Added `has_unconstrained_view()` so the caller can distinguish, and `get_scoped_enrolled_users()` now returns an empty set when the viewer has no capability AND no groups.
- The user-dropdown default option now reads "All my students" for group-scoped viewers (was misleadingly fixed to "All participants").
- **Sociogram tooltip on edge nodes** was being clipped by the container's `overflow: hidden`. Tooltip now anchors via `getBoundingClientRect()` (consistent across browsers) and flips horizontally/vertically when it would overflow.
- `fullname()` debug notice (missing `firstnamephonetic`, `lastnamephonetic`, `middlename`, `alternatename`) in the intervention button label, intervention history teacher name, and coordinator escalation list. All three now use `\core_user\fields::for_name()->get_required_fields()`.
- `intervention_action_announcement_posted` lang string was missing — the intervention history view rendered `[[…]]` for any logged announcement.
- `local_satsmail\message_data::__construct()` is private; the dispatcher now uses the `::new($course, $sender)` factory. Same fix applied to the `local_mail` path.
- **Lecturer feedback metric misreporting** when teachers feedback via Unified Grader / multimedia. Several related fixes:
  - **Multimedia feedback now counts.** Voice notes, screencasts, embedded video, and Loom/YouTube/Vimeo/BBB links in feedback text are detected via pattern matching (`<audio>`, `<video>`, `<iframe>`, `.mp3`/`.mp4`/etc) and credited with a full 3/3 quality score and an 80-word floor, since AI text assessment cannot evaluate them.
  - **"Written feedback" relabelled "Feedback coverage"** — the metric now aggregates written *and* multimedia feedback, so the old name was misleading.
  - **Coverage exceeding 100% bug**: `UNION ALL` over assign-submission feedback, editpdf comments, file annotations, gradebook overall-feedback, and UG submission/annotation/quiz signals was double-counting graded items that had feedback in multiple channels. Numerator and denominator are now `UNION`-distinct over `(modname, instanceid, userid)` tuples.
  - **Module-type filter for feedback-relevant activities.** New `get_feedback_relevant_modnames()` filters both numerator and denominator to `assign / forum / quiz / lesson / workshop / bigbluebuttonbn / data`, so auto-graded LTI and SCORM items no longer drag coverage down.
  - **Gradebook overall-feedback signals** (`grade_grades.feedback`) on non-assign items are now harvested for both feedback-quality analysis and signal counting — this is where UG-on-forum feedback lands.
  - **Student feedback-review denominator** broadened to match (same module-type filter), and the event list now includes `\gradereport_user\event\grade_report_viewed` so a student opening their gradebook counts as a feedback view.
  - **Grade-turnaround broadened** to `UNION` across assign / forum / quiz (previously assign only), so courses where grading happens in forums/quizzes report a real turnaround instead of `-`.
  - **BBB session count broadened** — the coordinator-tab BBB count was filtering log rows by `log = 'Create'`, which excluded most BBB activities. Now counts BBB activities unconditionally.
  - **Named-parameter SQL inflation** in `get_ug_nonassign_signals()` — Moodle expands `:name` to positional `?` per occurrence, and a reused `IN()` clause across UNION branches inflated the expected parameter count (`Expected 15, got 8`). Each branch now mints its own IN clause with a unique prefix (`ugtsc`, `ugtan`, `ugmsc`, `ugman`, `ugtqf`).
- **Orphan top-level grade items now display in the student report.** Assessments sitting at course root (not inside a subcategory) — e.g. a standalone final exam — were previously hidden. They now appear as their own cards in both the table view and the progress view, each card showing the item's own name and its course-relative weight (so a 60%-weighted final exam reads as a dedicated 60% card rather than being lumped into a "course-level assessments" wrapper).

## [2.5.2] - 2026-05-19

### Added
- **Group support** in the report and insights tabs. New `gradereport/coifish:viewallgroups` capability controls whether a role can see every group in a course; default ALLOW for editingteacher/manager, unset for non-editing teacher. Teachers without the capability see only the groups they're a member of, and the default "All my groups" selector unions those memberships rather than exposing every enrolled student. `groupid` URL tampering is validated and silently dropped when the viewer isn't entitled to the requested group.
- **Drop-the-lowest / keep-the-highest awareness** across the report.
  - Category aggregation metadata (`droplow`, `keephigh`) is now displayed alongside the category weight in both the table view and the progress chart.
  - Per-category running totals, the progress chart, best-possible, goal-target bisection, the bulk running-average column in the teacher summary, and the streak/trend/milestones diagnostics all skip items the category will discard.
  - The "X of Y graded" ring on each category bar now reflects the effective expected count after drop/keep, so optional assignments no longer make completion look incomplete.
- **Effective-now clamping for concluded courses** — new `report::effective_now()` returns `min(time(), $course->enddate)` when the course has ended. Wired into staleness/days-inactive diagnostics, cohort flags, weeks-enrolled anchors, the 30-day activity-balance window, and the 365-day collaboration lookback. Intervention snapshots in `log_intervention.php` clamp the same way. Closed courses no longer drift further into "X days absent" territory.
- **Drop/keep-aware engagement denominator** — new `report::get_expected_activity_count()` excludes optional assignments/quizzes from the engagement metric in cohort insights, the at-risk modal, intervention snapshots, and local_coifish longitudinal snapshots.

### Fixed
- Teacher summary "running average" column was computing a flat weighted mean across all grade items; now walks the category tree per-student and honors drop/keep.
- Pass-streak / trend / milestones diagnostics no longer treat would-be-dropped assignments as missed work.
- install.xml now declares the XMLDB schema namespace (resolves `core\db\plugin_checks_test::test_db_install_file` failure).

## [2.4.2] - 2026-04-09

### Changed
- **Social presence metric rewritten** — Now a multi-signal composite: forum breadth (60%) and volume (40%) for forum engagement, plus BigBlueButton attendance (20%), collaborative activities (15%), and peer messaging (15%). Weights redistribute when BBB is not installed.
- **Group-aware forum metrics** — Forum group modes (separate groups, visible groups) are now respected. Students in separate groups are only measured against discussions visible to them, preventing artificial deflation of social presence scores.
- **Running average in summary table** — New column showing weighted running average based on graded items only, alongside the existing marks achieved percentage.
- **Running averages in cohort analytics** — Risk quadrant, sociogram, grade distribution, and all cohort insights now use running averages instead of marks achieved, giving a realistic picture early in a course.
- All social presence calculations aligned across the plugin: student widget, cohort cards, cross-group comparison, cross-teacher comparison, compound risk detection, risk quadrant engagement index, and intervention snapshots all use the same composite methodology.
- Updated diagnostic text, methodology descriptions, and prescriptive recommendations to reflect multi-signal social presence composite.

## [2.4.1] - 2026-04-08

### Changed
- Repository renamed to `moodle-gradereport_coifish` following Moodle plugin naming convention (#1).
- Replaced `PARAM_RAW_TRIMMED` with `PARAM_ALPHANUMEXT` in course settings form for security (#4).
- Added Moodle boilerplate headers to `styles.css` and template files (#5).
- Moved inline stylesheets from setting templates to `styles.css` (#8).
- Hard-coded JavaScript strings replaced with localised strings via data attributes and lang API (#6).
- Replaced `innerHTML` in sociogram tooltip with safe DOM construction (#9).
- Added time bounds (365-day lookback) to logstore queries in intervention snapshot capture (#2).
- Full privacy provider implementation with metadata for intervention and feedback tables, export and delete support (#3).
- Course settings page converted from PHP echo blocks to Mustache template with renderable class (#7).

## [2.4.1] - 2026-04-02

### Changed
- Replaced inaccurate research citations: Dawson (2006) four-category claim removed, Macfadyen & Dawson r=.95 corrected to 2010 paper, Yorke (2003) replaced with Muljana & Luo (2019), Bawa (2016) attrition statistic softened.
- Longitudinal profile integration with local_coifish: early warning section on cohort insights, student profile on insights tab.
- Course-level toggle for longitudinal profiles in report settings.

### Fixed
- Capability lang string casing: "View COIfish user report" corrected to "View CoIFish user report".
- Feedback loop widget lang key when no graded assignments exist.

## [2.4.0] - 2026-03-31

### Added
- **Intervention tracking system** — Teachers can record interventions directly from diagnostic insight cards, closing the analytics loop identified in LAK research (Clow, 2012; Wise, 2014).
  - Low-friction "Log intervention" button on every student-level and cohort-level diagnostic card.
  - Pre-populated modal with diagnostic context, student selection (checkboxes for cohort interventions), preset action types, and optional notes.
  - Context-sensitive action options: individual interventions offer personal messaging, meetings, peer pairing, study plans, and referrals; cohort interventions offer group messaging, discussion prompts, activity restructuring, and resource provision.
  - Metric snapshots captured server-side at intervention time (grade, engagement, social presence, feedback review, days inactive).
  - AJAX submission via Moodle external function with capability check (`gradereport/coifish:intervene`).
- **Intervention outcome evaluation** — Scheduled task (daily at 2:30 AM) compares current student metrics to intervention snapshots at configurable follow-up intervals (7, 14, 28, 60, 90 days).
  - Outcome classification (improved, stable, declined) weighted by the diagnostic type that triggered the intervention.
  - Follow-up schedule configured via multi-checkbox setting (no comma-separated values).
- **Intervention history timeline** — Visible on the student insights tab showing past interventions with date, teacher, action, outcome badge, and snapshot-vs-current metric comparison.
- **Coordinator intervention analytics** — New section on the coordinator tab with summary cards (total interventions, improved/stable/declined percentages), effectiveness by diagnostic type, and an escalation list for students with 3+ interventions and no improvement.
- **Insights tab course-level override** — Admins can enable or disable the Insights tab per course, overriding the site-level default.
- New capability `gradereport/coifish:intervene` for teachers and managers.
- Three new database tables for intervention records, per-student snapshots, and follow-up outcomes.

### Fixed
- Feedback loop widget showing raw lang key when no graded assignments exist.
- Student insights view not respecting the site-level Insights tab toggle.
- Course-level insights override not saving due to `PARAM_ALPHA` stripping numeric values.

## [2.2.0] - 2026-03-30

### Added
- **Feedback quality dimension** — New 9th dimension in the coordinator engagement composite, measuring feedback coverage, depth (word count), qualitative indicators (dialogic, actionable, substantive markers), personalisation (uniqueness), and structured grading (rubric/marking guide usage). Grounded in Hattie & Timperley (2007), Nicol & Macfarlane-Dick (2006), and Boud & Molloy (2013).
- **Scheduled task** — Daily pre-computation of feedback quality metrics (default 2:00 AM) with database cache table, avoiding expensive text analysis at page load.
- **Configurable grading turnaround** — Target and maximum day sliders replace the hardcoded 0-day/7-day formula, allowing institutions to set realistic benchmarks.
- **Content updates toggle** — Disable the content updates dimension when curriculum design is handled by a separate team.
- **Messaging sources multi-select** — Admins can select which messaging tools to monitor (Moodle core, local_satsmail, etc.). Detected automatically from installed plugins.
- **Insights tab toggle** — Site-level setting to show or hide the Insights tab on the teacher summary view.
- **Percentile visibility threshold** — Slider to only show the class standing widget to students in the top N percent (default: top third).
- **Cross-teacher comparison on coordinator tab** — Moved from the cohort insights view to the coordinator tab where it belongs.
- **Per-group teacher engagement metrics** — Cross-group comparison now shows the teacher's own forum posts, messages, grading turnaround, and feedback coverage per group.
- **Engagement correlation diagnostics** — When groups differ in performance, diagnostics now probe whether the teacher's engagement also differs, surfacing correlations between facilitation effort and student outcomes.
- Prescriptive recommendations for low feedback coverage and generic (copy-pasted) feedback.

### Changed
- Coordinator composite weights rebalanced for 9 dimensions: grading 15%, feedback quality 15%, forum 13%, insights 12%, monitoring 10%, content 10%, messaging 9%, BBB 8%, active days 8%.
- Cross-group comparison scoped to the current teacher's groups (falls back to all groups if teacher is not in any).
- CoI level boundary setting descriptions shortened for slider UI.
- Unachievable goal thresholds (e.g. distinction) are now hidden entirely instead of showing "no longer possible".
- Student insights view and CoI widgets centred to match the cohort view layout.

## [2.1.0] - 2026-03-28

### Added
- **Coordinator tab** — New third tab for programme coordinators and curriculum designers with teacher engagement analytics.
  - Composite engagement score across eight dimensions: insights usage, grading turnaround, forum activity, BigBlueButton sessions, grade monitoring, content updates, messaging responsiveness, and active days.
  - Summary cards (facilitator count, average score, low/high engagement counts).
  - Prescriptive recommendations for low engagement, unused insights, slow grading, and inactive facilitators.
  - Stacked bar chart showing weighted engagement breakdown per teacher.
  - Methodology modal with full indicator descriptions, weights, benchmarks, and research citations.
  - New capability `gradereport/coifish:viewcoordinator` (manager archetype) and `coordinator_enabled` site setting.
- **S3 risk quadrant scatter graph** — Chart.js scatter plot of engagement vs. grade with four colour-coded quadrants (Essa & Ayad, 2012).
- **Forum sociogram** — SVG force-directed graph of student reply networks, nodes coloured by grade band.
- **Enriched self-regulation widget** — Composite score combining four indicators: progress monitoring (40%), feedback utilisation (25%), resource revisiting (20%), and planning behaviour (15%), based on Zimmerman's SRL framework.
- Self-regulation "How is this calculated?" modal with per-indicator cards, weights, sparkline, and research citations.
- Site-level toggles for risk quadrant and sociogram visualisations.
- Log data tables in insight detail modals (cards 4–8) showing recent relevant events.

### Changed
- Renamed plugin from `gradereport_gamified` to `gradereport_coifish`.
- Plugin display name changed to "CoIFish".
- Self-regulation insight card now references composite score instead of grade-check frequency alone.
- Self-regulation prescription updated to recommend all four SRL habits, not just grade checking.

## [2.0.0] - 2026-03-27

### Added
- **Cohort insights view** — Teacher-facing diagnostic and prescriptive analytics dashboard with per-student insight cards.
- **Community of Inquiry (CoI) widgets** — Social presence (community engagement, peer connection), cognitive presence (learning depth), and teaching presence (feedback loop) indicators with configurable level boundaries.
- **Consistency tracker widget** — Measures submission timing regularity.
- **Self-regulation widget** — Grade report view frequency tracking with sparkline.
- **Early bird widget** — Submission timing analysis relative to due dates.
- Grade distribution histogram with threshold markers.
- Cohort diagnostic sensitivity setting (low/normal/high).
- Stale activity threshold setting.
- Per-student detail modals with metrics, thresholds, methodology, rationale, and log data.

## [1.4.0] - 2026-03-26

### Added
- Feedback engagement widget — tracks how much graded feedback each student has reviewed, with completion ring and expandable per-assignment checklist linking to each assignment.
- Feedback milestones — "First feedback reviewed" and "Feedback champion" badges added to the milestones widget.
- Widget position setting (site and course level) — choose whether goals and gamification widgets appear above or below the category grade bars.
- Default view setting now uses a generic cascade helper (`resolve_setting`) for both site and course overrides.
- Running total indicators on the progress view — when the running total toggle is on, an animated blue marker and percentage label appear on the course total bar and each category bar showing the graded-only mark.
- Running total legend entry in the threshold legend strip.

### Changed
- "Display widgets" toggle replaces the old "Preview" button — label, icon, and logic corrected so toggling on makes widgets visible to students.
- Removed chart view entirely — pie chart was not providing useful information.
- Renamed plugin from `gradereport_gradetracker` to `gradereport_gamified` ("Gamified User Report").
- Renamed "Gamification Settings" to "Report Settings" throughout.
- Progress view restructured with Mustache partials (`progress_goals_widgets`, `progress_category_bars`) to support configurable widget position without template duplication.
- Course total bar moved to the top of the progress view for prominence.
- Threshold markers now use distinct colours (pass=green, merit=blue, distinction=gold) with letter labels.
- Category bars simplified to single-fill bars representing overall category percentage.

### Fixed
- Threshold markers and category bars no longer overflow into adjacent categories.
- Feedback widget ring now animates correctly (was outside the JS init scope).
- Bootstrap tooltips now initialise across the full progress view, not just the container.
- CSS class references updated to match renamed plugin, restoring weight badges and all scoped styles.

## [1.3.0] - 2026-03-25

### Added
- Gamification widget system with six widgets: overall percentile, nearest neighbours, improvement rank, personal trend sparkline, streak tracker, and milestone badges.
- Course-level gamification settings page — teachers can enable/disable widgets per course and override site defaults.
- Teacher preview mode — preview gamification widgets from a student's perspective before exposing them to students.
- Site-level admin settings for per-widget toggles and minimum enrolment threshold for competitive widgets.
- Competitive widgets (overall, neighbours, improvement) require minimum enrolment to protect student anonymity.
- Personal widgets (trend, streak, milestones) are always available regardless of cohort size.

## [1.2.0] - 2026-03-25

### Added
- Progress view — stacked horizontal bars for each grade category showing graded vs ungraded segments.
- Grade threshold markers (pass, merit, distinction) displayed on progress bars with configurable site-level settings.
- Best possible grade indicator on the course total bar.
- Completion rings showing graded/total item counts per category.
- Goal planner — calculates the average score needed on remaining assessments to reach each threshold.
- Animated bar fills and percentage counters on the progress view.

### Changed
- Expanded threshold marker hover targets for easier interaction.

## [1.1.0] - 2026-03-24

### Added
- Running total toggle — shows course mark based only on graded work so far.
- Help tooltips on course total and running total labels.
- Late submission and extension badge display (previously dead code).
- Toggle switches styled as form-switch controls with AMD-based event handling.

### Fixed
- Dead code in `process_grade_item()` — two return statements prevented late/extension badges from appearing.
- Duplicate "Course total" row when uncategorised items existed alongside real categories.
- Grade max values no longer show a redundant "(100.00%)" percentage.

### Changed
- Removed the item weight column from category tables — category weight badges on headers are sufficient.
- Suppressed the virtual "Course" catch-all section when real categories are present.

## [1.0.0] - 2026-03-20

### Added
- Initial release.
- Category-based grade overview with weight badges.
- Contribution column showing each item's impact on the course total.
- Pie chart view with drill-down into categories.
- Hidden item toggle for teachers with `moodle/grade:viewhidden` capability.
- Teacher summary view with student list and course totals.
- Group filtering support.
- Student list filtered by `moodle/course:isincompletionreports` capability.
