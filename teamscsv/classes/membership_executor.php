<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_teamscsv;

use local_o365\feature\coursesync\utils;
use local_o365\rest\unified;

defined('MOODLE_INTERNAL') || die();

/** One proposed due job, never cron/global synchronization. See MEMBERSHIP-SYNC.md. */
final class membership_executor {
    private membership_store $store;
    private $clientfactory;

    public function __construct(?membership_store $store = null, ?callable $clientfactory = null,
            private int $maxoperations = 10, private int $maxrequests = 60) {
        if ($maxoperations < 1 || $maxoperations > 10 || $maxrequests < 2 || $maxrequests > 60) {
            throw new \InvalidArgumentException('Invalid membership execution bounds');
        }
        $this->store = $store ?? new membership_store();
        $this->clientfactory = $clientfactory ?? static fn() => utils::get_graphclient();
    }

    /** Silent result: status completed|retry|skipped, reason code, API invocations and attempted writes.
     * Proposals come from store::due(). All authority is reread AFTER acquiring the storage course lock.
     */
    public function execute(array $job, int $now): array {
        $requests = 0;
        $operations = 0;
        $reserved = false;
        try {
            return $this->store->execution_lock($job, $now,
                function($job) use ($now, &$requests, &$operations, &$reserved) {
                    $mapping = $job['mapping'];
                    $this->authority($mapping);
                    $job = $this->store->execution_retry($job, $now);
                    $reserved = true;
                    // Exact0835 get_graphclient(), public Graph resource, forcecreate=false: cached token
                    // only. Missing/expired tokens fail; never use instance_for_user() or refresh here.
                    $client = ($this->clientfactory)();
                    if (!$client instanceof unified) {
                        throw new \RuntimeException('Managed client unavailable.');
                    }
                    $request = function(string $verb, string $path, array $data = []) use (
                            $client, $mapping, $job, &$requests, &$operations) {
                        $this->authority($mapping, $job['serial']);
                        if ($requests >= $this->maxrequests) {
                            throw new \RuntimeException('Membership request limit');
                        }
                        $requests++;
                        if ($verb !== 'GET') {
                            $operations++;
                        }
                        $response = $client->managed_membership_request($verb, $path, $data);
                        if ($verb !== 'GET' && (!is_array($response) || ($response['status'] ?? null) !== 204 ||
                                ($response['ok'] ?? null) !== true || ($response['body'] ?? null) !== '')) {
                            throw new \RuntimeException('Unacknowledged membership write');
                        }
                        // Snapshot validates strict GET200/ok=true, JSON, identities and every continuation.
                        return $response;
                    };
                    $snapshot = new membership_snapshot(static fn($path) => $request('GET', $path), $this->store);
                    $bounded = $this->maxrequests <= 12;
                    if ($bounded && ($this->store->stage_progress($mapping, $job['serial'], 'owners') !== null ||
                            $this->store->stage_progress($mapping, $job['serial'], 'members') !== null)) {
                        // A cross-cron traversal is NOT a current simultaneous snapshot. Never plan or write from it.
                        $progress = $snapshot->probe_staged($mapping, $job['serial'], $this->maxrequests, $now);
                        if (!$progress['complete']) {
                            return self::result('retry', 'request_limit', $requests, 0);
                        }
                        $staged = $snapshot->staged_addition_state($mapping, $job['serial'], $now);
                        $confirmed = false;
                        foreach ($staged['managed'] as $row) {
                            $id = $row['objectid'];
                            if (!isset($staged['userids'][$id]) ||
                                    (int)$row['userid'] !== $staged['userids'][$id] ||
                                    !($row['role'] === 'intent' || ($row['role'] === 'member' &&
                                        $staged['desired'][$id] === 'owner' &&
                                        in_array($id, $staged['owners'], true)))) {
                                continue;
                            }
                            $observed = ($staged['desired'][$id] === 'owner' &&
                                in_array($id, $staged['owners'], true)) ? 'owner' :
                                (in_array($id, $staged['members'], true) ? 'member' : null);
                            if ($observed !== null && !($row['role'] === 'member' && $observed !== 'owner')) {
                                $this->store->execution_confirm_staged($mapping, $job['serial'],
                                    $staged['userids'][$id], $id, $observed, $now);
                                $confirmed = true;
                            }
                        }
                        if ($progress['requests'] > 0) {
                            if ($confirmed) { $this->store->discard_stage($mapping, $job['serial']); }
                            // A traversal finishes without writing; a later locked job chooses at most one ADD.
                            return self::result('retry', 'staged_read_unverified', $requests, 0);
                        }
                        $add = null;
                        foreach ($staged['desired'] as $id => $role) {
                            if (!in_array($id, $staged['members'], true)) {
                                $add = ['id' => $id, 'role' => 'members'];
                                break;
                            }
                        }
                        if ($add === null) {
                            foreach ($staged['desired'] as $id => $role) {
                                if ($role === 'owner' && !in_array($id, $staged['owners'], true)) {
                                    $add = ['id' => $id, 'role' => 'owners'];
                                    break;
                                }
                            }
                        }
                        if ($add === null) {
                            if ($confirmed) { $this->store->discard_stage($mapping, $job['serial']); }
                            return self::result('retry', 'staged_read_unverified', $requests, 0);
                        }
                        $this->authority($mapping, $job['serial']);
                        // Durable intent before remote ADD; invalidate the entire staged read before writing.
                        $this->store->execution_intents($mapping, $job['serial'],
                            [$add['id'] => $staged['userids'][$add['id']]], $now);
                        $this->store->discard_stage($mapping, $job['serial']);
                        $request('POST', '/groups/' . $mapping['teamid'] . '/' . $add['role'] . '/$ref',
                            ['@odata.id' => 'https://graph.microsoft.com/v1.0/users/' . $add['id']]);
                        return self::result('retry', 'staged_add_unverified', $requests, $operations);
                    }
                    $initial = $state = $snapshot->read($mapping, false, $bounded ? $job['serial'] : null,
                        $bounded ? $now : null);
                    $plan = membership_plan::build($state);
                    $this->authority($mapping, $job['serial']);
                    if ($bounded) {
                        // Even a same-run snapshot becomes stale as soon as a Graph write starts.
                        $this->store->discard_stage($mapping, $job['serial']);
                    }
                    // Include already-present CURRENT participants, never remotely discovered outsiders.
                    // Commit intent before any ADD; pending rows remain planner scope even after unenrolment.
                    $this->store->execution_intents($mapping, $job['serial'], $state['userids'], $now);
                    foreach (array_slice($plan['operations'], 0, $this->maxoperations) as $operation) {
                        $id = $operation['id'];
                        $action = $operation['action'];
                        $add = str_starts_with($action, 'add_');
                        $role = str_ends_with($action, 'owner') ? 'owners' : 'members';
                        if ($add) {
                            if (!isset($state['userids'][$id]) || ($role === 'owners' &&
                                    !in_array($id, $state['members'], true))) {
                                throw new \RuntimeException('Unverified membership addition');
                            }
                            $request('POST', '/groups/' . $mapping['teamid'] . '/' . $role . '/$ref',
                                ['@odata.id' => 'https://graph.microsoft.com/v1.0/users/' . $id]);
                        } else {
                            // Last remote read before DELETE is the COMPLETE owner collection, not a
                            // prediction or a pre-members-GET owner list. Recheck ALL replacement owners.
                            $state = $snapshot->read($mapping, true);
                            $this->stable($initial, $state);
                            $owners = $state['owners'];
                            foreach ($state['desired'] as $ownerid => $desiredrole) {
                                if ($desiredrole === 'owner' && !in_array($ownerid, $owners, true)) {
                                    throw new \RuntimeException('Unverified replacement owner');
                                }
                            }
                            if ($role === 'owners' && in_array($id, $owners, true) && count($owners) <= 1) {
                                throw new \RuntimeException('Membership final owner');
                            }
                            if ($role === 'members' && in_array($id, $owners, true)) {
                                throw new \RuntimeException('Ownership must be removed before membership');
                            }
                            if ($role !== 'owners' || in_array($id, $owners, true)) {
                                $request('DELETE', '/groups/' . $mapping['teamid'] . '/' . $role . '/' . $id . '/$ref');
                            }
                        }
                        // An ACK is not complete state. Failure here retains intent/previous confirmation.
                        $state = $snapshot->read($mapping);
                        $this->stable($initial, $state);
                        $present = in_array($id, $state[$role], true);
                        if ($present !== $add || ($action === 'add_owner' && !in_array($id, $state['members'], true)) ||
                                ($action === 'remove_member' && in_array($id, $state['owners'], true))) {
                            throw new \RuntimeException('Membership write not remotely verified');
                        }
                    }
                    $remaining = membership_plan::build($state);
                    if ($remaining['operations']) {
                        return self::result('retry', 'operation_limit', $requests, $operations);
                    }
                    if ($remaining['blocked']) {
                        return self::result('retry', 'last_owner', $requests, $operations);
                    }
                    $this->authority($mapping, $job['serial']);
                    // Only a complete, current, operation-free re-read can update roles/release history.
                    $completed = $this->store->execution_complete($mapping, $job['serial'], $state['desired'],
                        $state['userids'], $remaining['baseline']['release'], $now);
                    return self::result($completed ? 'completed' : 'retry', $completed ? null : 'job_changed',
                        $requests, $operations);
                });
        } catch (\Throwable $e) {
            // No Graph bodies, tokens, output or hidden retries. The reserved backoff survives exceptions
            // AND real process death; a changed event serial is never overwritten in this catch path.
            $reason = match ($e->getMessage()) {
                'Membership storage busy' => 'busy',
                'Membership retry not due' => 'not_due',
                'Stale membership job', 'Stale membership retry' => $reserved ? 'job_changed' : 'stale_job',
                'Unverified or stale mapping' => 'stale_mapping',
                'Membership storage requires its own transaction' => 'transaction',
                'Membership request limit' => 'request_limit',
                'Stale staged membership identity or continuation', 'Stale staged membership page' => 'staging_invalid',
                'Unsafe Graph membership pagination' => $this->maxrequests <= 12 ? 'staging_invalid' : 'uncertain',
                'Membership final owner' => 'last_owner',
                default => $reserved ? 'uncertain' : 'unavailable',
            };
            return self::result($reserved ? 'retry' : 'skipped', $reason, $requests, $operations);
        }
    }

