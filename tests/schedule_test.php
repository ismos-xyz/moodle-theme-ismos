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

namespace theme_ismos;

use PHPUnit\Framework\Attributes\CoversClass;
use theme_ismos\local\schedule;

/**
 * The class times line, read from the course's attendance sessions.
 *
 * @package   theme_ismos
 * @copyright 2026 ISMOS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(schedule::class)]
#[CoversClass(\theme_ismos\local\card::class)]
#[CoversClass(\theme_ismos\local\catalogue::class)]
#[CoversClass(\theme_ismos\local\landing::class)]
#[CoversClass(\theme_ismos\output\core\course_renderer::class)]
final class schedule_test extends \advanced_testcase {
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
     * A timestamp from a local Buenos Aires date and time.
     *
     * @param string $local e.g. '2026-11-17 17:00'
     * @return int
     */
    private static function at(string $local): int {
        return (new \DateTimeImmutable($local, new \DateTimeZone(self::TZ)))->getTimestamp();
    }

    /**
     * A course with one attendance activity holding the given sessions.
     *
     * @param array $sessions [local start, minutes] pairs
     * @param array $cmoptions options for the attendance activity
     * @return \stdClass the course
     */
    private function course_with_sessions(array $sessions, array $cmoptions = []): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $this->add_sessions($course, $sessions, $cmoptions);
        return $course;
    }

    /**
     * Add an attendance activity with sessions to a course.
     *
     * @param \stdClass $course
     * @param array $sessions [local start, minutes] pairs, or [local start, minutes, group id] for one group's classes
     * @param array $cmoptions options for the attendance activity
     */
    private function add_sessions(\stdClass $course, array $sessions, array $cmoptions = []): void {
        global $DB;
        $attendance = $this->getDataGenerator()->create_module('attendance', ['course' => $course->id] + $cmoptions);
        foreach ($sessions as $session) {
            [$start, $minutes] = $session;
            $DB->insert_record('attendance_sessions', [
                'attendanceid' => $attendance->id,
                'groupid' => $session[2] ?? 0,
                'sessdate' => self::at($start),
                'duration' => $minutes * MINSECS,
                'description' => '',
            ]);
        }
    }

    public function test_weekly_classes_read_as_days_and_hours(): void {
        $course = $this->course_with_sessions([
            ['2026-11-17 17:00', 90], ['2026-11-19 17:00', 90],
            ['2026-11-24 17:00', 90], ['2026-11-26 17:00', 90],
        ]);

        $times = schedule::export((int) $course->id, self::at('2026-11-16 09:00'));

        $this->assertSame('Tuesday & Thursday, 17:00-18:30 (UTC-3)', $times['long']);
        $this->assertSame('Tue & Thu · 17:00 (UTC-3)', $times['short']);
        $this->assertSame([
            ['start' => self::at('2026-11-17 17:00'), 'duration' => 90 * MINSECS, 'once' => false],
            ['start' => self::at('2026-11-19 17:00'), 'duration' => 90 * MINSECS, 'once' => false],
        ], json_decode($times['slots'], true));
    }

    public function test_only_classes_not_over_yet_count(): void {
        $course = $this->course_with_sessions([
            ['2026-11-03 17:00', 90], ['2026-11-12 18:00', 60], ['2026-11-19 18:00', 60],
        ]);

        $this->assertSame('Thursday, 18:00-19:00 (UTC-3)', schedule::export((int) $course->id, self::at('2026-11-10 09:00'))['long']);
        $this->assertNull(schedule::export((int) $course->id, self::at('2026-11-20 09:00')));
    }

    public function test_a_class_under_way_stays_until_it_ends(): void {
        $course = $this->course_with_sessions([['2026-11-17 17:00', 90]]);

        $this->assertSame('November 17, 17:00-18:30 (UTC-3)', schedule::export((int) $course->id, self::at('2026-11-17 18:20'))['long']);
        $this->assertNull(schedule::export((int) $course->id, self::at('2026-11-17 18:30')));
    }

    public function test_one_off_classes_read_as_dates(): void {
        // No weekday has a second class to come: "Tuesday" would promise a weekly class.
        $course = $this->course_with_sessions([['2026-11-19 17:00', 90], ['2026-11-17 17:00', 90]]);

        $times = schedule::export((int) $course->id, self::at('2026-11-16 09:00'));

        $this->assertSame('November 17 & November 19, 17:00-18:30 (UTC-3)', $times['long']);
        $this->assertSame('Nov 17 & Nov 19 · 17:00 (UTC-3)', $times['short']);
        $this->assertSame([
            ['start' => self::at('2026-11-17 17:00'), 'duration' => 90 * MINSECS, 'once' => true],
            ['start' => self::at('2026-11-19 17:00'), 'duration' => 90 * MINSECS, 'once' => true],
        ], json_decode($times['slots'], true));
    }

    public function test_one_off_classes_on_different_hours_each_get_their_own(): void {
        $course = $this->course_with_sessions([['2026-11-17 17:00', 90], ['2026-11-20 10:00', 60]]);

        $this->assertSame(
            'November 17 17:00-18:30 & November 20 10:00-11:00 (UTC-3)',
            schedule::export((int) $course->id, self::at('2026-11-16 09:00'))['long']
        );
    }

    public function test_groups_with_classes_at_different_times_have_no_line(): void {
        // No line is true for both groups: the first would read as the course's, and both together as a third one.
        $course = $this->course_with_sessions([
            ['2026-11-17 17:00', 90, 1], ['2026-11-24 17:00', 90, 1],
            ['2026-11-17 19:00', 90, 2], ['2026-11-24 19:00', 90, 2],
        ]);

        $this->assertNull(schedule::export((int) $course->id, self::at('2026-11-16 09:00')));
    }

    public function test_groups_with_classes_at_the_same_times_share_the_line(): void {
        $course = $this->course_with_sessions([
            ['2026-11-17 17:00', 90, 1], ['2026-11-24 17:00', 90, 1],
            ['2026-11-17 17:00', 90, 2], ['2026-11-24 17:00', 90, 2],
        ]);

        $this->assertSame('Tuesday, 17:00-18:30 (UTC-3)', schedule::export((int) $course->id, self::at('2026-11-16 09:00'))['long']);
    }

    public function test_classes_are_not_shown_when_attendance_is_disabled(): void {
        global $DB;
        $course = $this->course_with_sessions([['2026-11-17 17:00', 90], ['2026-11-24 17:00', 90]]);
        $DB->set_field('modules', 'visible', 0, ['name' => 'attendance']);

        $this->assertNull(schedule::export((int) $course->id, self::at('2026-11-16 09:00')));
    }

    public function test_many_courses_are_read_at_once(): void {
        global $DB;
        $weekly = $this->course_with_sessions([['2026-11-17 17:00', 90], ['2026-11-24 17:00', 90]]);
        $once = $this->course_with_sessions([['2026-11-19 18:00', 60]]);
        $plain = $this->getDataGenerator()->create_course();
        schedule::export_many([(int) $plain->id]);

        $queries = $DB->perf_get_reads();
        $times = schedule::export_many([(int) $weekly->id, (int) $once->id, (int) $plain->id], self::at('2026-11-16 09:00'));

        $this->assertSame(1, $DB->perf_get_reads() - $queries);
        $this->assertSame([(int) $weekly->id, (int) $once->id], array_keys($times));
        $this->assertSame('Tue · 17:00 (UTC-3)', $times[(int) $weekly->id]['short']);
        $this->assertSame('Nov 19 · 18:00 (UTC-3)', $times[(int) $once->id]['short']);
    }

    public function test_a_class_moved_once_does_not_change_the_line(): void {
        $course = $this->course_with_sessions([
            ['2026-11-19 18:00', 120], ['2026-11-26 17:00', 120], ['2026-12-03 17:00', 120],
        ]);

        $times = schedule::export((int) $course->id, self::at('2026-11-16 09:00'));

        $this->assertSame('Thursday, 17:00-19:00 (UTC-3)', $times['long']);
        $this->assertSame(
            [['start' => self::at('2026-11-26 17:00'), 'duration' => 120 * MINSECS, 'once' => false]],
            json_decode($times['slots'], true)
        );
    }

    public function test_a_one_off_class_on_another_day_is_left_out(): void {
        $course = $this->course_with_sessions([
            ['2026-11-17 17:00', 90], ['2026-11-24 17:00', 90], ['2026-12-01 17:00', 90], ['2026-12-08 17:00', 90],
            ['2026-11-21 10:00', 180],
        ]);

        $this->assertSame('Tuesday, 17:00-18:30 (UTC-3)', schedule::export((int) $course->id, self::at('2026-11-16 09:00'))['long']);
    }

    public function test_days_with_different_hours_each_get_their_own(): void {
        $course = $this->course_with_sessions([
            ['2026-11-17 17:00', 90], ['2026-11-19 18:30', 60], ['2026-11-24 17:00', 90], ['2026-11-26 18:30', 60],
        ]);

        $times = schedule::export((int) $course->id, self::at('2026-11-16 09:00'));

        $this->assertSame('Tuesday 17:00-18:30 & Thursday 18:30-19:30 (UTC-3)', $times['long']);
        $this->assertSame('Tue 17:00 & Thu 18:30 (UTC-3)', $times['short']);
    }

    public function test_three_days_are_listed_with_commas(): void {
        $course = $this->course_with_sessions([
            ['2026-11-16 22:00', 60], ['2026-11-18 22:00', 60], ['2026-11-20 22:00', 60],
            ['2026-11-23 22:00', 60], ['2026-11-25 22:00', 60], ['2026-11-27 22:00', 60],
        ]);

        $this->assertSame('Mon, Wed & Fri · 22:00 (UTC-3)', schedule::export((int) $course->id, self::at('2026-11-16 09:00'))['short']);
    }

    public function test_sessions_that_are_not_classes_are_ignored(): void {
        // Placeholders seen on the campus: no length, or a whole day to let students mark themselves.
        $course = $this->course_with_sessions([
            ['2026-11-17 00:00', 0], ['2026-11-18 00:00', 1439], ['2026-11-19 17:00', 90], ['2026-11-26 17:00', 90],
        ]);

        $this->assertSame('Thursday, 17:00-18:30 (UTC-3)', schedule::export((int) $course->id, self::at('2026-11-16 09:00'))['long']);
    }

    public function test_a_hidden_attendance_activity_is_ignored(): void {
        $course = $this->course_with_sessions([['2026-11-17 17:00', 90]], ['visible' => 0]);

        $this->assertNull(schedule::export((int) $course->id, self::at('2026-11-16 09:00')));
    }

    public function test_sessions_of_every_attendance_activity_in_the_course_count(): void {
        $course = $this->course_with_sessions([['2026-11-17 17:00', 90], ['2026-11-24 17:00', 90]]);
        $this->add_sessions($course, [['2026-11-19 17:00', 90], ['2026-11-26 17:00', 90]]);

        $this->assertSame('Tue & Thu · 17:00 (UTC-3)', schedule::export((int) $course->id, self::at('2026-11-16 09:00'))['short']);
    }

    public function test_a_course_without_attendance_has_no_line(): void {
        $course = $this->getDataGenerator()->create_course();

        $this->assertNull(schedule::export((int) $course->id, self::at('2026-11-16 09:00')));
    }

    public function test_days_and_hours_follow_the_viewer_time_zone(): void {
        $course = $this->course_with_sessions([['2026-11-20 22:00', 60], ['2026-11-27 22:00', 60]]);
        $this->setUser($this->getDataGenerator()->create_user(['timezone' => 'Europe/Madrid']));

        // Friday 22:00 in Buenos Aires is Saturday 02:00 in Madrid.
        $this->assertSame('Saturday, 02:00-03:00 (UTC+1)', schedule::export((int) $course->id, self::at('2026-11-16 09:00'))['long']);
    }

    /**
     * Sessions from tomorrow on, every Tuesday and Thursday at 17:00 for four weeks, so the line does not depend on today.
     *
     * @return array [local start, minutes] pairs
     */
    private static function coming_weeks(): array {
        $tz = new \DateTimeZone(self::TZ);
        $sessions = [];
        foreach (['tuesday', 'thursday'] as $day) {
            $first = new \DateTimeImmutable('next ' . $day . ' 17:00', $tz);
            for ($week = 0; $week < 4; $week++) {
                $sessions[] = [$first->modify('+' . $week . ' weeks')->format('Y-m-d H:i'), 90];
            }
        }
        return $sessions;
    }

    /**
     * Force the ISMOS theme on a page, as the course pages run.
     *
     * @param string $pagetype
     * @return \moodle_page
     */
    private function ismos_page(string $pagetype): \moodle_page {
        global $PAGE, $SITE;
        $PAGE->set_course($SITE);
        $PAGE->set_url('/');
        $PAGE->set_context(\context_system::instance());
        $PAGE->force_theme('ismos');
        $PAGE->set_pagetype($pagetype);
        return $PAGE;
    }

    public function test_course_cards_show_the_short_line_for_the_browser_to_rewrite(): void {
        $page = $this->ismos_page('course-index');
        $course = $this->course_with_sessions(self::coming_weeks());
        $plain = $this->getDataGenerator()->create_course();
        $output = $page->get_renderer('core', null, RENDERER_TARGET_GENERAL);
        $card = fn(\stdClass $c): string => $output->render_from_template(
            'theme_ismos/course_card',
            \theme_ismos\local\card::export(new \core_course_list_element(get_course($c->id)))
        );

        $html = $card($course);
        $this->assertStringContainsString('data-ismos-classtimes="short"', $html);
        $this->assertStringContainsString('Tue &amp; Thu · 17:00 (UTC-3)', $html);
        $this->assertStringContainsString('data-yourtime="your time"', $html);
        $this->assertStringNotContainsString('ismos-classtimes', $card($plain));
    }

    public function test_the_course_page_shows_the_long_line_with_the_dates(): void {
        $page = $this->ismos_page('course-info');
        $course = $this->course_with_sessions(self::coming_weeks());
        $plain = $this->getDataGenerator()->create_course(['startdate' => 0, 'enddate' => 0]);
        $renderer = $page->get_renderer('core', 'course', RENDERER_TARGET_GENERAL);

        $html = $renderer->course_info_box(get_course($course->id));
        $this->assertStringContainsString('<dt>Classes</dt>', $html);
        $this->assertStringContainsString('data-ismos-classtimes="long"', $html);
        $this->assertStringContainsString('Tuesday &amp; Thursday, 17:00-18:30 (UTC-3)', $html);
        $this->assertStringContainsString('data-yourtimein="your time, {$a}"', $html);
        $this->assertStringNotContainsString('Classes', $renderer->course_info_box(get_course($plain->id)));
    }

    public function test_the_next_course_on_the_landing_shows_its_classes(): void {
        $this->ismos_page('site-index');
        $start = (new \DateTimeImmutable('tomorrow', new \DateTimeZone(self::TZ)))->getTimestamp();
        $course = $this->getDataGenerator()->create_course(['startdate' => $start]);
        $this->add_sessions($course, self::coming_weeks());

        $next = (new \theme_ismos\local\landing())->get_next_cohort();

        $this->assertSame('Tue & Thu · 17:00 (UTC-3)', $next['classes']['text']);
        $this->assertSame('short', $next['classes']['form']);
    }

    public function test_catalogue_cards_show_the_short_line(): void {
        $this->ismos_page('course-index');
        $category = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $this->add_sessions($course, self::coming_weeks());
        $plain = $this->getDataGenerator()->create_course(['category' => $category->id]);

        $cards = [];
        foreach ((new \theme_ismos\local\catalogue())->export()['sections'] as $level) {
            foreach ($level['cards'] as $card) {
                $cards[$card['url']] = $card['classes'];
            }
        }

        $this->assertSame('Tue & Thu · 17:00 (UTC-3)', $cards[\theme_ismos\local\card::course_url((int) $course->id)]['text']);
        $this->assertNull($cards[\theme_ismos\local\card::course_url((int) $plain->id)]);
    }
}
