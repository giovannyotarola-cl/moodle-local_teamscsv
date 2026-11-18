<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_teamscsv;

defined('MOODLE_INTERNAL') || die();

/** Optional isolated Moodle PHPUnit coverage; never run against a production database. */
final class link_publication_test extends \advanced_testcase {
    public function test_disabled_task_has_no_scan_or_link_effects(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('autolinks_enabled', 0, 'local_teamscsv');
        $before = [$DB->count_records('local_teamscsv_scan'), $DB->count_records('local_teamscsv_link'),
            $DB->count_records('course_modules')];
        (new \local_teamscsv\task\discover_links())->execute();
        $this->assertSame($before, [$DB->count_records('local_teamscsv_scan'),
            $DB->count_records('local_teamscsv_link'), $DB->count_records('course_modules')]);
    }

    public function test_invalid_candidate_cannot_create_resource(): void {
        global $DB;
        $this->resetAfterTest();
        $before = [$DB->count_records('local_teamscsv_link'), $DB->count_records('course_modules')];
        try {
            (new link_publisher())->publish(2, 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                ['kind' => 'unknown', 'url' => 'https://example.org', 'sourceid' => 'x']);
            $this->fail('Invalid candidate accepted.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Invalid publication candidate.', $e->getMessage());
        }
        $this->assertSame($before, [$DB->count_records('local_teamscsv_link'), $DB->count_records('course_modules')]);
    }
}
