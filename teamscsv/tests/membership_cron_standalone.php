<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Writer checks only: Moodle queue/transaction/lock doubles, never a live cron or Graph request.
namespace core\lock {
    class lock_config {
        public static $factory;
        public static function get_lock_factory($name) { return self::$factory; }
    }
}
namespace core\task {
    class adhoc_task {
        private $data;
        public function set_custom_data($data) { $this->data = (object)$data; }
        public function get_custom_data() { return $this->data; }
    }
    class scheduled_task extends adhoc_task {}
    class manager {
        public static function queue_adhoc_task($task, $deduplicate = false) {
            \expect($deduplicate === true, 'queue must request core deduplication');
            $data = $task->get_custom_data();
            foreach ($GLOBALS['DB']->get_records('task_adhoc') as $row) {
                if ($row->data == $data) { return false; }
            }
            return $GLOBALS['DB']->insert_record('task_adhoc', (object)['data' => $data]);
        }
    }
}
namespace local_teamscsv\task {
    function time() { return 100; }
}
namespace local_o365\feature\coursesync {
    class utils {
        public const MANAGED_MEMBERSHIP_GUARD_VERSION = 1;
        public static function is_enabled() { return $GLOBALS['o365enabled']; }
        public static function is_course_sync_enabled($id) { return $GLOBALS['courseenabled']; }
        public static function is_membership_managed($id = 0, $teamid = '') {
            return $GLOBALS['isolated'] && ($id ? $GLOBALS['DB']->record_exists('local_teamscsv_map', ['courseid' => $id]) :
                $GLOBALS['DB']->record_exists('local_teamscsv_generation', ['teamid' => $teamid]));
        }
        public static function get_graphclient() { $GLOBALS['factories']++; return $GLOBALS['client']; }
        public static function get_team_owner_user_ids_by_course_id($id) { return []; }
        public static function get_team_member_user_ids_by_course_id($id) { return []; }
    }
}
namespace local_o365\rest {
    class unified {
        public static function get_tokenresource() { return 'https://graph.microsoft.com'; }
        public function managed_membership_request(string $verb, string $path, array $data = []): array {
            $GLOBALS['requests']++;
            $GLOBALS['requestlog'][] = [$verb, $path, $data];
            if ($verb !== 'GET') { $GLOBALS['operations']++; }
            if ($GLOBALS['onrequest']) { ($GLOBALS['onrequest'])(); }
            $body = ['value' => []];
            if ($GLOBALS['paginate']) {
                $body['@odata.nextLink'] = 'https://graph.microsoft.com/v1.0/groups/' . \TEAM .
                    '/owners?$skiptoken=page' . $GLOBALS['requests'];
            }
            return ['status' => $GLOBALS['httpstatus'], 'ok' => $GLOBALS['httpstatus'] === 200, 'body' => json_encode($body)];
        }
    }
}
namespace {
    // Reuse the existing public DML/XMLDB model and artifact derivation, not copied storage logic.
    $fixture = file_get_contents(__DIR__ . '/membership_store_standalone.php');
    $end = strpos($fixture, "\n\$team =");
    if ($end === false) { throw new \RuntimeException('Storage fixture boundary missing'); }
    eval(substr($fixture, 5, $end - 5));
    $seam = file_get_contents(__DIR__ . '/import_seam_standalone.php');
    $start = strpos($seam, '    function artifact_sources(): array {');
    $end = strpos($seam, "\n    \$CFG =", $start);
    expect($start !== false && $end !== false, 'Artifact fixture boundary missing');
    eval(substr($seam, $start, $end - $start));
    [, $patched] = artifact_sources();
    foreach ($patched as $name => $source) {
        $path = '/scratch/local/o365/classes/' . ($name === 'o365api.php' ? 'rest/' : 'feature/coursesync/') . $name;
        if (!is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
        file_put_contents($path, $source);
    }
    define('CONTEXT_COURSE', 50);
    define('PARAM_INT', 'int');
    function get_config($plugin, $key) { return $GLOBALS['config'][$plugin][$key] ?? false; }
    function get_string($key, $component) { return $GLOBALS['strings'][$key] ?? $key; }
    function mtrace($text) { echo $text . "\n"; }
    foreach (['membership_guard', 'membership_plan', 'membership_snapshot', 'membership_executor', 'membership_runner',
            'observers', 'task/mark_membership_dirty', 'task/reconcile_memberships'] as $name) {
        $path = __DIR__ . '/../classes/' . $name . '.php';
        if (is_file($path)) { require_once $path; }
    }
    use local_teamscsv\membership_runner;
    use local_teamscsv\membership_store;
    use local_teamscsv\task\mark_membership_dirty;
    const TEAM = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    function setup(int $courses = 1): membership_store {
        $GLOBALS['CFG'] = (object)['dirroot' => '/scratch'];
        database();
        $queue = new xmldb_table('task_adhoc');
        $queue->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $GLOBALS['DB']->get_manager()->create_table($queue);
        \core\lock\lock_config::$factory = new memory_locks();
        $GLOBALS['config'] = ['local_o365' => ['version' => 2025040835], 'local_teamscsv' => ['version' => 2026060407]];
        $GLOBALS['o365enabled'] = $GLOBALS['courseenabled'] = $GLOBALS['isolated'] = true;
        $GLOBALS['client'] = new \local_o365\rest\unified();
        $GLOBALS['httpstatus'] = 200;
        $GLOBALS['paginate'] = false;
        $GLOBALS['requests'] = $GLOBALS['factories'] = $GLOBALS['operations'] = 0;
        $GLOBALS['requestlog'] = [];
        $GLOBALS['onrequest'] = null;
        $store = new membership_store();
        for ($id = 2; $id < $courses + 2; $id++) { link_course($id, TEAM); $store->register($id, TEAM, 100); }
        return $store;
    }
    function enabled(): void { $GLOBALS['config']['local_teamscsv']['membership_enabled'] = '1'; }
    function event(int $courseid = 2, int $contextlevel = CONTEXT_COURSE): object {
        return (object)['courseid' => $courseid, 'contextlevel' => $contextlevel, 'contextinstanceid' => $courseid];
    }
    function pending(): array { return array_values($GLOBALS['DB']->get_records('task_adhoc')); }
    function worker($data): mark_membership_dirty {
        $task = new mark_membership_dirty(); $task->set_custom_data($data); return $task;
    }
    function observed(callable $call): array {
        ob_start();
        try { $result = $call(); $error = null; }
        catch (\Throwable $e) { $result = null; $error = [get_class($e), $e->getMessage()]; }
        return [$result, $error, ob_get_clean()];
    }
    function fails(callable $call, string $message): void {
        $before = $GLOBALS['DB']->snapshot(); $requests = $GLOBALS['requests'];
        expect(observed($call) === [null, [\RuntimeException::class, $message], ''], 'wrong rejection: ' . $message);
        expect($GLOBALS['DB']->snapshot() === $before && $GLOBALS['requests'] === $requests,
            'rejected input changed rows/counters/Graph');
    }
    $groups = [
        'schedule and default-off settings preserve link discovery' => function() {
            setup(); $tasks = []; require __DIR__ . '/../db/tasks.php';
            expect($tasks === [
                ['classname' => '\\local_teamscsv\\task\\discover_links', 'blocking' => 0,
                    'minute' => '*/15', 'hour' => '*', 'day' => '*', 'month' => '*', 'dayofweek' => '*'],
                ['classname' => '\\local_teamscsv\\task\\reconcile_memberships', 'blocking' => 0,
                    'minute' => '*', 'hour' => '*', 'day' => '*', 'month' => '*', 'dayofweek' => '*'],
            ], 'membership must be eligible every cron tick without changing links');
            $before = $GLOBALS['DB']->snapshot();
            expect(silent(fn() => (new membership_runner())->run(100)) ===
                ['status' => 'disabled', 'checked' => 0, 'jobs' => 0, 'completed' => 0, 'pending' => 0, 'requests' => 0], 'off result');
            expect($GLOBALS['DB']->snapshot() === $before && $GLOBALS['factories'] === 0, 'off performed work');
            silent(fn() => \local_teamscsv\observers::membership_changed(event()));
            expect($GLOBALS['DB']->snapshot() === $before, 'default-off observer queued work');
            foreach ([true, 'true', 2, '0'] as $invalidflag) {
                $GLOBALS['config']['local_teamscsv']['membership_enabled'] = $invalidflag;
                expect(silent(fn() => (new membership_runner())->run(100))['status'] === 'disabled' &&
                    $GLOBALS['DB']->snapshot() === $before, 'unapproved opt-in value enabled work');
            }
        },
        'preflight rejects guard/version/upgrade/isolation/transport before durable writes' => function() {
            foreach (['guard', 'version', 'upgrade', 'isolation', 'client', 'disabled-course'] as $case) {
                $store = setup(); enabled();
                $message = 'Managed membership unavailable.';
                if ($case === 'guard') { $GLOBALS['CFG']->dirroot = '/scratch/absent';
                    $message = 'Reviewed local_o365 membership isolation patch is required.'; }
                if ($case === 'version') { $GLOBALS['config']['local_o365']['version']++; }
                if ($case === 'upgrade') { unset($GLOBALS['DB']->get_manager()->tables['local_teamscsv_mscan']);
                    $message = 'Membership database upgrade is required.'; }
                if ($case === 'isolation') { $GLOBALS['isolated'] = false;
                    $message = 'Upstream membership isolation could not be verified.'; }
                if ($case === 'client') { $GLOBALS['client'] = null; $message = 'Managed Graph transport unavailable.'; }
                if ($case === 'disabled-course') { $GLOBALS['courseenabled'] = false; }
                fails(fn() => (new membership_runner())->run(100), $message);
            }
        },
        'strict per-run budgets, failures, core overlap and retry persistence' => function() {
            $store = setup(5); enabled();
            $GLOBALS['config']['local_teamscsv'] += ['membership_courses' => 2, 'membership_jobs' => 2, 'membership_requests' => 4];
            $r = silent(fn() => (new membership_runner())->run(100));
            expect($r === ['status' => 'ok', 'checked' => 2, 'jobs' => 2, 'completed' => 2, 'pending' => 0, 'requests' => 4], 'bounded successful work');
            expect(count($store->due(100)) === 3 && count(pending()) === 2, 'wrong pending work');
            expect($GLOBALS['DB']->get_records('local_teamscsv_managed') === [], 'scheduler altered managed history');
            foreach (['membership_courses' => [0, 26, '01'], 'membership_jobs' => [-1, 3, ''],
                    'membership_requests' => [1, 13, '2.5']] as $key => $values) {
                foreach ($values as $value) {
                    $old = $GLOBALS['config']['local_teamscsv']; $GLOBALS['config']['local_teamscsv'][$key] = $value;
                    fails(fn() => (new membership_runner())->run(101), 'Invalid membership scheduling bounds');
                    $GLOBALS['config']['local_teamscsv'] = $old;
                }
            }
            $store = setup(3); enabled(); $GLOBALS['paginate'] = true;
            $GLOBALS['config']['local_teamscsv'] += ['membership_jobs' => 2, 'membership_requests' => 12];
            $r = silent(fn() => (new membership_runner())->run(100));
            expect([$r['status'], $r['jobs'], $r['pending'], $r['requests']] === ['pending', 1, 2, 12],
                'total request cap exceeded or unfinished read treated as complete');
            expect(count($store->due(159)) === 2 && count($store->due(160)) === 3 &&
                $GLOBALS['DB']->get_records('local_teamscsv_managed') === [], 'large read lost pending work/history');
            $store = setup(); enabled(); $GLOBALS['httpstatus'] = 429;
            $r = silent(fn() => (new membership_runner())->run(100));
            expect([$r['status'], $r['pending'], $r['requests']] === ['pending', 1, 1], 'failure falsely completed');
            expect($store->due(159) === [] && $store->due(160)[0]['attempts'] === 1, 'failure lost backoff');
            $store = setup(); enabled(); $locks = \core\lock\lock_config::$factory;
            $lock = $locks->get_lock('reconcile_memberships', 0); $before = $GLOBALS['DB']->snapshot();
            expect(silent(fn() => (new membership_runner())->run(100))['status'] === 'busy', 'overlap accepted');
            expect($before === $GLOBALS['DB']->snapshot() && $GLOBALS['factories'] === 0, 'overlap did work'); $lock->release();
            $lock = $locks->get_lock('course-2', 0);
            $r = silent(fn() => (new membership_runner())->run(100)); $lock->release();
            expect($r['pending'] === 1 && $store->due(100)[0]['attempts'] === 0, 'busy job abandoned or lock bypassed');
            $GLOBALS['httpstatus'] = 429;
            expect(observed(fn() => (new \local_teamscsv\task\reconcile_memberships())->execute()) ===
                [null, [\RuntimeException::class, 'Membership work pending; retry on a later cron run.'],
                    "Teams membership: checked=0; jobs=1; completed=0; pending=1; requests=1.\n" .
                    "Teams membership pending: courseid=2; status=retry; reason=uncertain; requests=1; operations=0.\n"],
                'task must signal retry, not silent success');
        },
        'pending diagnostics correlate two jobs without changing retry counters' => function() {
            $store = setup(2); enabled(); $GLOBALS['httpstatus'] = 429;
            $GLOBALS['config']['local_teamscsv']['membership_jobs'] = 2;
            expect(observed(fn() => (new \local_teamscsv\task\reconcile_memberships())->execute()) ===
                [null, [\RuntimeException::class, 'Membership work pending; retry on a later cron run.'],
                    "Teams membership: checked=2; jobs=2; completed=0; pending=2; requests=2.\n" .
                    "Teams membership pending: courseid=2; status=retry; reason=uncertain; requests=1; operations=0.\n" .
                    "Teams membership pending: courseid=3; status=retry; reason=uncertain; requests=1; operations=0.\n"],
                'missing bounded per-course pending diagnostics');
            expect($store->due(159) === [] && array_column($store->due(160), 'attempts') === [1, 1] &&
                $GLOBALS['requests'] === 2 && $GLOBALS['DB']->get_records('local_teamscsv_managed') === [],
                'diagnostics changed retry, requests or history');
        },
        'diagnostic formatter allowlists codes and strictly validates integers' => function() {
            setup(); $before = $GLOBALS['DB']->snapshot();
            foreach (['busy', 'not_due', 'stale_job', 'stale_mapping', 'transaction', 'unavailable', 'job_changed',
                    'request_limit', 'operation_limit', 'last_owner', 'uncertain', 'staging_invalid',
                    'staged_read_unverified', 'staged_add_unverified'] as $reason) {
                foreach (['retry', 'skipped'] as $status) {
                    expect(membership_runner::pending_diagnostic(122,
                        ['status' => $status, 'reason' => $reason, 'requests' => 5, 'operations' => 0]) ===
                        "Teams membership pending: courseid=122; status=$status; reason=$reason; requests=5; operations=0.",
                        'known static code lost');
                }
            }
            foreach (["SECRET\nhttps://private/token", 'new_code', null, [], true, 1] as $bad) {
                expect(membership_runner::pending_diagnostic($bad,
                    ['status' => $bad, 'reason' => $bad, 'requests' => -1, 'operations' => '0']) ===
                    'Teams membership pending: courseid=' . ($bad === 1 ? '1' : 'unknown') .
                    '; status=unknown; reason=unknown; requests=unknown; operations=unknown.', 'unsafe diagnostic');
            }
            foreach ([0, -1, '122', 1.5, PHP_INT_MAX] as $id) {
                expect(membership_runner::pending_diagnostic($id, []) ===
                    'Teams membership pending: courseid=' . ($id === PHP_INT_MAX ? PHP_INT_MAX : 'unknown') .
                    '; status=unknown; reason=unknown; requests=unknown; operations=unknown.', 'invalid course ID');
            }
            expect($GLOBALS['DB']->snapshot() === $before && $GLOBALS['requests'] === 0, 'formatter mutated state');
        },
        'five course-only observers dedupe and never mutate dirty state in a transaction' => function() {
            $store = setup(); enabled(); $observers = []; require __DIR__ . '/../db/events.php';
            expect(array_column($observers, 'eventname') === array_map(static fn($name) => '\\core\\event\\' . $name,
                ['user_enrolment_created', 'user_enrolment_deleted', 'user_enrolment_updated', 'role_assigned', 'role_unassigned']), 'missing event');
            $job = $store->due(100)[0]; $outer = $GLOBALS['DB']->start_delegated_transaction();
            foreach ($observers as $observer) {
                expect($observer['callback'] === '\\local_teamscsv\\observers::membership_changed', 'wrong observer');
                silent(fn() => \local_teamscsv\observers::membership_changed(event()));
            }
            expect(count(pending()) === 1 && $store->due(100)[0] === $job && $GLOBALS['factories'] === 0, 'observer did more than queue');
            $outer->allow_commit();
            $before = $GLOBALS['DB']->snapshot();
            foreach ([event(2, 40), event(SITEID), event(999)] as $ev) { silent(fn() => \local_teamscsv\observers::membership_changed($ev)); }
            expect($GLOBALS['DB']->snapshot() === $before, 'noncourse/absent registry event queued');
            $task = worker(pending()[0]->data);
            $lock = \core\lock\lock_config::$factory->get_lock('course-2', 0);
            fails(fn() => $task->execute(), 'Membership storage busy'); $lock->release();
            $GLOBALS['config']['local_teamscsv']['membership_enabled'] = 0;
            fails(fn() => $task->execute(), 'Managed membership is disabled.'); enabled();
            fails(fn() => (function() use ($task) {
                $outer = $GLOBALS['DB']->start_delegated_transaction();
                try { $task->execute(); } finally { $outer->allow_commit(); }
            })(), 'Membership storage requires its own transaction');
            expect(observed(fn() => $task->execute()) === [null, null, ''], 'dirty worker failed');
            $first = $store->due(time())[0];
            silent(fn() => $task->execute()); // At-least-once: duplicate delivery is safe, not assumed impossible.
            $second = $store->due(time())[0];
            expect($second['serial'] === $first['serial'] + 1 && count($store->due(time())) === 1 &&
                $second['attempts'] === 0 && $GLOBALS['requests'] === 0, 'duplicate delivery reset/lost work');
        },
        'queue rollback model, stale mappings, pre-upgrade payloads and missing guards' => function() {
            $store = setup(); enabled(); $before = $GLOBALS['DB']->snapshot();
            $outer = $GLOBALS['DB']->start_delegated_transaction();
            silent(fn() => \local_teamscsv\observers::membership_changed(event()));
            try { $outer->rollback(new \RuntimeException('rollback')); } catch (\RuntimeException $e) {}
            expect($GLOBALS['DB']->snapshot() === $before && pending() === [], 'rolled-back model event survived');
            silent(fn() => \local_teamscsv\observers::membership_changed(event())); $data = pending()[0]->data;
            $store->register(2, TEAM, 101);
            expect(observed(fn() => worker($data)->execute()) === [null, null, ''], 'stale event should be a safe no-op');
            foreach ([['courseid' => 2], ['courseid' => '2', 'generationid' => 2], ['courseid' => 2, 'generationid' => 999]] as $invalid) {
                $before = $GLOBALS['DB']->snapshot();
                expect(observed(fn() => worker($invalid)->execute()) === [null, null, ''] &&
                    $GLOBALS['DB']->snapshot() === $before, 'old/rejected payload changed counters');
            }
            $GLOBALS['CFG']->dirroot = '/scratch/absent'; $before = $GLOBALS['DB']->snapshot();
            silent(fn() => \local_teamscsv\observers::membership_changed(event()));
            expect($GLOBALS['DB']->snapshot() === $before, 'missing guard event queued');
            fails(fn() => worker($data)->execute(), 'Reviewed local_o365 membership isolation patch is required.');
            $GLOBALS['CFG']->dirroot = '/scratch';
            $data = ['courseid' => 2, 'generationid' => 2]; $GLOBALS['DB']->rows['local_o365_objects'][5]->objectid = 'changed';
            $before = $GLOBALS['DB']->snapshot(); silent(fn() => worker($data)->execute());
            expect($GLOBALS['DB']->snapshot() === $before, 'stale live mapping changed dirty state');
            $GLOBALS['DB']->rows['local_teamscsv_map'] = [];
            $before = $GLOBALS['DB']->snapshot(); silent(fn() => worker($data)->execute());
            expect($GLOBALS['DB']->snapshot() === $before, 'absent registry task mutated state');
        },
        '501-course indexed daily cursor progresses past stale entries and survives restart' => function() {
            $store = setup(501); enabled();
            $GLOBALS['DB']->rows['local_o365_objects'][5]->subtype = 'stale';
            $seen = [];
            for ($tick = 0; $tick < 21; $tick++) {
                $runner = new membership_runner();
                $GLOBALS['config']['local_teamscsv'] += ['membership_courses' => 25];
                $r = silent(fn() => $runner->run(100 + $tick));
                expect($r['checked'] <= 25 && $r['jobs'] <= 1 && $r['requests'] <= 8, 'unbounded tick');
                foreach (pending() as $row) { $seen[$row->data->courseid] = true; }
            }
            expect(count($seen) === 500 && !isset($seen[2]) && isset($seen[502]), 'stale mapping starved daily scan');
            $count = count(pending());
            expect(silent(fn() => (new membership_runner())->run(86399))['checked'] === 0 && count(pending()) === $count,
                'same course requeued inside daily cycle');
            // Subsequent cycle can discover repaired mappings; link-discovery state is irrelevant.
            link_course(2, TEAM);
            expect(silent(fn() => (new membership_runner())->run(86520))['checked'] === 25 && count(pending()) === $count + 1,
                'daily scan failed to restart after a day');
            expect($GLOBALS['DB']->get_records('local_teamscsv_scan') === [], 'membership cursor used unrelated links state');
        },
        'import copy is staging-only and settings defaults preserve link options' => function() {
            setup(); $string = []; require __DIR__ . '/../lang/en/local_teamscsv.php';
            expect($string['apply'] === 'Apply connections and stage managed membership' &&
                $string['queued'] === '{$a} connections saved and membership work staged. No Teams changes were performed by this import. Managed membership is off by default; enabled cron processes bounded work over later runs.', 'old sync promise remains');
            expect(str_contains($string['warning'], 'Unrelated Team owners and members are outside managed scope.') &&
                str_contains($string['acknowledge'], 'only current course participants establish managed scope'), 'scope warning missing');
            $GLOBALS['strings'] = $string; $hassiteconfig = true; $ADMIN = new settings_admin();
            require __DIR__ . '/../settings.php';
            expect($ADMIN->values['local_teamscsv/membership_enabled'] === 0 &&
                $ADMIN->values['local_teamscsv/membership_courses'] === 5 &&
                $ADMIN->values['local_teamscsv/membership_jobs'] === 1 &&
                $ADMIN->values['local_teamscsv/membership_requests'] === 8, 'unsafe scheduler defaults');
            expect([$ADMIN->values['local_teamscsv/autolinks_enabled'], $ADMIN->values['local_teamscsv/autolinks_courses'],
                $ADMIN->values['local_teamscsv/autolinks_requests']] === [0, 5, 12], 'link settings changed');
        },
    ];
    function is_siteadmin() { return true; }
    class moodle_url { public function __construct(...$args) {} }
    class admin_externalpage { public function __construct(...$args) {} }
    class admin_setting_configtext {
        public function __construct(public $name, $label, $description, public $default, ...$args) {}
    }
    class admin_setting_configcheckbox extends admin_setting_configtext {}
    class admin_settingpage {
        public $items = [];
        public function __construct(...$args) {}
        public function add($item) { $this->items[] = $item; }
    }
    class settings_admin {
        public $values = [];
        public function add($parent, $item) {
            foreach ($item->items ?? [] as $setting) { $this->values[$setting->name] = $setting->default; }
        }
    }
    // Offline composition equivalent to the old *_remote.py fixture builders. Never invoke SSH.
    // Keep every preservation suite visible; upgrade assertions include additive 406 and re-entry.
    if (($argv[1] ?? '') === '--preserve') {
        $root = __DIR__ . '/..';
        $publication = file_get_contents(__DIR__ . '/link_publication_standalone.php');
        $tokens = [];
        foreach (['TASK' => 'classes/task/discover_links.php', 'RUNNER' => 'classes/link_runner.php',
                'PUBLISHER' => 'classes/link_publisher.php'] as $key => $path) {
            $tokens['__' . $key . '__'] = base64_encode(file_get_contents($root . '/' . $path));
        }
        $publication = strtr($publication, $tokens);
        $prefix = explode('$task = new \\local_teamscsv\\task\\discover_links();', $publication, 2)[0];
        $start = strpos($prefix, 'namespace local_teamscsv {');
        $end = strpos($prefix, "\nnamespace {", $start);
        expect($start !== false && $end !== false, 'publication stub boundary missing');
        $prefix = substr($prefix, 0, $start) . substr($prefix, $end);
        $tabs = file_get_contents(__DIR__ . '/course_tabs_standalone.php');
        foreach (['COURSE_LINKS' => 'classes/course_links.php', 'LIB' => 'lib.php', 'CONTROLLER' => 'links.php',
                'PUBLISHER' => 'classes/link_publisher.php', 'EN' => 'lang/en/local_teamscsv.php',
                'ES' => 'lang/es/local_teamscsv.php'] as $key => $path) {
            $source = file_get_contents($root . '/' . $path);
            if ($key === 'CONTROLLER') {
                $source = str_replace("require_once(__DIR__ . '/../../config.php');", '/* offline bootstrap */', $source, $count);
                expect($count === 1 && !preg_match('/\\b(?:require|include)(?:_once)?\\b/', $source), 'unsafe controller fixture');
            }
            $tabs = str_replace('__' . $key . '__', base64_encode($source), $tabs);
        }
        $xml = simplexml_load_file($root . '/db/install.xml');
        $schema = ['version' => (string)$xml['VERSION'], 'fields' => [], 'columns' => [], 'indexes' => [], 'tabindexes' => []];
        foreach ($xml->TABLES->TABLE as $table) {
            $name = (string)$table['NAME'];
            $schema['indexes'][$name] = [];
            foreach ($table->FIELDS->FIELD as $field) {
                $schema['fields'][$name][] = (string)$field['NAME'];
                foreach ($field->attributes() as $attribute => $value) {
                    $schema['columns'][$name][(string)$field['NAME']][$attribute] = (string)$value;
                }
            }
            foreach ($table->INDEXES->INDEX as $index) {
                $schema['indexes'][$name][(string)$index['NAME']] = ['unique' => (string)$index['UNIQUE'] === 'true',
                    'columns' => explode(',', (string)$index['FIELDS'])];
            }
            if ($name === 'local_teamscsv_tablink') {
                foreach ($table->INDEXES->INDEX as $index) {
                    $schema['tabindexes'][] = ['UNIQUE' => (string)$index['UNIQUE'], 'FIELDS' => (string)$index['FIELDS']];
                }
            }
        }
        $scripts = [
            'link_publication_standalone.php' => $publication,
            'link_discovery_standalone.php' => strtr(file_get_contents(__DIR__ . '/link_discovery_standalone.php'), [
                '__READER_SOURCE_BASE64__' => base64_encode(file_get_contents($root . '/classes/link_discovery.php')),
                '__PUBLISHER_SOURCE_BASE64__' => base64_encode(file_get_contents($root . '/classes/link_publisher.php')),
            ]),
            'tabs_storage_standalone.php' => $prefix . "}\n" . substr(file_get_contents(__DIR__ . '/tabs_storage_standalone.php'), 6),
            'links_seam_standalone.php' => $prefix . 'eval("?>" . base64_decode("' .
                base64_encode(file_get_contents($root . '/classes/link_discovery.php')) . '"));' . "\n}\n" .
                substr(file_get_contents(__DIR__ . '/links_seam_standalone.php'), 6),
            'course_tabs_standalone.php' => $tabs,
            'scan_cursor_upgrade_standalone.php' => strtr(file_get_contents(__DIR__ . '/scan_cursor_upgrade_standalone.php'), [
                '__UPGRADE__' => base64_encode(file_get_contents($root . '/db/upgrade.php')),
                '__INSTALL_FIELDS__' => base64_encode(json_encode($schema)),
            ]),
        ];
        $failed = 0;
        foreach (array_merge(['parser_standalone.php', 'membership_plan_standalone.php', 'cache_cli_standalone.php'],
                array_keys($scripts)) as $name) {
            $command = isset($scripts[$name]) ? [PHP_BINARY, '-r', 'eval("?>" . base64_decode("' .
                base64_encode($scripts[$name]) . '"));'] : [PHP_BINARY, __DIR__ . '/' . $name];
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            expect(is_resource($process), 'preservation runner unavailable'); fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
            echo $name . ': exit=' . $exit . "\n" . $stdout;
            if ($stderr !== '') { fwrite(STDERR, $stderr); }
            $failed += $exit !== 0 || $stderr !== '' ? 1 : 0;
        }
        exit($failed ? 1 : 0);
    }
    // Test-only public task entry point; production classes above remain unchanged.
    if (in_array($argv[1] ?? '', ['--task-local', '--task-local-cli'], true)) {
        require __DIR__ . '/membership_task_local.php';
        exit(task_local_main($argv));
    }
    // Used against exact pre-edit sources and candidate in separate offline processes.
    if (($argv[1] ?? '') === '--differential') {
        foreach (['disabled', 'busy', 'completed'] as $case) {
            $store = setup();
            if ($case !== 'disabled') { enabled(); }
            $lock = $case === 'busy' ? \core\lock\lock_config::$factory->get_lock('reconcile_memberships', 0) : null;
            echo json_encode([$case, observed(fn() => (new \local_teamscsv\task\reconcile_memberships())->execute()),
                count($store->due(100)), $GLOBALS['requests'], $GLOBALS['factories']]) . "\n";
            if ($lock) { $lock->release(); }
        }
        exit(0);
    }
    $failed = 0;
    foreach ($groups as $name => $group) {
        try { $group(); echo 'PASS ' . $name . "\n"; }
        catch (\Throwable $e) { $failed++; echo 'FAIL ' . $name . ': ' . $e->getMessage() . "\n"; }
    }
    exit($failed ? 1 : 0);
}
