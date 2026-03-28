# CoIFish

*Swim in your data*

A Moodle grade report plugin that combines learning analytics with the Community of Inquiry (CoI) framework to give students, teachers, and programme coordinators actionable insights into engagement, self-regulation, and academic progress.

## Features

### Student View
- **Category-based grade layout** — Assessments grouped by grade category with weight badges and contribution column.
- **Running total** — Toggle to see the current mark based only on graded work, rather than treating ungraded items as 0%.
- **Progress view** — Stacked bar charts with pass/merit/distinction threshold markers, completion rings, best possible indicator, and goal planner.
- **Gamification widgets** — Motivational dashboard including personal trend, streak tracker, milestone badges, feedback engagement, consistency tracker, self-regulation composite, and early bird.
- **Competitive widgets** — Overall percentile, nearest neighbours, and improvement rank (privacy-gated by minimum enrolment threshold).
- **Community of Inquiry widgets** — Social presence (community engagement, peer connection), cognitive presence (learning depth), and teaching presence (feedback loop) indicators.
- **Self-regulation composite** — Four-indicator score combining progress monitoring, feedback utilisation, resource revisiting, and planning behaviour.

### Teacher View
- **Summary dashboard** — Overview of all students with course totals, drill-down, and group filtering.
- **Cohort insights** — Diagnostic and prescriptive analytics cards identifying at-risk students with transparent methodology modals.
- **S3 risk quadrant** — Scatter plot of engagement vs. grade based on the Student Success System model.
- **Forum sociogram** — Force-directed graph of student reply networks coloured by academic performance.
- **Grade distribution** — Histogram with threshold markers.

### Coordinator View
- **Teacher engagement analytics** — Composite engagement score for each facilitator across eight dimensions: insights usage, grading turnaround, forum activity, live sessions (BigBlueButton), grade monitoring, content updates, messaging, and active days.
- **Prescriptive recommendations** — Automated alerts for low engagement, unused analytics tools, slow grading, and inactive facilitators.
- **Engagement breakdown chart** — Stacked bar chart showing weighted contribution of each engagement dimension per teacher.
- **Transparent methodology** — Full documentation of indicators, weights, and benchmarks.

## Research Foundations

CoIFish draws on established learning analytics research and pedagogical frameworks:

### Community of Inquiry (CoI) Framework
- Garrison, D. R., Anderson, T., & Archer, W. (2000). Critical inquiry in a text-based environment: Computer conferencing in higher education. *The Internet and Higher Education*, 2(2–3), 87–105.
- Garrison, D. R., Anderson, T., & Archer, W. (2010). The first decade of the community of inquiry framework: A retrospective. *The Internet and Higher Education*, 13(1–2), 5–9.

### Learning Analytics and Student Success
- Macfadyen, L. P., & Dawson, S. (2010). Mining LMS data to develop an "early warning system" for educators: A proof of concept. *Computers & Education*, 54(2), 588–599.
- Macfadyen, L. P., & Dawson, S. (2012). Numbers are not enough. Why e-learning analytics failed to inform an institutional strategic plan. *Educational Technology & Society*, 15(3), 149–163.

### Student Success System (S3) Model
- Essa, A., & Ayad, H. (2012). Student success system: Risk analytics and data visualization using ensembles of predictive models. *Proceedings of the 2nd International Conference on Learning Analytics and Knowledge*, 158–161.

### Self-Regulated Learning
- Zimmerman, B. J. (2002). Becoming a self-regulated learner: An overview. *Theory Into Practice*, 41(2), 64–70.

### Teaching Presence and Instructor Engagement
- Anderson, T., Rourke, L., Garrison, D. R., & Archer, W. (2001). Assessing teaching presence in a computer conferencing context. *Journal of Asynchronous Learning Networks*, 5(2), 1–17.
- Baker, C. (2010). The impact of instructor immediacy and presence for online student affective learning, cognition, and motivation. *Journal of Educators Online*, 7(1), 1–30.

### Feedback and Assessment
- Hattie, J., & Timperley, H. (2007). The power of feedback. *Review of Educational Research*, 77(1), 81–112.

### Student Persistence and Community
- Rovai, A. P. (2002). Building sense of community at a distance. *International Review of Research in Open and Distance Learning*, 3(1), 1–16.

## Requirements

- Moodle 5.0+ (version 2024110400 or later)

## Installation

1. Copy the plugin folder to `grade/report/coifish` in your Moodle installation.
2. Log in as admin and visit **Site administration > Notifications** to complete the install.
3. Configure settings at **Site administration > Grades > Report settings > CoIFish**.

## Usage

Navigate to a course and select **CoIFish** from the gradebook report dropdown.

- **Students** see their own grades, progress bars, self-regulation metrics, and CoI presence indicators.
- **Teachers** see a summary of all students with cohort insights, risk analytics, and per-student diagnostic cards.
- **Coordinators** (with the `viewcoordinator` capability) see teacher engagement analytics and recommendations.

## License

This plugin is licensed under the [GNU GPL v3 or later](http://www.gnu.org/copyleft/gpl.html).

## Copyright

2026 [South African Theological Seminary](https://www.sats.ac.za)
