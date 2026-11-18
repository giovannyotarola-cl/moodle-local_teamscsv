<?php
namespace {
// Injected after the isolated publication framework; never bootstraps a live Moodle installation.
$DB = new fake_db();
$team = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
$meeting = 'https://teams.microsoft.com/l/meetup-join/19%3Achannel/123?context=fixture';
$recordings = 'https://site.sharepoint.com/sites/course/Recordings';
$manual = 'https://teams.microsoft.com/l/meetup-join/manual/123';
$legacy = 'https://teams.microsoft.com/l/meetup-join/legacy/123';
$tombstone = 'https://site.sharepoint.com/sites/course/Grabaciones';
$DB->rows['course'] = [(object)['id' => 6, 'fullname' => 'Test course']];
$DB->rows['modules'] = [(object)['id' => 5, 'name' => 'url']];
$DB->rows['url'] = [(object)['id' => 15, 'externalurl' => $manual]];
$DB->rows['course_modules'] = [(object)['id' => 18, 'course' => 6, 'module' => 5, 'instance' => 15]];
$DB->rows['local_teamscsv_link'] = [
    (object)['id' => 30, 'courseid' => 6, 'teamid' => $team, 'kind' => 'meeting',
        'urlhash' => hash('sha256', $legacy), 'url' => $legacy, 'cmid' => 406, 'timecreated' => 100],
    (object)['id' => 31, 'courseid' => 6, 'teamid' => $team, 'kind' => 'recordings',
        'urlhash' => hash('sha256', $tombstone), 'url' => $tombstone, 'cmid' => 407, 'timecreated' => 101],
];
$pub = new \local_teamscsv\link_publisher();
function snapshot_tabs() {
    global $DB;
    return [serialize($DB->rows), $DB->writes, serialize($GLOBALS['created'])];
}
foreach (['meeting' => $meeting, 'recordings' => $recordings] as $kind => $url) {
    check(\local_teamscsv\link_publisher::is_valid_destination($kind, $url) === true,
        'Shared destination API rejected valid ' . $kind);
}
foreach ([
    ['wrong', $meeting], ['meeting', 'http://teams.microsoft.com/l/meetup-join/a/b'],
    ['meeting', 'https://user@teams.microsoft.com/l/meetup-join/a/b'],
    ['meeting', 'https://teams.microsoft.com:443/l/meetup-join/a/b'],
    ['meeting', 'https://teams.microsoft.com/l/meetup-join/a/b#fragment'],
    ['meeting', 'https://teams.microsoft.com/meet/a'],
    ['meeting', 'https://evil.microsoft.com/l/meetup-join/a/b'],
    ['recordings', 'https://site.sharepoint.com/'],
    ['recordings', 'https://site.sharepoint.com/sites/course/Other'],
    ['recordings', 'https://user@site.sharepoint.com/sites/course/Recordings'],
    ['recordings', 'https://site.sharepoint.com:443/sites/course/Recordings'],
    ['recordings', 'https://site.sharepoint.com/sites/course/Recordings#fragment'],
    ['recordings', 'https://evilsharepoint.com/sites/course/Recordings'],
] as [$kind, $url]) {
    check(\local_teamscsv\link_publisher::is_valid_destination($kind, $url) === false,
        'Shared destination API accepted unsupported destination: ' . $kind . ' ' . $url);
    $before = snapshot_tabs();
    try {
        $pub->publish(6, $team, ['kind' => $kind, 'url' => $url]);
        throw new \Exception('Unsupported candidate accepted');
    } catch (\InvalidArgumentException $e) {
        check($e->getMessage() === 'Invalid publication candidate.', 'Invalid candidate message changed');
    }
    check(snapshot_tabs() === $before, 'Rejected candidate altered stored rows or module counters');
}
echo "PASS destination API and rejection atomicity\n";
// Valid pre-existing legacy associations, including removed/edited URL modules, are permanent tombstones.
foreach ([['meeting', $legacy], ['recordings', $tombstone], ['meeting', $manual]] as [$kind, $url]) {
    $before = snapshot_tabs();
    $pub->publish(6, $team, ['kind' => $kind, 'url' => $url]);
    check(snapshot_tabs() === $before, 'Legacy/tombstone/manual URL was adopted or modified');
}
echo "PASS existing module ownership and manual links preserved\n";
foreach ([['meeting', $meeting], ['recordings', $recordings]] as [$kind, $url]) {
    $legacybefore = serialize($DB->rows['local_teamscsv_link']);
    $modulesbefore = serialize([$DB->rows['url'], $DB->rows['course_modules'], $GLOBALS['created']]);
    $pub->publish(6, $team, ['kind' => $kind, 'url' => $url]);
    check(count($DB->rows['local_teamscsv_tablink']) === ($kind === 'meeting' ? 1 : 2),
        'Future link did not go to dedicated tab table');
    $row = $DB->rows['local_teamscsv_tablink'][count($DB->rows['local_teamscsv_tablink']) - 1];
    check($row->courseid === 6 && $row->teamid === $team && $row->kind === $kind &&
        $row->url === $url && $row->urlhash === hash('sha256', $url) &&
        is_int($row->timecreated) && $row->timecreated > 0 && !property_exists($row, 'cmid'),
        'Dedicated tab row has wrong identity, URL, timestamp or legacy cmid');
    check(serialize($DB->rows['local_teamscsv_link']) === $legacybefore &&
        serialize([$DB->rows['url'], $DB->rows['course_modules'], $GLOBALS['created']]) === $modulesbefore,
        'New publication modified old module/ownership rows');
    $before = snapshot_tabs();
    $pub->publish(6, $team, ['kind' => $kind, 'url' => $url]);
    check(snapshot_tabs() === $before, 'Retry inserted a duplicate tab or changed existing data');
}
echo "PASS future-only publication and idempotency\n";
// Tabs no longer depend on installing the URL activity module.
$DB->rows['modules'] = [];
$withoutmodule = 'https://teams.microsoft.com/l/meetup-join/another/123';
$legacybefore = serialize($DB->rows['local_teamscsv_link']);
$modulesbefore = serialize([$DB->rows['url'], $DB->rows['course_modules'], $GLOBALS['created']]);
$pub->publish(6, $team, ['kind' => 'meeting', 'url' => $withoutmodule]);
check(count($DB->rows['local_teamscsv_tablink']) === 3 &&
    $DB->rows['local_teamscsv_tablink'][2]->url === $withoutmodule &&
    serialize($DB->rows['local_teamscsv_link']) === $legacybefore &&
    serialize([$DB->rows['url'], $DB->rows['course_modules'], $GLOBALS['created']]) === $modulesbefore,
    'Tab storage improperly requires URL module or modified legacy resources');
echo "PASS direct tab storage without URL module\n";
// Material reuses append-only storage, with strict SharePoint folder validation.
$material = 'https://site.sharepoint.com/sites/course/Shared%20Documents/General';
check(\local_teamscsv\link_publisher::is_valid_destination('material', $material), 'Material rejected');
$history = serialize($DB->rows);
$pub->publish(6, $team, ['kind' => 'material', 'url' => $material]);
check(count($DB->rows['local_teamscsv_tablink']) === 4 &&
    $DB->rows['local_teamscsv_tablink'][3]->url === $material &&
    $DB->rows['local_teamscsv_tablink'][3]->kind === 'material', 'Material not appended');
$before = snapshot_tabs();
$pub->publish(6, $team, ['kind' => 'material', 'url' => $material]);
check(snapshot_tabs() === $before, 'Material replay changed history');
foreach (['http://site.sharepoint.com/sites/course/General', 'https://evilsharepoint.com/sites/course/General',
    'https://sharepoint.com/sites/course/General', 'https://site.sharepoint.com/',
    'https://user@site.sharepoint.com/sites/course/General', 'https://site.sharepoint.com:443/sites/course/General',
    $material . '#fragment', $material . '?redirect=other', $material . '/%2e%2e/Other',
    $material . '/x%2fy', $material . '/%252e%252e', $material . '/%5c', $material . '/%00'] as $url) {
    $before = snapshot_tabs(); ob_start(); $message = null;
    try { $pub->publish(6, $team, ['kind' => 'material', 'url' => $url]); }
    catch (\InvalidArgumentException $e) { $message = $e->getMessage(); }
    $output = ob_get_clean();
    check($message === 'Invalid publication candidate.' && $output === '' && snapshot_tabs() === $before,
        'Unsafe material changed data/counters/output: ' . $url);
}
// New material validation must not broaden the existing recordings kind.
check(!\local_teamscsv\link_publisher::is_valid_destination('recordings', $material), 'Recordings broadened');
$pub->publish(6, $team, ['kind' => 'material', 'url' => $material . '2']);
check(count($DB->rows['local_teamscsv_tablink']) === 5 &&
    $DB->rows['local_teamscsv_tablink'][3]->url === $material, 'New folder replaced material history');
echo "PASS material validation, append-only history and replay\n";
}