    private function authority(array $mapping, ?int $serial = null): void {
        membership_guard::require_installed();
        if (!in_array(get_config('local_o365', 'version'), [2025040835, '2025040835'], true) ||
                utils::is_enabled() !== true || utils::is_course_sync_enabled($mapping['courseid']) !== true ||
                unified::get_tokenresource() !== 'https://graph.microsoft.com') {
            throw new \RuntimeException('Managed membership unavailable');
        }
        if ($this->store->current($mapping['courseid']) !== $mapping) {
            throw new \InvalidArgumentException('Unverified or stale mapping');
        }
        membership_guard::assert_isolated($mapping['courseid'], $mapping['teamid']);
        if ($serial !== null && !$this->store->execution_current($mapping, $serial)) {
            throw new \InvalidArgumentException('Stale membership job');
        }
    }

    private function stable(array $initial, array $state): void {
        if ($initial['desired'] !== $state['desired'] || $initial['userids'] !== $state['userids']) {
            throw new \RuntimeException('Course participants changed during membership execution');
        }
        $this->preserved($initial, $state['owners'], 'owners');
        $this->preserved($initial, $state['members'], 'members');
    }

    private function preserved(array $initial, array $observed, string $role): void {
        $scope = array_merge(array_keys($initial['desired']), $initial['managed']);
        $outsiders = array_diff($initial[$role], $scope);
        if (array_diff($outsiders, $observed)) {
            throw new \RuntimeException('Unrelated Team membership changed');
        }
    }

    private static function result(string $status, ?string $reason, int $requests, int $operations): array {
        return compact('status', 'reason', 'requests', 'operations');
    }
}
