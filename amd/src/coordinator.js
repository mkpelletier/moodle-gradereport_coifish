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

            if (!chartData.length) {
                return;
            }

            var labels = chartData.map(function(t) {
                return t.name;
            });

            // Each component gets a weighted slice — show the weighted contribution, not the raw score.
            var hasContent = chartData.length > 0 && chartData[0].content !== undefined;
            var hasFeedback = chartData.length > 0 && chartData[0].feedback !== undefined;

            var weights = {
                insight: 0.12,
                grading: 0.15,
                feedback: 0.15,
                forum: 0.13,
                monitoring: 0.10,
                content: 0.10,
                messaging: 0.09,
                active: 0.08
            };

            var datasets = [
                {
                    label: 'Insights usage',
                    data: chartData.map(function(t) {
                        return Math.round(t.insight * weights.insight);
                    }),
                    backgroundColor: 'rgba(54, 162, 235, 0.8)',
                },
                {
                    label: 'Grading turnaround',
                    data: chartData.map(function(t) {
                        return Math.round(t.grading * weights.grading);
                    }),
                    backgroundColor: 'rgba(75, 192, 192, 0.8)',
                }
            ];

            if (hasFeedback) {
                datasets.push({
                    label: 'Feedback quality',
                    data: chartData.map(function(t) {
                        return Math.round((t.feedback || 0) * weights.feedback);
                    }),
                    backgroundColor: 'rgba(0, 168, 120, 0.8)',
                });
            }

            datasets.push(
                {
                    label: 'Forum activity',
                    data: chartData.map(function(t) {
                        return Math.round(t.forum * weights.forum);
                    }),
                    backgroundColor: 'rgba(153, 102, 255, 0.8)',
                },
                {
                    label: 'Grade monitoring',
                    data: chartData.map(function(t) {
                        return Math.round(t.monitoring * weights.monitoring);
                    }),
                    backgroundColor: 'rgba(255, 206, 86, 0.8)',
                }
            );

            if (hasContent) {
                datasets.push({
                    label: 'Content updates',
                    data: chartData.map(function(t) {
                        return Math.round(t.content * weights.content);
                    }),
                    backgroundColor: 'rgba(255, 159, 64, 0.8)',
                });
            }

            datasets.push(
                {
                    label: 'Messaging',
                    data: chartData.map(function(t) {
                        return Math.round(t.messaging * weights.messaging);
                    }),
                    backgroundColor: 'rgba(255, 99, 132, 0.8)',
                },
                {
                    label: 'Active days',
                    data: chartData.map(function(t) {
                        return Math.round(t.active * weights.active);
                    }),
                    backgroundColor: 'rgba(201, 203, 207, 0.8)',
                }
            );

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
