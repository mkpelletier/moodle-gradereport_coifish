/**
 * Coordinator engagement breakdown chart using Chart.js.
 *
 * Renders a horizontal stacked bar chart showing each teacher's engagement
 * score broken down by component (insights, grading, forum, etc.).
 *
 * @module     gradereport_coifish/coordinator
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/chartjs-lazy'], function(ChartJS) {
    return {
        /**
         * Initialise the coordinator engagement chart.
         *
         * @param {string} canvasId The canvas element ID.
         */
        init: function(canvasId) {
            var canvas = document.getElementById(canvasId);
            if (!canvas) {
                return;
            }
            var container = canvas.closest('.gradetracker-coordinator-chart');
            if (!container) {
                return;
            }
            var rawData = container.getAttribute('data-chart');
            if (!rawData) {
                return;
            }

            var chartData;
            try {
                chartData = JSON.parse(rawData);
            } catch (e) {
                return;
            }

            // Read localised chart labels from data attribute.
            var chartLabels = {};
            try {
                chartLabels = JSON.parse(container.getAttribute('data-labels') || '{}');
            } catch (e) {
                chartLabels = {};
            }

            if (!chartData.length) {
                return;
            }

            var labels = chartData.map(function(t) {
                return t.name;
            });

            // Each teacher carries their weighted contribution per dimension
            // (computed server-side with the composite's own weights), so the
            // stacked bar adds up to the composite score.
            var series = [
                {key: 'insight', label: chartLabels.insights || 'Insights usage', colour: 'rgba(54, 162, 235, 0.8)'},
                {key: 'grading', label: chartLabels.grading || 'Grading turnaround', colour: 'rgba(75, 192, 192, 0.8)'},
                {key: 'feedback', label: chartLabels.feedback || 'Feedback quality', colour: 'rgba(0, 168, 120, 0.8)'},
                {key: 'forum', label: chartLabels.forum || 'Forum activity', colour: 'rgba(153, 102, 255, 0.8)'},
                {key: 'live', label: chartLabels.live || 'Live sessions', colour: 'rgba(23, 107, 135, 0.8)'},
                {key: 'monitoring', label: chartLabels.monitoring || 'Grade monitoring', colour: 'rgba(255, 206, 86, 0.8)'},
                {key: 'content', label: chartLabels.content || 'Content updates', colour: 'rgba(255, 159, 64, 0.8)'},
                {key: 'messaging', label: chartLabels.messaging || 'Messaging', colour: 'rgba(255, 99, 132, 0.8)'},
                {key: 'active', label: chartLabels.active || 'Active days', colour: 'rgba(201, 203, 207, 0.8)'}
            ];
            var present = chartData[0].contrib || {};
            var datasets = series.filter(function(s) {
                return present[s.key] !== undefined;
            }).map(function(s) {
                return {
                    label: s.label,
                    data: chartData.map(function(t) {
                        return (t.contrib || {})[s.key] || 0;
                    }),
                    backgroundColor: s.colour,
                };
            });

            // Set canvas height based on number of teachers.
            var barHeight = 40;
            canvas.style.height = Math.max(200, chartData.length * barHeight + 60) + 'px';

            new ChartJS(canvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: datasets
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                padding: 10,
                                font: {size: 11}
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + context.raw + ' pts';
                                },
                                afterBody: function(tooltipItems) {
                                    var idx = tooltipItems[0].dataIndex;
                                    return ['Total: ' + chartData[idx].composite + '%'];
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            stacked: true,
                            max: 100,
                            title: {
                                display: true,
                                text: 'Engagement score',
                                font: {size: 12}
                            }
                        },
                        y: {
                            stacked: true,
                            ticks: {
                                font: {size: 12}
                            }
                        }
                    }
                }
            });
        }
    };
});
