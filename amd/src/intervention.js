/**
 * Intervention logging module for CoIFish.
 *
 * Handles the intervention modal: opening with pre-populated context,
 * multi-student selection for cohort interventions, AJAX submission,
 * and success feedback.
 *
 * @module     gradereport_coifish/intervention
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import $ from 'jquery';
import Ajax from 'core/ajax';
import Notification from 'core/notification';

let $modal = null;

/**
 * Escape HTML entities.
 *
 * @param {string} text
 * @return {string}
 */
const escapeHtml = (text) => {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
};

/**
 * Open the modal and pre-populate from the button's data attributes.
 *
 * @param {jQuery} $btn The trigger button.
 */
const openModal = ($btn) => {
    const diagnostictype = $btn.attr('data-diagnostictype') || '';
    const scope = $btn.attr('data-scope') || 'individual';
    const cardtitle = $btn.attr('data-cardtitle') || '';
    const studentsjson = $btn.attr('data-students') || '[]';

    $('#intv-diagnostictype').val(diagnostictype);
    $('#intv-scope').val(scope);
    $('#intv-diagnostic-context').text(cardtitle);

    let students = [];
    try {
        students = JSON.parse(studentsjson);
    } catch (e) {
        students = [];
    }

    const $container = $('#intv-students-container');
    $container.empty();

    if (students.length === 0 && scope === 'cohort') {
        $container.html('<span class="form-control-plaintext text-muted small">' +
            '<i class="fa fa-users me-1"></i>All enrolled students in this course</span>');
    } else if (students.length === 0) {
        $container.html('<span class="text-muted small">No students specified</span>');
    } else if (students.length === 1) {
        $container.html('<span class="form-control-plaintext">' +
            escapeHtml(students[0].name) +
            '<input type="hidden" class="intv-student-id" value="' + students[0].id + '"></span>');
    } else {
        students.forEach((s) => {
            $container.append(
                '<div class="form-check">' +
                '<input type="checkbox" class="form-check-input intv-student-cb" ' +
                'value="' + s.id + '" id="intv-stu-' + s.id + '" checked>' +
                '<label class="form-check-label" for="intv-stu-' + s.id + '">' +
                escapeHtml(s.name) + '</label></div>'
            );
        });
    }

    // Reset form fields and filter action options by scope.
    const $actionSelect = $('#intv-actiontype');
    $actionSelect.val('');
    $actionSelect.find('option[data-scope]').each(function() {
        const optScope = $(this).attr('data-scope');
        if (optScope === 'both' || optScope === scope) {
            $(this).show();
        } else {
            $(this).hide();
        }
    });
    $('#intv-customaction').val('');
    $('#intv-notes').val('');
    $('#intv-custom-container').addClass('d-none');

    $modal.modal('show');
};

/**
 * Submit the intervention via AJAX.
 */
const submitIntervention = () => {
    const courseid = parseInt($('#intv-courseid').val(), 10);
    const diagnostictype = $('#intv-diagnostictype').val();
    const scope = $('#intv-scope').val();
    const actiontype = $('#intv-actiontype').val();
    const customaction = $('#intv-customaction').val();
    const notes = $('#intv-notes').val();

    if (!actiontype) {
        Notification.addNotification({
            message: 'Please select an action.',
            type: 'error'
        });
        return;
    }

    const studentids = [];
    $modal.find('.intv-student-id').each(function() {
        studentids.push(parseInt($(this).val(), 10));
    });
    $modal.find('.intv-student-cb:checked').each(function() {
        studentids.push(parseInt($(this).val(), 10));
    });

    if (studentids.length === 0 && scope !== 'cohort') {
        Notification.addNotification({
            message: 'Please select at least one student.',
            type: 'error'
        });
        return;
    }

    const $submitBtn = $('#intv-submit-btn');
    $submitBtn.prop('disabled', true);

    Ajax.call([{
        methodname: 'gradereport_coifish_log_intervention',
        args: {
            courseid: courseid,
            studentids: studentids,
            diagnostictype: diagnostictype,
            scope: scope,
            actiontype: actiontype,
            customaction: customaction,
            notes: notes
        }
    }])[0].then((result) => {
        $modal.modal('hide');
        Notification.addNotification({
            message: 'Intervention logged successfully.',
            type: 'success'
        });
        $submitBtn.prop('disabled', false);
        return result;
    }).catch((error) => {
        Notification.exception(error);
        $submitBtn.prop('disabled', false);
    });
};

/**
 * Initialise intervention logging.
 */
export const init = () => {
    $modal = $('#gradetracker-intervention-modal');
    if (!$modal.length) {
        return;
    }

    $('#intv-actiontype').on('change', function() {
        if ($(this).val() === 'custom') {
            $('#intv-custom-container').removeClass('d-none');
        } else {
            $('#intv-custom-container').addClass('d-none');
        }
    });

    $(document).on('click', '.gradetracker-log-intervention-btn', function(e) {
        e.preventDefault();
        openModal($(this));
    });

    $('#intv-submit-btn').on('click', function() {
        submitIntervention();
    });
};
