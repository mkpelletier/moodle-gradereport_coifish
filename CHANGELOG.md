# Changelog

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
