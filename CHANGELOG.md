# Changelog

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
