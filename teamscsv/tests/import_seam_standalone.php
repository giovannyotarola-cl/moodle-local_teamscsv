<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Public seam doubles only. Mount /plugin and the three /artifacts inputs read-only; /scratch is Docker tmpfs.
namespace core\lock {
    class lock_config {
        public static $factories = [];
        public static function get_lock_factory($name) { return self::$factories[$name] ??= new \seam_locks(); }
    }
}
namespace core\task {
    class adhoc_task {
        private $data;
        public function set_custom_data($data) { $this->data = (object)$data; }
        public function get_custom_data() { return $this->data; }
        public function set_userid($id) {}
    }
    class manager {
        public static function queue_adhoc_task($task) {
            $GLOBALS['DB']->insert_record('task_adhoc', (object)['data' => $task->get_custom_data()]);
        }
    }
}
namespace local_o365 {
    class utils {
        public static function is_configured() { return $GLOBALS['config']['configured']; }
        public static function is_connected() { $GLOBALS['calls'][] = 'oauth'; return true; }
    }
}
namespace local_o365\rest {
    class unified {
        // Callable capability double only; native transport behavior is tested against the exact patched class.
        public function managed_membership_request(string $method, string $path, array $data = [], array $options = []): array {
            throw new \RuntimeException('Unexpected managed transport call');
        }
    }
}
namespace local_o365\feature\coursesync {
    class utils {
        public const MANAGED_MEMBERSHIP_GUARD_VERSION = 1;
        public static function is_enabled() { return $GLOBALS['config']['enabled']; }
        public static function get_team_owner_object_ids_by_course_id($id) { return $GLOBALS['config']['owners'][$id] ?? []; }
        public static function set_course_sync_enabled($id, $enabled) {
            $GLOBALS['DB']->insert_record('config_plugins', (object)['courseid' => $id, 'enabled' => $enabled]);
        }
        public static function is_course_sync_enabled($id) {
            return $GLOBALS['DB']->record_exists('config_plugins', ['courseid' => $id, 'enabled' => true]);
        }
        public static function is_membership_managed($id = 0, $teamid = '') {
            if ($GLOBALS['guarderror']) { throw new \RuntimeException('Registry read failed'); }
            if (!$GLOBALS['guardresult']) { return false; }
            if ($id) { return $GLOBALS['DB']->record_exists('local_teamscsv_map', ['courseid' => $id]); }
            if ($GLOBALS['guardresult'] === 'course-only') { return false; }
            return $GLOBALS['DB']->record_exists('local_teamscsv_generation', ['teamid' => $teamid]);
        }
        public static function get_graphclient() { $GLOBALS['calls'][] = 'client'; return new \stdClass(); }
    }
    class main {
        public function __construct($client) {}
        public function process_course_team_user_sync_from_moodle_to_microsoft(...$args) {
            $GLOBALS['calls'][] = 'upstream'; return true;
        }
        public function install_moodle_app_in_team(...$args) { $GLOBALS['calls'][] = 'app'; }
    }
}
namespace {
    define('MOODLE_INTERNAL', true);
    define('SITEID', 1);
    define('TEAM_UNLOCKED', 2);
    define('TEAM_LOCKED_STATUS_UNKNOWN', 0);
    const TEAM = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    const OTHER = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
    const PATCH_ERROR = 'Reviewed local_o365 membership isolation patch is required.';
    const EXCLUSION_ERROR = 'Upstream membership isolation could not be verified.';
    const STAGED = 'CSV membership staged; executor unavailable. No Teams changes performed.';

    class required_capability_exception extends \RuntimeException {
        public $errorcode = 'nopermissions';
        public function __construct(...$args) { parent::__construct('nopermissions'); }
    }
    class context_system { public static function instance() { return new self(); } }
    function is_siteadmin() { return $GLOBALS['admin']; }
    function require_capability(...$args) {
        if (!$GLOBALS['capable']) { throw new required_capability_exception(); }
    }
    function get_admin() { return (object)['id' => 2]; }
    function get_config($plugin, $key) { return $GLOBALS['config'][$key] ?? false; }
    function mtrace($text) { echo $text . "\n"; }
    function expect($condition, $reason) { if (!$condition) { throw new \RuntimeException($reason); } }

