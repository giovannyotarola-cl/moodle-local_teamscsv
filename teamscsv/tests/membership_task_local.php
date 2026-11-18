<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Loaded only by membership_cron_standalone.php; real task/runner/executor/store.
function task_local_setup(string $case): array {
    expect(in_array($case, ['disabled', 'busy', 'completed', 'request_limit'], true), 'Unknown task case');
    $store = setup();
    // A nonempty released audit proves that completion does not erase historical responsibility.
    $mapping = $store->current(2);
    $GLOBALS['participants'] = [2 => [7]];
    $store->record_confirmed($mapping, 7, 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', 'member', true, 80);
    $store->release_confirmed($mapping, 7, 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', true, 90);
    if ($case !== 'disabled') { enabled(); }
    if ($case === 'request_limit') {
        $GLOBALS['paginate'] = true;
        $GLOBALS['config']['local_teamscsv']['membership_requests'] = 2;
    }
    $lock = $case === 'busy' ? \core\lock\lock_config::$factory->get_lock('reconcile_memberships', 0) : null;
    return [$store, $mapping, $lock];
}

function task_local_main(array $argv): int {
    if ($argv[1] === '--task-local-cli') {
        task_local_setup($argv[2] ?? '');
        // This wrapper maps the exception to an exit status; execute() itself has no CLI exit contract.
        try { (new \local_teamscsv\task\reconcile_memberships())->execute(); return 0; }
        catch (\RuntimeException $e) { fwrite(STDERR, $e->getMessage() . "\n"); return 1; }
    }
    foreach (['disabled', 'busy', 'completed', 'request_limit'] as $case) {
        [$store, $mapping, $lock] = task_local_setup($case);
        $before = $GLOBALS['DB']->snapshot();
        [$rows, $writes] = unserialize($before);
        $audit = $store->audit(2);
        $managed = $store->managed($mapping);
        $job = $store->due(100)[0];
        $held = \core\lock\lock_config::$factory->held;
        $active = in_array($case, ['completed', 'request_limit'], true);
        $pending = $case === 'request_limit';
        $error = in_array($case, ['busy', 'request_limit'], true) ?
            [\RuntimeException::class, 'Membership work pending; retry on a later cron run.'] : null;
        $stdout = match ($case) {
            'disabled' => '',
            'busy' => "Teams membership: checked=0; jobs=0; completed=0; pending=0; requests=0.\n",
            'completed' => "Teams membership: checked=1; jobs=1; completed=1; pending=0; requests=2.\n",
            'request_limit' => "Teams membership: checked=1; jobs=1; completed=0; pending=1; requests=2.\n" .
                "Teams membership pending: courseid=2; status=retry; reason=request_limit; requests=2; operations=0.\n",
        };
        expect(observed(fn() => (new \local_teamscsv\task\reconcile_memberships())->execute()) ===
            [null, $error, $stdout], $case . ': exact execute return/exception/stdout');
        $base = '/groups/' . TEAM;
        $requests = $active ? [
            ['GET', $base . '/owners?$top=999', []],
            ['GET', $base . ($pending ? '/owners?$skiptoken=page1' : '/members?$top=999'), []],
        ] : [];
        expect([$GLOBALS['factories'], $GLOBALS['requests'], $GLOBALS['operations'], $GLOBALS['requestlog']] ===
            [$active ? 1 : 0, $active ? 2 : 0, 0, $requests], $case . ': exact factory/transport/operation counts');
        if ($active) {
            // One queue insert + two daily cursor checkpoints, independent of remote outcome.
            $rows['task_adhoc'][1] = (object)['data' => (object)['courseid' => 2, 'generationid' => 1], 'id' => 1];
            $rows['local_teamscsv_mscan'][1] = (object)[
                'scan_key' => 'daily', 'afterid' => 0, 'ceiling' => 0, 'lastchecked' => 100, 'id' => 1];
            if ($pending) {
                // Reservation increments serial/attempts and retains the first 60-second backoff.
                $rows['local_teamscsv_dirty'][1]->serial = 2;
                $rows['local_teamscsv_dirty'][1]->attempts = 1;
                $rows['local_teamscsv_dirty'][1]->nextattempt = 160;
                $rows['local_teamscsv_page'][1] = (object)[
                    'generationid' => 1, 'role' => 'owners', 'serial' => 2,
                    'nextpath' => $base . '/owners?$skiptoken=page2',
                    'seen' => json_encode(['identityhash' => hash('sha256', serialize([[], [], []])),
                        'paths' => [hash('sha256', $base . '/owners?$top=999'),
                            hash('sha256', $base . '/owners?$skiptoken=page1')]], JSON_THROW_ON_ERROR),
                    'pages' => 2, 'idcount' => 0, 'startedat' => 100, 'complete' => 0, 'id' => 1];
                // Queue/cursor 3 + reservation 1 + two owner-page checkpoints 2.
                $writes += 6;
            } else {
                $rows['local_teamscsv_dirty'] = [];
                // Queue/cursor 3 + reservation 1 + role pages 2 + purge page/id pairs 4 + completion 1.
                $writes += 11;
            }
        }
        expect($GLOBALS['DB']->snapshot() === serialize([$rows, $writes]), $case . ': complete mock rows/write count');
        expect($store->current(2) === $mapping && $store->managed($mapping) === $managed &&
            $store->audit(2) === $audit && count($audit) === 1, $case . ': mapping/managed/audit preservation');
        $retry = array_replace($job, ['serial' => 2, 'attempts' => 1, 'nextattempt' => 160]);
        expect($store->due(159) === ($active ? [] : [$job]) &&
            $store->due(160) === ($pending ? [$retry] : ($active ? [] : [$job])), $case . ': dirty/backoff');
        expect($GLOBALS['DB']->transactiondepth === 0 && \core\lock\lock_config::$factory->held === $held,
            $case . ': transaction/lock preservation');
        if ($lock) { $lock->release(); }
        echo 'PASS task-local ' . $case . "\n";
    }
    return 0;
}
