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
 * Read from the course's attendance sessions, which teachers create for every live class. Only classes not over yet
 * count. For each weekday the most common start time and length wins, so one moved class does not change the line, and
 * a weekday with far fewer classes than the busiest one (a one-off extra class) is left out. When no weekday has a
 * second class to come, the classes are one-offs and the line gives their dates instead: "November 17, 17:00-18:30".
 * When groups have classes at different times, no single line is true for everyone, so there is none.
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
     * The class times of courses: what export() gives, by course id, for the courses with a class to come.
     *
     * One query for every course, for pages with many cards.
     *
     * @param int[] $courseids
     * @param int|null $now defaults to the current time
     * @return array[] by course id: long ("Tuesday & Thursday, 17:00-18:30 (UTC-3)"), short ("Tue & Thu · 17:00
     *     (UTC-3)") and slots (JSON list of the next class of each weekday: start timestamp, duration in seconds and
     *     once, true for one-off classes)
     */
    public static function export_many(array $courseids, ?int $now = null): array {
        $tz = \core_date::get_user_timezone_object();
        $result = [];
        foreach (self::sessions($courseids, $now ?? time()) as $courseid => $sessions) {
            $slots = self::slots($sessions, $tz);
            if (!$slots) {
                continue;
            }
            $label = ' (' . self::utc_offset($tz, $slots[0]['start']) . ')';
            $result[$courseid] = [
                'long' => self::text($slots, $tz, true) . $label,
                'short' => self::text($slots, $tz, false) . $label,
                'slots' => json_encode(self::public_slots($slots)),
            ];
        }
        return $result;
    }

    /**
     * The class times of a course, or null when no class is to come.
     *
     * @param int $courseid
     * @param int|null $now defaults to the current time
     * @return array|null long, short and slots, as export_many() gives them
     */
    public static function export(int $courseid, ?int $now = null): ?array {
        return self::export_many([$courseid], $now)[$courseid] ?? null;
    }

    /**
     * The next class of each weekday with classes, for clients that write the line themselves (the ISMOS website).
     *
     * Weekdays are grouped in the site time zone, where the classes are planned, whoever asks.
     *
     * @param int[] $courseids
     * @param int|null $now defaults to the current time
     * @return array[] by course id, for the courses with a class to come: slots with start (timestamp), duration
     *     (seconds) and once (a one-off class), Monday first
     */
    public static function next_classes(array $courseids, ?int $now = null): array {
        $tz = \core_date::get_server_timezone_object();
        $result = [];
        foreach (self::sessions($courseids, $now ?? time()) as $courseid => $sessions) {
            $slots = self::slots($sessions, $tz);
            if ($slots) {
                $result[$courseid] = self::public_slots($slots);
            }
        }
        return $result;
    }

    /**
     * Context for the theme_ismos/classtimes template, by course id, for the courses with a class to come.
     *
     * @param int[] $courseids
     * @param string $form "long" for the course page, "short" for cards
     * @return array[] form, text, slots and the "your time" labels the browser uses
     */
    public static function template_many(array $courseids, string $form): array {
        $result = [];
        foreach (self::export_many($courseids) as $courseid => $times) {
            $result[$courseid] = [
                'form' => $form,
                'text' => $times[$form],
                'slots' => $times['slots'],
                'yourtime' => get_string('classtimesyourtime', 'theme_ismos'),
                // The browser puts the place name in; {$a} stays for it.
                'yourtimein' => get_string('classtimesyourtimein', 'theme_ismos', '{$a}'),
            ];
        }
        return $result;
    }

    /**
     * Context for the theme_ismos/classtimes template, or null when no class is to come.
     *
     * @param int $courseid
     * @param string $form "long" for the course page, "short" for cards
     * @return array|null
     */
    public static function template(int $courseid, string $form): ?array {
        return self::template_many([$courseid], $form)[$courseid] ?? null;
    }

    /**
     * Sessions not over yet in the courses' visible attendance activities.
     *
     * Nothing when the attendance plugin is not installed, or an admin disabled it.
     *
     * @param int[] $courseids
     * @param int $now
     * @return array[] by course id: sessions (sessdate, duration, groupid), earliest first
     */
    private static function sessions(array $courseids, int $now): array {
        global $DB;
        $courseids = array_unique(array_map('intval', $courseids));
        if (!$courseids || !get_config('mod_attendance', 'version')) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $records = $DB->get_records_sql(
            "SELECT s.id, a.course, s.sessdate, s.duration, s.groupid
               FROM {attendance_sessions} s
               JOIN {attendance} a ON a.id = s.attendanceid
               JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = a.course
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname AND m.visible = 1
              WHERE a.course $insql AND cm.visible = 1 AND cm.deletioninprogress = 0
                AND s.sessdate + s.duration > :now AND s.duration > 0 AND s.duration <= :maxduration
           ORDER BY s.sessdate ASC, s.id ASC",
            $params + ['modname' => 'attendance', 'now' => $now, 'maxduration' => self::MAXDURATION]
        );
        $bycourse = [];
        foreach ($records as $record) {
            $bycourse[(int) $record->course][] = $record;
        }
        ksort($bycourse);
        return $bycourse;
    }

    /**
     * The slots of a course, unless its groups have classes at different times.
     *
     * Sessions for the whole course count as one more group.
     *
     * @param \stdClass[] $sessions earliest first
     * @param \DateTimeZone $tz
     * @return array[] as pattern() gives them; empty when the groups disagree
     */
    private static function slots(array $sessions, \DateTimeZone $tz): array {
        $bygroup = [];
        foreach ($sessions as $session) {
            $bygroup[(int) $session->groupid][] = $session;
        }
        if (count($bygroup) > 1) {
            $shape = fn(array $slots): array => array_map(
                fn(array $slot): array => [$slot['once'] ? $slot['start'] : $slot['weekday'],
                    userdate($slot['start'], '%H:%M', $tz->getName()), $slot['duration']],
                $slots
            );
            $shapes = array_map(fn(array $group): array => $shape(self::pattern($group, $tz)), $bygroup);
            if (count(array_unique(array_map('serialize', $shapes))) > 1) {
                return [];
            }
        }
        return self::pattern($sessions, $tz);
    }

    /**
     * One slot per weekday that has classes: its usual start time and length, Monday first.
     *
     * @param \stdClass[] $sessions earliest first
     * @param \DateTimeZone $tz
     * @return array[] weekday (1 Monday to 7 Sunday), start (the next class in that slot), duration and once (when no
     *     weekday has a second class to come: each slot is then a one-off class on its date)
     */
    private static function pattern(array $sessions, \DateTimeZone $tz): array {
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
                'once' => $busiest === 1,
            ];
        }
        if ($busiest === 1) {
            // One-off classes read in date order, not weekday order.
            usort($slots, fn(array $a, array $b): int => $a['start'] <=> $b['start']);
        }
        return $slots;
    }

    /**
     * The slots as clients get them.
     *
     * @param array[] $slots
     * @return array[] start, duration and once
     */
    private static function public_slots(array $slots): array {
        return array_map(
            fn(array $slot): array => ['start' => $slot['start'], 'duration' => $slot['duration'], 'once' => $slot['once']],
            $slots
        );
    }

    /**
     * The line without the time zone: days (or dates, for one-off classes), then hours.
     *
     * @param array[] $slots
     * @param \DateTimeZone $tz
     * @param bool $long full day names and the end time, for the course page; else short names and the start, for cards
     * @return string
     */
    private static function text(array $slots, \DateTimeZone $tz, bool $long): string {
        if ($slots[0]['once']) {
            // The same ICU pattern the browser's Intl uses: "November 17", "17 de noviembre".
            $locale = str_replace('_', '-', current_language());
            $pattern = (new \IntlDatePatternGenerator($locale))->getBestPattern($long ? 'dMMMM' : 'dMMM');
            $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $tz, null, $pattern);
            $day = fn(array $slot): string => (string) $formatter->format($slot['start']);
        } else {
            $day = fn(array $slot): string => userdate($slot['start'], $long ? '%A' : '%a', $tz->getName());
        }
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
