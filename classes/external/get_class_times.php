<?php
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

namespace theme_ismos\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use theme_ismos\local\schedule;

/**
 * When the classes of visible courses are, for the ISMOS website: the next class of each weekday with classes.
 *
 * Only times: the same the public course page shows, so any signed-in web service user may read them. Unlike
 * mod_attendance_get_sessions it carries no student names or attendance records.
 *
 * @package   theme_ismos
 * @copyright 2026 ISMOS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_class_times extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseids' => new external_multiple_structure(new external_value(PARAM_INT, 'Course id')),
        ]);
    }

    /**
     * Class times of the given courses; courses that are hidden, unknown or have no class still to come are left out.
     *
     * @param int[] $courseids
     * @return array[] courseid and slots (start, duration)
     */
    public static function execute(array $courseids): array {
        global $DB;
        ['courseids' => $courseids] = self::validate_parameters(self::execute_parameters(), ['courseids' => $courseids]);
        self::validate_context(\context_system::instance());

        $result = [];
        foreach (array_unique($courseids) as $courseid) {
            if ((int) $courseid === SITEID || !$DB->record_exists('course', ['id' => $courseid, 'visible' => 1])) {
                continue;
            }
            $slots = schedule::next_classes((int) $courseid);
            if ($slots) {
                $result[] = ['courseid' => (int) $courseid, 'slots' => $slots];
            }
        }
        return $result;
    }

    /**
     * Return structure.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'slots' => new external_multiple_structure(new external_single_structure([
                'start' => new external_value(PARAM_INT, 'Start of the next class on this weekday (timestamp)'),
                'duration' => new external_value(PARAM_INT, 'Length of the class in seconds'),
            ]), 'One per weekday with classes, Monday first in the site time zone'),
        ]));
    }
}
