<?php
namespace {
// Integration body injected into the existing isolated framework stubs by run_links_seam_remote.py.
// Uses the REAL scheduled task, reader, runner and publisher, but no Moodle bootstrap or Graph.
$task = new \local_teamscsv\task\discover_links();
$task->execute();
check($DB->writes === 0 && $GLOBALS['created'] === [], 'Disabled seam wrote state');
$team = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
$channel = '19:general@thread.tacv2';
$base = '/teams/' . $team . '/channels/' . rawurlencode($channel);
$join = 'https://teams.microsoft.com/l/meetup-join/' . rawurlencode($channel) . '/123?context=fixture';
$folderurl = 'https://fixture.sharepoint.com/sites/course/General';
$recordings = $folderurl . '/Recordings';
$DB->rows['course'] = [(object)['id' => 6, 'fullname' => 'Test course']];
$DB->rows['modules'] = [(object)['id' => 5, 'name' => 'url']];
foreach (['course', 'courseteam'] as $index => $subtype) {
    $DB->rows['local_o365_objects'][] = (object)['id' => $index + 1, 'type' => 'group',
        'subtype' => $subtype, 'moodleid' => 6, 'objectid' => $team];
}
$DB->selection = [(object)['id' => 6, 'teamid' => $team]];
$GLOBALS['config'] = ['autolinks_enabled' => true, 'autolinks_courses' => 1, 'autolinks_requests' => 12];
$GLOBALS['api_calls'] = [];
$api = new class {
    public $pages = [];
    public function apicall($method, $path) {
        check($method === 'get', 'Seam attempted a non-GET Graph call');
        $GLOBALS['api_calls'][] = $path;
        if (!array_key_exists($path, $this->pages)) {
            throw new \RuntimeException('Unexpected fake Graph path');
        }
        return json_encode($this->pages[$path], JSON_THROW_ON_ERROR);
    }
};
$GLOBALS['api'] = $api;
$api->pages = [
    '/teams/' . $team . '/primaryChannel' => ['id' => $channel],
    $base . '/messages?$expand=replies' => ['value' => []],
    $base . '/filesFolder' => ['id' => 'folder', 'webUrl' => $folderurl,
        'parentReference' => ['driveId' => 'drive']],
    '/drives/drive/items/folder/children' => ['value' => []],
];
$task->execute();
check($GLOBALS['created'] === [] && count($DB->rows['local_teamscsv_tablink']) === 1 &&
    $DB->rows['local_teamscsv_tablink'][0]->kind === 'material' &&
    $DB->rows['local_teamscsv_tablink'][0]->url === $folderurl && count($GLOBALS['api_calls']) === 4 &&
    $DB->rows['local_teamscsv_scan'][0]->status === 'pending',
    'Missing resources did not remain pending');
echo "PASS actual seam: resources initially absent\n";
$api->pages[$base . '/messages?$expand=replies']['value'] = [[
    'id' => 'meeting', 'body' => ['content' => '<a href="' . $join . '">Join</a>'],
    'replies' => [['id' => 'reply', 'body' => ['content' => $join]]],
]];
// A temporarily unprovisioned filesFolder must not suppress the independently found meeting.
$savedfolder = $api->pages[$base . '/filesFolder'];
$api->pages[$base . '/filesFolder'] = ['error' => ['code' => 'itemNotFound']];
$task->execute();
check($GLOBALS['created'] === [] && count($DB->rows['local_teamscsv_tablink']) === 2 &&
    $DB->rows['local_teamscsv_tablink'][0]->url === $folderurl &&
    $DB->rows['local_teamscsv_tablink'][1]->url === $join &&
    $DB->rows['local_teamscsv_scan'][0]->status === 'pending',
    'Folder itemNotFound suppressed meeting tab or became an error through native adapter');
echo "PASS actual seam: folder pending while meeting exists\n";
$api->pages[$base . '/filesFolder'] = $savedfolder;
$api->pages['/drives/drive/items/folder/children']['value'] = [[
    'id' => 'recordings', 'name' => 'Recordings', 'folder' => [], 'webUrl' => $recordings,
]];
$task->execute();
check($GLOBALS['created'] === [] && $DB->rows['local_teamscsv_link'] === [] &&
    count($DB->rows['local_teamscsv_tablink']) === 3,
    'Later-created resources not published to tabs exactly once');
check($DB->rows['local_teamscsv_tablink'][0]->url === $folderurl &&
    $DB->rows['local_teamscsv_tablink'][1]->url === $join &&
    $DB->rows['local_teamscsv_tablink'][2]->url === $recordings,
    'Tab URLs do not match real reader candidates');
check($DB->rows['local_teamscsv_scan'][0]->scan_cursor === null, 'Completed seam left a cursor');
echo "PASS actual seam: later recordings published\n";
$task->execute();
check($GLOBALS['created'] === [] && count($DB->rows['local_teamscsv_tablink']) === 3,
    'Repeat task created duplicate tab resources');
echo "PASS actual seam: repeat cycle deduplicates\n";
// Rejected material must not abort the public runner/publisher pipeline or consume replay state.
foreach (['General%20%25', 'General/%2e%2e/Other'] as $suffix) {
    $rejected = 'https://fixture.sharepoint.com/sites/course/' . $suffix;
    $oldrows = serialize($DB->rows['local_teamscsv_tablink']);
    $oldcount = count($DB->rows['local_teamscsv_tablink']);
    $writes = $DB->writes;
    $calls = count($GLOBALS['api_calls']);
    $logcount = count($GLOBALS['logs']);
    $newjoin = $join . '&case=' . rawurlencode($suffix);
    $newrecording = $rejected . '/Recordings';
    $api->pages[$base . '/messages?$expand=replies'] = ['value' => [[
        'id' => 'newmeeting', 'body' => ['content' => $newjoin],
    ]]];
    $api->pages[$base . '/filesFolder']['webUrl'] = $rejected;
    $api->pages['/drives/drive/items/folder/children']['value'][0]['webUrl'] = $newrecording;
    $results = [];
    $reader = new \local_teamscsv\link_discovery(function($path) use ($api) {
        return json_decode($api->apicall('get', $path), true);
    });
    $publisher = new \local_teamscsv\link_publisher();
    $runner = new \local_teamscsv\link_runner(function($teamid, $cursor, $budget) use ($reader, &$results) {
        $results[] = $reader->discover($teamid, $cursor, $budget);
        return end($results);
    }, [$publisher, 'publish']);
    $runner->run(1, 3);
    check($DB->rows['local_teamscsv_scan'][0]->status === 'progress', 'Rejected material aborted runner');
    check($results[0]['warnings'] === ['Material folder link is unavailable or unsafe'], 'Unsafe warning mismatch');
    check($results[0]['requests'] === 3 && !$results[0]['complete'] &&
        $results[0]['candidates'] === [['kind' => 'meeting', 'url' => $newjoin, 'sourceid' => 'newmeeting']],
        'Rejected material changed meeting candidates or budget');
    $cursor = json_decode($DB->rows['local_teamscsv_scan'][0]->scan_cursor, true);
    check($cursor === ['stage' => 'children', 'team' => $team, 'seen' => [
        hash('sha256', "meeting\0" . $newjoin) => true], 'queue' => [], 'channel' => $channel,
        'folderurl' => $rejected, 'childpath' => '/drives/drive/items/folder/children'],
        'Rejected material changed cursor or replay cache');
    $runner->run(1, 1);
    check($results[1] === ['candidates' => [['kind' => 'recordings', 'url' => $newrecording,
        'sourceid' => 'recordings']], 'cursor' => null, 'complete' => true, 'requests' => 1, 'warnings' => []],
        'Recording resume changed');
    check(count($GLOBALS['api_calls']) === $calls + 4 && $DB->writes === $writes + 4 &&
        array_slice($GLOBALS['api_calls'], $calls) === array_keys($api->pages), 'Calls or writes changed');
    check(serialize(array_slice($DB->rows['local_teamscsv_tablink'], 0, $oldcount)) === $oldrows &&
        count($DB->rows['local_teamscsv_tablink']) === $oldcount + 2 &&
        $DB->rows['local_teamscsv_tablink'][$oldcount]->url === $newjoin &&
        $DB->rows['local_teamscsv_tablink'][$oldcount]->kind === 'meeting' &&
        $DB->rows['local_teamscsv_tablink'][$oldcount + 1]->url === $newrecording &&
        $DB->rows['local_teamscsv_tablink'][$oldcount + 1]->kind === 'recordings',
        'Old links changed or rejected material stored');
    check($DB->rows['local_teamscsv_scan'][0]->scan_cursor === null &&
        $DB->rows['local_teamscsv_scan'][0]->status === 'pending' &&
        $DB->rows['local_teamscsv_scan'][0]->message === null &&
        array_slice($GLOBALS['logs'], $logcount) === ['Teams links: one course checked; status=progress.',
            'Teams links: one course checked; status=pending.'] && $GLOBALS['created'] === [] &&
        $DB->rows['local_teamscsv_link'] === [], 'Completion or safe output changed');
    echo "PASS actual seam: rejected material preserves pipeline $suffix\n";
}
$GLOBALS['config']['autolinks_enabled'] = false;
$before = [$DB->writes, count($GLOBALS['api_calls']), count($GLOBALS['created'])];
$task->execute();
check($before === [$DB->writes, count($GLOBALS['api_calls']), count($GLOBALS['created'])],
    'Disabled after enabled made a Graph call or write');
echo "PASS actual seam: disabled performs no calls or writes\n";
// No message bodies, URLs or credentials may reach task logs.
check(strpos(implode(' ', $GLOBALS['logs']), $join) === false, 'Task logs leaked candidate URL');
echo "PASS actual seam: logs do not expose links\n";
}
