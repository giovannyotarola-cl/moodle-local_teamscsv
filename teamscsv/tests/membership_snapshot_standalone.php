<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Public read/plan API, exact0835 helpers, in-memory Moodle DML and bounded fake GETs only.
namespace core\context {
    class course {
        public static function instance($id) { return (object)['id' => $id]; }
    }
}
namespace local_o365 {
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
namespace {
    define('MOODLE_INTERNAL', true);
    define('SITEID', 1);
    const TEAM = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    const OTHER = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
    const STALE = 'Unverified or stale mapping';
    const IDENTITY = 'Ambiguous participant identity';
    const HISTORY = 'Invalid managed history';
    const PAYLOAD = 'Invalid Graph membership response';
    const NEXTLINK = 'Unsafe Graph membership pagination';
    const LIMIT = 'Graph membership page limit exceeded';

    function expect($ok, string $reason): void { if (!$ok) { throw new \RuntimeException($reason); } }
    function same($expected, $actual, string $reason): void {
        expect($expected === $actual, $reason . ': ' . var_export($actual, true));
    }
    function uid(int $n): string { return sprintf('00000000-0000-0000-0000-%012d', $n); }
    function get_config($plugin, $key) { return $GLOBALS['version']; }
    function get_enrolled_users($context, $capability, $group, $fields, $sort, $from, $limit, $active) {
        same([6, 0, 'u.*', null, 0, 0, true], [$context->id, $group, $fields, $sort, $from, $limit, $active],
            'exact0835 unlimited active enrolment helper');
        $GLOBALS['rolesread'][] = $capability;
        if ($GLOBALS['enrolerror']) { throw new \RuntimeException('Enrolment read failed'); }
        $users = [];
        foreach ($GLOBALS['eligible'][$capability] as $id) {
            if (!in_array($id, $GLOBALS['inactive'], true)) {
                $users[] = $GLOBALS['DB']->rows['user'][$id] ?? (object)['id' => $id, 'deleted' => 0];
            }
        }
        return $users;
    }
    class snapshot_db {
        public $rows = [];
        public $writes = 0;
        public $guardcourse = true;
        public $guardgroup = true;
        public $guarderror = false;
        public function snapshot() { return serialize([$this->rows, $this->writes]); }
        public function get_manager() { return $this; }
        public function table_exists($name) { return true; }
        public function get_records($table, $where = [], ...$args) {
            $rows = array_filter($this->rows[$table] ?? [], static function($row) use ($where) {
                foreach ($where as $key => $value) {
                    if (($row->$key ?? null) != $value) { return false; }
                }
                return true;
            });
            return array_map(static fn($row) => clone $row, $rows);
        }
        public function get_record($table, $where) { $rows = $this->get_records($table, $where); return reset($rows) ?: false; }
        public function record_exists($table, $where) {
            if ($table === 'local_teamscsv_map') {
                if ($this->guarderror) { throw new \RuntimeException('Registry read failed'); }
                if (!$this->guardcourse) { return false; }
            }
            return (bool)$this->get_record($table, $where);
        }
        public function record_exists_sql($sql, $params) {
            if ($this->guarderror) { throw new \RuntimeException('Registry read failed'); }
            return $this->guardgroup;
        }
        public function get_records_select($table, $sql, $params, ...$args) {
            same('local_o365_objects', $table, 'identity collision table');
            same('type = :type AND LOWER(objectid) = :objectid', $sql, 'case-independent identity collision query');
            return array_filter($this->get_records($table, ['type' => $params['type']]),
                static fn($row) => strtolower($row->objectid) === $params['objectid']);
        }
        public function insert_record(...$args) { $this->writes++; throw new \RuntimeException('Unexpected write'); }
        public function update_record(...$args) { $this->writes++; throw new \RuntimeException('Unexpected write'); }
    }

