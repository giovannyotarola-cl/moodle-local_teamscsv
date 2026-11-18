<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Public executor + real snapshot/store/exact0835 factory and transport. Synthetic users only.
// Run in read-only, network-disabled Docker; fixtures exist only in its disposable /scratch tmpfs.
namespace core\context {
    class course { public static function instance($id) { return (object)['id' => $id]; } }
}
namespace core\lock {
    class lock_config {
        public static $factory;
        public static function get_lock_factory($name) {
            \same('local_teamscsv_membership', $name, 'shared storage/executor lock factory');
            return self::$factory;
        }
    }
}
namespace local_o365\oauth2 {
    function time() { return 1000; }
    class clientdata { public static function instance_from_oidc() { return new self(); } }
}
namespace local_o365 {
    class utils {
        public static function is_configured() { return true; }
        public static function debug(...$args) { $GLOBALS['debugs']++; }
        public static function get_application_token($resource, $data, $http) {
            $GLOBALS['factories']++;
            // Same exact0835 non-forcecreate app-token path; actual token/apptoken classes below.
            return \local_o365\oauth2\apptoken::instance(null, $resource, $data, $http);
        }
    }
    function install_httpclient(): void {
        class httpclient implements httpclientinterface {
            public function resetheader() {}
            public function setheader($headers) {}
            public function get($u, $p = [], $o = []) { throw new \RuntimeException('Forbidden legacy HTTP'); }
            public function post($u, $p = '', $o = []) { throw new \RuntimeException('Forbidden OAuth/legacy HTTP'); }
            public function delete($u, $p = [], $o = []) { throw new \RuntimeException('Forbidden legacy HTTP'); }
            public function patch($u, $p = '', $o = []) { throw new \RuntimeException('Forbidden legacy HTTP'); }
        }
    }
}
namespace local_o365\rest {
    function curl_init() { $GLOBALS['inits']++; return new \stdClass(); }
    function curl_setopt_array($handle, $options) { $GLOBALS['options'] = $options; return true; }
    function curl_exec($handle) {
        $options = $GLOBALS['options'];
        \same(15, $options[CURLOPT_TIMEOUT], 'one-attempt deadline');
        \same(false, $options[CURLOPT_FOLLOWLOCATION], 'no redirects');
        \same(0, $GLOBALS['DB']->depth, 'Graph must not run inside a DB transaction');
        \expect(isset(\core\lock\lock_config::$factory->held['course-6']), 'Graph outside storage course lock');
        $path = substr($options[CURLOPT_URL], strlen('https://graph.microsoft.com/v1.0'));
        $verb = $options[CURLOPT_CUSTOMREQUEST];
        $data = isset($options[CURLOPT_POSTFIELDS]) ? json_decode($options[CURLOPT_POSTFIELDS], true) : [];
        $GLOBALS['requests'][] = [$verb, $path, $data];
        if ($GLOBALS['onrequest']) { ($GLOBALS['onrequest'])($verb, $path); }
        $response = \remote_request($verb, $path, $data);
        if ($GLOBALS['responsehook']) { $response = ($GLOBALS['responsehook'])($verb, $path, $response); }
        if ($verb !== 'GET' && $GLOBALS['afterack']) { ($GLOBALS['afterack'])($verb, $path); }
        [$GLOBALS['httpstatus'], $body] = $response;
        $GLOBALS['options'][CURLOPT_WRITEFUNCTION]($handle, $body);
        return true;
    }
    function curl_getinfo($handle, $key) { return $GLOBALS['httpstatus']; }
    function curl_errno($handle) { return 0; }
    function curl_close($handle) { $GLOBALS['closes']++; }
}
namespace {
    define('MOODLE_INTERNAL', true);
    define('SITEID', 1);
    const TEAM = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    const OTHER = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
    foreach (['CURLOPT_URL', 'CURLOPT_CUSTOMREQUEST', 'CURLOPT_HTTPHEADER', 'CURLOPT_RETURNTRANSFER',
            'CURLOPT_HEADER', 'CURLOPT_CONNECTTIMEOUT', 'CURLOPT_TIMEOUT', 'CURLOPT_FOLLOWLOCATION',
            'CURLOPT_MAXREDIRS', 'CURLOPT_PROTOCOLS', 'CURLOPT_REDIR_PROTOCOLS', 'CURLOPT_SSL_VERIFYPEER',
            'CURLOPT_SSL_VERIFYHOST', 'CURLOPT_PROXY', 'CURLOPT_POSTFIELDS', 'CURLOPT_WRITEFUNCTION',
            'CURLINFO_HTTP_CODE', 'CURLPROTO_HTTPS', 'CURLOPT_HTTP_VERSION', 'CURL_HTTP_VERSION_1_1'] as $n => $name) {
        if (!defined($name)) { define($name, $n + 10000); }
    }
    function expect($ok, string $why): void { if (!$ok) { throw new \RuntimeException($why); } }
    function same($expected, $actual, string $why): void {
        expect($expected === $actual, $why . ': ' . var_export($actual, true));
    }
    function uid(int $n): string { return sprintf('00000000-0000-0000-0000-%012d', $n); }
    function get_config($plugin, $key) { return $GLOBALS['config'][$plugin][$key] ?? false; }
    function get_enrolled_users($context, $capability, ...$args) {
        same([0, 'u.*', null, 0, 0, true], $args, 'exhaustive active exact0835 helper');
        return array_map(static fn($id) => $GLOBALS['DB']->get_record('user', ['id' => $id]),
            $GLOBALS['roles'][$capability]);
    }
    class context_course extends \core\context\course {}
    function is_enrolled($context, $userid, $capability, $active) {
        same(true, $active, 'active participation for intent');
        return in_array($userid, array_merge(...array_values($GLOBALS['roles'])), true);
    }

