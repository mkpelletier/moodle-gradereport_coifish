/**
 * Show the student pulse dashboard modal on course entry and wire its actions.
 *
 * @module     gradereport_coifish/student_pulse
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define('gradereport_coifish/student_pulse', ['theme_boost/bootstrap/modal', 'core/ajax', 'core/log'],
function(Modal, Ajax, Log) {

    return {
        /**
         * Display the pre-rendered pulse modal and bind the mute action.
         */
        init: function() {
            var el = document.getElementById('gradereport-coifish-pulse-modal');
            if (!el) {
                return;
            }

            var courseid = parseInt(el.dataset.courseid, 10);
            var modal = new Modal(el);
            modal.show();

            el.querySelectorAll('[data-action="pulse-mute"]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    Ajax.call([{
                        methodname: 'gradereport_coifish_mute_pulse',
                        args: {courseid: courseid},
                        fail: Log.error
                    }]);
                    modal.hide();
                });
            });
        }
    };
});