    class seam_transaction {
        private $before;
        public function __construct(private $db) { $this->before = $db->snapshot(); }
        public function allow_commit() {
            if ($this->db->beforecommit) { ($this->db->beforecommit)(); }
            if ($this->db->failcommit) { throw new \RuntimeException('Injected commit failure'); }
            $this->db->depth--;
        }
        public function rollback($error) {
            $this->db->restore($this->before); $this->db->depth--; throw $error;
        }
    }
    class seam_db {
        public $rows = [];
        public $writes = 0;
        public $depth = 0;
        public $failtable;
        public $failcommit = false;
        public $beforecommit;
        public function snapshot() { return serialize([$this->rows, $this->writes]); }
        public function restore($snapshot) { [$this->rows, $this->writes] = unserialize($snapshot); }
        public function is_transaction_started() { return $this->depth > 0; }
        public function start_delegated_transaction() { $this->depth++; return new seam_transaction($this); }
        public function get_records($table, $where = [], ...$args) {
            $rows = array_filter($this->rows[$table] ?? [], static function($row) use ($where, $table) {
                foreach ($where as $key => $value) {
                    // Emulate a case-insensitive cache collation; importer must still match names exactly.
                    if ($table === 'local_o365_groups_cache' && $key === 'name') {
                        if (strcasecmp($row->$key, $value) !== 0) { return false; }
                    } else if (($row->$key ?? null) != $value) { return false; }
                }
                return true;
            });
            return array_map(static fn($row) => clone $row, $rows);
        }
        public function get_record($table, $where) { $rows = $this->get_records($table, $where); return reset($rows) ?: false; }
        public function record_exists($table, $where) { return (bool)$this->get_record($table, $where); }
        public function insert_record($table, $record) {
            if ($this->failtable === $table) { throw new \RuntimeException('Injected write failure'); }
            expect($this->depth === 1, 'write outside one owned transaction');
            $record = clone $record; $record->id = count($this->rows[$table] ?? []) + 1;
            $this->rows[$table][$record->id] = $record; $this->writes++;
            return $record->id;
        }
        public function update_record($table, $record) {
            if ($this->failtable === $table) { throw new \RuntimeException('Injected write failure'); }
            expect($this->depth === 1, 'update outside one owned transaction');
            foreach ((array)$record as $key => $value) { $this->rows[$table][$record->id]->$key = $value; }
            $this->writes++;
        }
    }
    class seam_lock {
        private $depth;
        public function __construct(private $factory, private $key) { $this->depth = $GLOBALS['DB']->depth; }
        public function release() {
            expect($GLOBALS['DB']->depth === $this->depth, 'lock released before actual commit/rollback');
            unset($this->factory->held[$this->key]);
        }
    }
    class seam_locks {
        public $held = [];
        public function get_lock($key, $timeout) {
            expect($timeout === 0, 'unbounded lock wait');
            if (isset($this->held[$key])) { return false; }
            $this->held[$key] = true;
            return new seam_lock($this, $key);
        }
    }