    // Reuse the existing exact-context, SHA256-pinned artifact derivation, not invented guard bytes.
    $seam = file_get_contents(__DIR__ . '/import_seam_standalone.php');
    $start = strpos($seam, '    function artifact_sources(): array {');
    $end = strpos($seam, "\n    \$CFG =", $start);
    expect($start !== false && $end !== false, 'artifact fixture unavailable');
    eval(substr($seam, $start, $end - $start));
    [$original, $patched] = artifact_sources();
    $CFG = (object)['dirroot' => '/scratch', 'libdir' => '/scratch/lib'];
    foreach (['lib/enrollib.php', 'auth/oidc/lib.php', 'local/o365/lib.php'] as $stub) {
        if (!is_dir(dirname('/scratch/' . $stub))) { mkdir(dirname('/scratch/' . $stub), 0700, true); }
        file_put_contents('/scratch/' . $stub, '<?php // Offline bootstrap double.');
    }
    function installed(array $sources): void {
        foreach ($sources as $name => $source) {
            $path = '/scratch/local/o365/classes/' . ($name === 'o365api.php' ? 'rest/' : 'feature/coursesync/') . $name;
            if (!is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
            file_put_contents($path, $source);
        }
    }
    installed($patched);
    $zip = new \PharData('/artifacts/o365.zip');
    foreach (['httpclientinterface.php', 'oauth2/token.php', 'oauth2/apptoken.php'] as $name) {
        eval(substr($zip['moodle-local_o365-5.0.7/classes/' . $name]->getContent(), 5));
    }
    \local_o365\install_httpclient();
    eval(substr($patched['o365api.php'], 5));
    eval(substr($zip['moodle-local_o365-5.0.7/classes/rest/unified.php']->getContent(), 5));
    eval(substr($patched['utils.php'], 5));
    foreach (['course_links', 'membership_store', 'membership_guard', 'membership_plan', 'membership_snapshot'] as $name) {
        require_once __DIR__ . '/../classes/' . $name . '.php';
    }
    if (is_file(__DIR__ . '/../classes/membership_executor.php')) {
        require_once __DIR__ . '/../classes/membership_executor.php';
    }
    use local_teamscsv\membership_executor;
    use local_teamscsv\membership_store;

    class executor_transaction {
        private $before;
        public function __construct(private $db) { $this->before = $db->snapshot(); }
        public function allow_commit() {
            if ($this->db->beforecommit) { ($this->db->beforecommit)(); }
            $this->db->depth--;
        }
        public function rollback($error) { $this->db->restore($this->before); $this->db->depth--; throw $error; }
    }
    class executor_db {
        public $rows = [];
        public $writes = 0;
        public $depth = 0;
        public $beforecommit;
        public $guardgroup = true;
        public function snapshot() { return serialize([$this->rows, $this->writes]); }
        public function restore($snapshot) { [$this->rows, $this->writes] = unserialize($snapshot); }
        public function is_transaction_started() { return $this->depth > 0; }
        public function start_delegated_transaction() {
            same(0, $this->depth, 'exactly one owned transaction');
            $this->depth++;
            return new executor_transaction($this);
        }
        public function get_manager() { return $this; }
        public function table_exists($name) { return true; }
        public function get_records($table, $where = [], ...$args) {
            return array_map(static fn($row) => clone $row,
                array_filter($this->rows[$table] ?? [], static function($row) use ($where) {
                    foreach ($where as $key => $value) { if (($row->$key ?? null) != $value) { return false; } }
                    return true;
                }));
        }
        public function get_record($table, $where) { $rows = $this->get_records($table, $where); return reset($rows) ?: false; }
        public function record_exists($table, $where) { return (bool)$this->get_record($table, $where); }
        public function record_exists_sql($sql, $params) { return $this->guardgroup; }
        public function get_records_select($table, $sql, $params, ...$args) {
            same('type = :type AND LOWER(objectid) = :objectid', $sql, 'same identity collision rule');
            return array_filter($this->get_records($table, ['type' => $params['type']]),
                static fn($row) => strtolower($row->objectid) === $params['objectid']);
        }
        private function writing($table, $row): void {
            if ($table === 'local_teamscsv_managed' && isset($row->role)) {
                expect(strlen($row->role) <= 6, 'intent must fit the unchanged six-character role column');
            }
            same(1, $this->depth, 'write outside owned transaction');
            expect(isset(\core\lock\lock_config::$factory->held['course-6']), 'write outside course lock');
            $this->writes++;
        }
        public function insert_record($table, $row) {
            $this->writing($table, $row); $row = clone $row;
            $row->id = max(array_merge([0], array_keys($this->rows[$table] ?? []))) + 1;
            $this->rows[$table][$row->id] = $row;
            return $row->id;
        }
        public function update_record($table, $row) {
            $this->writing($table, $row);
            foreach ((array)$row as $key => $value) { $this->rows[$table][$row->id]->$key = $value; }
        }
        public function delete_records($table, $where) {
            expect(in_array($table, ['local_teamscsv_dirty', 'local_teamscsv_pageid', 'local_teamscsv_page'], true),
                'only completed dirty jobs and task-owned staged pages may be deleted');
            $this->writing($table, new \stdClass());
            foreach ($this->get_records($table, $where) as $row) { unset($this->rows[$table][$row->id]); }
        }
        public function get_records_sql($sql, $params, $from = 0, $limit = 0) {
            expect($from === 0 && $limit >= 1 && $limit <= 25, 'bounded public storage read');
            $result = [];
            if (str_contains($sql, 'FROM {local_teamscsv_dirty}')) {
                foreach ($this->get_records('local_teamscsv_dirty') as $row) {
                    $g = $this->get_record('local_teamscsv_generation', ['id' => $row->generationid]);
                    if ($row->nextattempt <= $params['now'] && $this->record_exists('local_teamscsv_map', ['generationid' => $g->id]) &&
                            \local_teamscsv\course_links::mapped_team($g->courseid) === $g->teamid) {
                        $result[] = (object)array_merge((array)$row, ['courseid' => $g->courseid,
                            'teamid' => $g->teamid, 'revision' => $g->revision]);
                    }
                }
                usort($result, static fn($a, $b) => [$a->nextattempt, $a->id] <=> [$b->nextattempt, $b->id]);
            } else {
                foreach ($this->get_records('local_teamscsv_managed') as $row) {
                    $g = $this->get_record('local_teamscsv_generation', ['id' => $row->generationid]);
                    if ($g->courseid === $params['courseid'] && $row->id > $params['afterid']) {
                        $result[] = (object)array_merge((array)$row, ['courseid' => $g->courseid,
                            'teamid' => $g->teamid, 'revision' => $g->revision]);
                    }
                }
            }
            return array_slice($result, 0, $limit);
        }
    }
    class executor_lock {
        public function __construct(private $factory, private $key) {}
        public function release() {
            same(0, $GLOBALS['DB']->depth, 'lock retained through real commit/rollback');
            unset($this->factory->held[$this->key]);
        }
    }
    class executor_locks {
        public $held = [];
        public function get_lock($key, $timeout) {
            same(0, $timeout, 'nonblocking runner');
            if (isset($this->held[$key])) { return false; }
            $this->held[$key] = true;
            return new executor_lock($this, $key);
        }
    }
    class envelope_client extends \local_o365\rest\unified {
        public function __construct(private array $response) {}
        public function managed_membership_request(string $method, string $path, array $data = [], array $options = []): array {
            same('GET', $method, 'invalid GET envelope never authorizes a write');
            return $this->response;
        }
    }
    function setup(array $owners = [8], array $members = [9], array $teachers = [21], array $students = [22]): array {
        installed($GLOBALS['patched']);
        $GLOBALS['CFG']->dirroot = '/scratch';
        $GLOBALS['DB'] = new executor_db();
        \core\lock\lock_config::$factory = new executor_locks();
        $GLOBALS['config'] = ['local_o365' => ['version' => 2025040835, 'coursesync' => 'onall',
            'apptokens' => serialize(['https://graph.microsoft.com' => ['token' => 'synthetic', 'expiry' => 2000,
                'scope' => '', 'tokenresource' => 'https://graph.microsoft.com']])]];
        $GLOBALS['roles'] = ['local/o365:teamowner' => $teachers, 'local/o365:teammember' => $students];
        $GLOBALS['remote'] = ['owners' => array_map('uid', $owners), 'members' => array_map('uid', $members)];
        $GLOBALS['requests'] = [];
        $GLOBALS['inits'] = $GLOBALS['closes'] = $GLOBALS['factories'] = $GLOBALS['debugs'] = 0;
        $GLOBALS['onrequest'] = $GLOBALS['responsehook'] = $GLOBALS['afterack'] = $GLOBALS['paging'] = null;
        $GLOBALS['DB']->rows = ['course' => [6 => (object)['id' => 6]],
            'local_o365_objects' => [1 => (object)['id' => 1, 'type' => 'group', 'subtype' => 'course',
                'moodleid' => 6, 'objectid' => TEAM], 2 => (object)['id' => 2, 'type' => 'group',
                'subtype' => 'courseteam', 'moodleid' => 6, 'objectid' => TEAM]]];
        foreach ([21, 22, 23] as $user) {
            $GLOBALS['DB']->rows['user'][$user] = (object)['id' => $user, 'deleted' => 0];
            $GLOBALS['DB']->rows['local_o365_objects'][$user] = (object)['id' => $user, 'type' => 'user',
                'moodleid' => $user, 'objectid' => uid($user - 20)];
        }
        $store = new membership_store();
        $mapping = $store->register(6, TEAM, 100);
        return [$store, $mapping, $store->due(100, 1)[0]];
    }
    function remote_request($verb, $path, $data): array {
        expect(preg_match('~^/groups/' . TEAM . '/(owners|members)(.*)$~D', $path, $m), 'only exact Team endpoints');
        $role = $m[1]; $suffix = $m[2];
        if ($verb === 'GET') {
            if (is_array($GLOBALS['paging'])) {
                $index = 0;
                if ($suffix !== '?$top=999') {
                    expect(preg_match('/^\?\$skiptoken=p([1-9][0-9]*)&\$top=999$/D', $suffix, $match),
                        'valid normalized resumed Graph cursor');
                    $index = (int)$match[1];
                }
                expect($index < $GLOBALS['paging'][$role], 'page index exceeds synthetic collection');
                $visible = [uid(($role === 'owners' ? 100 : 200) + $index)];
                if ($index === 0) { $visible = array_merge($visible, $GLOBALS['remote'][$role]); }
                $body = ['value' => array_map(static fn($id) =>
                    ['id' => $id, '@odata.type' => '#microsoft.graph.user'], $visible)];
                if ($index + 1 < $GLOBALS['paging'][$role]) {
                    $body['@odata.nextLink'] = 'https://graph.microsoft.com/v1.0/groups/' . TEAM . '/' . $role .
                        '?$skiptoken=p' . ($index + 1) . '&$top=999';
                }
                return [200, json_encode($body)];
            }
            same('?$top=999', $suffix, 'initial bounded collection read');
            return [200, json_encode(['value' => array_map(static fn($id) =>
                ['id' => $id, '@odata.type' => '#microsoft.graph.user'], $GLOBALS['remote'][$role])])];
        }
        if ($verb === 'POST') {
            same('/$ref', $suffix, 'safe add reference');
            expect(preg_match('~^https://graph.microsoft.com/v1.0/users/([0-9a-f-]+)$~D', $data['@odata.id'], $id), 'user reference');
            expect(!in_array($id[1], $GLOBALS['remote'][$role], true), 'duplicate ADD instead of observed-state recovery');
            // Responsibility must be durably committed BEFORE the remote acknowledgement/crash boundary.
            $store = new membership_store(); $mapping = $store->current(6);
            expect(in_array($id[1], array_column($store->managed($mapping), 'objectid'), true), 'ADD without durable responsibility');
            $GLOBALS['remote'][$role][] = $id[1];
        } else {
            same('DELETE', $verb, 'no arbitrary methods');
            expect(preg_match('~^/([0-9a-f-]+)/\$ref$~D', $suffix, $id), 'safe delete reference');
            expect(in_array($id[1], $GLOBALS['remote'][$role], true), 'deleting absent identity');
            if ($role === 'owners') { expect(count($GLOBALS['remote']['owners']) > 1, 'deleted final owner'); }
            if ($role === 'members') { expect(!in_array($id[1], $GLOBALS['remote']['owners'], true), 'member removed before owner'); }
            $GLOBALS['remote'][$role] = array_values(array_diff($GLOBALS['remote'][$role], [$id[1]]));
        }
        return [204, ''];
    }
    function runjob($job, int $now = 100, int $maxoperations = 10, int $maxrequests = 60, $factory = null): array {
        ob_start();
        try { $result = (new membership_executor(new membership_store(), $factory, $maxoperations, $maxrequests))->execute($job, $now); }
        finally { same('', ob_get_clean(), 'executor failures/successes emit no output'); }
        same([], \core\lock\lock_config::$factory->held, 'execution lock leaked');
        same(0, $GLOBALS['DB']->depth, 'transaction leaked');
        same($GLOBALS['inits'], $GLOBALS['closes'], 'transport handle leaked');
        return $result;
    }
    function outcome($status, $reason, $requests, $operations): array {
        return compact('status', 'reason', 'requests', 'operations');
    }
    function writes(): array { return array_values(array_filter($GLOBALS['requests'], static fn($r) => $r[0] !== 'GET')); }
    function responsible($store, $mapping): array {
        $rows = [];
        foreach ($store->managed($mapping) as $row) {
            $rows[$row['objectid']] = [(int)$row['userid'], $row['role'], (int)$row['confirmedat']];
        }
        return $rows;
    }
    function retryjob($store, int $now = 160): array {
        same([], $store->due($now - 1), 'no inline/early retries');
        $jobs = $store->due($now, 1); same(1, count($jobs), 'dirty responsibility retained');
        return $jobs[0];
    }
    function seed($store, $mapping, int $user, string $role): void {
        $store->record_confirmed($mapping, $user, uid($user - 20), $role, true, 90);
    }

    $groups = [
        'fourteen paged GETs resume without deleting or adopting incomplete Graph evidence' => function() {
            [$store, $mapping, $job] = setup([8], [9], [], []);
            $GLOBALS['paging'] = ['owners' => 7, 'members' => 7];
            same(outcome('retry', 'request_limit', 12, 0), runjob($job, 100, 10, 12),
                'first bounded run must checkpoint twelve pages');
            same([], writes(), 'partial pages must never authorize Graph writes');
            same([], responsible($store, $mapping), 'unverified Graph IDs became managed responsibility');
            $later = $store->due(160, 1)[0];
            $first = count($GLOBALS['requests']);
            same(outcome('retry', 'staged_read_unverified', 2, 0), runjob($later, 160, 10, 12),
                'second bounded run must finish traversal without execution authority');
            same(['GET', '/groups/' . TEAM . '/members?$skiptoken=p5&$top=999', []], $GLOBALS['requests'][$first],
                'second run repeated the first Graph page instead of its exact continuation');
            same([], writes(), 'cross-run evidence authorized a write');
            same([], responsible($store, $mapping), 'cross-run evidence adopted outsiders');
        },
        'expired remote checkpoint restarts safely instead of stalling the course forever' => function() {
            [$store, $mapping, $job] = setup([8], [9], [], []);
            $GLOBALS['paging'] = ['owners' => 7, 'members' => 7];
            same(outcome('retry', 'request_limit', 12, 0), runjob($job, 100, 10, 12), 'initial stage');
            $first = count($GLOBALS['requests']);
            same(outcome('retry', 'request_limit', 12, 0),
                runjob($store->due(90000, 1)[0], 90000, 10, 12), 'expired stage must restart bounded traversal');
            same(['GET', '/groups/' . TEAM . '/owners?$top=999', []], $GLOBALS['requests'][$first],
                'expired stage replayed a remote continuation');
            same([], writes(), 'expired stage permitted a Graph write');
            same([], responsible($store, $mapping), 'expired stage changed managed responsibility');
        },
        'large-Team addition persists intent before ACK and confirms only after fresh staged observation' => function() {
            [$store, $mapping, $job] = setup([], [], [], [22]);
            $GLOBALS['paging'] = ['owners' => 7, 'members' => 7];
            same(outcome('retry', 'request_limit', 12, 0), runjob($job, 100, 10, 12),
                'initial remote traversal checkpoint');
            same(outcome('retry', 'staged_read_unverified', 2, 0),
                runjob($store->due(160, 1)[0], 160, 10, 12), 'remote traversal completed without writes');
            same(outcome('retry', 'staged_add_unverified', 1, 1),
                runjob($store->due(340, 1)[0], 340, 10, 12), 'one bounded ADD after complete staged traversal');
            same([['POST', '/groups/' . TEAM . '/members/$ref',
                ['@odata.id' => 'https://graph.microsoft.com/v1.0/users/' . uid(2)]]], writes(),
                'unexpected write, duplicate ADD or removal');
            same([uid(2) => [22, 'intent', 0]], responsible($store, $mapping),
                'pre-ACK responsibility not durable or ACK treated as confirmation');
            same(outcome('retry', 'request_limit', 12, 0),
                runjob($store->due(700, 1)[0], 700, 10, 12), 'fresh post-ACK traversal checkpoint');
            same(outcome('retry', 'staged_read_unverified', 2, 0),
                runjob($store->due(1400, 1)[0], 1400, 10, 12), 'later positive observation');
            same([uid(2) => [22, 'member', 1400]], responsible($store, $mapping),
                'positive post-ACK observation did not confirm member');
            same(1, count(writes()), 'reconciliation reissued an already observed ADD');
            same([uid(2)], $GLOBALS['remote']['members'], 'synthetic Team membership not added');
        },
        'large-Team teacher becomes member then owner, preserving an unrelated owner' => function() {
            [$store, $mapping, $job] = setup([8], [], [21], []);
            $GLOBALS['paging'] = ['owners' => 7, 'members' => 7];
            foreach ([100, 160, 340, 700, 1400, 3000, 7000, 11000, 16000, 21000] as $now) {
                $outcome = runjob($store->due($now, 1)[0], $now, 10, 12);
                same('retry', $outcome['status'], 'staged path must retain pending job');
            }
            same(['POST', 'POST'], array_column(writes(), 0), 'no duplicate addition or DELETE');
            same(['/groups/' . TEAM . '/members/$ref', '/groups/' . TEAM . '/owners/$ref'],
                array_column(writes(), 1), 'member must be added before owner');
            same([uid(1) => [21, 'owner', 21000]], responsible($store, $mapping),
                'later positive owner observation did not confirm teacher role');
            same([uid(8), uid(1)], $GLOBALS['remote']['owners'], 'unrelated owner changed');
        },
        'large-Team ACK crash keeps intent, restarts traversal and never duplicates ADD' => function() {
            [$store, $mapping, $job] = setup([], [], [], [22]);
            $GLOBALS['paging'] = ['owners' => 7, 'members' => 7];
            same(outcome('retry', 'request_limit', 12, 0), runjob($job, 100, 10, 12), 'initial checkpoint');
            same(outcome('retry', 'staged_read_unverified', 2, 0),
                runjob($store->due(160, 1)[0], 160, 10, 12), 'complete staged traversal');
            $GLOBALS['afterack'] = static function() { throw new \RuntimeException('Simulated process death after ACK'); };
            same(outcome('retry', 'uncertain', 1, 1),
                runjob($store->due(340, 1)[0], 340, 10, 12), 'uncertain acknowledged addition');
            same([uid(2) => [22, 'intent', 0]], responsible($store, $mapping),
                'crash before confirmation lost durable responsibility');
            $GLOBALS['afterack'] = null; $first = count($GLOBALS['requests']);
            same(outcome('retry', 'request_limit', 12, 0),
                runjob($store->due(700, 1)[0], 700, 10, 12), 'fresh post-crash read');
            same(['GET', '/groups/' . TEAM . '/owners?$top=999', []], $GLOBALS['requests'][$first],
                'post-ACK crash reused stale pre-write cursor');
            same(outcome('retry', 'staged_read_unverified', 2, 0),
                runjob($store->due(1400, 1)[0], 1400, 10, 12), 'observed positive after crash');
            same([uid(2) => [22, 'member', 1400]], responsible($store, $mapping),
                'positive observation after crash not confirmed');
            same(1, count(writes()), 'retry duplicated acknowledged ADD');
        },
        'invalid cross-cron continuation stops visibly without deletion or adopted history' => function() {
            [$store, $mapping, $job] = setup([8], [9], [], []);
            $GLOBALS['paging'] = ['owners' => 7, 'members' => 7];
            same(outcome('retry', 'request_limit', 12, 0), runjob($job, 100, 10, 12), 'initial pages checkpointed');
            $GLOBALS['responsehook'] = static fn($verb, $path, $normal) =>
                $verb === 'GET' && str_contains($path, 'skiptoken=p5') ? [200,
                    json_encode(['value' => [], '@odata.nextLink' =>
                        'https://invalid.example/v1.0/groups/' . TEAM . '/members?$skiptoken=other'])] : $normal;
            same(outcome('retry', 'staging_invalid', 1, 0), runjob($store->due(160, 1)[0], 160, 10, 12),
                'invalid continuation must be visible without replaying an unsafe URL');
            same([], writes(), 'bad token authorized a Graph write');
            same([], responsible($store, $mapping), 'bad token adopted or released history');
        },
        'local identity changes invalidate staged traversal before another Graph request' => function() {
            [$store, $mapping, $job] = setup([8], [9], [], []);
            $GLOBALS['paging'] = ['owners' => 7, 'members' => 7];
            same(outcome('retry', 'request_limit', 12, 0), runjob($job, 100, 10, 12), 'initial pages checkpointed');
            $GLOBALS['roles']['local/o365:teamowner'] = [21];
            $before = count($GLOBALS['requests']);
            same(outcome('retry', 'staging_invalid', 0, 0), runjob($store->due(160, 1)[0], 160, 10, 12),
                'local identity changed without a dirty event');
            same($before, count($GLOBALS['requests']), 'identity drift made another Graph request');
            same([], writes(), 'identity drift authorized Graph write');
            same([], responsible($store, $mapping), 'identity drift changed managed responsibility');
        },
        'default verified factory, initial roles, outsider preservation and completed-job replay' => function() {
            [$store, $mapping, $job] = setup();
            same(outcome('completed', null, 11, 3), runjob($job), 'initial synchronization');
            same(1, $GLOBALS['factories'], 'exact0835 get_graphclient factory');
            same(['owners' => [uid(8), uid(1)], 'members' => [uid(9), uid(1), uid(2)]], $GLOBALS['remote'], 'unrelated users intact');
            same([uid(1) => [21, 'owner', 100], uid(2) => [22, 'member', 100]], responsible($store, $mapping), 'verified same-scan Moodle IDs');
            same([], $store->due(100), 'only successful job completed');
            $before = $GLOBALS['DB']->snapshot();
            same(outcome('skipped', 'stale_job', 0, 0), runjob($job), 'completed proposal replay');
            same($before, $GLOBALS['DB']->snapshot(), 'replay changed data/counters');
        },
        'all replacement owners verified before demotion; owner deletion immediately follows owner reread' => function() {
            [$store, $mapping, $job] = setup([1, 8], [1, 9], [21, 22], []);
            seed($store, $mapping, 21, 'owner');
            $GLOBALS['roles'] = ['local/o365:teamowner' => [22], 'local/o365:teammember' => [21]];
            same('completed', runjob($job)['status'], 'role transition');
            $actions = writes();
            same(['POST', 'POST', 'DELETE'], array_column($actions, 0), 'replacement before removal');
            same('/groups/' . TEAM . '/owners/' . uid(1) . '/$ref', $actions[2][1], 'demotion only');
            foreach ($GLOBALS['requests'] as $i => $request) {
                if ($request[0] === 'DELETE') {
                    same(['GET', '/groups/' . TEAM . '/owners?$top=999', []], $GLOBALS['requests'][$i - 1], 'immediate owner reread');
                }
            }
            same(['owners' => [uid(8), uid(2)], 'members' => [uid(1), uid(9), uid(2)]], $GLOBALS['remote'], 'demotion retains membership/outsiders');
            same([uid(1) => [21, 'member', 100], uid(2) => [22, 'owner', 100]], responsible($store, $mapping), 'confirmed role transition');
        },
        'ALL replacements need observed ownership, not just an ACK, before any owner deletion' => function() {
            foreach ([false, true] as $unobserved) {
                [$store, $mapping, $job] = setup([1, 8], [1, 9], [21], []);
                seed($store, $mapping, 21, 'owner');
                $GLOBALS['roles'] = ['local/o365:teamowner' => [22, 23], 'local/o365:teammember' => [21]];
                if ($unobserved) {
                    $GLOBALS['afterack'] = static function($verb, $path) {
                        if ($path === '/groups/' . TEAM . '/owners/$ref') {
                            $GLOBALS['remote']['owners'] = array_values(array_diff($GLOBALS['remote']['owners'], [uid(3)]));
                        }
                    };
                }
                $result = runjob($job);
                same($unobserved ? 'retry' : 'completed', $result['status'], 'all replacements verified');
                same($unobserved ? ['POST', 'POST', 'POST', 'POST'] : ['POST', 'POST', 'POST', 'POST', 'DELETE'],
                    array_column(writes(), 0), 'no deletion before every replacement is observed');
                if ($unobserved) {
                    same([uid(1) => [21, 'owner', 90], uid(2) => [22, 'intent', 0], uid(3) => [23, 'intent', 0]],
                        responsible($store, $mapping), 'ACK alone never updates confirmed roles');
                    retryjob($store);
                } else {
                    same(['owners' => [uid(8), uid(2), uid(3)], 'members' => [uid(1), uid(9), uid(2), uid(3)]],
                        $GLOBALS['remote'], 'multiple replacements and outsiders preserved');
                }
            }
        },
        'intent persistence failure prevents ADD; uncertain post-DELETE read cannot release history' => function() {
            [$store, $mapping, $job] = setup();
            $GLOBALS['DB']->beforecommit = static function() {
                if ($GLOBALS['DB']->get_records('local_teamscsv_managed')) { throw new \RuntimeException('Intent commit failed'); }
            };
            same(outcome('retry', 'uncertain', 2, 0), runjob($job), 'intent must actually commit first');
            same([], $store->managed($mapping), 'failed intent rolled back');
            same([], writes(), 'no remote ADD without persistent responsibility'); retryjob($store);
            [$store, $mapping, $job] = setup([1, 8], [1, 9], [21], []);
            seed($store, $mapping, 21, 'owner'); $GLOBALS['roles']['local/o365:teamowner'] = [];
            $GLOBALS['responsehook'] = static fn($verb, $path, $normal) =>
                $verb === 'GET' && count(writes()) && str_contains($path, '/members') ? [200, '{'] : $normal;
            same('retry', runjob($job)['status'], 'incomplete reread after DELETE');
            same(1, count(writes()), 'stop before dependent membership deletion');
            same([uid(1) => [21, 'owner', 90]], responsible($store, $mapping), 'incomplete read retained history');
            $GLOBALS['responsehook'] = null; $GLOBALS['requests'] = [];
            same('completed', runjob(retryjob($store), 160)['status'], 'next read reconciles the owner ACK');
            same(1, count(writes()), 'only remaining membership removal');
            same([], $store->managed($mapping), 'complete later absence permits release');
        },
        'departures remove owner before member and release only after both complete rereads' => function() {
            [$store, $mapping, $job] = setup([1, 8], [1, 9], [21], []);
            seed($store, $mapping, 21, 'owner'); $GLOBALS['roles']['local/o365:teamowner'] = [];
            same('completed', runjob($job)['status'], 'managed departure');
            same(['/groups/' . TEAM . '/owners/' . uid(1) . '/$ref', '/groups/' . TEAM . '/members/' . uid(1) . '/$ref'],
                array_column(writes(), 1), 'owner first');
            same(['owners' => [uid(8)], 'members' => [uid(9)]], $GLOBALS['remote'], 'outsiders not deleted');
            same([], $store->managed($mapping), 'confirmed absence released');
            same(1, (int)$store->audit(6)[0]['released'], 'release retains audit');
            [$store, $mapping, $job] = setup([8], [9], [], []);
            same(outcome('completed', null, 2, 0), runjob($job), 'unknown historical users not adopted');
            same([], $store->audit(6), 'no outsider intent or confirmation');
        },
        'final owner is retained with dirty history; later replacement permits progress' => function() {
            [$store, $mapping, $job] = setup([1], [1, 9], [21], []);
            seed($store, $mapping, 21, 'owner'); $GLOBALS['roles']['local/o365:teamowner'] = [];
            same(outcome('retry', 'last_owner', 2, 0), runjob($job), 'last owner blocked');
            same([uid(1) => [21, 'owner', 90]], responsible($store, $mapping), 'history unchanged while blocked');
            same([], writes(), 'no final-owner mutation');
            $job = retryjob($store); same([1, 2, 160], [$job['attempts'], $job['serial'], $job['nextattempt']], 'reserved backoff');
            $GLOBALS['roles']['local/o365:teamowner'] = [22];
            same('completed', runjob($job, 160)['status'], 'replacement unblocks departure');
            same([uid(2)], $GLOBALS['remote']['owners'], 'replacement survives');
            same([uid(2) => [22, 'owner', 160]], responsible($store, $mapping), 'only new owner responsibility');
        },
        'crash after Graph ACK recovers observed success without duplicate ADD or orphan' => function() {
            foreach ([false, true] as $unenrol) {
                [$store, $mapping, $job] = setup();
                $GLOBALS['afterack'] = static function() { throw new \RuntimeException('Simulated process death after ACK'); };
                same(outcome('retry', 'uncertain', 3, 1), runjob($job), 'crash window');
                same([uid(1) => [21, 'intent', 0], uid(2) => [22, 'intent', 0]], responsible($store, $mapping), 'pre-ACK durable intent');
                $job = retryjob($store); $GLOBALS['afterack'] = null; $GLOBALS['requests'] = [];
                if ($unenrol) { $GLOBALS['roles'] = ['local/o365:teamowner' => [], 'local/o365:teammember' => []]; }
                same('completed', runjob($job, 160)['status'], 'recovery from observed state');
                foreach (writes() as $write) {
                    expect(!($write[0] === 'POST' && $write[1] === '/groups/' . TEAM . '/members/$ref' &&
                        $write[2]['@odata.id'] === 'https://graph.microsoft.com/v1.0/users/' . uid(1)), 'repeated acknowledged addition');
                }
                if ($unenrol) {
                    same([], $store->managed($mapping), 'unenrolled pending participant removed/released');
                    same(['owners' => [uid(8)], 'members' => [uid(9)]], $GLOBALS['remote'], 'no orphan or outsider deletion');
                }
            }
        },
        'confirmation/complete commit failure retains pending history and retries using observed state' => function() {
            [$store, $mapping, $job] = setup();
            $GLOBALS['DB']->beforecommit = static function() {
                foreach ($GLOBALS['DB']->get_records('local_teamscsv_managed') as $row) {
                    if ($row->role !== 'intent') { throw new \RuntimeException('Simulated confirmation commit failure'); }
                }
            };
            same('retry', runjob($job)['status'], 'commit failure not completion');
            same([uid(1) => [21, 'intent', 0], uid(2) => [22, 'intent', 0]], responsible($store, $mapping), 'atomic confirmation rollback');
            $GLOBALS['DB']->beforecommit = null; $GLOBALS['requests'] = [];
            same(outcome('completed', null, 2, 0), runjob(retryjob($store), 160), 'already successful not persisted');
            same([], writes(), 'observed recovery does not repeat writes');
            [$store, $mapping, $job] = setup([1, 8], [1, 9], [21], []);
            seed($store, $mapping, 21, 'owner'); $GLOBALS['roles']['local/o365:teamowner'] = [];
            $GLOBALS['DB']->beforecommit = static function() {
                foreach ($GLOBALS['DB']->get_records('local_teamscsv_managed') as $row) {
                    if ($row->released) { throw new \RuntimeException('Simulated release commit failure'); }
                }
            };
            same('retry', runjob($job)['status'], 'release commit failure');
            same([uid(1) => [21, 'owner', 90]], responsible($store, $mapping), 'uncertain release retains responsibility');
            $GLOBALS['DB']->beforecommit = null; $GLOBALS['requests'] = [];
            same(outcome('completed', null, 2, 0), runjob(retryjob($store), 160), 'absence recovery');
            same([], $store->managed($mapping), 'later complete absence releases');
        },
        'ambiguous/429/Graph-error/timeout/incomplete reads are not empty Teams or successful jobs' => function() {
            foreach (['429', 'malformed', 'error', 'timeout', 'members', 'pages'] as $failure) {
                [$store, $mapping, $job] = setup([1, 8], [1, 9]); seed($store, $mapping, 21, 'owner');
                $before = responsible($store, $mapping);
                $GLOBALS['responsehook'] = static function($verb, $path, $response) use ($failure) {
                    if ($failure === 'timeout') { throw new \RuntimeException('Managed membership transport failed.'); }
                    if ($failure === 'members' && !str_contains($path, '/members')) { return $response; }
                    return match ($failure) {
                        '429' => [429, '{"error":{"code":"TooManyRequests"}}'],
                        'error' => [200, '{"value":[],"error":{}}'],
                        'pages' => [200, '{"value":[],"@odata.nextLink":"https://evil.example/"}'],
                        default => [200, '{'],
                    };
                };
                same(outcome('retry', 'uncertain', $failure === 'members' ? 2 : 1, 0), runjob($job), 'read uncertainty ' . $failure);
                same($before, responsible($store, $mapping), 'failed read retained history');
                same([], writes(), 'failed read cannot authorize a write'); retryjob($store);
            }
        },
        'GET must carry strict integer200 and ok=true, never a body-only or fabricated success' => function() {
            foreach ([['status' => 200, 'body' => '{"value":[]}'],
                    ['status' => 200, 'ok' => false, 'body' => '{"value":[]}'],
                    ['status' => 200, 'ok' => 1, 'body' => '{"value":[]}'],
                    ['status' => '200', 'ok' => true, 'body' => '{"value":[]}']] as $response) {
                [$store, $mapping, $job] = setup();
                same(outcome('retry', 'uncertain', 1, 0),
                    runjob($job, 100, 10, 60, static fn() => new envelope_client($response)), 'strict executor GET envelope');
                same([], $store->managed($mapping), 'bad envelope cannot establish intent'); retryjob($store);
            }
        },
        'historical identity reassigned during remote read cannot authorize a removal' => function() {
            [$store, $mapping, $job] = setup([3, 8], [3, 9], [23], []);
            seed($store, $mapping, 23, 'owner'); $GLOBALS['roles']['local/o365:teamowner'] = [];
            $GLOBALS['onrequest'] = static function($verb, $path) {
                if (str_contains($path, '/members')) { $GLOBALS['DB']->rows['local_o365_objects'][23]->moodleid = 99; }
            };
            same('retry', runjob($job)['status'], 'history reassignment fails closed');
            same([], writes(), 'no deletion on stale history identity evidence');
            same([uid(3) => [23, 'owner', 90]], responsible($store, $mapping), 'responsibility retained for reconciliation');
            retryjob($store);
        },
        'write needs strict actual204 empty acknowledgement; ambiguity retains all intent' => function() {
            foreach ([[200, ''], [202, ''], [204, '{}'], [429, ''], [500, '']] as $response) {
                [$store, $mapping, $job] = setup();
                $GLOBALS['responsehook'] = static fn($verb, $path, $normal) => $verb === 'GET' ? $normal : $response;
                same(outcome('retry', 'uncertain', 3, 1), runjob($job), 'non204/uncertain write');
                same([uid(1) => [21, 'intent', 0], uid(2) => [22, 'intent', 0]], responsible($store, $mapping), 'no premature confirmation');
                same(1, count(writes()), 'no retry or dependent write'); retryjob($store);
            }
        },
        'stale proposal, missing patch, disabled sync, resource or enclosing transaction fail silently without writes' => function() {
            foreach (['serial', 'early', 'mapping', 'patch', 'guard', 'disabled', 'resource', 'outer'] as $failure) {
                [$store, $mapping, $job] = setup();
                $expected = 'unavailable';
                if ($failure === 'serial') { $store->mark_dirty($mapping, 100); $expected = 'stale_job'; }
                if ($failure === 'early') { $job = $store->retry($mapping, $job['serial'], 100); $expected = 'not_due'; }
                if ($failure === 'mapping') { $GLOBALS['DB']->rows['local_o365_objects'][2]->objectid = OTHER; $expected = 'stale_mapping'; }
                if ($failure === 'patch') { $GLOBALS['CFG']->dirroot = '/scratch/absent'; }
                if ($failure === 'guard') { $GLOBALS['DB']->guardgroup = false; }
                if ($failure === 'disabled') { $GLOBALS['config']['local_o365']['coursesync'] = 'off'; }
                if ($failure === 'resource') { $GLOBALS['config']['auth_oidc']['oidcresource'] = 'https://evil.example'; }
                if ($failure === 'outer') { $outer = $GLOBALS['DB']->start_delegated_transaction(); $expected = 'transaction'; }
                $before = $GLOBALS['DB']->snapshot();
                if ($failure === 'outer') {
                    ob_start(); $result = (new membership_executor($store))->execute($job, 100);
                    same('', ob_get_clean(), 'outer transaction failure silent'); $outer->allow_commit();
                } else { $result = runjob($job); }
                same(outcome('skipped', $expected, 0, 0), $result, 'fail closed ' . $failure);
                same($before, $GLOBALS['DB']->snapshot(), 'rejection changed rows/counters');
                same(0, $GLOBALS['factories'], 'authority before client/token acquisition');
            }
        },
        'no cached/expired token never refreshes OAuth or attempts Graph' => function() {
            foreach (['missing', 'expired'] as $failure) {
                [$store, $mapping, $job] = setup();
                $GLOBALS['config']['local_o365']['apptokens'] = $failure === 'missing' ? false : serialize([
                    'https://graph.microsoft.com' => ['token' => 'synthetic', 'expiry' => 999, 'scope' => '',
                        'tokenresource' => 'https://graph.microsoft.com']]);
                $result = runjob($job);
                same('retry', $result['status'], 'token failure remains dirty');
                same([0, []], [$GLOBALS['inits'], writes()], 'no HTTP for missing/expired cached token');
                same([], $store->managed($mapping), 'token failure did not adopt history'); retryjob($store);
            }
        },
        'mapping, patch and event serial changes during Graph cannot complete or consume newer dirty work' => function() {
            foreach (['mapping', 'patch', 'event-read', 'event-ack'] as $failure) {
                [$store, $mapping, $job] = setup();
                $change = static function() use ($failure) {
                    if ($failure === 'mapping') { $GLOBALS['DB']->rows['local_o365_objects'][2]->objectid = OTHER; }
                    else if ($failure === 'patch') { $GLOBALS['CFG']->dirroot = '/scratch/absent'; }
                    else {
                        // Simulate an out-of-band event serial (ordinary storage writers are lock-excluded).
                        foreach ($GLOBALS['DB']->rows['local_teamscsv_dirty'] as $row) { $row->serial++; }
                    }
                };
                if ($failure === 'event-ack') { $GLOBALS['afterack'] = $change; }
                else { $GLOBALS['onrequest'] = static function($verb, $path) use ($change) {
                    if (str_contains($path, '/members')) { $change(); }
                }; }
                same('retry', runjob($job)['status'], 'changed execution not completed');
                same($failure === 'event-ack' ? 1 : 0, count(writes()), 'no further writes after authority change');
                $GLOBALS['CFG']->dirroot = '/scratch'; $GLOBALS['DB']->rows['local_o365_objects'][2]->objectid = TEAM;
                $later = $store->due(160, 1)[0];
                same(str_starts_with($failure, 'event') ? 3 : 2, $later['serial'], 'new event not consumed');
                if ($failure === 'event-ack') {
                    same([uid(1) => [21, 'intent', 0], uid(2) => [22, 'intent', 0]], responsible($store, $mapping), 'ACK intent retained');
                }
            }
        },
        'identity changes and incomplete confirmation reads retain old scope, never release' => function() {
            foreach (['missing', 'collision', 'changed-during-read', 'postwrite-read'] as $failure) {
                [$store, $mapping, $job] = setup([8], [9, 3]);
                $GLOBALS['roles']['local/o365:teammember'][] = 23; seed($store, $mapping, 23, 'member');
                $GLOBALS['roles']['local/o365:teammember'] = [22];
                if ($failure === 'missing') { unset($GLOBALS['DB']->rows['local_o365_objects'][21]); }
                if ($failure === 'collision') { $GLOBALS['DB']->rows['local_o365_objects'][22]->objectid = uid(1); }
                if ($failure === 'changed-during-read') {
                    $GLOBALS['onrequest'] = static function() { $GLOBALS['DB']->rows['local_o365_objects'][21]->objectid = uid(7); };
                }
                if ($failure === 'postwrite-read') {
                    $GLOBALS['responsehook'] = static fn($verb, $path, $normal) =>
                        $verb === 'GET' && count(writes()) ? [200, '{'] : $normal;
                }
                same('retry', runjob($job)['status'], 'uncertain identity/confirmation');
                $scope = responsible($store, $mapping);
                same([23, 'member', 90], $scope[uid(3)], 'history not discarded');
                same($failure === 'postwrite-read' ? 1 : 0, count(writes()), 'no unsafe subsequent writes');
                expect(!isset($scope[uid(8)]) && !isset($scope[uid(9)]), 'outsider adopted'); retryjob($store);
            }
        },
        'fresh final-owner race and unrelated-user loss stop confirmation and deletion' => function() {
            [$store, $mapping, $job] = setup([1, 8], [1, 9], [21], []);
            seed($store, $mapping, 21, 'owner'); $GLOBALS['roles']['local/o365:teamowner'] = [];
            $GLOBALS['onrequest'] = static function($verb, $path) {
                if ($verb === 'GET' && str_contains($path, '/owners') && count($GLOBALS['requests']) > 2) {
                    $GLOBALS['remote']['owners'] = [uid(1)];
                }
            };
            same('retry', runjob($job)['status'], 'final owner race');
            same([], writes(), 'never delete final owner after fresh check');
            same([uid(1) => [21, 'owner', 90]], responsible($store, $mapping), 'race retains confirmed responsibility');
            [$store, $mapping, $job] = setup();
            $GLOBALS['afterack'] = static function() { $GLOBALS['remote']['members'] = [uid(1)]; };
            same('retry', runjob($job)['status'], 'unrelated membership disappeared');
            same('intent', responsible($store, $mapping)[uid(1)][1], 'no confirmation of outsider loss'); retryjob($store);
        },
        'overlapping runner/import/event writers skip under the SAME course lock through commits and Graph' => function() {
            [$store, $mapping, $job] = setup();
            $GLOBALS['onrequest'] = static function() use ($job, $mapping) {
                $GLOBALS['onrequest'] = null;
                $before = $GLOBALS['DB']->snapshot();
                ob_start(); $overlap = (new membership_executor(new membership_store()))->execute($job, 100);
                same('', ob_get_clean(), 'overlapping runner silent');
                same(outcome('skipped', 'busy', 0, 0), $overlap, 'no interleaving runner');
                foreach ([fn() => (new membership_store())->mark_dirty($mapping, 100),
                        fn() => (new membership_store())->register(6, TEAM, 100)] as $call) {
                    try { $call(); throw new \RuntimeException('concurrent writer accepted'); }
                    catch (\RuntimeException $e) { same('Membership storage busy', $e->getMessage(), 'storage shares lock'); }
                }
                same($before, $GLOBALS['DB']->snapshot(), 'contention changed stored state/counters');
            };
            $GLOBALS['DB']->beforecommit = static function() {
                expect(isset(\core\lock\lock_config::$factory->held['course-6']), 'lock missing at actual commit');
            };
            same('completed', runjob($job)['status'], 'single runner completes');
            [$store, $mapping, $job] = setup();
            $lock = \core\lock\lock_config::$factory->get_lock('course-6', 0);
            $before = $GLOBALS['DB']->snapshot();
            ob_start(); $result = (new membership_executor($store))->execute($job, 100);
            same('', ob_get_clean(), 'busy silent'); $lock->release();
            same(outcome('skipped', 'busy', 0, 0), $result, 'busy runner skipped');
            same($before, $GLOBALS['DB']->snapshot(), 'busy runner wrote');
        },
        'operation/request budgets preserve partial responsibility and persistent capped backoff' => function() {
            [$store, $mapping, $job] = setup();
            foreach ([[0, 60], [11, 60], [10, 1], [10, 61]] as [$operations, $requests]) {
                $before = $GLOBALS['DB']->snapshot();
                ob_start();
                try {
                    new membership_executor($store, null, $operations, $requests);
                    throw new \RuntimeException('invalid execution bounds accepted');
                } catch (\InvalidArgumentException $e) {
                    same('Invalid membership execution bounds', $e->getMessage(), 'exact constructor bounds error');
                } finally { same('', ob_get_clean(), 'constructor rejection silent'); }
                same($before, $GLOBALS['DB']->snapshot(), 'bounds rejection changed data/counters');
                same([], $GLOBALS['requests'], 'bounds rejection attempted a request');
            }
            same(outcome('retry', 'operation_limit', 5, 1), runjob($job, 100, 1), 'bounded write slice');
            same('intent', responsible($store, $mapping)[uid(1)][1], 'partial work not falsely confirmed');
            same('completed', runjob(retryjob($store), 160)['status'], 'next slice recovers');
            [$store, $mapping, $job] = setup();
            same(outcome('retry', 'request_limit', 3, 1), runjob($job, 100, 10, 3), 'hard invocation cap');
            same('intent', responsible($store, $mapping)[uid(1)][1], 'unverified ACK retained');
            same('completed', runjob(retryjob($store), 160)['status'], 'budget recovery');
            [$store, $mapping, $job] = setup();
            $GLOBALS['responsehook'] = static fn() => [429, '{}'];
            $now = 100;
            for ($n = 1; $n <= 10; $n++) {
                $GLOBALS['requests'] = [];
                same(outcome('retry', 'uncertain', 1, 0), runjob($job, $now), 'one failure attempt, no inline retry');
                $delay = min(3600, 60 * (2 ** min(6, $n - 1)));
                $job = retryjob($store, $now + $delay);
                same($n, $job['attempts'], 'persistent attempt counter');
                $now += $delay;
            }
        },
    ];
    $failed = 0;
    foreach ($groups as $name => $group) {
        try { $group(); echo 'PASS ' . $name . "\n"; }
        catch (\Throwable $e) { $failed++; echo 'FAIL ' . $name . ': ' . $e->getMessage() . "\n"; }
    }
    exit($failed ? 1 : 0);
}
