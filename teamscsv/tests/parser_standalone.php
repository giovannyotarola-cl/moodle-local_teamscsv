<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Standalone public-parser checks; no Moodle bootstrap, database, credentials or Graph calls.
if (!defined('MOODLE_INTERNAL')) {
    define('MOODLE_INTERNAL', true);
}
if (!class_exists(\local_teamscsv\importer::class)) {
    require_once(__DIR__ . '/../classes/importer.php');
}

$failures = 0;
function csv_check(bool $ok, string $name): void {
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}
try {
    $rows = \local_teamscsv\importer::parse("\xEF\xBB\xBFcourse_id,team_name\r\n6,\"Equipo, Prueba\"\r\n");
    csv_check($rows === [['course_id' => 6, 'team_name' => 'Equipo, Prueba']], 'Internal numeric ID, BOM, quoted name');
} catch (Throwable $e) {
    csv_check(false, 'Internal numeric ID: ' . $e->getMessage());
}
foreach (['0', '-6', '6.0', '6e0', 'abc', '06', '+6', '9223372036854775808', '٦'] as $id) {
    try {
        \local_teamscsv\importer::parse("course_id,team_name\n" . $id . ",T\n");
        csv_check(false, 'Reject invalid ID ' . $id);
    } catch (InvalidArgumentException $e) {
        csv_check($e->getMessage() === 'Course ID must be a positive integer without leading zeros.', 'Reject invalid ID ' . $id);
    }
}
$cases = [
    ["course_idnumber,team_name\n6,T\n", 'Expected header: course_id,team_name'],
    ["course_id,team_name\n6,T\n6,U\n", 'Duplicate course ID or Team name in CSV.'],
    ["course_id,team_name\n6,T\n7,T\n", 'Duplicate course ID or Team name in CSV.'],
    ["course_id,team_name\n6,\n", 'Each record must contain a course ID and a Team name.'],
    ["course_id,team_name\n6,T,X\n", 'Each record must contain a course ID and a Team name.'],
    ["course_id,team_name\n", 'CSV contains no records.'],
    ["course_id,team_name\n6,\xFF\n", 'CSV must be UTF-8 and no larger than 1 MiB.'],
    [str_repeat('x', 1048577), 'CSV must be UTF-8 and no larger than 1 MiB.'],
];
foreach ($cases as [$content, $expected]) {
    try {
        \local_teamscsv\importer::parse($content);
        csv_check(false, $expected);
    } catch (InvalidArgumentException $e) {
        csv_check($e->getMessage() === $expected, $expected);
    }
}
$csv = "course_id,team_name\n";
for ($i = 1; $i <= 100; $i++) {
    $csv .= "$i,T$i\n";
}
try {
    csv_check(count(\local_teamscsv\importer::parse($csv)) === 100, 'Accept 100 records');
    try {
        \local_teamscsv\importer::parse($csv . "101,T101\n");
        csv_check(false, 'Reject 101 records');
    } catch (InvalidArgumentException $e) {
        csv_check($e->getMessage() === 'Maximum 100 records per import.', 'Reject 101 records');
    }
} catch (Throwable $e) {
    csv_check(false, 'Record limits: ' . $e->getMessage());
}
exit($failures ? 1 : 0);
