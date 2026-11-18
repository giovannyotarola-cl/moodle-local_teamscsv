<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_teamscsv;

use local_o365\feature\coursesync\utils;
use local_o365\rest\unified;

defined('MOODLE_INTERNAL') || die();

/** Default-off orchestration only; executor owns all remote authority and managed history. */
final class membership_runner {
    public static function enabled(): bool {
        return in_array(get_config('local_teamscsv', 'membership_enabled'), [1, '1'], true);
    }

    /** No authentication or mutation. Also used when an old queued task runs after an upgrade. */
    public static function require_ready(): void {
        global $DB;
        if (!self::enabled()) {
            throw new \RuntimeException('Managed membership is disabled.');
        }
        membership_guard::require_installed();
        if (!in_array(get_config('local_o365', 'version'), [2025040835, '2025040835'], true) ||
                utils::is_enabled() !== true || unified::get_tokenresource() !== 'https://graph.microsoft.com') {
            throw new \RuntimeException('Managed membership unavailable.');
        }
        if (!in_array(get_config('local_teamscsv', 'version'), [2026060407, '2026060407'], true)) {
            throw new \RuntimeException('Membership database upgrade is required.');
        }
        foreach (['map', 'generation', 'managed', 'dirty', 'mscan', 'page', 'pageid'] as $table) {
            if (!$DB->get_manager()->table_exists(new \xmldb_table('local_teamscsv_' . $table))) {
                throw new \RuntimeException('Membership database upgrade is required.');
            }
        }
    }

