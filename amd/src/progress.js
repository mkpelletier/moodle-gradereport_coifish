/**
 * Progress bar animations, threshold markers, and completion rings.
 *
 * @module     gradereport_coifish/progress
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define('gradereport_coifish/progress', ['theme_boost/bootstrap/tooltip'], function(Tooltip) {

    /** Colour stops for performance gradient (red → amber → green). */
    var COLOUR_STOPS = [
        {at: 0, colour: [220, 53, 69]}, // Red.
        {at: 50, colour: [255, 193, 7]}, // Amber.
        {at: 75, colour: [25, 135, 84]} // Green.
    ];

    /**
     * Interpolate between colour stops to get a colour for a given percentage.
     *
     * @param {number} percent The percentage (0-100).
     * @returns {string} CSS rgb colour string.
     */
    function getColour(percent) {
        if (percent <= COLOUR_STOPS[0].at) {
            var c = COLOUR_STOPS[0].colour;
            return 'rgb(' + c[0] + ',' + c[1] + ',' + c[2] + ')';
        }
        if (percent >= COLOUR_STOPS[COLOUR_STOPS.length - 1].at) {
            var last = COLOUR_STOPS[COLOUR_STOPS.length - 1].colour;
            return 'rgb(' + last[0] + ',' + last[1] + ',' + last[2] + ')';
        }
        for (var i = 0; i < COLOUR_STOPS.length - 1; i++) {
            var a = COLOUR_STOPS[i];
            var b = COLOUR_STOPS[i + 1];
            if (percent >= a.at && percent <= b.at) {
                var t = (percent - a.at) / (b.at - a.at);
                var r = Math.round(a.colour[0] + t * (b.colour[0] - a.colour[0]));
                var g = Math.round(a.colour[1] + t * (b.colour[1] - a.colour[1]));
                var bl = Math.round(a.colour[2] + t * (b.colour[2] - a.colour[2]));
                return 'rgb(' + r + ',' + g + ',' + bl + ')';
            }
        }
        return 'rgb(25,135,84)';
    }

    /**
     * Animate a number counting up from 0 to target.
     *
     * @param {HTMLElement} el The element to update.
     * @param {number} target The target number.
     * @param {number} duration Animation duration in ms.
     */
    function animateNumber(el, target, duration) {
        var start = performance.now();
        /** @param {number} now Current timestamp. */
        function tick(now) {
            var elapsed = now - start;
            var progress = Math.min(elapsed / duration, 1);
            // Ease out cubic.
            var eased = 1 - Math.pow(1 - progress, 3);
            var current = Math.round(eased * target * 10) / 10;
            el.textContent = current + '%';
            if (progress < 1) {
                requestAnimationFrame(tick);
            }
        }
        requestAnimationFrame(tick);
    }

    /**
     * Animate a bar fill from 0 to target width.
     *
     * @param {HTMLElement} el The fill element.
     * @param {string} targetWidth The target width (e.g. "75%").
     * @param {number} delay Delay before starting in ms.
     * @param {number} duration Animation duration in ms.
     */
    function animateFill(el, targetWidth, delay, duration) {
        setTimeout(function() {
            el.style.transition = 'width ' + duration + 'ms cubic-bezier(0.25, 0.46, 0.45, 0.94)';
            el.style.width = targetWidth;
        }, delay);
    }

    /** Map threshold keys to CSS modifier classes. */
    var THRESHOLD_CLASSES = {
        pass: 'gradetracker-threshold-pass',
        merit: 'gradetracker-threshold-merit',
        distinction: 'gradetracker-threshold-distinction'
    };

    /**
     * Render threshold marker lines on a bar wrapper.
     *
     * @param {HTMLElement} wrapper The .gradetracker-bar-wrapper element.
     * @param {Array} thresholds The threshold definitions.
     */
    function renderThresholds(wrapper, thresholds) {
        var container = wrapper.querySelector('.gradetracker-threshold-markers');
        if (!container) {
            return;
        }
        thresholds.forEach(function(t) {
            var marker = document.createElement('div');
            var colourClass = THRESHOLD_CLASSES[t.key] || THRESHOLD_CLASSES.pass;
            marker.className = 'gradetracker-threshold-marker ' + colourClass;
            marker.style.left = t.value + '%';
            marker.setAttribute('data-bs-toggle', 'tooltip');
            marker.setAttribute('title', t.label + ' (' + t.value + '%)');
            // Short label above the marker (first letter of each word, e.g. "P", "M", "D").
            var abbr = t.label.split(/\s+/).map(function(w) {
                return w.charAt(0);
            }).join('').toUpperCase();
            marker.setAttribute('data-label', abbr);
            container.appendChild(marker);
            new Tooltip(marker);
        });
    }

    /**
     * Animate completion rings (SVG circle dash).
     *
     * @param {HTMLElement} ring The ring container element.
     */
    function animateRing(ring) {
        var graded = parseInt(ring.getAttribute('data-graded'), 10) || 0;
        var total = parseInt(ring.getAttribute('data-total'), 10) || 1;
        var percent = (graded / total) * 100;
        var fillPath = ring.querySelector('.gradetracker-ring-fill');
        if (fillPath) {
            fillPath.style.stroke = getColour(percent);
            setTimeout(function() {
                fillPath.style.strokeDasharray = percent + ', 100';
            }, 200);
        }
    }

    return {
        /**
         * Initialise the progress view.
         */
        init: function() {
            var container = document.querySelector('.gradetracker-progress-container');
            if (!container) {
                return;
            }

            var progressData;
            try {
                progressData = JSON.parse(container.getAttribute('data-progressdata'));
            } catch (e) {
                return;
            }

            var thresholds = progressData.thresholds || [];

            // Render threshold markers on each bar wrapper.
            var barWrappers = container.querySelectorAll('.gradetracker-bar-wrapper');
            barWrappers.forEach(function(wrapper) {
                renderThresholds(wrapper, thresholds);
            });

            // Animate fills with staggered delay.
            var fills = container.querySelectorAll('.gradetracker-bar-fill');
            fills.forEach(function(fill, index) {
                var targetWidth = fill.getAttribute('data-target-width');
                if (targetWidth) {
                    var percent = parseFloat(targetWidth);
                    fill.style.backgroundColor = getColour(percent);
                    animateFill(fill, targetWidth, 100 + index * 60, 800);
                }
            });

            // Animate percentage numbers.
            var percentEls = container.querySelectorAll('.gradetracker-bar-percentage');
            percentEls.forEach(function(el) {
                var target = parseFloat(el.getAttribute('data-target')) || 0;
                setTimeout(function() {
                    animateNumber(el, target, 800);
                }, 200);
            });

            // Animate completion rings.
            var rings = container.querySelectorAll('.gradetracker-completion-ring');
            rings.forEach(function(ring) {
                animateRing(ring);
            });

            // Running total markers.
            var runningMarkers = container.querySelectorAll('.gradetracker-running-total-marker');
            runningMarkers.forEach(function(marker) {
                var targetLeft = marker.getAttribute('data-target-left');
                if (targetLeft) {
                    setTimeout(function() {
                        marker.style.left = targetLeft;
                    }, 300);
                }
            });

            // Best possible ghost bar.
            var bestBars = container.querySelectorAll('.gradetracker-best-possible');
            bestBars.forEach(function(bar) {
                var targetWidth = bar.getAttribute('data-target-width');
                if (targetWidth) {
                    animateFill(bar, targetWidth, 300, 1000);
                }
            });

            // Goal planner: animate required-percentage numbers and colour by difficulty.
            var goalEls = container.querySelectorAll('.gradetracker-goal-required');
            goalEls.forEach(function(el) {
                var target = parseFloat(el.getAttribute('data-target')) || 0;
                // Colour: green (easy) → amber → red (hard).
                // Invert the scale so low requirement = green, high = red.
                var difficulty = Math.min(target, 100);
                el.style.color = getColour(100 - difficulty);
                setTimeout(function() {
                    animateNumber(el, target, 800);
                }, 400);
            });

            // Sparkline rendering for trend widget.
            var sparklines = container.querySelectorAll('.gradetracker-sparkline');
            sparklines.forEach(function(canvas) {
                var points;
                try {
                    points = JSON.parse(canvas.getAttribute('data-points'));
                } catch (e) {
                    return;
                }
                if (!points || points.length < 2) {
                    return;
                }
                var ctx = canvas.getContext('2d');
                var w = canvas.width;
                var h = canvas.height;
                var pad = 4;
                var min = Math.max(0, Math.min.apply(null, points) - 10);
                var max = Math.min(100, Math.max.apply(null, points) + 10);
                var range = max - min || 1;
                var stepX = (w - pad * 2) / (points.length - 1);

                // Animated draw.
                var animDuration = 800;
                var startTime = performance.now();
                /** @param {number} now Current timestamp. */
                function drawFrame(now) {
                    var elapsed = now - startTime;
                    var progress = Math.min(elapsed / animDuration, 1);
                    var eased = 1 - Math.pow(1 - progress, 3);
                    var drawCount = Math.max(2, Math.ceil(eased * points.length));

                    ctx.clearRect(0, 0, w, h);

                    // Line.
                    ctx.beginPath();
                    ctx.strokeStyle = getColour(points[points.length - 1]);
                    ctx.lineWidth = 2;
                    ctx.lineJoin = 'round';
                    ctx.lineCap = 'round';
                    for (var i = 0; i < drawCount; i++) {
                        var x = pad + i * stepX;
                        var y = h - pad - ((points[i] - min) / range) * (h - pad * 2);
                        if (i === 0) {
                            ctx.moveTo(x, y);
                        } else {
                            ctx.lineTo(x, y);
                        }
                    }
                    ctx.stroke();

                    // Dots.
                    for (var j = 0; j < drawCount; j++) {
                        var dx = pad + j * stepX;
                        var dy = h - pad - ((points[j] - min) / range) * (h - pad * 2);
                        ctx.beginPath();
                        ctx.arc(dx, dy, 3, 0, Math.PI * 2);
                        ctx.fillStyle = getColour(points[j]);
                        ctx.fill();
                    }

                    if (progress < 1) {
                        requestAnimationFrame(drawFrame);
                    }
                }
                setTimeout(function() {
                    requestAnimationFrame(drawFrame);
                }, 400);
            });

            // Init tooltips on all elements within the progress view.
            var progressView = document.getElementById('gradetracker-progress-view');
            var tooltipRoot = progressView || container;
            tooltipRoot.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
                new Tooltip(el);
            });

            // Animate feedback engagement ring (lives inside widgets, outside container).
            var feedbackRings = document.querySelectorAll('.gradetracker-feedback-ring');
            feedbackRings.forEach(function(ring) {
                animateRing(ring);
            });

            // Feedback details toggle.
            var detailsBtn = document.querySelector('.gradetracker-feedback-details-btn');
            if (detailsBtn) {
                detailsBtn.addEventListener('click', function() {
                    var panel = document.getElementById('gradetracker-feedback-details');
                    if (!panel) {
                        return;
                    }
                    var expanded = detailsBtn.getAttribute('aria-expanded') === 'true';
                    panel.classList.toggle('d-none');
                    detailsBtn.setAttribute('aria-expanded', !expanded);
                    var icon = detailsBtn.querySelector('.fa');
                    if (icon) {
                        icon.classList.toggle('fa-chevron-down', expanded);
                        icon.classList.toggle('fa-chevron-up', !expanded);
                    }
                });
            }
        }
    };
});
