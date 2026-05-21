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
    const tplfamily = $btn.attr('data-tpl-family') || 'generic';
    const studentsjson = $btn.attr('data-students') || '[]';

    $('#intv-diagnostictype').val(diagnostictype);
    $('#intv-scope').val(scope);
    $('#intv-diagnostic-context').text(cardtitle);
    // Default template family for this card — overridden when the user picks
    // an action that declares its own data-tpl-family (e.g. feedback_reminder).
    $modal.attr('data-card-tpl-family', tplfamily);

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
    $('#intv-composer-container').addClass('d-none');
    $('#intv-subject').val('');
    $('#intv-body').val('');

    $modal.modal('show');
};

/**
 * Look up a pre-rendered template from the modal's data-templates JSON.
 *
 * @param {string} kind 'message' or 'announcement'
 * @param {string} family Template family key, e.g. 'missing', 'feedback'.
 * @returns {{subject: string, body: string}|null}
 */
const lookupTemplate = (kind, family) => {
    try {
        const all = JSON.parse($modal.attr('data-templates') || '{}');
        if (all && all[kind] && all[kind][family]) {
            return all[kind][family];
        }
        if (all && all[kind] && all[kind].generic) {
            return all[kind].generic;
        }
    } catch (e) {
        // Fall through.
    }
    return null;
};

/**
 * Pre-fill the composer for the selected action. Resolution order for the
 * template family: action-option data-tpl-family override, then the diagnostic
 * card's data-tpl-family captured when the modal was opened, then 'generic'.
 *
 * Bodies use a {firstname} placeholder which the backend dispatcher swaps per
 * recipient. For individual scope with one named recipient, we substitute
 * client-side as well so the teacher sees the rendered greeting while editing.
 *
 * @param {string} kind 'message' or 'announcement'.
 * @param {string} family Template family key.
 */
const fillComposerTemplate = (kind, family) => {
    const tpl = lookupTemplate(kind, family);
    if (!tpl) {
        return;
    }
    const scope = $('#intv-scope').val();
    let body = tpl.body;
    if (scope === 'individual') {
        const $singleName = $modal.find('.intv-student-id').first();
        if ($singleName.length) {
            const firstname = $singleName.parent().text().trim().split(/\s+/)[0] || '';
            if (firstname) {
                body = body.replace(/{firstname}/g, firstname);
            }
        }
    }
    $('#intv-subject').val(tpl.subject);
    $('#intv-body').val(body);
};

/**
 * Toggle the composer visibility based on the selected action.
 */
const refreshComposer = () => {
    const $opt = $('#intv-actiontype option:selected');
    const kind = $opt.attr('data-kind') || '';
    const $composer = $('#intv-composer-container');
    const $hint = $('#intv-composer-hint');

    if (kind !== 'message' && kind !== 'announcement') {
        $composer.addClass('d-none');
        return;
    }

    // Action-type family override (e.g. feedback_reminder → 'feedback') takes
    // priority; otherwise use the diagnostic card's family.
    const family = $opt.attr('data-tpl-family')
        || $modal.attr('data-card-tpl-family')
        || 'generic';

    $hint.text($modal.attr(kind === 'message'
        ? 'data-composer-hint-message'
        : 'data-composer-hint-announcement') || '');
    $composer.removeClass('d-none');

    // Only auto-fill if the teacher hasn't started typing.
    if (!$('#intv-subject').val() && !$('#intv-body').val()) {
        fillComposerTemplate(kind, family);
    }
};

/**
 * Submit the intervention via AJAX.
 *
 * Branches based on the selected action's data-kind: message and announcement
 * actions go through `dispatch_intervention` (sends + auto-logs), everything
 * else goes through the original `log_intervention` (record-only).
 */
const submitIntervention = () => {
    const courseid = parseInt($('#intv-courseid').val(), 10);
    const diagnostictype = $('#intv-diagnostictype').val();
    const scope = $('#intv-scope').val();
    const actiontype = $('#intv-actiontype').val();
    const customaction = $('#intv-customaction').val();
    const notes = $('#intv-notes').val();
    const $opt = $('#intv-actiontype option:selected');
    const kind = $opt.attr('data-kind') || '';

    if (!actiontype) {
        Notification.addNotification({
            message: $modal.attr('data-msg-noaction') || 'Please select an action.',
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
            message: $modal.attr('data-msg-nostudent') || 'Please select at least one student.',
            type: 'error'
        });
        return;
    }

    const $submitBtn = $('#intv-submit-btn');
    $submitBtn.prop('disabled', true);

    // Dispatch path: send / post + auto-log.
    if (kind === 'message' || kind === 'announcement') {
        const subject = ($('#intv-subject').val() || '').trim();
        const body = ($('#intv-body').val() || '').trim();
        if (!subject || !body) {
            Notification.addNotification({
                message: $modal.attr('data-msg-nocomposer') || 'Please complete the subject and body.',
                type: 'error'
            });
            $submitBtn.prop('disabled', false);
            return;
        }
        Ajax.call([{
            methodname: 'gradereport_coifish_dispatch_intervention',
            args: {
                courseid: courseid,
                studentids: studentids,
                diagnostictype: diagnostictype,
                scope: scope,
                actionkind: kind,
                subject: subject,
                body: body,
                notes: notes
            }
        }])[0].then((result) => {
            $modal.modal('hide');
            const tmpl = kind === 'message'
                ? ($modal.attr('data-msg-success-sent') || 'Sent and logged.')
                : ($modal.attr('data-msg-success-posted') || 'Posted and logged.');
            const msg = tmpl.replace('{count}', String(result.delivered || 0));
            Notification.addNotification({message: msg, type: 'success'});
            $submitBtn.prop('disabled', false);
            return result;
        }).catch((error) => {
            Notification.exception(error);
            $submitBtn.prop('disabled', false);
        });
        return;
    }

    // Record-only path (original behaviour).
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
            message: $modal.attr('data-msg-success') || 'Intervention logged successfully.',
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
        refreshComposer();
    });

    $(document).on('click', '.gradetracker-log-intervention-btn', function(e) {
        e.preventDefault();
        openModal($(this));
    });

    $('#intv-submit-btn').on('click', function() {
        submitIntervention();
    });
};
