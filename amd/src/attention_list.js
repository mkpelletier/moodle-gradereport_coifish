/**
 * Selection and bulk messaging for the "Students requiring attention" list.
 *
 * Teachers tick students (or use the "select everyone with missed due dates"
 * shortcut) and press "Message selected". The button is an ordinary
 * intervention trigger: this module keeps its data-students list and its
 * template family in step with the selection, and intervention.js opens the
 * composer from those attributes when it is clicked.
 *
 * @module     gradereport_coifish/attention_list
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Wire up one attention list.
 *
 * @param {HTMLElement} region The [data-region="attention-list"] container.
 */
const setup = (region) => {
    const selectAll = region.querySelector('[data-action="select-all"]');
    const selectMissed = region.querySelector('[data-action="select-missed"]');
    const messageBtn = region.querySelector('[data-action="message-selected"]');
    const countEl = region.querySelector('[data-region="selected-count"]');
    // The count placeholder is __COUNT__ because Moodle's string helper reads a
    // parameter that starts with "{" as JSON.
    const countTemplate = region.getAttribute('data-selected-template') || '__COUNT__';
    const noneText = region.getAttribute('data-none-selected') || '';

    const boxes = () => Array.from(region.querySelectorAll('.gradetracker-attention-select'));

    const update = () => {
        const all = boxes();
        const checked = all.filter((cb) => cb.checked);
        const students = checked.map((cb) => {
            const row = cb.closest('tr');
            return {id: parseInt(cb.value, 10), name: row.getAttribute('data-fullname') || ''};
        });

        all.forEach((cb) => cb.closest('tr').classList.toggle('table-active', cb.checked));

        if (selectAll) {
            selectAll.checked = checked.length > 0 && checked.length === all.length;
            selectAll.indeterminate = checked.length > 0 && checked.length < all.length;
        }
        if (countEl) {
            countEl.textContent = checked.length
                ? countTemplate.replace('__COUNT__', String(checked.length))
                : noneText;
        }
        if (messageBtn) {
            // When every selected student has missed a due date, open the composer
            // on the "missed work" template ({missedwork} lists each student's own
            // missed activities); otherwise use the general check-in template.
            const allMissed = checked.length > 0 && checked.every((cb) => {
                return parseInt(cb.closest('tr').getAttribute('data-missed') || '0', 10) > 0;
            });
            messageBtn.setAttribute('data-students', JSON.stringify(students));
            messageBtn.setAttribute('data-diagnostictype', allMissed ? 'cohort_missed' : 'cohort_attention');
            messageBtn.setAttribute('data-tpl-family', allMissed ? 'missing' : 'generic');
            messageBtn.disabled = checked.length === 0;
        }
    };

    region.addEventListener('change', (e) => {
        if (e.target === selectAll) {
            boxes().forEach((cb) => {
                cb.checked = selectAll.checked;
            });
        }
        if (e.target === selectAll || e.target.classList.contains('gradetracker-attention-select')) {
            update();
        }
    });

    if (selectMissed) {
        selectMissed.addEventListener('click', () => {
            boxes().forEach((cb) => {
                cb.checked = parseInt(cb.closest('tr').getAttribute('data-missed') || '0', 10) > 0;
            });
            update();
            if (messageBtn) {
                messageBtn.focus();
            }
        });
    }

    update();
};

/**
 * Initialise every attention list on the page.
 */
export const init = () => {
    document.querySelectorAll('[data-region="attention-list"]').forEach((region) => {
        if (!region.hasAttribute('data-initialised')) {
            region.setAttribute('data-initialised', '1');
            setup(region);
        }
    });
};
