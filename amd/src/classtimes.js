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
 * Class times in the visitor's own time zone: "Tuesday & Thursday, 21:00-22:30 (your time, Madrid)".
 *
 * The server renders the line in the viewer's Moodle time zone (theme_ismos\local\schedule) with the next class of each
 * weekday; this rebuilds it the same way from those classes in the browser's time zone, where the weekday may change.
 *
 * @module     theme_ismos/classtimes
 * @copyright  2026 ISMOS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTOR = '[data-ismos-classtimes]';

/** Weekday names Intl gives in en-US, Monday first, to order the days whatever the page language. */
const WEEK = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/**
 * "A", "A & B", "A, B & C".
 *
 * @param {string[]} parts
 * @returns {string}
 */
const join = (parts) => (parts.length < 2 ? parts.join('')
    : parts.slice(0, -1).join(', ') + ' & ' + parts[parts.length - 1]);

/**
 * The line without the time zone, as theme_ismos\local\schedule::text() builds it: weekdays, or dates when the classes
 * are one-offs.
 *
 * @param {{start: number, duration: number, once: boolean}[]} slots
 * @param {boolean} long Full day names and the end time; else short names and the start time
 * @param {string|undefined} lang
 * @param {string} timeZone
 * @returns {string}
 */
const format = (slots, long, lang, timeZone) => {
    const once = slots[0].once === true;
    const day = new Intl.DateTimeFormat(lang, once
        ? {day: 'numeric', month: long ? 'long' : 'short', timeZone}
        : {weekday: long ? 'long' : 'short', timeZone});
    const clock = new Intl.DateTimeFormat(lang, {hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone});
    const weekday = new Intl.DateTimeFormat('en-US', {weekday: 'short', timeZone});
    const items = slots.map((slot) => {
        const start = new Date(slot.start * 1000);
        const end = new Date((slot.start + slot.duration) * 1000);
        return {
            // One-off classes read in date order, weekly ones Monday first.
            index: once ? slot.start : WEEK.indexOf(weekday.format(start)),
            day: day.format(start),
            hours: long ? clock.format(start) + '-' + clock.format(end) : clock.format(start),
        };
    }).sort((a, b) => a.index - b.index);
    if (new Set(items.map((item) => item.hours)).size === 1) {
        return join(items.map((item) => item.day)) + (long ? ', ' : ' · ') + items[0].hours;
    }
    return join(items.map((item) => item.day + ' ' + item.hours));
};

/**
 * Rewrite every class times line on the page that is not rewritten yet.
 */
export const init = () => {
    let timeZone;
    try {
        timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    } catch (e) {
        return;
    }
    if (!timeZone) {
        return;
    }
    const lang = document.documentElement.lang || undefined;
    // "Europe/Madrid" reads as "Madrid". A zone without a place gets no name: UTC, and Etc/GMT+3, whose sign is the
    // reverse of its offset.
    const place = timeZone.includes('/') && !timeZone.startsWith('Etc/')
        ? timeZone.split('/').pop().replace(/_/g, ' ') : '';

    document.querySelectorAll(SELECTOR).forEach((element) => {
        const long = element.dataset.ismosClasstimes === 'long';
        // Marks the line done: init() runs once per line rendered, on pages with many cards.
        element.removeAttribute('data-ismos-classtimes');
        let slots;
        try {
            slots = JSON.parse(element.dataset.slots);
        } catch (e) {
            return;
        }
        if (!Array.isArray(slots) || !slots.length) {
            return;
        }
        const label = long && place ? element.dataset.yourtimein.replace('{$a}', place) : element.dataset.yourtime;
        element.textContent = format(slots, long, lang, timeZone) + ' (' + label + ')';
    });
};
