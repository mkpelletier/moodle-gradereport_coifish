// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Lightweight click-to-sort table helper.
 *
 * Activates on any <table data-sortable="true">. Each sortable column header
 * needs class="gradetracker-sortable" and data-sorttype="text|number". Cells
 * may carry data-sortvalue when the visible text isn't directly sortable
 * (e.g. "65%" with raw 65, or a badge label backed by a numeric level).
 *
 * Click a header to sort ascending; click again to toggle descending. Sort is
 * stable — rows with equal keys preserve their original order. There are no
 * external dependencies and no AJAX — the sort runs entirely in the DOM.
 *
 * @module gradereport_coifish/sortable_table
 * @copyright 2026 South African Theological Seminary (ict@sats.ac.za)
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    'use strict';

    /**
     * Read the sort key from a cell, preferring data-sortvalue, falling back to text.
     *
     * @param {HTMLElement} cell The td element.
     * @param {string} type 'number' or 'text'.
     * @returns {string|number} The sort key.
     */
    function cellKey(cell, type) {
        if (!cell) {
            return type === 'number' ? -Infinity : '';
        }
        var raw = cell.getAttribute('data-sortvalue');
        if (raw === null) {
            raw = cell.textContent || '';
        }
        if (type === 'number') {
            var n = parseFloat(raw);
            return isNaN(n) ? -Infinity : n;
        }
        return raw.trim().toLowerCase();
    }

    /**
     * Sort the table by the clicked header.
     *
     * @param {HTMLTableElement} table The table element.
     * @param {HTMLElement} th The clicked header cell.
     */
    function sortBy(table, th) {
        var headers = table.querySelectorAll('th.gradetracker-sortable');
        var index = Array.prototype.indexOf.call(th.parentNode.children, th);
        var type = th.getAttribute('data-sorttype') || 'text';
        var current = th.getAttribute('data-sortdir');
        var dir = current === 'asc' ? 'desc' : 'asc';

        // Reset all headers, then set this one.
        headers.forEach(function(h) {
            h.removeAttribute('data-sortdir');
            var icon = h.querySelector('.gradetracker-sort-icon');
            if (icon) {
                icon.classList.remove('fa-sort-asc', 'fa-sort-desc');
                icon.classList.add('fa-sort');
            }
        });
        th.setAttribute('data-sortdir', dir);
        var thicon = th.querySelector('.gradetracker-sort-icon');
        if (thicon) {
            thicon.classList.remove('fa-sort');
            thicon.classList.add(dir === 'asc' ? 'fa-sort-asc' : 'fa-sort-desc');
        }

        var tbody = table.tBodies[0];
        if (!tbody) {
            return;
        }
        var rows = Array.prototype.slice.call(tbody.rows);
        // Decorate with original index for stable sort.
        var decorated = rows.map(function(row, i) {
            return {row: row, key: cellKey(row.cells[index], type), idx: i};
        });
        decorated.sort(function(a, b) {
            if (a.key < b.key) {
                return dir === 'asc' ? -1 : 1;
            }
            if (a.key > b.key) {
                return dir === 'asc' ? 1 : -1;
            }
            return a.idx - b.idx;
        });
        decorated.forEach(function(entry) {
            tbody.appendChild(entry.row);
        });
    }

    return {
        /**
         * Wire every sortable table on the page.
         */
        init: function() {
            var tables = document.querySelectorAll('table[data-sortable="true"]');
            tables.forEach(function(table) {
                var headers = table.querySelectorAll('th.gradetracker-sortable');
                headers.forEach(function(th) {
                    th.style.cursor = 'pointer';
                    th.addEventListener('click', function() {
                        sortBy(table, th);
                    });
                });
            });
        },
    };
});
