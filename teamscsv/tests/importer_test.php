<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_teamscsv;

defined('MOODLE_INTERNAL') || die();

/** Run only in Moodle's isolated PHPUnit database; never on production data. */
final class importer_test extends \advanced_testcase {
    public function test_parse_utf8_bom_and_quoted_names(): void {
        $actual = importer::parse("\xEF\xBB\xBFcourse_id,team_name\r\n6,\"Matemática, 2026\"\r\n");
        $this->assertSame([['course_id' => 6, 'team_name' => 'Matemática, 2026']], $actual);
    }

    public function test_parser_limits_and_rejections_do_not_write(): void {
        global $DB;
        $this->resetAfterTest();
        $before = [$DB->count_records('local_o365_objects'), $DB->count_records('task_adhoc'),
            $DB->count_records('config_plugins')];
        $invalid = [
            ['course_id;team_name\nA;B', 'Expected header: course_id,team_name'],
            ["course_id,team_name\n", 'CSV contains no records.'],
            ["course_id,team_name\n6,\n", 'Each record must contain a course ID and a Team name.'],
            ["course_id,team_name\n6,B,C\n", 'Each record must contain a course ID and a Team name.'],
            ["course_id,team_name\n6,B\n6,C\n", 'Duplicate course ID or Team name in CSV.'],
            ["course_id,team_name\n6,B\n7,B\n", 'Duplicate course ID or Team name in CSV.'],
            ["course_id,team_name\n0,B\n", 'Course ID must be a positive integer without leading zeros.'],
            ["course_id,team_name\n6,\xFF\n", 'CSV must be UTF-8 and no larger than 1 MiB.'],
            [str_repeat('x', importer::MAXBYTES + 1), 'CSV must be UTF-8 and no larger than 1 MiB.'],
        ];
        foreach ($invalid as [$csv, $expected]) {
            try {
                importer::parse($csv);
                $this->fail('Expected CSV rejection.');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame($expected, $e->getMessage());
            }
            $this->assertSame($before, [$DB->count_records('local_o365_objects'),
                $DB->count_records('task_adhoc'), $DB->count_records('config_plugins')]);
        }
        $csv = "course_id,team_name\n";
        for ($i = 1; $i <= 100; $i++) {
            $csv .= "$i,T$i\n";
        }
        $this->assertCount(100, importer::parse($csv));
        try {
            importer::parse($csv . "101,T101\n");
            $this->fail('Expected row-limit rejection.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Maximum 100 records per import.', $e->getMessage());
        }
        $this->assertSame($before, [$DB->count_records('local_o365_objects'),
            $DB->count_records('task_adhoc'), $DB->count_records('config_plugins')]);
    }

    public function test_preview_rejects_unpinned_version_without_changes(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('version', 2025040836, 'local_o365');
        $before = [$DB->count_records('local_o365_objects'), $DB->count_records('task_adhoc'),
            get_config('local_o365', 'coursesynccustom')];
        try {
            importer::preview([['course_id' => 6, 'team_name' => 'B']]);
            $this->fail('Expected version rejection.');
        } catch (\RuntimeException $e) {
            $this->assertSame('This adapter requires local_o365 version 2025040835 exactly.', $e->getMessage());
        }
        $this->assertSame($before, [$DB->count_records('local_o365_objects'),
            $DB->count_records('task_adhoc'), get_config('local_o365', 'coursesynccustom')]);
    }

    public function test_apply_rejects_nonadmin_without_changes(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $before = [$DB->count_records('local_o365_objects'), $DB->count_records('task_adhoc'),
            get_config('local_o365', 'coursesynccustom')];
        try {
            importer::apply([], []);
            $this->fail('Expected permission rejection.');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        $this->assertSame($before, [$DB->count_records('local_o365_objects'),
            $DB->count_records('task_adhoc'), get_config('local_o365', 'coursesynccustom')]);
    }
}