    /** Reviewed ZIP only, exact hunk offsets/context, no source extraction on the host. */
    function artifact_sources(): array {
        expect(hash_file('sha256', '/artifacts/o365.zip') ===
            '21584c98e162403426a2cd6c5ef1d178aae65cdbc5ee63138594c180b0c5fa2c', 'unapproved O365 ZIP');
        $zip = new \PharData('/artifacts/o365.zip');
        $base = [];
        foreach (['main.php' => 'feature/coursesync/main.php', 'utils.php' => 'feature/coursesync/utils.php',
                'o365api.php' => 'rest/o365api.php'] as $name => $path) {
            $base[$name] = $zip['moodle-local_o365-5.0.7/classes/' . $path]->getContent();
        }
        $patched = $base;
        $lines = explode("\n", file_get_contents('/artifacts/isolation.patch'));
        $i = 0; $targets = [];
        while ($i < count($lines) && $lines[$i] !== '') {
            expect(str_starts_with($lines[$i++], 'diff --git '), 'invalid patch header');
            expect(preg_match('~^--- a/classes/(feature/coursesync/(?:main|utils)\.php|rest/o365api\.php)$~',
                $lines[$i++], $m), 'invalid target');
            $name = basename($m[1]);
            expect(!isset($targets[$name]), 'duplicate patch target'); $targets[$name] = true;
            expect($lines[$i++] === '+++ b/classes/' . $m[1], 'mismatched target');
            $old = explode("\n", $base[$name]); $out = []; $cursor = 0;
            while (isset($lines[$i]) && str_starts_with($lines[$i], '@@ ')) {
                expect(preg_match('/^@@ -(\d+),(\d+) \+(\d+),(\d+) @@$/', $lines[$i++], $h), 'invalid hunk');
                expect((int)$h[1] - 1 >= $cursor, 'overlapping hunks');
                while ($cursor < (int)$h[1] - 1) { $out[] = $old[$cursor++]; }
                expect(count($out) === (int)$h[3] - 1, 'wrong new offset');
                $removed = $added = 0;
                while (isset($lines[$i]) && $lines[$i] !== '' && in_array($lines[$i][0], [' ', '+', '-'], true)) {
                    $line = $lines[$i++]; $kind = $line[0]; $value = substr($line, 1);
                    if ($kind !== '+') {
                        expect(($old[$cursor++] ?? null) === $value, 'upstream patch context drift'); $removed++;
                    }
                    if ($kind !== '-') { $out[] = $value; $added++; }
                }
                expect([$removed, $added] === [(int)$h[2], (int)$h[4]], 'wrong hunk size');
            }
            $patched[$name] = implode("\n", array_merge($out, array_slice($old, $cursor)));
        }
        expect(count($targets) === 3, 'incomplete patch');
        return [$base, $patched];
    }

    $CFG = (object)['dirroot' => '/scratch'];
    $root = '/scratch/local/o365/classes/feature/coursesync';
    if (!is_dir($root)) { mkdir($root, 0700, true); }
    file_put_contents('/scratch/local/o365/lib.php', '<?php // Offline bootstrap double.');
    [$original, $patched] = artifact_sources();
    function installed($sources): void {
        global $root;
        foreach ($sources as $name => $source) {
            $directory = $name === 'o365api.php' ? dirname(dirname($root)) . '/rest' : $root;
            if (!is_dir($directory)) { mkdir($directory, 0700, true); }
            file_put_contents($directory . '/' . $name, $source);
        }
    }
    function setup(): void {
        global $DB, $config, $calls, $admin, $capable, $guardresult, $guarderror, $patched;
        $GLOBALS['CFG']->dirroot = '/scratch';
        installed($patched);
        $DB = new seam_db();
        $DB->rows['course'] = [1 => (object)['id' => 1, 'visible' => 1],
            6 => (object)['id' => 6, 'visible' => 1, 'fullname' => 'Six'],
            7 => (object)['id' => 7, 'visible' => 1, 'fullname' => 'Seven']];
        $DB->rows['local_o365_groups_cache'] = [1 => (object)['id' => 1, 'name' => 'Team Six',
            'has_team' => 1, 'locked' => TEAM_UNLOCKED, 'not_found_since' => 0, 'objectid' => TEAM],
            2 => (object)['id' => 2, 'name' => 'Team Seven', 'has_team' => 1,
                'locked' => TEAM_UNLOCKED, 'not_found_since' => 0, 'objectid' => OTHER]];
        $config = ['version' => 2025040835, 'configured' => true, 'enabled' => true, 'courseusersyncdirection' => 1,
            'owners' => [6 => ['teacher'], 7 => ['teacher']], 'coursesync' => 'oncustom'];
        $admin = $capable = $guardresult = true; $guarderror = false; $calls = [];
        \core\lock\lock_config::$factories = [];
    }
    function rows($two = false): array {
        $rows = [['course_id' => 6, 'team_name' => 'Team Six']];
        if ($two) { $rows[] = ['course_id' => 7, 'team_name' => 'Team Seven']; }
        return $rows;
    }
    function state() { return [$GLOBALS['DB']->snapshot(), $GLOBALS['config'], $GLOBALS['calls']]; }
    function observe(callable $call): array {
        ob_start();
        try { $value = $call(); $error = null; }
        catch (\Throwable $e) { $value = null; $error = [get_class($e), $e->getMessage()]; }
        return [$value, $error, ob_get_clean()];
    }
    function silent(callable $call) {
        [$value, $error, $output] = observe($call);
        expect($error === null && $output === '', 'unexpected result: ' . json_encode([$error, $output]));
        return $value;
    }
    function rejected(callable $call, string $message, string $class = \RuntimeException::class): void {
        $before = state();
        expect(observe($call) === [null, [$class, $message], ''], 'wrong rejection for: ' . $message);
        expect(state() === $before, 'rejection changed rows, counters, configuration or Graph calls');
    }
    function unlocked(): void {
        expect($GLOBALS['DB']->depth === 0, 'transaction left open');
        foreach (\core\lock\lock_config::$factories as $factory) { expect($factory->held === [], 'lock leaked'); }
    }
    require_once '/plugin/classes/course_links.php';
    require_once '/plugin/classes/membership_store.php';
    if (is_file('/plugin/classes/membership_guard.php')) { require_once '/plugin/classes/membership_guard.php'; }
    require_once '/plugin/classes/task/sync_team.php';

