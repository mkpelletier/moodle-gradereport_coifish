/**
 * Live updates for the "Missed due dates" activity cards.
 *
 * When an intervention about an activity is sent or logged from its card,
 * intervention.js fires `gradereport_coifish:intervention-saved` with the
 * activity's cmid. The matching card then shows it was contacted and its
 * button switches to a follow-up, without reloading the page.
 *
 * @module     gradereport_coifish/missed_activities
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

let initialised = false;

/**
 * Mark one card as contacted.
 *
 * @param {HTMLElement} card The [data-region="missed-activity"] element.
 */
const markContacted = (card) => {
    card.classList.add('is-contacted');
    const status = card.querySelector('[data-region="contact-status"]');
    if (status) {
        status.textContent = card.getAttribute('data-messaged-now') || status.textContent;
        status.classList.remove('gradetracker-missed-status-pending');
        status.classList.add('gradetracker-missed-status-done');
    }
    const button = card.querySelector('[data-action="message-activity"]');
    if (button) {
        const label = button.querySelector('[data-region="label"]');
        if (label) {
            label.textContent = card.getAttribute('data-follow-label') || label.textContent;
        }
        button.classList.remove('btn-primary');
        button.classList.add('btn-outline-primary');
    }
};

/**
 * Listen for saved interventions.
 */
export const init = () => {
    if (initialised) {
        return;
    }
    initialised = true;
    document.addEventListener('gradereport_coifish:intervention-saved', (e) => {
        const cmid = parseInt((e.detail && e.detail.cmid) || 0, 10);
        if (!cmid) {
            return;
        }
        document.querySelectorAll('[data-region="missed-activity"][data-cmid="' + cmid + '"]').forEach(markContacted);
    });
};
