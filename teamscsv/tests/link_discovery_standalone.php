<?php
// Isolated public interface fixture, transported via stdin; no Moodle bootstrap.
define('MOODLE_INTERNAL', true);
// Explicit dependency: discovery reuses publication's strict material destination validator.
eval('?>' . base64_decode('__PUBLISHER_SOURCE_BASE64__', true));
eval('?>' . base64_decode('__READER_SOURCE_BASE64__', true));
function check($name, $expected, $actual): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL $name expected " . var_export($expected, true) . ' got ' . var_export($actual, true) . "\n");
        exit(1);
    }
    echo "PASS $name\n";
}
function fixture(array $pages, array &$calls): \local_teamscsv\link_discovery {
    return new \local_teamscsv\link_discovery(function(string $path) use ($pages, &$calls): array {
        $calls[] = $path;
        if (!array_key_exists($path, $pages)) {
            throw new RuntimeException('Unexpected GET: ' . $path);
        }
        return $pages[$path];
    });
}
$team = 'team'; $channel = '19:general@thread.tacv2';
$base = '/teams/team/channels/19%3Ageneral%40thread.tacv2';
$join = 'https://teams.microsoft.com/l/meetup-join/19%3Ageneral%40thread.tacv2/0?context=x';
$foreign = 'https://teams.microsoft.com/l/meetup-join/19%3Aforeign%40thread.tacv2/0';
$folderurl = 'https://tenant.sharepoint.com/sites/course/Shared%20Documents/General';
$recordingurl = $folderurl . '/Recordings';
$pages = [
    '/teams/team/primaryChannel' => ['id' => $channel],
    $base . '/messages?$expand=replies' => ['value' => [[
        'id' => 'm1', 'body' => ['content' => '<a href="' . $join . '">Join</a>'],
        'replies' => [['id' => 'r1', 'body' => ['content' => $join]]],
        'replies@odata.nextLink' => 'https://graph.microsoft.com/v1.0' . $base . '/messages/m1/replies?$skiptoken=reply',
    ]], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0' . $base . '/messages?$skiptoken=root'],
    $base . '/messages/m1/replies?$skiptoken=reply' => ['value' => [[
        'id' => 'r2', 'attachments' => [['content' => json_encode(['joinUrl' => $join])]],
    ]]],
    $base . '/messages?$skiptoken=root' => ['value' => [[
        'id' => 'm2', 'body' => ['content' => $foreign . ' ' . $join],
    ]]],
    $base . '/filesFolder' => ['id' => 'folder', 'webUrl' => $folderurl,
        'parentReference' => ['driveId' => 'drive']],
    '/drives/drive/items/folder/children' => ['value' => [[
        'id' => 'rec', 'name' => 'Recordings', 'folder' => ['childCount' => 0],
        'webUrl' => $recordingurl,
    ]]],
];
$calls = []; $reader = fixture($pages, $calls); $cursor = null; $all = [];
$expectedpaths = array_keys($pages);
foreach ([1, 1, 1, 1, 1, 1] as $index => $budget) {
    $result = $reader->discover($team, $cursor, $budget);
    check('bounded requests ' . $index, 1, $result['requests']);
    $all = array_merge($all, $result['candidates']);
    $cursor = $result['cursor'];
    check('resume pending ' . $index, $index < 5, !$result['complete']);
}
check('ordered GETs', $expectedpaths, $calls);
check('cursor complete', null, $cursor);
check('unique pilot links', [
    ['kind' => 'meeting', 'url' => $join, 'sourceid' => 'm1'],
    ['kind' => 'material', 'url' => $folderurl, 'sourceid' => 'folder'],
    ['kind' => 'recordings', 'url' => $recordingurl, 'sourceid' => 'rec'],
], $all);
check('cycle starts anew', '/teams/team/primaryChannel', (function() use ($reader, &$calls) {
    $reader->discover('team', null, 1); return end($calls);
})());
// A rejected Graph nextLink must not make a GET or return a completed scan.
foreach (['https://evil.example/v1.0' . $base . '/messages',
    'https://graph.microsoft.com@evil.example/v1.0' . $base . '/messages',
    'https://graph.microsoft.com/v1.0/other/messages',
    'https://graph.microsoft.com/v1.0' . $base . '/messages#fragment'] as $bad) {
    $invalid = $pages;
    $invalid[$base . '/messages?$expand=replies']['@odata.nextLink'] = $bad;
    $calls = []; $reader = fixture($invalid, $calls);
    try {
        $reader->discover('team', null, 4);
        fwrite(STDERR, "FAIL unsafe nextLink accepted\n"); exit(1);
    } catch (RuntimeException $e) {
        check('unsafe link GETs', ['/teams/team/primaryChannel', $base . '/messages?$expand=replies'], $calls);
    }
}
// Errors are thrown, never converted to success or advanced cursors.
$calls = []; $reader = fixture(['/teams/team/primaryChannel' => ['error' => ['code' => 'Denied']]], $calls);
try { $reader->discover('team'); fwrite(STDERR, "FAIL error accepted\n"); exit(1); }
catch (RuntimeException $e) { check('error GET count', ['/teams/team/primaryChannel'], $calls); }
// Missing messages/folder items are pending, not fabricated links.
$pending = $pages;
$pending[$base . '/messages?$expand=replies'] = ['value' => []];
$pending[$base . '/filesFolder'] = ['error' => ['code' => 'itemNotFound']];
$calls = []; $reader = fixture($pending, $calls);
$result = $reader->discover('team', null, 5);
check('pending has no candidates', [], $result['candidates']);
check('pending warnings exist', true, count($result['warnings']) > 0);
check('pending no child GET', ['/teams/team/primaryChannel', $base . '/messages?$expand=replies', $base . '/filesFolder'], $calls);
// Pilot-shaped anonymous URL: percent-encoded channel thread, lowercase escapes, epoch and JSON context.
$pilot = 'https://teams.microsoft.com/l/meetup-join/19%3ageneral%40thread.tacv2/1712345678901?context=%7b%22Tid%22%3a%22demo%22%7d';
$pilotpages = $pending;
$pilotpages[$base . '/messages?$expand=replies'] = ['value' => [[
    'id' => 'pilot', 'body' => ['content' => '<a href="' . $pilot . '">Join</a>'],
]]];
$calls = []; $result = fixture($pilotpages, $calls)->discover('team', null, 4);
check('pilot channel identity', [['kind' => 'meeting', 'url' => $pilot, 'sourceid' => 'pilot']], $result['candidates']);
// Pagination must not silently discard unseen URLs past the bounded seen cache.
$many = $pending; $messages = [];
for ($i = 0; $i < 260; $i++) {
    $messages[] = ['id' => 'm' . $i, 'body' => ['content' => $join . '&occurrence=' . $i]];
}
$messages[] = ['id' => 'repeated', 'body' => ['content' => $join . '&occurrence=0']];
$many[$base . '/messages?$expand=replies'] = ['value' => $messages];
$calls = []; $result = fixture($many, $calls)->discover('team', null, 3);
check('260 distinct URLs emitted once', 260, count($result['candidates']));
check('bounded scan GETs', ['/teams/team/primaryChannel', $base . '/messages?$expand=replies', $base . '/filesFolder'], $calls);
// Reject arbitrary paths and cross-team cursors before making any GET.
$validcursor = fixture($pages, $calls)->discover('team', null, 1)['cursor'];
foreach (['next' => '/users/other/messages', 'rootnext' => '/teams/other/channels/x/messages',
    'queue' => ['/users/other/messages'], 'childpath' => '/users/other/children'] as $field => $value) {
    $tampered = $validcursor;
    $tampered['stage'] = in_array($field, ['queue', 'rootnext'], true) ? 'replies' :
        ($field === 'childpath' ? 'children' : 'messages');
    $tampered['queue'] = $field === 'queue' ? $value : [];
    $tampered[$field] = $value;
    $calls = []; $reader = fixture($pages, $calls);
    try { $reader->discover('team', $tampered, 1); fwrite(STDERR, "FAIL tampered $field accepted\n"); exit(1); }
    catch (RuntimeException $e) { check('tampered ' . $field . ' no GET', [], $calls); }
}
$calls = []; $reader = fixture($pages, $calls);
try { $reader->discover('other', $validcursor, 1); fwrite(STDERR, "FAIL cross-team cursor accepted\n"); exit(1); }
catch (RuntimeException $e) { check('cross-team no GET', [], $calls); }
// Child links must be immediate, canonical descendants on the same SharePoint origin.
$badurls = [
    'https://user@tenant.sharepoint.com/sites/course/Shared%20Documents/General/Recordings',
    'https://tenant.sharepoint.com:444/sites/course/Shared%20Documents/General/Recordings',
    $folderurl . '/Recordings#frag', $folderurl . '/%2e%2e/Other/Recordings',
    $folderurl . '/x%2fRecordings', $folderurl . '/\\Recordings',
    $folderurl . '/Other/Recordings',
    'https://other.sharepoint.com/sites/course/Shared%20Documents/General/Recordings',
];
foreach ($badurls as $badurl) {
    $badpages = $pending;
    $badpages[$base . '/filesFolder'] = $pages[$base . '/filesFolder'];
    $badpages['/drives/drive/items/folder/children'] = ['value' => [[
        'id' => 'rec', 'name' => 'Recordings', 'folder' => [], 'webUrl' => $badurl,
    ]]];
    $calls = []; $result = fixture($badpages, $calls)->discover('team', null, 4);
    check('unsafe recording skipped ' . $badurl,
        [['kind' => 'material', 'url' => $folderurl, 'sourceid' => 'folder']], $result['candidates']);
}
// Material is the actual primary-channel folder, even when Graph uses a localized name.
foreach ([$folderurl, 'https://tenant.sharepoint.com/sites/course/Documentos/General%20local'] as $materialurl) {
    $materialpages = $pending;
    $materialpages[$base . '/filesFolder'] = $pages[$base . '/filesFolder'];
    $materialpages[$base . '/filesFolder']['webUrl'] = $materialurl;
    $materialpages['/drives/drive/items/folder/children'] = ['value' => []];
    $calls = []; $reader = fixture($materialpages, $calls);
    $result = $reader->discover('team', null, 3);
    check('material at existing folder boundary', [['kind' => 'material', 'url' => $materialurl,
        'sourceid' => 'folder']], $result['candidates']);
    check('material request budget unchanged', 3, $result['requests']);
    $result = $reader->discover('team', $result['cursor'], 1);
    check('material not replayed on children resume', [], $result['candidates']);
    check('material no extra GET', ['/teams/team/primaryChannel', $base . '/messages?$expand=replies',
        $base . '/filesFolder', '/drives/drive/items/folder/children'], $calls);
}
// Missing response content is malformed; 403/429 must never be reclassified as pending.
foreach ([[], ['error' => ['code' => 'Forbidden']], ['error' => ['code' => 'TooManyRequests']]] as $failure) {
    $badpages = $pending; $badpages[$base . '/filesFolder'] = $failure;
    $calls = [];
    try { fixture($badpages, $calls)->discover('team', null, 4); fwrite(STDERR, "FAIL folder error accepted\n"); exit(1); }
    catch (RuntimeException $e) {
        check('folder error GETs', ['/teams/team/primaryChannel', $base . '/messages?$expand=replies', $base . '/filesFolder'], $calls);
    }
}