    // One script compares actual ZIP405 source (in memory) and the candidate in separate PHP processes.
    // The known pre-existing unlocked-Team correction is applied explicitly, never attributed to this unit.
    if (isset($argv[1]) && str_starts_with($argv[1], '--observe=')) {
        $which = substr($argv[1], strlen('--observe='));
        if ($which === '405') {
            expect(hash_file('sha256', '/artifacts/teamscsv405.zip') ===
                'c724daaa239e6e4d93bbbdbeeafb7c1cc608d292631d7a90c033180b4921c6c0', 'unapproved 405 ZIP');
            $zip = new \PharData('/artifacts/teamscsv405.zip');
            $source = str_replace("\r", '', $zip['teamscsv/classes/importer.php']->getContent());
            $source = str_replace('if (!empty($team->locked) || !empty($team->not_found_since) ||',
                'if ((int)($team->locked ?? TEAM_LOCKED_STATUS_UNKNOWN) !== TEAM_UNLOCKED || !empty($team->not_found_since) ||',
                $source, $count);
            expect($count === 1, '405 unlocked-guard preimage drift');
            eval(substr($source, 5));
        } else { require_once '/plugin/classes/importer.php'; }
        $cases = [];
        foreach (['valid', 'version', 'configuration', 'disabled', 'direction', 'badid', 'nocourse', 'site', 'hidden',
                'missingteam', 'ambiguous', 'case', 'locked', 'unknown', 'notfound', 'invaliduuid',
                'coursemap', 'teammap', 'courserequest', 'teamrequest', 'noowner', 'duplicate'] as $case) {
            setup(); $input = rows();
            switch ($case) {
                case 'version': $config['version']++; break;
                case 'configuration': $config['configured'] = false; break;
                case 'disabled': $config['enabled'] = false; break;
                case 'direction': $config['courseusersyncdirection'] = 2; break;
                case 'badid': $input[0]['course_id'] = '06'; break;
                case 'nocourse': unset($DB->rows['course'][6]); break;
                case 'site': $input[0]['course_id'] = 1; break;
                case 'hidden': $DB->rows['course'][6]->visible = 0; break;
                case 'missingteam': $input[0]['team_name'] = 'absent'; break;
                case 'ambiguous': $DB->rows['local_o365_groups_cache'][3] = clone $DB->rows['local_o365_groups_cache'][1]; break;
                case 'case': $input[0]['team_name'] = 'team six'; break;
                case 'locked': $DB->rows['local_o365_groups_cache'][1]->locked = 1; break;
                case 'unknown': unset($DB->rows['local_o365_groups_cache'][1]->locked); break;
                case 'notfound': $DB->rows['local_o365_groups_cache'][1]->not_found_since = 10; break;
                case 'invaliduuid': $DB->rows['local_o365_groups_cache'][1]->objectid = 'invalid'; break;
                case 'coursemap': $DB->rows['local_o365_objects'] = [(object)['type' => 'group', 'moodleid' => 6]]; break;
                case 'teammap': $DB->rows['local_o365_objects'] = [(object)['type' => 'group', 'objectid' => TEAM]]; break;
                case 'courserequest': $DB->rows['local_o365_course_request'] = [(object)['courseid' => 6]]; break;
                case 'teamrequest': $DB->rows['local_o365_course_request'] = [(object)['teamoid' => TEAM]]; break;
                case 'noowner': $config['owners'] = []; break;
                case 'duplicate': $input[] = $input[0]; break;
            }
            $before = state();
            $cases[$case] = observe(fn() => \local_teamscsv\importer::preview($input));
            expect(state() === $before, 'preview mutated state');
        }
        echo json_encode($cases, JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    }
    require_once '/plugin/classes/importer.php';

    $groups = [
        'artifact fingerprints and read-only preview/apply precondition' => function() use ($original, $patched) {
            foreach (['original', 'main-original', 'utils-original', 'api-original',
                    'drift-main', 'drift-utils', 'drift-api', 'missing'] as $case) {
                setup(); $sources = $patched;
                if ($case === 'original') { $sources = $original; }
                if ($case === 'main-original') { $sources['main.php'] = $original['main.php']; }
                if ($case === 'utils-original') { $sources['utils.php'] = $original['utils.php']; }
                if ($case === 'api-original') { $sources['o365api.php'] = $original['o365api.php']; }
                if ($case === 'drift-api') { $sources['o365api.php'] .= "\n// local modification\n"; }
                if ($case === 'drift-main') { $sources['main.php'] .= "\n// local modification\n"; }
                if ($case === 'drift-utils') { $sources['utils.php'] .= "\n// local modification\n"; }
                installed($sources);
                if ($case === 'missing') {
                    if (!is_dir('/scratch/absent/local/o365')) { mkdir('/scratch/absent/local/o365', 0700, true); }
                    file_put_contents('/scratch/absent/local/o365/lib.php', '<?php // Bootstrap-only missing-class fixture.');
                    $GLOBALS['CFG']->dirroot = '/scratch/absent';
                }
                rejected(fn() => \local_teamscsv\importer::preview(rows()), PATCH_ERROR);
                rejected(fn() => \local_teamscsv\importer::apply(rows(), []), PATCH_ERROR);
                unlocked();
            }
            setup();
            $validutils = 'public const MANAGED_MEMBERSHIP_GUARD_VERSION = 1; ' .
                'public static function is_membership_managed() { return true; }';
            $contracts = [['', ''], ['public const MANAGED_MEMBERSHIP_GUARD_VERSION = 1;', ''],
                ['public static function is_membership_managed() { return true; }', ''],
                ['public const MANAGED_MEMBERSHIP_GUARD_VERSION = 2; public static function is_membership_managed() { return true; }', ''],
                [$validutils, '']];
            foreach (['protected', 'private', 'public static'] as $visibility) {
                $contracts[] = [$validutils, $visibility . ' function managed_membership_request() {}'];
            }
            foreach ($contracts as [$body, $transport]) {
                $code = 'define("MOODLE_INTERNAL", true); $CFG = (object)["dirroot" => "/scratch"]; ' .
                    'eval(' . var_export('namespace local_o365\\feature\\coursesync; class utils {' . $body . '}', true) . '); ' .
                    'eval(' . var_export('namespace local_o365\\rest; class unified {' . $transport . '}', true) . '); ' .
                    'require "/plugin/classes/membership_guard.php"; ' .
                    'try { \\local_teamscsv\\membership_guard::require_installed(); exit(1); } ' .
                    'catch (\\RuntimeException $e) { echo $e->getMessage(); }';
                $pipes = [];
                $process = proc_open([PHP_BINARY, '-r', $code],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                expect(is_resource($process), 'guard contract runner unavailable'); fclose($pipes[0]);
                $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
                expect([$exit, $stdout, $stderr] === [0, PATCH_ERROR, ''], 'missing/wrong guard contract accepted');
            }
            setup(); $before = state();
            expect(silent(fn() => \local_teamscsv\importer::preview(rows())) === [
                ['course_name' => 'Six', 'team_name' => 'Team Six', 'courseid' => 6, 'teamid' => TEAM]], 'preview changed');
            expect(state() === $before, 'preview wrote state or called OAuth/Graph');
        },
        'atomic multi-course registration, dirty queue and commit-held locks' => function() {
            setup(); $plan = silent(fn() => \local_teamscsv\importer::preview(rows(true)));
            $GLOBALS['DB']->beforecommit = function() {
                $factories = \core\lock\lock_config::$factories;
                expect(isset($factories['local_teamscsv']->held['import']), 'import lock missing at commit');
                foreach ([6, 7] as $id) {
                    expect(isset($factories['local_teamscsv_membership']->held['course-' . $id]), 'course lock missing at commit');
                    $mapping = (new \local_teamscsv\membership_store())->current($id);
                    expect(utils_guard($id, $mapping['teamid']), 'mapping visible without exclusion at commit');
                }
            };
            expect(silent(fn() => \local_teamscsv\importer::apply(rows(true), $plan)) === 2, 'wrong import count');
            $DB = $GLOBALS['DB']; $store = new \local_teamscsv\membership_store();
            expect(count($DB->rows['local_o365_objects'] ?? []) === 4 && count($DB->rows['config_plugins'] ?? []) === 2 &&
                count($DB->rows['local_teamscsv_dirty'] ?? []) === 2 && count($DB->rows['local_teamscsv_generation'] ?? []) === 2 &&
                empty($DB->rows['task_adhoc']) && $GLOBALS['calls'] === [], 'unsafe or missing queue effects');
            expect($store->managed($store->current(6)) === [] && $store->managed($store->current(7)) === [], 'import invented history');
            rejected(fn() => \local_teamscsv\importer::apply(rows(true), $plan),
                'Existing connection or course request; resolve manually: 6');
            unlocked();
        },
        'rollback of mappings, enablement, generation, pointer, history and dirty failure' => function() {
            foreach (['local_o365_objects', 'config_plugins', 'local_teamscsv_generation', 'local_teamscsv_map',
                    'local_teamscsv_dirty', 'commit', 'guard-false', 'guard-error'] as $failure) {
                setup(); $plan = silent(fn() => \local_teamscsv\importer::preview(rows(true)));
                $DB = $GLOBALS['DB'];
                // Include unrelated archived evidence; no failure may change it.
                $DB->rows['local_teamscsv_managed'] = [(object)['id' => 41, 'generationid' => 99, 'released' => 1]];
                if ($failure === 'commit') { $DB->failcommit = true; }
                else if ($failure === 'guard-false') { $GLOBALS['guardresult'] = 'course-only'; }
                else if ($failure === 'guard-error') { $GLOBALS['guarderror'] = true; }
                else { $DB->failtable = $failure; }
                $message = match ($failure) {
                    'commit' => 'Injected commit failure', 'guard-false' => EXCLUSION_ERROR,
                    'guard-error' => 'Registry read failed', default => 'Injected write failure',
                };
                rejected(fn() => \local_teamscsv\importer::apply(rows(true), $plan), $message);
                unlocked();
            }
        },
        'previous permissions, preview changes, unlocked guard and import/course contention' => function() {
            setup(); $plan = silent(fn() => \local_teamscsv\importer::preview(rows()));
            $GLOBALS['admin'] = false;
            rejected(fn() => \local_teamscsv\importer::apply(rows(), $plan), 'nopermissions', required_capability_exception::class);
            $GLOBALS['admin'] = true; $GLOBALS['capable'] = false;
            rejected(fn() => \local_teamscsv\importer::apply(rows(), $plan), 'nopermissions', required_capability_exception::class);
            $GLOBALS['capable'] = true; $plan[0]['course_name'] = 'changed';
            rejected(fn() => \local_teamscsv\importer::apply(rows(), $plan), 'Preview changed. Upload the CSV again before applying.');
            rejected(fn() => \local_teamscsv\importer::apply([['course_id' => 1, 'team_name' => 'Team Six']], []),
                'Course ID not found or is the site course: 1');
            rejected(fn() => \local_teamscsv\importer::apply(array_merge(rows(), rows()), []),
                'Multiple records resolve to the same course or Team.');
            expect(silent(fn() => \local_teamscsv\importer::apply([], [])) === 0, 'empty direct apply changed');
            foreach ([0, 1, null] as $locked) {
                $GLOBALS['DB']->rows['local_o365_groups_cache'][1]->locked = $locked;
                rejected(fn() => \local_teamscsv\importer::preview(rows()), 'Team is locked, unavailable, or has an invalid ID: Team Six');
            }
            setup(); $plan = silent(fn() => \local_teamscsv\importer::preview(rows(true)));
            $factory = \core\lock\lock_config::get_lock_factory('local_teamscsv');
            $lock = $factory->get_lock('import', 0);
            rejected(fn() => \local_teamscsv\importer::apply(rows(true), $plan), 'Another CSV import is running.');
            $lock->release();
            $factory = \core\lock\lock_config::get_lock_factory('local_teamscsv_membership');
            $lock = $factory->get_lock('course-7', 0);
            rejected(fn() => \local_teamscsv\importer::apply(rows(true), $plan), 'Membership storage busy');
            $lock->release(); unlocked();
            $outer = $GLOBALS['DB']->start_delegated_transaction();
            // A caller-owned transaction must not let the importer release its own locks early.
            $observed = observe(fn() => \local_teamscsv\importer::apply(rows(true), $plan));
            expect($observed === [null, [\RuntimeException::class, 'Membership storage requires its own transaction'], ''],
                'enclosing transaction accepted');
            $outer->allow_commit(); unlocked();
        },
        'old queued work and stale mappings never authorize a Graph call' => function() use ($original) {
            setup(); $plan = silent(fn() => \local_teamscsv\importer::preview(rows()));
            silent(fn() => \local_teamscsv\importer::apply(rows(), $plan));
            $mapping = (new \local_teamscsv\membership_store())->current(6);
            $valid = $GLOBALS['DB']->snapshot();
            foreach (['legacy', 'badtype', 'missingregistry', 'changedteam', 'livemapping', 'newgeneration', 'extrarow',
                    'staletoken', 'disabled', 'guard', 'guard-error', 'patch'] as $case) {
                $GLOBALS['DB']->restore($valid); $GLOBALS['guardresult'] = true; $GLOBALS['guarderror'] = false;
                installed($GLOBALS['patched']);
                $data = $mapping;
                $message = 'Unverified or stale mapping'; $class = \InvalidArgumentException::class;
                switch ($case) {
                    case 'legacy': unset($data['revision']); break;
                    case 'badtype': $data['courseid'] = '6'; break;
                    case 'missingregistry': $GLOBALS['DB']->rows['local_teamscsv_map'] = []; break;
                    case 'changedteam': $data['teamid'] = OTHER; break;
                    case 'livemapping':
                        foreach ($GLOBALS['DB']->rows['local_o365_objects'] as $row) { $row->objectid = OTHER; }
                        break;
                    case 'newgeneration': (new \local_teamscsv\membership_store())->register(6, TEAM, time()); break;
                    case 'extrarow': $GLOBALS['DB']->rows['local_o365_objects'][3] = clone $GLOBALS['DB']->rows['local_o365_objects'][1]; break;
                    case 'staletoken': $data['revision'] = str_repeat('f', 64); break;
                    case 'disabled': $GLOBALS['DB']->rows['config_plugins'] = [];
                        $message = 'Course sync disabled; refusing Microsoft operations.'; $class = \RuntimeException::class; break;
                    case 'guard': $GLOBALS['guardresult'] = 'course-only'; $message = EXCLUSION_ERROR; $class = \RuntimeException::class; break;
                    case 'guard-error': $GLOBALS['guarderror'] = true;
                        $message = 'Registry read failed'; $class = \RuntimeException::class; break;
                    case 'patch': installed($original); $message = PATCH_ERROR; $class = \RuntimeException::class; break;
                }
                $task = new \local_teamscsv\task\sync_team(); $task->set_custom_data($data);
                rejected(fn() => $task->execute(), $message, $class);
                unlocked();
            }
        },
        'staging is idempotent and immutable; no app/tab success or history adoption' => function() {
            setup(); $plan = silent(fn() => \local_teamscsv\importer::preview(rows()));
            silent(fn() => \local_teamscsv\importer::apply(rows(), $plan));
            $store = new \local_teamscsv\membership_store(); $mapping = $store->current(6);
            $task = new \local_teamscsv\task\sync_team(); $task->set_custom_data($mapping);
            $before = state();
            for ($i = 0; $i < 2; $i++) {
                expect(observe(fn() => $task->execute()) === [null, null, STAGED . "\n"], 'wrong staging output');
                expect(state() === $before && $store->current(6) === $mapping && $store->managed($mapping) === [],
                    'staging changed generation, queue, history, counters or Graph');
            }
        },
        'scheduler opt-in does not change existing import preview or staging behavior' => function() {
            $results = [];
            foreach ([false, true] as $enabled) {
                setup(); $GLOBALS['config']['membership_enabled'] = $enabled ? '1' : false;
                $plan = silent(fn() => \local_teamscsv\importer::preview(rows()));
                $count = silent(fn() => \local_teamscsv\importer::apply(rows(), $plan));
                $results[] = [$plan, $count, count($GLOBALS['DB']->rows['local_o365_objects']),
                    count($GLOBALS['DB']->rows['local_teamscsv_dirty']), $GLOBALS['calls'],
                    $GLOBALS['DB']->get_records('task_adhoc')];
                unlocked();
            }
            expect($results[0] === $results[1] && $results[0][1] === 1 && $results[0][4] === [] &&
                $results[0][5] === [], 'scheduler changed public import behavior or performed remote work');
        },
        'differential preview public interface against ZIP405 plus pre-existing unlocked correction' => function() {
            $outputs = [];
            foreach (['405', 'candidate'] as $which) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, __FILE__, '--observe=' . $which],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                expect(is_resource($process), 'differential runner unavailable'); fclose($pipes[0]);
                $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
                expect($exit === 0 && $stderr === '', 'differential fixture failed: ' . $stderr);
                $outputs[] = [$exit, $stdout, $stderr];
            }
            expect($outputs[0] === $outputs[1], 'unrequested preview difference');
            expect(count(json_decode($outputs[0][1], true, 512, JSON_THROW_ON_ERROR)) === 22, 'missing preview comparisons');
        },
    ];
    function utils_guard($courseid, $teamid) {
        return \local_o365\feature\coursesync\utils::is_membership_managed($courseid) &&
            \local_o365\feature\coursesync\utils::is_membership_managed(0, $teamid);
    }
    $failures = 0;
    foreach ($groups as $name => $group) {
        try { $group(); echo 'PASS ' . $name . "\n"; }
        catch (\Throwable $e) { $failures++; echo 'FAIL ' . $name . ': ' . $e->getMessage() . "\n"; }
    }
    exit($failures ? 1 : 0);
}
