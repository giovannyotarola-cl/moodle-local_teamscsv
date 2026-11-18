<?php
// Isolated public-interface checks: this script never bootstraps Moodle.
namespace core\task { class scheduled_task { public function get_name() { return ''; } } }
namespace core\lock {
    class fake_lock { public function release() {} }
    class fake_factory { public function get_lock($key, $timeout) { return new fake_lock(); } }
    class lock_config { public static function get_lock_factory($key) { return new fake_factory(); } }
}
namespace local_o365 {
    class fake_api {
        public $body = '{"value":[]}';
        public function apicall($method, $path) {
            $GLOBALS['api_calls'][] = [$method, $path];
            return $this->body;
        }
    }
    class utils { public static function get_api() { return $GLOBALS['api']; } }
}
namespace local_teamscsv {
    class link_discovery {
        private $get;
        public function __construct(callable $get) { $this->get = $get; }
        public function discover(string $teamid, ?array $cursor = null, int $budget = 12): array {
            ($this->get)('/teams/' . $teamid . '/primaryChannel');
            return ['candidates' => [], 'cursor' => ['next' => 'page2'], 'complete' => false,
                'requests' => 1, 'warnings' => []];
        }
    }
}
namespace {
define('MOODLE_INTERNAL', true);
define('SITEID', 1);
$GLOBALS['config'] = ['autolinks_enabled' => false];
$GLOBALS['created'] = [];
$GLOBALS['logs'] = [];
function get_config($component, $key) { return $component === 'local_o365' ? 2025040835 : ($GLOBALS['config'][$key] ?? false); }
function get_string($key, $component) { return $key; }
function mtrace($text) { $GLOBALS['logs'][] = $text; }
function add_moduleinfo($info, $course) {
    $GLOBALS['created'][] = $info;
    return (object)['coursemodule' => 100 + count($GLOBALS['created'])];
}
class fake_transaction { public function allow_commit() {} public function rollback($e) { throw $e; } }
class fake_db {
    public $rows = ['course' => [], 'local_o365_objects' => [], 'local_teamscsv_scan' => [],
        'local_teamscsv_link' => [], 'local_teamscsv_tablink' => [], 'url' => [], 'course_modules' => []];
    public $writes = 0;
    public $selection = [];
    public $selectioncalls = [];
    public $unboundedmappings = 0;
    public function get_records_sql($sql, $params, $from, $limit) {
        $this->selectioncalls[] = [$sql, $params, $from, $limit];
        return array_slice($this->selection, $from, $limit);
    }
    public function get_records($table, $conditions = null, $sort = '', $fields = '*', $limitfrom = 0, $limitnum = 0) {
        if ($table === 'local_o365_objects' && ($conditions['subtype'] ?? '') === 'courseteam') {
            $this->unboundedmappings++;
        }
        $rows = array_values(array_filter($this->rows[$table] ?? [], function($row) use ($conditions) {
            foreach ($conditions ?? [] as $k => $value) { if (($row->$k ?? null) != $value) { return false; } }
            return true;
        }));
        return $limitnum ? array_slice($rows, $limitfrom, $limitnum) : $rows;
    }
    public function get_record($table, $conditions) { return $this->get_records($table, $conditions)[0] ?? false; }
    public function record_exists($table, $conditions) { return (bool)$this->get_record($table, $conditions); }
    public function insert_record($table, $row) {
        if ($table === 'local_teamscsv_scan' && property_exists($row, 'cursor')) {
            throw new \RuntimeException('MySQL reserved identifier CURSOR in unquoted INSERT');
        }
        $this->writes++; $row->id = $this->writes; $this->rows[$table][] = clone $row; return $row->id;
    }
    public function update_record($table, $row) {
        if ($table === 'local_teamscsv_scan' && property_exists($row, 'cursor')) {
            throw new \RuntimeException('MySQL reserved identifier CURSOR in unquoted UPDATE');
        }
        $this->writes++;
        foreach ($this->rows[$table] as &$existing) { if ($existing->id == $row->id) { $existing = clone $row; return; } }
        throw new \RuntimeException('Missing state');
    }
    public function start_delegated_transaction() { return new fake_transaction(); }
}
$DB = new fake_db();
function check($condition, $message) { if (!$condition) { throw new \Exception($message); } }
foreach (['TASK' => '__TASK__', 'RUNNER' => '__RUNNER__', 'PUBLISHER' => '__PUBLISHER__'] as $name => $encoded) {
    if (!$encoded) { throw new \Exception('Missing ' . $name); }
    eval('?>' . base64_decode($encoded));
}
$task = new \local_teamscsv\task\discover_links();
$task->execute();
check($DB->writes === 0 && $GLOBALS['created'] === [] && $GLOBALS['logs'] === [], 'Disabled task changed state or logged');
echo "disabled: OK\n";
$DB->rows['course'] = [(object)['id' => 6, 'fullname' => 'A'], (object)['id' => 7, 'fullname' => 'B']];
$team = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
foreach ([6, 7] as $id) {
    foreach (['group', 'group'] as $i => $type) {
        $DB->rows['local_o365_objects'][] = (object)['id' => $id * 2 + $i, 'type' => $type,
            'subtype' => $i ? 'courseteam' : 'course', 'moodleid' => $id, 'objectid' => $team];
    }
}
$DB->selection = [(object)['id' => 6, 'teamid' => $team, 'scanid' => null, 'cursor' => null, 'lastchecked' => null]];
$seen = [];
$reader = function($teamid, $cursor, $budget) use (&$seen) {
    $seen[] = [$teamid, $cursor, $budget];
    return ['candidates' => [['kind' => 'meeting', 'url' => 'https://teams.microsoft.com/l/meetup-join/a/b',
        'sourceid' => 'root']], 'cursor' => ['next' => 'page2'], 'complete' => false,
        'requests' => 2, 'warnings' => []];
};
$published = [];
$publish = function($courseid, $teamid, $candidate) use (&$published) { $published[] = $courseid; };
$runner = new \local_teamscsv\link_runner($reader, $publish);
$runner->run(1, 12);
check($published === [6] && count($seen) === 1 && $seen[0][2] === 12, 'Bound or discovery failed');
check($DB->unboundedmappings === 0 && count($DB->selectioncalls) === 1 &&
    $DB->selectioncalls[0][3] === 1 && strpos($DB->selectioncalls[0][0], 'lastchecked') !== false &&
    strpos($DB->selectioncalls[0][0], 'HAVING COUNT(*) = 2') !== false &&
    strpos($DB->selectioncalls[0][0], 'JOIN {course}') !== false,
    'Unbounded or unfair mapping selection');
check(($DB->rows['local_teamscsv_scan'][0]->status ?? '') === 'progress', 'Cursor progress not saved');
$previous = $DB->rows['local_teamscsv_scan'][0]->scan_cursor;
$DB->selection = [(object)['id' => 6, 'teamid' => $team, 'scanid' => $DB->rows['local_teamscsv_scan'][0]->id,
    'cursor' => $previous, 'lastchecked' => $DB->rows['local_teamscsv_scan'][0]->lastchecked]];
$DB->rows['course'] = [(object)['id' => 6, 'fullname' => 'A']];
$fail = new \local_teamscsv\link_runner($reader, function() { throw new \RuntimeException('secret URL'); });
$fail->run(1, 12);
check($DB->rows['local_teamscsv_scan'][0]->scan_cursor === $previous, 'Failure advanced cursor');
check($DB->rows['local_teamscsv_scan'][0]->status === 'error' &&
    strpos($DB->rows['local_teamscsv_scan'][0]->message, 'secret') === false, 'Error leaked details');
// A malformed reader response must not publish, and must preserve the prior cursor.
foreach ([['candidates' => [['kind' => 'meeting', 'url' => 'https://teams.microsoft.com/l/meetup-join/a/b']],
        'cursor' => null, 'complete' => false, 'requests' => 1, 'warnings' => []],
    ['candidates' => [], 'cursor' => null, 'complete' => true, 'requests' => 13, 'warnings' => []]] as $invalid) {
    $publishedbefore = count($published);
    (new \local_teamscsv\link_runner(function() use ($invalid) { return $invalid; }, $publish))->run(1, 12);
    check(count($published) === $publishedbefore && $DB->rows['local_teamscsv_scan'][0]->scan_cursor === $previous &&
        $DB->rows['local_teamscsv_scan'][0]->status === 'error', 'Malformed reader advanced or published');
}
$DB->rows['local_teamscsv_scan'][0]->scan_cursor = '{invalid json';
$seenbefore = count($seen);
$runner->run(1, 12);
check(count($seen) === $seenbefore && $DB->rows['local_teamscsv_scan'][0]->status === 'error' &&
    $DB->rows['local_teamscsv_scan'][0]->scan_cursor === '{invalid json', 'Malformed stored cursor reset');
echo "runner: OK\n";
// Enabled task exercises native JSON-string transport through the public scheduled task.
$DB->selection[0]->scan_cursor = null;
$DB->rows['local_teamscsv_scan'][0]->scan_cursor = null;
$GLOBALS['config']['autolinks_enabled'] = true;
$GLOBALS['api'] = new \local_o365\fake_api();
$GLOBALS['api_calls'] = [];
$task->execute();
check(count($GLOBALS['api_calls']) === 1 && $GLOBALS['api_calls'][0][0] === 'get' &&
    $DB->rows['local_teamscsv_scan'][0]->status === 'progress', 'JSON-string GET did not progress');
$progress = $DB->rows['local_teamscsv_scan'][0]->scan_cursor;
$DB->selection[0]->scan_cursor = $progress;
$GLOBALS['api']->body = '{bad JSON';
$task->execute();
check($DB->rows['local_teamscsv_scan'][0]->status === 'error' &&
    $DB->rows['local_teamscsv_scan'][0]->scan_cursor === $progress, 'Invalid JSON advanced cursor');
$GLOBALS['api']->body = '{"error":{"code":"Forbidden","message":"private"}}';
$task->execute();
check($DB->rows['local_teamscsv_scan'][0]->status === 'error' &&
    $DB->rows['local_teamscsv_scan'][0]->scan_cursor === $progress &&
    strpos(implode(' ', $GLOBALS['logs']), 'private') === false, 'Graph error leaked or advanced');
$GLOBALS['config']['autolinks_enabled'] = false;
echo "transport: OK\n";
// Publisher must not claim an existing manual URL and must reject unknown candidate types.
$pub = new \local_teamscsv\link_publisher();
$before = [$DB->writes, count($GLOBALS['created'])];
try { $pub->publish(6, $team, ['kind' => 'wrong', 'url' => 'https://example.org/', 'sourceid' => 'x']);
    throw new \Exception('Accepted invalid type');
} catch (\InvalidArgumentException $e) {}
check([$DB->writes, count($GLOBALS['created'])] === $before, 'Invalid candidate modified state');
foreach (['https://user@teams.microsoft.com/l/meetup-join/a/b',
    'https://teams.microsoft.com:443/l/meetup-join/a/b',
    'https://teams.microsoft.com/l/meetup-join/a/b#fragment',
    'https://teams.microsoft.com/meet/a',
    'https://other.microsoft.com/l/meetup-join/a/b'] as $unsafe) {
    try { $pub->publish(6, $team, ['kind' => 'meeting', 'url' => $unsafe, 'sourceid' => 'root']);
        throw new \Exception('Accepted unsafe meeting URL');
    } catch (\InvalidArgumentException $e) {}
    check([$DB->writes, count($GLOBALS['created'])] === $before, 'Unsafe URL modified state');
}
foreach (['https://user@site.sharepoint.com/sites/course/Recordings',
    'https://site.sharepoint.com:443/sites/course/Recordings',
    'https://site.sharepoint.com/sites/course/Recordings#x',
    'https://site.sharepoint.com/'] as $unsafe) {
    try { $pub->publish(6, $team, ['kind' => 'recordings', 'url' => $unsafe, 'sourceid' => 'folder']);
        throw new \Exception('Accepted unsafe recordings URL');
    } catch (\InvalidArgumentException $e) {}
    check([$DB->writes, count($GLOBALS['created'])] === $before, 'Unsafe URL modified state');
}
$DB->rows['url'][] = (object)['id' => 15, 'externalurl' => 'https://teams.microsoft.com/l/meetup-join/a/b'];
$DB->rows['course_modules'][] = (object)['id' => 18, 'course' => 6, 'module' => 5, 'instance' => 15, 'deletioninprogress' => 0];
$DB->rows['modules'] = [(object)['id' => 5, 'name' => 'url']];
$candidate = ['kind' => 'meeting', 'url' => 'https://teams.microsoft.com/l/meetup-join/a/b', 'sourceid' => 'root'];
$pub->publish(6, $team, $candidate);
check([$DB->writes, count($GLOBALS['created'])] === $before, 'Manual URL was claimed or duplicated');
$DB->rows['url'] = $DB->rows['course_modules'] = [];
$pub->publish(6, $team, $candidate);
check($GLOBALS['created'] === [] && $DB->rows['local_teamscsv_link'] === [] &&
    count($DB->rows['local_teamscsv_tablink']) === 1, 'Meeting was not stored only for tab');
$pub->publish(6, $team, $candidate);
check(count($DB->rows['local_teamscsv_tablink']) === 1 && $GLOBALS['created'] === [],
    'Meeting tab retry duplicated or created module');
$pub->publish(6, $team, ['kind' => 'recordings',
    'url' => 'https://site.sharepoint.com/sites/course/Recordings', 'sourceid' => 'folder']);
check(count($DB->rows['local_teamscsv_tablink']) === 2 && $DB->rows['local_teamscsv_link'] === [] &&
    $GLOBALS['created'] === [] &&
    $DB->rows['local_teamscsv_tablink'][1]->url === 'https://site.sharepoint.com/sites/course/Recordings',
    'Recordings were not stored only for tab');
echo "publisher: OK\n";
}
