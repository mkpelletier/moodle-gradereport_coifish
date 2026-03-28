/**
 * Toggle switches, view switching, and tooltips for the Grade tracker report.
 *
 * @module     gradereport_coifish/toggles
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define('gradereport_coifish/toggles', ['theme_boost/bootstrap/tooltip'], function(Tooltip) {

    var progressInitialised = false;

    /**
     * Update the hidden view parameter in the action bar form so that
     * group/user selector changes preserve the currently active tab.
     *
     * @param {string} viewName The active view name.
     */
    function setViewParam(viewName) {
        var hidden = document.getElementById('gradetracker-view-param');
        if (hidden) {
            hidden.value = viewName;
        }
    }

    /**
     * Switch between table, progress, and insights views.
     *
     * @param {string} activeView One of 'table', 'progress', 'insights'.
     */
    function switchView(activeView) {
        var views = {
            'table': document.getElementById('gradetracker-table-view'),
            'progress': document.getElementById('gradetracker-progress-view'),
            'insights': document.getElementById('gradetracker-insights-view')
        };
        var buttons = {
            'table': document.getElementById('gradetracker-view-table-btn'),
            'progress': document.getElementById('gradetracker-view-progress-btn'),
            'insights': document.getElementById('gradetracker-view-insights-btn')
        };

        Object.keys(views).forEach(function(key) {
            if (views[key]) {
                if (key === activeView) {
                    views[key].classList.remove('d-none');
                } else {
                    views[key].classList.add('d-none');
                }
            }
            if (buttons[key]) {
                if (key === activeView) {
                    buttons[key].classList.add('active');
                    buttons[key].setAttribute('aria-pressed', 'true');
                } else {
                    buttons[key].classList.remove('active');
                    buttons[key].setAttribute('aria-pressed', 'false');
                }
            }
        });

        setViewParam(activeView);

        // Lazy-init progress module on first view.
        if (activeView === 'progress' && !progressInitialised) {
            progressInitialised = true;
            require(['gradereport_coifish/progress'], function(progressModule) {
                progressModule.init();
            });
        }
    }

    /**
     * Switch between table and insights views on the summary page.
     *
     * @param {string} activeView One of 'table', 'insights'.
     */
    function switchSummaryView(activeView) {
        var views = {
            'table': document.getElementById('gradetracker-summary-table-view'),
            'insights': document.getElementById('gradetracker-summary-insights-view')
        };
        var buttons = {
            'table': document.getElementById('gradetracker-summary-table-btn'),
            'insights': document.getElementById('gradetracker-summary-insights-btn')
        };

        Object.keys(views).forEach(function(key) {
            if (views[key]) {
                if (key === activeView) {
                    views[key].classList.remove('d-none');
                } else {
                    views[key].classList.add('d-none');
                }
            }
            if (buttons[key]) {
                if (key === activeView) {
                    buttons[key].classList.add('active');
                    buttons[key].setAttribute('aria-pressed', 'true');
                } else {
                    buttons[key].classList.remove('active');
                    buttons[key].setAttribute('aria-pressed', 'false');
                }
            }
        });

        setViewParam(activeView);
    }

    return {
        init: function() {
            // Wire up toggle switches (running total, show hidden).
            // Preserve the current view param when toggling.
            document.querySelectorAll('[data-action="gradetracker-toggle"]').forEach(function(toggle) {
                toggle.addEventListener('change', function() {
                    var param = toggle.dataset.param;
                    var url = new URL(window.location.href);
                    if (toggle.checked) {
                        url.searchParams.set(param, '1');
                    } else {
                        url.searchParams.delete(param);
                    }
                    // Preserve the active view tab.
                    var hidden = document.getElementById('gradetracker-view-param');
                    if (hidden && hidden.value) {
                        url.searchParams.set('view', hidden.value);
                    }
                    window.location.href = url.toString();
                });
            });

            // Wire up view toggle buttons.
            var tableBtn = document.getElementById('gradetracker-view-table-btn');
            var progressBtn = document.getElementById('gradetracker-view-progress-btn');
            var insightsBtn = document.getElementById('gradetracker-view-insights-btn');

            if (tableBtn) {
                tableBtn.addEventListener('click', function() {
                    switchView('table');
                });
            }
            if (progressBtn) {
                progressBtn.addEventListener('click', function() {
                    switchView('progress');
                });
            }
            if (insightsBtn) {
                insightsBtn.addEventListener('click', function() {
                    switchView('insights');
                });
            }

            // If progress view is the default (already visible), init it now.
            var progressView = document.getElementById('gradetracker-progress-view');
            if (progressView && !progressView.classList.contains('d-none') && !progressInitialised) {
                progressInitialised = true;
                require(['gradereport_coifish/progress'], function(progressModule) {
                    progressModule.init();
                });
            }

            // Initialise Bootstrap tooltips.
            document.querySelectorAll('.gradereport-coifish [data-bs-toggle="tooltip"]').forEach(function(el) {
                new Tooltip(el);
            });
        },

        /**
         * Initialise the summary page view toggle (table / insights).
         */
        initSummary: function() {
            var tableBtn = document.getElementById('gradetracker-summary-table-btn');
            var insightsBtn = document.getElementById('gradetracker-summary-insights-btn');

            if (tableBtn) {
                tableBtn.addEventListener('click', function() {
                    switchSummaryView('table');
                });
            }
            if (insightsBtn) {
                insightsBtn.addEventListener('click', function() {
                    switchSummaryView('insights');
                });
            }

            // Initialise Bootstrap tooltips for the summary/insights view.
            document.querySelectorAll('.gradereport-coifish-summary [data-bs-toggle="tooltip"]').forEach(function(el) {
                new Tooltip(el);
            });
        }
    };
});
