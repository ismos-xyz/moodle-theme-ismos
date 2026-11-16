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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The web service the ISMOS website reads class times from.
 *
 * @package   theme_ismos
 * @copyright 2026 ISMOS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(get_class_times::class)]
final class get_class_times_test extends \advanced_testcase {
    /** @var string The site time zone of the campus. */
    private const TZ = 'America/Argentina/Buenos_Aires';

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        if (\core_component::get_component_directory('mod_attendance') === null) {
            $this->markTestSkipped('mod_attendance is not installed');
        }
        $this->resetAfterTest();
        $this->setTimezone(self::TZ);
    }

    /**
     * A course with an attendance activity holding sessions from next week on.
     *
     * @param array $sessions [weekday and time relative to now, minutes] pairs, e.g. ['next tuesday 17:00', 90]
     * @param array $course course fields
     * @return \stdClass
     */
    private function course(array $sessions, array $course = []): \stdClass {
        global $DB;
        $course = $this->getDataGenerator()->create_course($course);
        $attendance = $this->getDataGenerator()->create_module('attendance', ['course' => $course->id]);
        foreach ($sessions as [$when, $minutes]) {
            $DB->insert_record('attendance_sessions', [
                'attendanceid' => $attendance->id,
                'sessdate' => (new \DateTimeImmutable($when, new \DateTimeZone(self::TZ)))->getTimestamp(),
                'duration' => $minutes * MINSECS,
                'description' => '',
            ]);
        }
        return $course;
    }

    /**
     * Call the function as a web service client does, returns cleaned.
     *
     * @param int[] $courseids
     * @return array
     */
    private function call(array $courseids): array {
        $result = get_class_times::execute($courseids);
        return external_api::clean_returnvalue(get_class_times::execute_returns(), $result);
    }

    public function test_returns_the_next_class_of_each_weekday_and_nothing_else(): void {
        $this->setAdminUser();
        $course = $this->course([['next tuesday 17:00', 90], ['next thursday 17:00', 90], ['next tuesday 17:00 +1 week', 90]]);
        $plain = $this->getDataGenerator()->create_course();

        $result = $this->call([(int) $course->id, (int) $plain->id]);

        $tz = new \DateTimeZone(self::TZ);
        $this->assertSame([[
            'courseid' => (int) $course->id,
            'slots' => [
                ['start' => (new \DateTimeImmutable('next tuesday 17:00', $tz))->getTimestamp(), 'duration' => 5400, 'once' => false],
                ['start' => (new \DateTimeImmutable('next thursday 17:00', $tz))->getTimestamp(), 'duration' => 5400, 'once' => false],
            ],
        ]], $result);
    }

    public function test_hidden_and_unknown_courses_are_left_out(): void {
        $this->setAdminUser();
        $hidden = $this->course([['next tuesday 17:00', 90]], ['visible' => 0]);

        $this->assertSame([], $this->call([(int) $hidden->id, 999999, SITEID]));
    }

    public function test_courses_in_hidden_categories_are_left_out(): void {
        // The public catalogue does not list them either.
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category(['visible' => 0]);
        $course = $this->course([['next tuesday 17:00', 90]], ['category' => $category->id]);

        $this->assertSame([], $this->call([(int) $course->id]));
    }

    public function test_one_off_classes_are_marked(): void {
        $this->setAdminUser();
        $course = $this->course([['next tuesday 17:00', 90]]);

        $this->assertTrue($this->call([(int) $course->id])[0]['slots'][0]['once']);
    }

    public function test_too_many_courses_in_one_call_are_refused(): void {
        $this->setAdminUser();

        $this->expectException(\invalid_parameter_exception::class);
        $this->call(range(1, get_class_times::MAXCOURSES + 1));
    }

    public function test_any_signed_in_user_may_read_it(): void {
        // The same times are on the public course page.
        $course = $this->course([['next tuesday 17:00', 90]]);
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertCount(1, $this->call([(int) $course->id]));
    }

    public function test_weekdays_are_grouped_in_the_site_time_zone_whatever_the_caller(): void {
        // Friday 22:00 in Buenos Aires is Saturday in Madrid; the slot is the same class either way.
        $course = $this->course([['next friday 22:00', 60], ['next friday 22:00 +1 week', 60]]);
        $this->setUser($this->getDataGenerator()->create_user(['timezone' => 'Europe/Madrid']));

        $slots = $this->call([(int) $course->id])[0]['slots'];

        $this->assertCount(1, $slots);
        $this->assertSame((new \DateTimeImmutable('next friday 22:00', new \DateTimeZone(self::TZ)))->getTimestamp(), $slots[0]['start']);
    }
}
