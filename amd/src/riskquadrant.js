/**
 * S3-model risk quadrant scatter chart.
 *
 * Plots each student on an Engagement Index (x) vs Current Grade (y) scatter
 * with colour-coded quadrant backgrounds derived from configurable thresholds.
 *
 * Based on the Student Success System (S3) model described by Macfadyen & Dawson
 * (2010) and Essa & Ayad (2012).
 *
 * @module     gradereport_coifish/riskquadrant
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define('gradereport_coifish/riskquadrant', ['core/chartjs-lazy'], function(Chart) {

    /**
     * Custom Chart.js plugin that paints four colour-coded quadrant backgrounds.
     * @type {Object}
     */
    var quadrantsPlugin = {
        id: 'quadrants',
        beforeDraw: function(chart, _args, options) {
            var ctx = chart.ctx;
            var area = chart.chartArea;
            var x = chart.scales.x;
            var y = chart.scales.y;
            var midX = x.getPixelForValue(options.engagementThreshold);
            var midY = y.getPixelForValue(options.gradeThreshold);

            ctx.save();
            // Bottom-left: Withdrawal/dropout risk (red).
            ctx.fillStyle = 'rgba(220, 53, 69, 0.07)';
            ctx.fillRect(area.left, midY, midX - area.left, area.bottom - midY);
            // Bottom-right: Academic risk (orange).
            ctx.fillStyle = 'rgba(255, 152, 0, 0.07)';
            ctx.fillRect(midX, midY, area.right - midX, area.bottom - midY);
            // Top-left: Under-engagement risk (amber).
            ctx.fillStyle = 'rgba(255, 193, 7, 0.07)';
            ctx.fillRect(area.left, area.top, midX - area.left, midY - area.top);
            // Top-right: On track (green).
            ctx.fillStyle = 'rgba(40, 167, 69, 0.07)';
            ctx.fillRect(midX, area.top, area.right - midX, midY - area.top);

            // Draw threshold lines.
            ctx.strokeStyle = 'rgba(0,0,0,0.15)';
            ctx.lineWidth = 1;
            ctx.setLineDash([5, 3]);
            ctx.beginPath();
            ctx.moveTo(midX, area.top);
            ctx.lineTo(midX, area.bottom);
            ctx.moveTo(area.left, midY);
            ctx.lineTo(area.right, midY);
            ctx.stroke();
            ctx.setLineDash([]);

            // Quadrant labels.
            ctx.font = '11px sans-serif';
            ctx.fillStyle = 'rgba(0,0,0,0.3)';
            ctx.textAlign = 'left';
            ctx.fillText(options.labelTopRight || 'On track', midX + 6, area.top + 16);
            ctx.textAlign = 'right';
            ctx.fillText(options.labelTopLeft || 'Under-engaged', midX - 6, area.top + 16);
            ctx.textAlign = 'left';
            ctx.fillText(options.labelBottomRight || 'Academic risk', midX + 6, area.bottom - 6);
            ctx.textAlign = 'right';
            ctx.fillText(options.labelBottomLeft || 'At risk', midX - 6, area.bottom - 6);

            ctx.restore();
        }
    };

    /**
     * Determine point colour based on which quadrant the student falls in.
     *
     * @param {number} engagement The student's engagement index (0-100).
     * @param {number} grade The student's current grade (0-100).
     * @param {number} engThresh The engagement threshold.
     * @param {number} gradeThresh The grade threshold.
     * @returns {string} RGBA colour string.
     */
    function getPointColour(engagement, grade, engThresh, gradeThresh) {
        if (engagement >= engThresh && grade >= gradeThresh) {
            return 'rgba(40, 167, 69, 0.75)';
        }
        if (engagement < engThresh && grade >= gradeThresh) {
            return 'rgba(255, 193, 7, 0.75)';
        }
        if (engagement >= engThresh && grade < gradeThresh) {
            return 'rgba(255, 152, 0, 0.75)';
        }
        return 'rgba(220, 53, 69, 0.75)';
    }

    return {
        /**
         * Initialise the risk quadrant scatter chart.
         *
         * @param {string} containerId The ID of the container element holding the canvas.
         */
        init: function(containerId) {
            var container = document.getElementById(containerId);
            if (!container) {
                return;
            }
            var rawData = container.getAttribute('data-scatter');
            var engThresh = parseFloat(container.getAttribute('data-engagement-threshold')) || 50;
            var gradeThresh = parseFloat(container.getAttribute('data-grade-threshold')) || 50;
            var labelX = container.getAttribute('data-label-x') || 'Engagement Index (%)';
            var labelY = container.getAttribute('data-label-y') || 'Current Grade (%)';
            var labelTopRight = container.getAttribute('data-label-tr') || 'On track';
            var labelTopLeft = container.getAttribute('data-label-tl') || 'Under-engaged';
            var labelBottomRight = container.getAttribute('data-label-br') || 'Academic risk';
            var labelBottomLeft = container.getAttribute('data-label-bl') || 'At risk';

            var students;
            try {
                students = JSON.parse(rawData);
            } catch (e) {
                return;
            }

            if (!students || !students.length) {
                return;
            }

            // Create canvas.
            var canvas = document.createElement('canvas');
            canvas.style.maxHeight = '420px';
            container.appendChild(canvas);

            var bgColours = students.map(function(s) {
                return getPointColour(s.x, s.y, engThresh, gradeThresh);
            });

            new Chart(canvas, {
                type: 'scatter',
                data: {
                    datasets: [{
                        data: students,
                        backgroundColor: bgColours,
                        borderColor: bgColours.map(function(c) {
                            return c.replace('0.75', '1');
                        }),
                        borderWidth: 1,
                        pointRadius: 6,
                        pointHoverRadius: 9
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: {
                        x: {
                            min: 0,
                            max: 100,
                            title: {display: true, text: labelX}
                        },
                        y: {
                            min: 0,
                            max: 100,
                            title: {display: true, text: labelY}
                        }
                    },
                    plugins: {
                        legend: {display: false},
                        quadrants: {
                            engagementThreshold: engThresh,
                            gradeThreshold: gradeThresh,
                            labelTopRight: labelTopRight,
                            labelTopLeft: labelTopLeft,
                            labelBottomRight: labelBottomRight,
                            labelBottomLeft: labelBottomLeft
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    var pt = context.raw;
                                    return pt.name + ': Grade ' + pt.y + '%, Engagement ' + pt.x + '%';
                                }
                            }
                        }
                    }
                },
                plugins: [quadrantsPlugin]
            });
        }
    };
});
