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

namespace theme_ismos\local;

/**
 * When a course's classes are: "Tuesday & Thursday, 17:00-18:30 (UTC-3)".
 *
 * Read from the course's attendance sessions, which teachers create for every live class. Only sessions still to come
 * count. For each weekday the most common start time and length wins, so one moved class does not change the line, and
 * a weekday with far fewer classes than the busiest one (a one-off extra class) is left out.
 *
 * The text is in the viewer's Moodle time zone (the site's, for a visitor who is not signed in). The slots let the
 * browser rewrite it in the visitor's own time zone (amd/src/classtimes.js).
 *
 * @package   theme_ismos
 * @copyright 2026 ISMOS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schedule {
    /** @var int Sessions longer than this are not classes (a whole day left open for students to mark themselves). */
    private const MAXDURATION = 8 * HOURSECS;

    /**
     * The class times of a course, or null when no class is still to come.
     *
     * @param int $courseid
     * @param int|null $now defaults to the current time
     * @return array|null long ("Tuesday & Thursday, 17:00-18:30 (UTC-3)"), short ("Tue & Thu · 17:00 (UTC-3)") and
     *     slots (JSON list of the next class of each weekday: start timestamp and duration in seconds)
     */
    public static function export(int $courseid, ?int $now = null): ?array {
        $tz = \core_date::get_user_timezone_object();
        $slots = self::slots(self::sessions($courseid, $now ?? time()), $tz);
        if (!$slots) {
            return null;
        }
        $label = ' (' . self::utc_offset($tz, $slots[0]['start']) . ')';
        return [
            'long' => self::text($slots, $tz, true) . $label,
            'short' => self::text($slots, $tz, false) . $label,
            'slots' => json_encode(array_map(
                fn(array $slot): array => ['start' => $slot['start'], 'duration' => $slot['duration']],
                $slots
            )),
        ];
    }

    /**
     * Context for the theme_ismos/classtimes template, or null when no class is still to come.
     *
     * @param int $courseid
     * @param string $form "long" for the course page, "short" for cards
     * @return array|null form, text, slots and the "your time" labels the browser uses
     */
    public static function template(int $courseid, string $form): ?array {
        $times = self::export($courseid);
        if ($times === null) {
            return null;
        }
        return [
            'form' => $form,
            'text' => $times[$form],
            'slots' => $times['slots'],
            'yourtime' => get_string('classtimesyourtime', 'theme_ismos'),
            // The browser puts the place name in; {$a} stays for it.
            'yourtimein' => get_string('classtimesyourtimein', 'theme_ismos', '{$a}'),
        ];
    }

    /**
     * Sessions still to come in the course's visible attendance activities.
     *
     * @param int $courseid
     * @param int $now
     * @return \stdClass[] sessdate and duration, earliest first
     */
    private static function sessions(int $courseid, int $now): array {
        global $DB;
        if (\core_component::get_component_directory('mod_attendance') === null) {
            return [];
        }
        return array_values($DB->get_records_sql(
            "SELECT s.id, s.sessdate, s.duration
               FROM {attendance_sessions} s
               JOIN {attendance} a ON a.id = s.attendanceid
               JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = a.course
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
              WHERE a.course = :courseid AND cm.visible = 1 AND cm.deletioninprogress = 0
                AND s.sessdate >= :now AND s.duration > 0 AND s.duration <= :maxduration
           ORDER BY s.sessdate ASC, s.id ASC",
            ['modname' => 'attendance', 'courseid' => $courseid, 'now' => $now, 'maxduration' => self::MAXDURATION]
        ));
    }

    /**
     * One slot per weekday that has classes: its usual start time and length, Monday first.
     *
     * @param \stdClass[] $sessions earliest first
     * @param \DateTimeZone $tz
     * @return array[] weekday (1 Monday to 7 Sunday), start (the next class in that slot) and duration
     */
    private static function slots(array $sessions, \DateTimeZone $tz): array {
        // Weekday => "H:i|duration" => start times, earliest first.
        $days = [];
        foreach ($sessions as $session) {
            $local = (new \DateTimeImmutable('@' . $session->sessdate))->setTimezone($tz);
            $days[(int) $local->format('N')][$local->format('H:i') . '|' . $session->duration][] = (int) $session->sessdate;
        }
        if (!$days) {
            return [];
        }
        $counts = array_map(fn(array $kinds): int => array_sum(array_map('count', $kinds)), $days);
        $busiest = max($counts);
        ksort($days);

        $slots = [];
        foreach ($days as $weekday => $kinds) {
            if ($counts[$weekday] * 2 < $busiest) {
                continue;
            }
            // Most classes first; on a tie, the one coming first.
            uasort($kinds, fn(array $a, array $b): int => [count($b), $a[0]] <=> [count($a), $b[0]]);
            $starts = reset($kinds);
            $slots[] = [
                'weekday' => $weekday,
                'start' => $starts[0],
                'duration' => (int) explode('|', key($kinds))[1],
            ];
        }
        return $slots;
    }

    /**
     * The line without the time zone: days, then hours.
     *
     * @param array[] $slots
     * @param \DateTimeZone $tz
     * @param bool $long full day names and the end time, for the course page; else short names and the start, for cards
     * @return string
     */
    private static function text(array $slots, \DateTimeZone $tz, bool $long): string {
        $day = fn(array $slot): string => userdate($slot['start'], $long ? '%A' : '%a', $tz->getName());
        $hours = function (array $slot) use ($tz, $long): string {
            $start = userdate($slot['start'], '%H:%M', $tz->getName());
            return $long ? $start . '-' . userdate($slot['start'] + $slot['duration'], '%H:%M', $tz->getName()) : $start;
        };
        $samehours = count(array_unique(array_map($hours, $slots))) === 1;
        if ($samehours) {
            return self::join(array_map($day, $slots)) . ($long ? ', ' : ' · ') . $hours($slots[0]);
        }
        return self::join(array_map(fn(array $slot): string => $day($slot) . ' ' . $hours($slot), $slots));
    }

    /**
     * "A", "A & B", "A, B & C".
     *
     * @param string[] $parts
     * @return string
     */
    private static function join(array $parts): string {
        $last = array_pop($parts);
        return $parts ? implode(', ', $parts) . ' & ' . $last : $last;
    }

    /**
     * The time zone's offset at a moment: "UTC-3", "UTC+5:30", "UTC".
     *
     * @param \DateTimeZone $tz
     * @param int $time
     * @return string
     */
    private static function utc_offset(\DateTimeZone $tz, int $time): string {
        $offset = $tz->getOffset(new \DateTimeImmutable('@' . $time));
        if ($offset === 0) {
            return 'UTC';
        }
        $minutes = intdiv(abs($offset), MINSECS);
        $text = intdiv($minutes, 60) . ($minutes % 60 ? sprintf(':%02d', $minutes % 60) : '');
        return 'UTC' . ($offset < 0 ? '-' : '+') . $text;
    }
}
