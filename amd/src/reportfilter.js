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
 * Filter the reference list in the reference check report.
 *
 * Purely client side: every reference is already in the DOM, so filtering is a matter of hiding
 * the ones that do not match. The report is rendered server side and stays usable without this.
 *
 * @module     assignsubmission_refchecker/reportfilter
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTORS = {
    report: '[data-region="refchecker-report"]',
    filterButton: '[data-action="refchecker-filter"]',
    reference: '[data-region="refchecker-reference"]',
};

/**
 * Whether a reference matches the selected filter.
 *
 * @param {HTMLElement} reference
 * @param {String} filter
 * @return {Boolean}
 */
const matches = (reference, filter) => {
    if (filter === 'all') {
        return true;
    }
    if (filter === 'issues') {
        return reference.dataset.hasissues === '1';
    }
    return reference.dataset.matchstatus === filter;
};

/**
 * Apply a filter within one report.
 *
 * @param {HTMLElement} report
 * @param {String} filter
 */
const applyFilter = (report, filter) => {
    report.querySelectorAll(SELECTORS.reference).forEach((reference) => {
        reference.classList.toggle('d-none', !matches(reference, filter));
    });

    report.querySelectorAll(SELECTORS.filterButton).forEach((button) => {
        const active = button.dataset.filter === filter;
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
        button.classList.toggle('btn-secondary', active);
        button.classList.toggle('btn-outline-secondary', !active);
    });
};

/**
 * Wire up the filter bars.
 *
 * Delegated from the document so that a page showing several reports — the grading view expanding
 * more than one submission — works without re-initialising.
 */
export const init = () => {
    if (document.body.dataset.refcheckerFilterReady === '1') {
        return;
    }
    document.body.dataset.refcheckerFilterReady = '1';

    document.addEventListener('click', (e) => {
        const button = e.target.closest(SELECTORS.filterButton);
        if (!button) {
            return;
        }
        const report = button.closest(SELECTORS.report);
        if (!report) {
            return;
        }
        e.preventDefault();
        applyFilter(report, button.dataset.filter);
    });
};