    // Reuse the existing seam's exact-context artifact derivation without executing its test runner.
    // Its function has no dependencies except expect(), the ZIP and patch mounts.
    $seam = file_get_contents(__DIR__ . '/import_seam_standalone.php');
    $start = strpos($seam, '    function artifact_sources(): array {');
    $end = strpos($seam, "\n    \$CFG =", $start);
    expect($start !== false && $end !== false, 'existing artifact fixture unavailable');
    eval(substr($seam, $start, $end - $start));
    [$original, $patched] = artifact_sources();
    $CFG = (object)['dirroot' => '/scratch', 'libdir' => '/scratch/lib'];
    foreach (['lib/enrollib.php', 'auth/oidc/lib.php', 'local/o365/lib.php'] as $stub) {
        $path = '/scratch/' . $stub;
        if (!is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
        file_put_contents($path, '<?php // Offline bootstrap double.');
    }
    foreach ($patched as $name => $source) {
        $path = '/scratch/local/o365/classes/' . ($name === 'o365api.php' ? 'rest/' : 'feature/coursesync/') . $name;
        if (!is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
        file_put_contents($path, $source);
    }
    $zip = new \PharData('/artifacts/o365.zip');
    foreach (['httpclientinterface.php', 'oauth2/token.php', 'oauth2/apptoken.php'] as $name) {
        eval(substr($zip['moodle-local_o365-5.0.7/classes/' . $name]->getContent(), 5));
    }
    \local_o365\install_httpclient();
    eval(substr($patched['o365api.php'], 5));
    eval(substr($zip['moodle-local_o365-5.0.7/classes/rest/unified.php']->getContent(), 5));
    eval(substr($patched['utils.php'], 5)); // Actual patched0835 helpers and isolation methods, not invented doubles.
    foreach (['course_links', 'membership_store', 'membership_guard', 'membership_plan'] as $name) {
        require_once __DIR__ . '/../classes/' . $name . '.php';
    }
    if (is_file(__DIR__ . '/../classes/membership_snapshot.php')) {
        require_once __DIR__ . '/../classes/membership_snapshot.php';
    }
    use local_teamscsv\membership_plan;
    use local_teamscsv\membership_snapshot;
    use local_teamscsv\membership_store;

    function localobject(int $id, int $user, string $object): object {
        return (object)['id' => $id, 'type' => 'user', 'moodleid' => $user, 'objectid' => $object];
    }
    function history(int $id, int $user, string $object, int $generation = 10, int $released = 0): object {
        return (object)['id' => $id, 'generationid' => $generation, 'userid' => $user, 'objectid' => $object,
            'role' => 'member', 'released' => $released, 'confirmedat' => 100];
    }
    function setup(): array {
        global $DB;
        $DB = new snapshot_db();
        $DB->rows = [
            'course' => [6 => (object)['id' => 6]],
            'user' => [21 => (object)['id' => 21, 'deleted' => 0], 22 => (object)['id' => 22, 'deleted' => 0],
                23 => (object)['id' => 23, 'deleted' => 1], 24 => (object)['id' => 24, 'deleted' => 0]],
            'local_o365_objects' => [
                1 => (object)['id' => 1, 'type' => 'group', 'subtype' => 'course', 'moodleid' => 6, 'objectid' => TEAM],
                2 => (object)['id' => 2, 'type' => 'group', 'subtype' => 'courseteam', 'moodleid' => 6, 'objectid' => TEAM],
                3 => localobject(3, 21, uid(1)), 4 => localobject(4, 22, uid(2)),
            ],
            'local_teamscsv_map' => [1 => (object)['id' => 1, 'courseid' => 6, 'generationid' => 10]],
            'local_teamscsv_generation' => [10 => (object)['id' => 10, 'courseid' => 6, 'teamid' => TEAM,
                'revision' => str_repeat('a', 64)]],
            'local_teamscsv_managed' => [1 => history(1, 23, uid(3)), 2 => history(2, 99, uid(7), 9),
                3 => history(3, 99, uid(6), 10, 1)],
        ];
        $GLOBALS['version'] = 2025040835;
        $GLOBALS['CFG']->dirroot = '/scratch';
        $GLOBALS['eligible'] = ['local/o365:teamowner' => [21], 'local/o365:teammember' => [21, 22, 23, 24]];
        $GLOBALS['inactive'] = [24];
        $GLOBALS['enrolerror'] = false;
        $GLOBALS['rolesread'] = $GLOBALS['gets'] = [];
        $GLOBALS['externalstate'] = null;
        $GLOBALS['remote'] = [base('owners') => page([8]), base('members') => page([9, 3])];
        $GLOBALS['afterget'] = null;
        return (new membership_store(new \stdClass()))->current(6);
    }
    function base(string $role): string { return '/groups/' . TEAM . '/' . $role . '?$top=999'; }
    function nexturl(string $role, string $query = '$skiptoken=next'): string {
        return 'https://graph.microsoft.com/v1.0/groups/' . TEAM . '/' . $role . '?' . $query;
    }
    function page(array $ids, $next = false): array {
        $body = ['value' => array_map(static fn($id) => ['id' => uid($id), '@odata.type' => '#microsoft.graph.user'], $ids)];
        if ($next !== false) { $body['@odata.nextLink'] = $next; }
        return raw(json_encode($body, JSON_THROW_ON_ERROR));
    }
    function raw($body, $status = 200): array { return ['status' => $status, 'ok' => true, 'body' => $body]; }
    function reader(int $limit = 20): membership_snapshot {
        return new membership_snapshot(static function($path) {
            $GLOBALS['gets'][] = $path;
            expect(count($GLOBALS['gets']) <= 20, 'unexpectedly high GET count');
            $response = $GLOBALS['remote'][$path] ?? null;
            if ($GLOBALS['afterget']) { ($GLOBALS['afterget'])($path); }
            return $response;
        }, new membership_store(new \stdClass()), $limit);
    }
    function observe(callable $call): array {
        ob_start();
        try { $value = $call(); $error = null; }
        catch (\Throwable $e) { $value = null; $error = [get_class($e), $e->getMessage()]; }
        return [$value, $error, ob_get_clean()];
    }
    function readok(array $mapping, bool $plan = false, int $limit = 20): array {
        $before = $GLOBALS['DB']->snapshot();
        [$value, $error, $stdout] = observe(fn() => $plan ? reader($limit)->plan($mapping) : reader($limit)->read($mapping));
        same([null, ''], [$error, $stdout], 'successful silent public API');
        same($before, $GLOBALS['DB']->snapshot(), 'read/plan changed stored rows or counters');
        return $value;
    }
    function rejected(array $mapping, string $message, int $requests = 0, string $class = \RuntimeException::class,
            int $limit = 20): void {
        $before = $GLOBALS['DB']->snapshot();
        same([null, [$class, $message], ''], observe(fn() => reader($limit)->plan($mapping)), 'exact silent rejection');
        same($GLOBALS['externalstate'] ?? $before, $GLOBALS['DB']->snapshot(), 'rejection changed rows or counters');
        same($requests, count($GLOBALS['gets']), 'rejection GET count');
    }

    $groups = [
        'active exact0835 roles, owner precedence, outsiders and planner preservation' => function() {
            $mapping = setup(); $input = readok($mapping);
            same(['local/o365:teamowner', 'local/o365:teammember', 'local/o365:teamowner', 'local/o365:teammember'],
                $GLOBALS['rolesread'], 'same complete identity scan revalidated after remote reads');
            same(['mapping' => $mapping, 'verifiedmapping' => $mapping,
                'desired' => [uid(1) => 'owner', uid(2) => 'member'], 'userids' => [uid(1) => 21, uid(2) => 22],
                'managed' => [uid(3)],
                'owners' => [uid(8)], 'members' => [uid(9), uid(3)],
                'complete' => ['participants' => true, 'identities' => true, 'history' => true,
                    'owners' => true, 'members' => true]], $input, 'complete validated input');
            same([base('owners'), base('members')], $GLOBALS['gets'], 'bounded initial endpoints');
            $GLOBALS['gets'] = [];
            $plan = readok($mapping, true);
            same(membership_plan::build($input), $plan, 'existing pure planner reused');
            same([['action' => 'add_member', 'id' => uid(1)], ['action' => 'add_member', 'id' => uid(2)],
                ['action' => 'add_owner', 'id' => uid(1)], ['action' => 'remove_member', 'id' => uid(3)]],
                $plan['operations'], 'initial roles and managed departure only');
            same(['owners' => [uid(8), uid(1)], 'members' => [uid(9), uid(1), uid(2)]], $plan['predicted'], 'outsiders intact');
        },
        'eligible identities missing, duplicate, deleted, noncanonical or colliding fail before GET' => function() {
            foreach (['missing', 'duplicate', 'badid', 'uppercase', 'wrongtype', 'nouser', 'duplicateuser',
                    'crossuser', 'outsidecollision', 'casecollision'] as $case) {
                $mapping = setup(); $objects = &$GLOBALS['DB']->rows['local_o365_objects'];
                switch ($case) {
                    case 'missing': unset($objects[3]); break;
                    case 'duplicate': $objects[5] = localobject(5, 21, uid(4)); break;
                    case 'badid': $objects[3]->objectid = 'not-a-uuid'; break;
                    case 'uppercase': $objects[3]->objectid = strtoupper(OTHER); break;
                    case 'wrongtype': $objects[3]->type = 'group'; break;
                    case 'nouser': unset($GLOBALS['DB']->rows['user'][21]); break;
                    case 'duplicateuser': $GLOBALS['DB']->rows['user'][99] = clone $GLOBALS['DB']->rows['user'][21]; break;
                    case 'crossuser': $objects[4]->objectid = uid(1); break;
                    case 'outsidecollision': $objects[5] = localobject(5, 99, uid(1)); break;
                    case 'casecollision': $objects[3]->objectid = OTHER; $objects[5] = localobject(5, 99, strtoupper(OTHER)); break;
                }
                rejected($mapping, IDENTITY);
            }
            foreach (['local/o365:teamowner', 'local/o365:teammember'] as $capability) {
                foreach ([[21, 21], [0]] as $invalid) {
                    $mapping = setup(); $GLOBALS['eligible'][$capability] = $invalid;
                    rejected($mapping, 'Invalid course participants');
                }
            }
            $mapping = setup();
            $GLOBALS['DB']->rows['user'][21]->id = '21';
            $GLOBALS['DB']->rows['local_o365_objects'][3]->moodleid = '21';
            $GLOBALS['DB']->rows['local_teamscsv_managed'][1]->userid = '23';
            same([uid(1) => 'owner', uid(2) => 'member'], readok($mapping)['desired'], 'Moodle DML integer strings');
        },
        'generation-scoped history retains old identities and rejects conflicting responsibility' => function() {
            $mapping = setup();
            $GLOBALS['DB']->rows['local_teamscsv_managed'][4] = history(4, 21, uid(4));
            same([uid(3), uid(4)], readok($mapping)['managed'], 'only current active generation, including old user identity');
            foreach (['duplicate', 'badid', 'baduser', 'crossdesired', 'reassigned'] as $case) {
                $mapping = setup(); $rows = &$GLOBALS['DB']->rows['local_teamscsv_managed'];
                if ($case === 'duplicate') { $rows[4] = history(4, 99, uid(3)); }
                if ($case === 'badid') { $rows[1]->objectid = 'bad'; }
                if ($case === 'baduser') { $rows[1]->userid = 0; }
                if ($case === 'crossdesired') { $rows[1]->objectid = uid(1); }
                if ($case === 'reassigned') { $GLOBALS['DB']->rows['local_o365_objects'][5] = localobject(5, 99, uid(3)); }
                rejected($mapping, HISTORY);
            }
        },
        'both complete paginated sets include unrelated users and normalize only pagination encoding' => function() {
            $mapping = setup();
            $GLOBALS['remote'][base('owners')] = page([8], nexturl('owners', '%24top=999&%24skiptoken=a%2Bb%2F%3D'));
            $GLOBALS['remote']['/groups/' . TEAM . '/owners?$skiptoken=a%2Bb%2F%3D&$top=999'] = page([1]);
            $GLOBALS['remote'][base('members')] = page([9, 1], nexturl('members'));
            $GLOBALS['remote']['/groups/' . TEAM . '/members?$skiptoken=next'] = page([2, 3]);
            $input = readok($mapping);
            same([uid(8), uid(1)], $input['owners'], 'all owner pages');
            same([uid(9), uid(1), uid(2), uid(3)], $input['members'], 'all member pages');
            same(4, count($GLOBALS['gets']), 'exact page requests');
            $mapping = setup();
            $GLOBALS['remote'][base('owners')] = raw(json_encode(['value' => [], '@odata.count' => 1,
                '@odata.context' => 'https://graph.microsoft.com/v1.0/$metadata#directoryObjects',
                '@odata.nextLink' => nexturl('owners')]));
            $GLOBALS['remote']['/groups/' . TEAM . '/owners?$skiptoken=next'] = raw(json_encode([
                'value' => [['id' => uid(8), '@odata.type' => '#microsoft.graph.user', 'displayName' => 'Escaped " quote \\ slash']],
                '@odata.count' => 1]));
            same([uid(8)], readok($mapping)['owners'], 'counted pagination and ordinary escaped record properties');
        },
        'strict HTTP and JSON evidence never treats invalid or empty responses as an empty Team' => function() {
            foreach ([null, false, [], ['body' => '{"value":[]}'], ['status' => 200, 'body' => '{"value":[]}'],
                    ['status' => 200, 'ok' => false, 'body' => '{"value":[]}'],
                    ['status' => 200, 'ok' => 1, 'body' => '{"value":[]}'], raw('{"value":[]}', '200'),
                    raw('{"value":[]}', 202), raw('{"value":[]}', 204), raw('{"value":[]}', 301),
                    raw('{"value":[]}', 403), raw('{"value":[]}', 429), raw('{"value":[]}', 500),
                    raw(null), raw([]), raw(''), raw('null'), raw('[]'), raw('{}'), raw('{'),
                    raw('{"value":{}}'), raw('{"value":null}'), raw('{"value":[],"error":null}'),
                    raw('{"value":[],"odata.error":{}}'), raw('{"value":[],"odata.nextLink":"missing"}'),
                    raw('{"value":[],"@odata.deltaLink":"unexpected"}'), raw('{"value":[],"@odata.count":"0"}'),
                    raw('{"value":[],"value":[]}'), raw('{"value":[],"v\\u0061lue":[]}'),
                    raw('{"value":[],"@odata.nextLink":null}'), raw('{"value":[],"@odata.context":false}')
                    ] as $response) {
                $mapping = setup(); $GLOBALS['remote'][base('owners')] = $response;
                rejected($mapping, PAYLOAD, 1);
            }
            $mapping = setup();
            $GLOBALS['remote'][base('owners')] = raw('{"value":[],"@odata.count":1}');
            rejected($mapping, PAYLOAD, 1);
            foreach ([str_repeat(' ', 4194305), '{"value":' . str_repeat('[', 32) . '0' . str_repeat(']', 32) . '}',
                    json_encode(['value' => array_fill(0, 1000, ['id' => uid(8), '@odata.type' => '#microsoft.graph.user'])])]
                    as $oversized) {
                $mapping = setup(); $GLOBALS['remote'][base('owners')] = raw($oversized);
                rejected($mapping, PAYLOAD, 1);
            }
            $mapping = setup(); $GLOBALS['remote'][base('owners')] = raw(json_encode([
                'value' => [], '@odata.count' => 2, '@odata.nextLink' => nexturl('owners')]));
            $GLOBALS['remote']['/groups/' . TEAM . '/owners?$skiptoken=next'] = raw('{"value":[],"@odata.count":1}');
            rejected($mapping, PAYLOAD, 2);
        },
        'every remote record is a canonical unambiguous user and duplicate IDs are rejected' => function() {
            $valid = ['id' => uid(8), '@odata.type' => '#microsoft.graph.user'];
            foreach ([null, uid(8), [], ['id' => uid(8)], ['@odata.type' => '#microsoft.graph.user'],
                    array_merge($valid, ['id' => 7]), array_merge($valid, ['id' => 'bad']),
                    array_merge($valid, ['id' => strtoupper(OTHER)]),
                    array_merge($valid, ['@odata.type' => '#microsoft.graph.group']),
                    array_merge($valid, ['@odata.type' => '#microsoft.graph.servicePrincipal'])] as $record) {
                $mapping = setup(); $GLOBALS['remote'][base('owners')] = raw(json_encode(['value' => [$record]]));
                rejected($mapping, PAYLOAD, 1);
            }
            foreach (['owners', 'members'] as $role) {
                $mapping = setup(); $GLOBALS['remote'][base($role)] = page([8, 8]);
                rejected($mapping, PAYLOAD, $role === 'owners' ? 1 : 2);
                $mapping = setup(); $GLOBALS['remote'][base($role)] = page([8], nexturl($role));
                $GLOBALS['remote']['/groups/' . TEAM . '/' . $role . '?$skiptoken=next'] = page([8]);
                rejected($mapping, PAYLOAD, $role === 'owners' ? 2 : 3);
            }
            $mapping = setup();
            $GLOBALS['remote'][base('owners')] = raw('{"value":[{"id":"' . uid(8) . '","id":"' . uid(1) .
                '","@odata.type":"#microsoft.graph.user"}]}');
            rejected($mapping, PAYLOAD, 1);
        },
        'nextLink host, version, exact team and role prevent SSRF or endpoint changes' => function() {
            $good = nexturl('owners');
            foreach ([str_replace('https:', 'http:', $good), str_replace('graph.microsoft.com', 'evil.example', $good),
                    str_replace('graph.microsoft.com', 'graph.microsoft.com.evil.example', $good),
                    str_replace('graph.microsoft.com', 'user@graph.microsoft.com', $good),
                    str_replace('graph.microsoft.com', 'graph.microsoft.com:443', $good), $good . '#fragment',
                    str_replace('/v1.0/', '/beta/', $good), str_replace(TEAM, OTHER, $good),
                    str_replace('/owners?', '/members?', $good), str_replace('/owners?', '/owners/extra?', $good),
                    str_replace('/owners?', '/%6fwners?', $good), '/v1.0/groups/' . TEAM . '/owners?$skiptoken=x',
                    str_replace('https:', '', $good), $good . "\n", str_replace('/v1.0', '\\v1.0', $good),
                    nexturl('owners', '$skiptoken=' . str_repeat('a', 8200))] as $url) {
                $mapping = setup(); $GLOBALS['remote'][base('owners')] = page([], $url);
                rejected($mapping, NEXTLINK, 1);
            }
        },
        'nextLink permits only one bounded pagination token and optional valid page size' => function() {
            foreach (['', '$top=999', '$skip=2', '$skiptoken=', '$skiptoken=x&$skiptoken=y',
                    '$skiptoken=x&%24skiptoken=y', '$skiptoken=x&$top=0', '$skiptoken=x&$top=1000',
                    '$skiptoken=x&$top=01', '$skiptoken=x&$top=2&$top=3', '$skiptoken=x&$filter=id',
                    '$skiptoken=x&$select=id', '$skiptoken=x&$expand=members', '$skiptoken=x&$count=true',
                    '$skiptoken=x&&$top=2', '$skiptoken=x&', '$skiptoken=%ZZ', '$skiptoken=%00',
                    '$skiptoken=%0d%0a', '$skiptoken=' . str_repeat('a', 4097)] as $query) {
                $mapping = setup(); $GLOBALS['remote'][base('owners')] = page([], nexturl('owners', $query));
                rejected($mapping, NEXTLINK, 1);
            }
        },
        'pagination cycles and global hard cap fail closed before excess fetches' => function() {
            $mapping = setup();
            $GLOBALS['remote'][base('owners')] = page([], nexturl('owners', '$skiptoken=a'));
            $GLOBALS['remote']['/groups/' . TEAM . '/owners?$skiptoken=a'] = page([], nexturl('owners', '%24skiptoken=%61'));
            rejected($mapping, NEXTLINK, 2);
            foreach ([0, 1, 21, 100] as $limit) {
                $mapping = setup(); rejected($mapping, 'Invalid membership page limit', 0, \InvalidArgumentException::class, $limit);
            }
            $mapping = setup();
            $GLOBALS['remote'][base('owners')] = page([], nexturl('owners', '$skiptoken=p1'));
            for ($i = 1; $i <= 20; $i++) {
                $GLOBALS['remote']['/groups/' . TEAM . '/owners?$skiptoken=p' . $i] =
                    page([], nexturl('owners', '$skiptoken=p' . ($i + 1)));
            }
            rejected($mapping, LIMIT, 20);
            $mapping = setup();
            $GLOBALS['remote'][base('members')] = page([], nexturl('members'));
            rejected($mapping, LIMIT, 2, \RuntimeException::class, 2);
            $mapping = setup();
            $GLOBALS['remote'][base('owners')] = page([], nexturl('owners', '$skiptoken=p1'));
            for ($i = 1; $i <= 18; $i++) {
                $GLOBALS['remote']['/groups/' . TEAM . '/owners?$skiptoken=p' . $i] = $i === 18 ? page([8]) :
                    page([], nexturl('owners', '$skiptoken=p' . ($i + 1)));
            }
            same([uid(8)], readok($mapping)['owners'], 'complete evidence at exact hard cap');
            same(20, count($GLOBALS['gets']), 'cap counts both complete collections');
        },
        'complete means both lists validated; explicit successful empty lists are allowed' => function() {
            $mapping = setup(); $GLOBALS['remote'][base('members')] = raw('');
            rejected($mapping, PAYLOAD, 2);
            $mapping = setup();
            $GLOBALS['eligible'] = ['local/o365:teamowner' => [], 'local/o365:teammember' => []];
            $GLOBALS['DB']->rows['local_teamscsv_managed'] = [];
            $GLOBALS['remote'] = [base('owners') => page([]), base('members') => page([])];
            $input = readok($mapping, false, 2);
            same([[], [], [], []], [$input['desired'], $input['managed'], $input['owners'], $input['members']], 'proven empty evidence');
            $GLOBALS['gets'] = [];
            same([], readok($mapping, true, 2)['operations'], 'proven empty plan');
            $mapping = setup(); $GLOBALS['enrolerror'] = true;
            rejected($mapping, 'Enrolment read failed');
        },
        'exact version, current registry and installed course/group isolation precede Graph' => function() {
            foreach (['version', 'courseid', 'teamid', 'revision', 'missingregistry', 'missingpair', 'duplicatepair',
                    'changedpair', 'guardabsent', 'courseguard', 'groupguard', 'guarderror'] as $case) {
                $mapping = setup(); $message = STALE; $class = \InvalidArgumentException::class;
                switch ($case) {
                    case 'version': $GLOBALS['version'] = 2025040836;
                        $message = 'This adapter requires local_o365 version 2025040835 exactly.'; $class = \RuntimeException::class; break;
                    case 'courseid': $mapping['courseid'] = '6'; break;
                    case 'teamid': $mapping['teamid'] = OTHER; break;
                    case 'revision': $mapping['revision'] = str_repeat('b', 64); break;
                    case 'missingregistry': $GLOBALS['DB']->rows['local_teamscsv_map'] = []; break;
                    case 'missingpair': unset($GLOBALS['DB']->rows['local_o365_objects'][2]); break;
                    case 'duplicatepair': $GLOBALS['DB']->rows['local_o365_objects'][5] = clone $GLOBALS['DB']->rows['local_o365_objects'][2]; break;
                    case 'changedpair': $GLOBALS['DB']->rows['local_o365_objects'][2]->objectid = OTHER; break;
                    case 'guardabsent': $GLOBALS['CFG']->dirroot = '/scratch/absent';
                        $message = 'Reviewed local_o365 membership isolation patch is required.'; $class = \RuntimeException::class; break;
                    case 'courseguard': $GLOBALS['DB']->guardcourse = false;
                        $message = 'Upstream membership isolation could not be verified.'; $class = \RuntimeException::class; break;
                    case 'groupguard': $GLOBALS['DB']->guardgroup = false;
                        $message = 'Upstream membership isolation could not be verified.'; $class = \RuntimeException::class; break;
                    case 'guarderror': $GLOBALS['DB']->guarderror = true;
                        $message = 'Registry read failed'; $class = \RuntimeException::class; break;
                }
                rejected($mapping, $message, 0, $class);
            }
        },
        'participant associations cannot change during remote reads; owners-last reads remain complete' => function() {
            $mapping = setup();
            $GLOBALS['afterget'] = static function($path) {
                if ($path === base('members')) {
                    $GLOBALS['DB']->rows['local_o365_objects'][3]->objectid = uid(5);
                    $GLOBALS['externalstate'] = $GLOBALS['DB']->snapshot();
                }
            };
            rejected($mapping, 'Course participants changed during membership read.', 2);
            $mapping = setup(); $before = $GLOBALS['DB']->snapshot();
            [$input, $error, $output] = observe(fn() => reader()->read($mapping, true));
            same([null, ''], [$error, $output], 'strict complete owners-last snapshot');
            same([[uid(8)], [uid(9), uid(3)]], [$input['owners'], $input['members']], 'both collections still complete');
            same([base('members'), base('owners')], $GLOBALS['gets'], 'owners are the last remote read');
            same($before, $GLOBALS['DB']->snapshot(), 'owners-last reread changed state');
        },
        'fresh registry/live mapping and isolation are checked again after all reads' => function() {
            foreach (['revision', 'pair', 'pointer', 'guard', 'patch', 'version'] as $case) {
                $mapping = setup();
                $GLOBALS['afterget'] = static function($path) use ($case) {
                    if ($path !== base('members')) { return; }
                    if ($case === 'revision') { $GLOBALS['DB']->rows['local_teamscsv_generation'][10]->revision = str_repeat('b', 64); }
                    if ($case === 'pair') { $GLOBALS['DB']->rows['local_o365_objects'][2]->objectid = OTHER; }
                    if ($case === 'pointer') { $GLOBALS['DB']->rows['local_teamscsv_map'][1]->generationid = 11; }
                    if ($case === 'guard') { $GLOBALS['DB']->guardgroup = false; }
                    if ($case === 'patch') { $GLOBALS['CFG']->dirroot = '/scratch/absent'; }
                    if ($case === 'version') { $GLOBALS['version'] = 2025040836; }
                    $GLOBALS['externalstate'] = $GLOBALS['DB']->snapshot();
                };
                $message = match ($case) {
                    'guard' => 'Upstream membership isolation could not be verified.',
                    'patch' => 'Reviewed local_o365 membership isolation patch is required.',
                    'version' => 'This adapter requires local_o365 version 2025040835 exactly.', default => STALE,
                };
                rejected($mapping, $message, 2, in_array($case, ['guard', 'patch', 'version']) ? \RuntimeException::class : \InvalidArgumentException::class);
            }
        },
    ];
    $failures = 0;
    foreach ($groups as $name => $group) {
        try { $group(); echo 'PASS ' . $name . "\n"; }
        catch (\Throwable $e) { $failures++; echo 'FAIL ' . $name . ': ' . $e->getMessage() . "\n"; }
    }
    exit($failures ? 1 : 0);
}