    /** Small read-only event seam: capture a pointer, NOT membership authority or a participant list.
     * Core owns queue deduplication/transactions; duplicate delivery remains safe and supported.
     */
    public static function queue_dirty(int $courseid, ?int $generationid = null): void {
        global $DB;
        self::require_ready();
        if ($courseid < 1 || $courseid === SITEID) {
            return;
        }
        $registry = $DB->get_record('local_teamscsv_map', ['courseid' => $courseid]);
        if (!$registry || ($generationid !== null && (int)$registry->generationid !== $generationid)) {
            return;
        }
        $generation = $DB->get_record('local_teamscsv_generation', ['id' => $registry->generationid]);
        if (!$generation || (int)$generation->courseid !== $courseid ||
                course_links::mapped_team($courseid) !== $generation->teamid ||
                utils::is_course_sync_enabled($courseid) !== true) {
            return;
        }
        membership_guard::assert_isolated($courseid, $generation->teamid);
        $task = new \local_teamscsv\task\mark_membership_dirty();
        $task->set_custom_data(['courseid' => $courseid, 'generationid' => (int)$registry->generationid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /** Worker only: never run storage mutations inside the event's enclosing transaction. */
    public static function mark_dirty(int $courseid, int $generationid, int $now): void {
        global $DB;
        self::require_ready();
        $registry = $DB->get_record('local_teamscsv_map', ['courseid' => $courseid]);
        if (!$registry || (int)$registry->generationid !== $generationid) {
            return;
        }
        $store = new membership_store();
        try {
            $mapping = $store->current($courseid);
        } catch (\InvalidArgumentException $e) {
            return; // Retired mapping; the daily cursor visits the live generation independently.
        }
        $generation = $DB->get_record('local_teamscsv_generation', ['id' => $generationid]);
        if (!$generation || (int)$generation->courseid !== $courseid || $generation->teamid !== $mapping['teamid'] ||
                $generation->revision !== $mapping['revision']) {
            return;
        }
        self::course_ready($store, $mapping);
        $store->mark_dirty($mapping, $now); // Existing lock, actual transaction, serial and backoff path.
    }

    /** At most 25 cursor rows, 2 jobs and 12 total one-attempt requests (180s transport deadlines).
     * Local DB/hash/queue costs are additional; this is not a wall-clock SLA or backlog completion bound.
     */
    public function run(?int $now = null): array {
        global $DB;
        $result = ['status' => 'disabled', 'checked' => 0, 'jobs' => 0, 'completed' => 0, 'pending' => 0, 'requests' => 0];
        if (!self::enabled()) {
            return $result;
        }
        $now ??= time();
        $courses = self::setting('membership_courses', 5, 1, 25);
        $jobs = self::setting('membership_jobs', 1, 1, 2);
        $budget = self::setting('membership_requests', 8, 2, 12);
        if ($now < 0) {
            throw new \RuntimeException('Invalid membership scheduling bounds');
        }
        if ($DB->is_transaction_started()) {
            throw new \RuntimeException('Membership storage requires its own transaction');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_teamscsv')->get_lock('reconcile_memberships', 0);
        if (!$lock) {
            $result['status'] = 'busy';
            return $result;
        }
        try {
            self::require_ready();
            $store = new membership_store();
            $due = $store->due($now, $jobs);
            // Fail before fallback/counters/authentication if any proposed course lacks isolation.
            foreach ($due as $job) {
                self::course_ready($store, $job['mapping']);
            }
            try {
                if (!function_exists('curl_init')) {
                    throw new \RuntimeException('Native transport missing');
                }
                $client = utils::get_graphclient(); // Exact0835 cached public-resource token only.
                if (!$client instanceof unified) {
                    throw new \RuntimeException('Client missing');
                }
            } catch (\Throwable $e) {
                throw new \RuntimeException('Managed Graph transport unavailable.');
            }
            $result['checked'] = $store->fallback($now, $courses, [self::class, 'queue_dirty']);
            foreach ($due as $job) {
                $remaining = $budget - $result['requests'];
                if ($remaining < 2) {
                    $result['pending']++;
                    continue;
                }
                $executor = new membership_executor($store, static fn() => $client, 10, $remaining);
                $outcome = $executor->execute($job, $now);
                $result['jobs']++;
                $result['requests'] += $outcome['requests'];
                $result[$outcome['status'] === 'completed' ? 'completed' : 'pending']++;
                if ($outcome['status'] !== 'completed') {
                    $result['diagnostics'][] = self::pending_diagnostic($job['mapping']['courseid'], $outcome);
                }
            }
            // Busy jobs cannot be rewritten without their course lock. The scheduled task signals
            // core failure/backoff instead. Dirty rows/intents survive; never bypass the lock to defer.
            $result['status'] = $result['pending'] ? 'pending' : 'ok';
            return $result;
        } finally {
            $lock->release();
        }
    }

    /** Log-only projection: never include opaque mapping data or exception/transport details. */
    public static function pending_diagnostic($courseid, array $outcome): string {
        $status = $outcome['status'] ?? null;
        $reason = $outcome['reason'] ?? null;
        $status = in_array($status, ['retry', 'skipped'], true) ? $status : 'unknown';
        $reason = in_array($reason, ['busy', 'not_due', 'stale_job', 'stale_mapping', 'transaction',
            'unavailable', 'job_changed', 'request_limit', 'operation_limit', 'last_owner', 'uncertain',
            'staging_invalid', 'staged_read_unverified', 'staged_add_unverified'], true) ? $reason : 'unknown';
        $integer = static fn($value, int $min) => is_int($value) && $value >= $min ? $value : 'unknown';
        return 'Teams membership pending: courseid=' . $integer($courseid, 1) . '; status=' . $status .
            '; reason=' . $reason . '; requests=' . $integer($outcome['requests'] ?? null, 0) .
            '; operations=' . $integer($outcome['operations'] ?? null, 0) . '.';
    }

    private static function course_ready(membership_store $store, array $mapping): void {
        membership_guard::require_installed();
        if ($store->current($mapping['courseid']) !== $mapping) {
            throw new \InvalidArgumentException('Unverified or stale mapping');
        }
        if (utils::is_course_sync_enabled($mapping['courseid']) !== true) {
            throw new \RuntimeException('Managed membership unavailable.');
        }
        membership_guard::assert_isolated($mapping['courseid'], $mapping['teamid']);
    }

    private static function setting(string $key, int $default, int $min, int $max): int {
        $value = get_config('local_teamscsv', $key);
        $value = $value === false ? $default : $value;
        if ((!is_int($value) && (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value))) ||
                $value < $min || $value > $max) {
            throw new \RuntimeException('Invalid membership scheduling bounds');
        }
        return (int)$value;
    }
}
