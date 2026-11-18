<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_teamscsv;

defined('MOODLE_INTERNAL') || die();

/** Local persistence only. Remote confirmation belongs to the guarded executor, never inferred here. */
final class membership_store {
    private $locks;
    private $importcourses = [];
    private ?array $executionmapping = null;

    public function __construct($locks = null) {
        $this->locks = $locks ?? \core\lock\lock_config::get_lock_factory('local_teamscsv_membership');
    }

    /** Explicit link/relink only, NOT discovery: every call creates a new immutable generation. */
    public function register(int $courseid, string $teamid, int $now): array {
        self::bounds($now);
        if (!self::uuid($teamid)) {
            throw new \InvalidArgumentException('Unverified or stale mapping');
        }
        return $this->mutate($courseid, fn() => $this->register_generation($courseid, $teamid, $now));
    }

    /** Own the entire import transaction, holding every course lock through its actual commit/rollback. */
    public function import_transaction(array $courseids, callable $import) {
        if (!$courseids || count(array_unique($courseids, SORT_REGULAR)) !== count($courseids)) {
            throw new \InvalidArgumentException('Invalid import courses');
        }
        foreach ($courseids as $courseid) {
            if (!is_int($courseid) || $courseid < 1 || $courseid === SITEID) {
                throw new \InvalidArgumentException('Invalid import courses');
            }
        }
        sort($courseids, SORT_NUMERIC);
        return $this->transaction($courseids, function() use ($courseids, $import) {
            $this->importcourses = array_fill_keys($courseids, true);
            try {
                return $import();
            } finally {
                $this->importcourses = [];
            }
        });
    }

    /** Only inside this instance's import_transaction callback, never an arbitrary enclosing transaction. */
    public function register_imported_course(int $courseid, string $teamid, int $now): array {
        global $DB;
        if (!isset($this->importcourses[$courseid]) || !$DB->is_transaction_started()) {
            throw new \RuntimeException('Import registration requires the held import transaction');
        }
        self::bounds($now);
        if (!self::uuid($teamid)) {
            throw new \InvalidArgumentException('Unverified or stale mapping');
        }
        return $this->register_generation($courseid, $teamid, $now);
    }

    /** One generation/pointer/queue path for both explicit registration and atomic imports. */
    private function register_generation(int $courseid, string $teamid, int $now): array {
        global $DB;
        if (course_links::mapped_team($courseid) !== $teamid) {
            throw new \InvalidArgumentException('Unverified or stale mapping');
        }
        // An existing broken pointer is not authority to replace or discard its history.
        $this->registered($courseid);
        $registry = $DB->get_record('local_teamscsv_map', ['courseid' => $courseid]);
        if ($registry) {
            // A relink archives managed history but not task-created unverified remote pages.
            $this->purge_stage((int)$registry->generationid);
        }
        $generation = (object)['courseid' => $courseid, 'teamid' => $teamid,
            'revision' => bin2hex(random_bytes(32)), 'timecreated' => $now];
        $generation->id = $DB->insert_record('local_teamscsv_generation', $generation);
        $record = (object)['courseid' => $courseid, 'generationid' => $generation->id];
        if ($registry) {
            $record->id = $registry->id;
            $DB->update_record('local_teamscsv_map', $record);
        } else {
            $DB->insert_record('local_teamscsv_map', $record);
        }
        $this->dirty((int)$generation->id, $now);
        return self::token($generation);
    }

    /** Registry identity AND the existing exact two-row live mapping rule are required. */
    public function current(int $courseid): array {
        $generation = $this->registered($courseid);
        if (!$generation || course_links::mapped_team($courseid) !== $generation->teamid) {
            throw new \InvalidArgumentException('Unverified or stale mapping');
        }
        return self::token($generation);
    }

    public function mark_dirty(array $mapping, int $now): array {
        self::bounds($now);
        return $this->mutate((int)($mapping['courseid'] ?? 0), function() use ($mapping, $now) {
            $generation = $this->checked($mapping);
            return self::job($mapping, $this->dirty((int)$generation->id, $now));
        });
    }

    /** Compare the queue serial as well as generation; a concurrent event cannot be consumed by old work. */
    public function retry(array $mapping, int $serial, int $now): array {
        self::bounds($now);
        return $this->mutate((int)($mapping['courseid'] ?? 0), fn() => $this->retry_job($mapping, $serial, $now));
    }

    /** Bounded proposals, NOT claims or permission to write remotely. Revalidate under executor lock. */
    public function due(int $now, int $limit = 25): array {
        global $DB;
        self::bounds($now, $limit);
        // Filter inactive/invalid mappings BEFORE LIMIT, so stale entries cannot starve valid courses.
        $sql = "SELECT q.*, g.courseid, g.teamid, g.revision
                  FROM {local_teamscsv_dirty} q
                  JOIN {local_teamscsv_map} m ON m.generationid = q.generationid
                  JOIN {local_teamscsv_generation} g ON g.id = q.generationid AND g.courseid = m.courseid
                  JOIN {course} c ON c.id = m.courseid AND c.id <> :siteid
                 WHERE q.nextattempt <= :now
                   AND (SELECT COUNT(*) FROM {local_o365_objects} o
                         WHERE o.type = :grouptype AND o.moodleid = m.courseid) = 2
                   AND EXISTS (SELECT 1 FROM {local_o365_objects} a WHERE a.type = :atype
                               AND a.moodleid = m.courseid AND a.subtype = :course AND a.objectid = g.teamid)
                   AND EXISTS (SELECT 1 FROM {local_o365_objects} b WHERE b.type = :btype
                               AND b.moodleid = m.courseid AND b.subtype = :courseteam AND b.objectid = g.teamid)
              ORDER BY q.nextattempt, q.id";
        $params = ['now' => $now, 'siteid' => SITEID, 'grouptype' => 'group', 'atype' => 'group',
            'btype' => 'group', 'course' => 'course', 'courseteam' => 'courseteam'];
        $jobs = [];
        foreach ($DB->get_records_sql($sql, $params, 0, $limit) as $row) {
            $mapping = self::token($row);
            try {
                $this->checked($mapping); // Reuse course_links, including its strict string comparison.
                $jobs[] = self::job($mapping, $row);
            } catch (\InvalidArgumentException $e) {
                // A mapping can change between the bounded query and this live check. Fail closed.
            }
        }
        return $jobs;
    }

    /** Independent daily traversal of explicit registry pointers, never link discovery or adoption.
     * High-water ID freezes one cycle; stale mappings still advance. Queue FIRST, checkpoint SECOND:
     * a crash may duplicate a hint, but cannot checkpoint past an unqueued course. No DB transaction
     * spans the callback, which must use the core deduped queue, not remote I/O or managed history.
     */
    public function fallback(int $now, int $limit, callable $enqueue): int {
        global $DB;
        self::bounds($now, $limit);
        // Reserved invalid course 0 serializes this cursor through the same lock/transaction machinery.
        return $this->locked([0], function() use ($now, $limit, $enqueue) {
            global $DB;
            $state = $DB->get_record('local_teamscsv_mscan', ['scan_key' => 'daily']);
            $state = $state ?: (object)['scan_key' => 'daily', 'afterid' => 0, 'ceiling' => 0, 'lastchecked' => 0];
            if ((int)$state->ceiling === 0) {
                if (isset($state->id) && $now < (int)$state->lastchecked + 86400) {
                    return 0;
                }
                $last = $DB->get_records('local_teamscsv_map', [], 'id DESC', 'id', 0, 1);
                $state->ceiling = $last ? (int)reset($last)->id : 0;
                $state->afterid = 0;
            }
            $rows = $DB->get_records_sql("SELECT m.id, m.courseid, m.generationid
                                           FROM {local_teamscsv_map} m
                                          WHERE m.id > :afterid AND m.id <= :ceiling ORDER BY m.id",
                ['afterid' => $state->afterid, 'ceiling' => $state->ceiling], 0, $limit);
            foreach ($rows as $row) {
                $enqueue((int)$row->courseid, (int)$row->generationid);
                $state->afterid = (int)$row->id;
                $this->save_fallback($state);
            }
            if (count($rows) < $limit || (int)$state->afterid >= (int)$state->ceiling) {
                $state->afterid = $state->ceiling = 0;
                $state->lastchecked = $now;
                $this->save_fallback($state);
            }
            return count($rows);
        });
    }

    private function save_fallback($state): void {
        global $DB;
        $this->own_transaction(function() use ($DB, $state) {
            if (isset($state->id)) {
                $DB->update_record('local_teamscsv_mscan', $state);
            } else {
                $state->id = $DB->insert_record('local_teamscsv_mscan', $state);
            }
        });
    }

    /** Records a caller-confirmed full role, not an intended Graph operation or Graph-discovered outsider. */
    public function record_confirmed(array $mapping, int $userid, string $objectid, string $role,
            $confirmed, int $now): void {
        self::confirmation($userid, $objectid, $confirmed, $now);
        if (!in_array($role, ['owner', 'member'], true)) {
            throw new \InvalidArgumentException('Invalid membership role');
        }
        $this->mutate((int)($mapping['courseid'] ?? 0),
            fn() => $this->write_managed($mapping, $userid, $objectid, $role, $now));
    }

    /** Only confirmed absence from BOTH owners and members releases responsibility; keep the audit row. */
    public function release_confirmed(array $mapping, int $userid, string $objectid, $confirmed, int $now): void {
        self::confirmation($userid, $objectid, $confirmed, $now);
        $this->mutate((int)($mapping['courseid'] ?? 0), fn() => $this->release_managed($mapping, $userid, $objectid, $now));
    }

    /** Hold the SAME nonblocking course lock as every storage/import writer, including across Graph.
     * No transaction spans the callback. Each narrow execution mutation below owns its actual commit.
     */
    public function execution_lock(array $job, int $now, callable $execute) {
        self::bounds($now);
        $mapping = $job['mapping'] ?? null;
        if (!is_array($mapping) || !is_int($mapping['courseid'] ?? null)) {
            throw new \InvalidArgumentException('Unverified or stale mapping');
        }
        if ($this->executionmapping !== null) {
            throw new \RuntimeException('Membership storage busy');
        }
        return $this->locked([$mapping['courseid']], function() use ($job, $mapping, $now, $execute) {
            global $DB;
            $generation = $this->checked($mapping);
            $row = $DB->get_record('local_teamscsv_dirty', ['generationid' => $generation->id]);
            if (!$row || self::job($mapping, $row) !== $job) {
                throw new \InvalidArgumentException('Stale membership job');
            }
            if ((int)$row->nextattempt > $now) {
                throw new \InvalidArgumentException('Membership retry not due');
            }
            $this->executionmapping = $mapping;
            try {
                return $execute($job);
            } finally {
                $this->executionmapping = null;
            }
        });
    }

    /** Revalidate the serial without consuming a newer event; allowed only on the lock-owning instance. */
    public function execution_current(array $mapping, int $serial): bool {
        global $DB;
        $this->require_execution($mapping);
        $generation = $this->checked($mapping);
        $row = $DB->get_record('local_teamscsv_dirty', ['generationid' => $generation->id]);
        return $row && (int)$row->serial === $serial;
    }

    /** Reserve/back off BEFORE I/O, so even process death cannot cause immediate unbounded retries. */
    public function execution_retry(array $job, int $now): array {
        global $DB;
        self::bounds($now);
        return $this->execution_mutate($job['mapping'], function() use ($DB, $job, $now) {
            $reserved = $this->retry_job($job['mapping'], $job['serial'], $now);
            $generation = $this->checked($job['mapping']);
            $stages = $DB->get_records('local_teamscsv_page', ['generationid' => $generation->id]);
            $invalid = false;
            foreach ($stages as $stage) {
                $invalid = $invalid || (int)$stage->serial !== $job['serial'] ||
                    $now < (int)$stage->startedat || $now - (int)$stage->startedat > 86400;
            }
            if ($invalid) {
                // Never combine a fresh role with an expired/event-stale role.
                $this->purge_stage((int)$generation->id);
            } else {
                foreach ($stages as $stage) {
                    // A retry reservation changes serial without changing the course or remote evidence.
                    $stage->serial = $reserved['serial'];
                    $DB->update_record('local_teamscsv_page', $stage);
                }
            }
            return $reserved;
        });
    }

    /** Read an incomplete or completed page checkpoint only under the course execution lock. */
    public function stage_progress(array $mapping, int $serial, string $role): ?array {
        global $DB;
        $this->require_execution($mapping);
        if (!in_array($role, ['owners', 'members'], true) || !$this->execution_current($mapping, $serial)) {
            throw new \InvalidArgumentException('Stale membership job');
        }
        $generation = $this->checked($mapping);
        $stage = $DB->get_record('local_teamscsv_page',
            ['generationid' => $generation->id, 'role' => $role]);
        if (!$stage || (int)$stage->serial !== $serial) {
            return null;
        }
        $metadata = json_decode($stage->seen, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($metadata)) {
            throw new \RuntimeException('Unsafe staged membership pagination');
        }
        return ['role' => $role, 'nextpath' => $stage->nextpath, 'count' => (int)$stage->idcount,
            'complete' => (bool)$stage->complete, 'startedat' => (int)$stage->startedat,
            'pages' => (int)$stage->pages, 'identityhash' => $metadata['identityhash'] ?? null];
    }

    /** Positive observations are advisory for ADD only; a staged set is never DELETE scope. */
    public function staged_ids(array $mapping, int $serial, string $role): array {
        global $DB;
        $progress = $this->stage_progress($mapping, $serial, $role);
        if (!$progress || !$progress['complete']) {
            throw new \InvalidArgumentException('Incomplete staged membership');
        }
        $generation = $this->checked($mapping);
        $stage = $DB->get_record('local_teamscsv_page',
            ['generationid' => $generation->id, 'role' => $role, 'serial' => $serial]);
        $ids = [];
        foreach ($DB->get_records('local_teamscsv_pageid', ['pageid' => $stage->id], 'id ASC') as $row) {
            if (!self::uuid($row->objectid) || isset($ids[$row->objectid])) {
                throw new \RuntimeException('Invalid staged membership identity');
            }
            $ids[$row->objectid] = true;
        }
        if (count($ids) !== $progress['count']) {
            throw new \RuntimeException('Invalid staged membership identity');
        }
        return array_keys($ids);
    }

    /** Confirm an existing pending ADD only after its ID is positively observed in a later full staged role. */
    public function execution_confirm_staged(array $mapping, int $serial, int $userid, string $id,
            string $role, int $now): void {
        global $DB;
        $this->require_execution($mapping);
        self::confirmation($userid, $id, true, $now);
        if (!in_array($role, ['owner', 'member'], true) ||
                !in_array($id, $this->staged_ids($mapping, $serial, $role . 's'), true)) {
            throw new \InvalidArgumentException('Unverified staged membership addition');
        }
        $this->execution_mutate($mapping, function() use ($DB, $mapping, $serial, $userid, $id, $role, $now) {
            if (!$this->execution_current($mapping, $serial)) {
                throw new \InvalidArgumentException('Stale membership job');
            }
            $generation = $this->checked($mapping);
            $row = $DB->get_record('local_teamscsv_managed',
                ['generationid' => $generation->id, 'objectid' => $id, 'userid' => $userid, 'released' => 0]);
            if (!$row || !($row->role === 'intent' || ($row->role === 'member' && $role === 'owner')) ||
                    !is_enrolled(\context_course::instance($mapping['courseid']), $userid, '', true)) {
                throw new \InvalidArgumentException('Unverified staged membership addition');
            }
            $this->write_managed($mapping, $userid, $id, $role, $now);
        });
    }

    /** Invalidate only task-owned remote staging BEFORE a write can make it stale. */
    public function discard_stage(array $mapping, int $serial): void {
        global $DB;
        $this->require_execution($mapping);
        $this->execution_mutate($mapping, function() use ($DB, $mapping, $serial) {
            $generation = $this->checked($mapping);
            if (!$this->execution_current($mapping, $serial)) {
                throw new \InvalidArgumentException('Stale membership job');
            }
            $this->purge_stage((int)$generation->id);
        });
    }

    /** Delete only task-created unverified pages within the caller's owned course transaction. */
    private function purge_stage(int $generationid): void {
        global $DB;
        foreach ($DB->get_records('local_teamscsv_page', ['generationid' => $generationid]) as $stage) {
            $DB->delete_records('local_teamscsv_pageid', ['pageid' => $stage->id]);
            $DB->delete_records('local_teamscsv_page', ['id' => $stage->id]);
        }
    }

    /** Persist one validated remote page, never managed responsibility or deletion authority.
     * The course lock spans the caller's Graph request; this transaction covers page IDs and cursor together.
     */
    public function stage_page(array $mapping, int $serial, string $role, string $path, array $ids,
            ?string $nextpath, int $now, ?string $identityhash = null): array {
        global $DB;
        $this->require_execution($mapping);
        self::bounds($now);
        if (!$this->execution_current($mapping, $serial) || !in_array($role, ['owners', 'members'], true) ||
                !array_is_list($ids) || count($ids) > 999 || count(array_unique($ids)) !== count($ids) ||
                ($identityhash !== null && !preg_match('/^[0-9a-f]{64}$/D', $identityhash))) {
            throw new \InvalidArgumentException('Invalid staged membership page');
        }
        foreach ($ids as $id) {
            if (!self::uuid($id)) {
                throw new \InvalidArgumentException('Invalid staged membership page');
            }
        }
        $endpoint = '/groups/' . $mapping['teamid'] . '/' . $role;
        $firstpath = $endpoint . '?$top=999';
        $continuation = '#^' . preg_quote($endpoint, '#') . '\?\$skiptoken=[A-Za-z0-9._~%\-]+'
            . '(?:&\$top=[1-9][0-9]{0,2})?$#D';
        if (strlen($path) > 8192 || preg_match('/%(?![0-9a-fA-F]{2})/', $path) ||
                ($path !== $firstpath && !preg_match($continuation, $path)) ||
                ($nextpath !== null && (strlen($nextpath) > 8192 ||
                    preg_match('/%(?![0-9a-fA-F]{2})/', $nextpath) || !preg_match($continuation, $nextpath)))) {
            throw new \InvalidArgumentException('Invalid staged membership page');
        }
        return $this->execution_mutate($mapping, function() use ($DB, $mapping, $serial, $role, $path,
                $ids, $nextpath, $now, $firstpath, $identityhash) {
            $generation = $this->checked($mapping);
            if (!$this->execution_current($mapping, $serial)) {
                throw new \InvalidArgumentException('Stale membership job');
            }
            $pages = $DB->get_records('local_teamscsv_page', ['generationid' => $generation->id]);
            $totalpages = array_sum(array_map(static fn($entry) => (int)$entry->pages, $pages));
            if ($totalpages >= 20) {
                throw new \RuntimeException('Graph membership page limit exceeded');
            }
            $stage = $DB->get_record('local_teamscsv_page',
                ['generationid' => $generation->id, 'role' => $role]);
            if ($stage && ((int)$stage->serial !== $serial || (int)$stage->complete !== 0 ||
                    $stage->nextpath !== $path)) {
                throw new \InvalidArgumentException('Stale staged membership page');
            }
            if (!$stage && $path !== $firstpath) {
                throw new \InvalidArgumentException('Stale staged membership page');
            }
            $metadata = $stage ? json_decode($stage->seen, true, 32, JSON_THROW_ON_ERROR) :
                ['identityhash' => $identityhash, 'paths' => []];
            $legacy = is_array($metadata) && array_is_list($metadata);
            $seen = $legacy ? $metadata : ($metadata['paths'] ?? null);
            $storedhash = $legacy ? null : ($metadata['identityhash'] ?? null);
            $hash = hash('sha256', $path);
            if (!is_array($seen) || !array_is_list($seen) || $storedhash !== $identityhash ||
                    in_array($hash, $seen, true) ||
                    ($nextpath !== null && ($nextpath === $path ||
                        in_array(hash('sha256', $nextpath), $seen, true)))) {
                throw new \RuntimeException('Unsafe staged membership pagination');
            }
            foreach ($ids as $id) {
                if ($stage && $DB->record_exists('local_teamscsv_pageid', ['pageid' => $stage->id,
                        'objectid' => $id])) {
                    throw new \RuntimeException('Invalid Graph membership response');
                }
            }
            $seen[] = $hash;
            $encoded = json_encode(['identityhash' => $identityhash, 'paths' => $seen], JSON_THROW_ON_ERROR);
            if (!$stage) {
                $stage = (object)['generationid' => $generation->id, 'role' => $role, 'serial' => $serial,
                    'nextpath' => $nextpath, 'seen' => $encoded,
                    'pages' => 1, 'idcount' => count($ids), 'startedat' => $now,
                    'complete' => $nextpath === null ? 1 : 0];
                $stage->id = $DB->insert_record('local_teamscsv_page', $stage);
            } else {
                $stage->nextpath = $nextpath;
                $stage->seen = $encoded;
                $stage->pages++;
                $stage->idcount += count($ids);
                $stage->complete = $nextpath === null ? 1 : 0;
                $DB->update_record('local_teamscsv_page', $stage);
            }
            foreach ($ids as $id) {
                $DB->insert_record('local_teamscsv_pageid', (object)['pageid' => $stage->id, 'objectid' => $id]);
            }
            return ['role' => $role, 'nextpath' => $nextpath,
                'count' => (int)$stage->idcount, 'complete' => (bool)$stage->complete];
        });
    }

    /** Current participants only: objectid=>userid comes from the snapshot's single identity scan.
     * Persist responsibility before ADD, including already-present baseline users. Never adopt outsiders.
     */
    public function execution_intents(array $mapping, int $serial, array $userids, int $now): void {
        $this->require_execution($mapping);
        self::bounds($now);
        if (count(array_unique($userids, SORT_REGULAR)) !== count($userids)) {
            throw new \InvalidArgumentException('Invalid managed identity');
        }
        foreach ($userids as $id => $userid) {
            if (!is_int($userid) || !is_string($id)) {
                throw new \InvalidArgumentException('Invalid managed identity');
            }
            self::confirmation($userid, $id, true, $now);
        }
        $this->execution_mutate($mapping, function() use ($mapping, $serial, $userids, $now) {
            if (!$this->execution_current($mapping, $serial)) {
                throw new \InvalidArgumentException('Stale membership job');
            }
            foreach ($userids as $id => $userid) {
                $this->write_managed($mapping, $userid, $id, 'intent', $now, true);
            }
        });
    }

    /** Executor-supplied, complete remote evidence only. All confirmations/releases/queue removal commit
     * together, or none do. A newer serial returns false WITHOUT changing any history or queue counters.
     */
    public function execution_complete(array $mapping, int $serial, array $roles, array $userids,
            array $release, int $now): bool {
        $this->require_execution($mapping);
        self::bounds($now);
        if (array_keys($roles) !== array_keys($userids) || !array_is_list($release) ||
                count(array_unique($release, SORT_REGULAR)) !== count($release)) {
            throw new \InvalidArgumentException('Invalid managed identity');
        }
        foreach ($roles as $id => $role) {
            if (!is_string($id) || !is_int($userids[$id])) {
                throw new \InvalidArgumentException('Invalid managed identity');
            }
            self::confirmation($userids[$id], $id, true, $now);
            if (!in_array($role, ['owner', 'member'], true)) {
                throw new \InvalidArgumentException('Invalid membership role');
            }
        }
        foreach ($release as $id) {
            if (!is_string($id) || !self::uuid($id) || isset($roles[$id])) {
                throw new \InvalidArgumentException('Invalid managed identity');
            }
        }
        return $this->execution_mutate($mapping, function() use ($mapping, $serial, $roles, $userids, $release, $now) {
            global $DB;
            if (!$this->execution_current($mapping, $serial)) {
                return false;
            }
            $generation = $this->checked($mapping);
            foreach ($roles as $id => $role) {
                $this->write_managed($mapping, $userids[$id], $id, $role, $now);
            }
            foreach ($release as $id) {
                $row = $DB->get_record('local_teamscsv_managed', ['generationid' => $generation->id, 'objectid' => $id]);
                if (!$row) {
                    throw new \InvalidArgumentException('Unknown managed identity');
                }
                self::confirmation((int)$row->userid, $id, true, $now);
                $this->release_managed($mapping, (int)$row->userid, $id, $now);
            }
            if ($DB->delete_records('local_teamscsv_dirty', ['generationid' => $generation->id, 'serial' => $serial]) === false) {
                throw new \RuntimeException('Membership completion was not persisted');
            }
            return true;
        });
    }

    /** Only live-generation active responsibility, INCLUDING pending intents, is planner history. */
    public function managed(array $mapping): array {
        global $DB;
        $generation = $this->checked($mapping);
        return array_values(array_map(static fn($row) => (array)$row, $DB->get_records('local_teamscsv_managed',
            ['generationid' => $generation->id, 'released' => 0], 'id ASC')));
    }

    /** Read-only historical inspection, including archived/released rows; NEVER input to a sync plan. */
    public function audit(int $courseid, int $afterid = 0, int $limit = 25): array {
        global $DB;
        self::bounds($afterid, $limit);
        if ($courseid < 1 || $courseid === SITEID) {
            throw new \InvalidArgumentException('Unverified or stale mapping');
        }
        $sql = "SELECT h.*, g.courseid, g.teamid, g.revision
                  FROM {local_teamscsv_managed} h
                  JOIN {local_teamscsv_generation} g ON g.id = h.generationid
                 WHERE g.courseid = :courseid AND h.id > :afterid ORDER BY h.id";
        return array_values(array_map(static fn($row) => (array)$row,
            $DB->get_records_sql($sql, ['courseid' => $courseid, 'afterid' => $afterid], 0, $limit)));
    }

    private function registered(int $courseid) {
        global $DB;
        if ($courseid < 1 || $courseid === SITEID) {
            throw new \InvalidArgumentException('Unverified or stale mapping');
        }
        $registry = $DB->get_record('local_teamscsv_map', ['courseid' => $courseid]);
        if (!$registry) {
            return false;
        }
        $generation = $DB->get_record('local_teamscsv_generation', ['id' => $registry->generationid]);
        if (!$generation || (int)$generation->courseid !== $courseid || !self::uuid($generation->teamid) ||
                !preg_match('/^[0-9a-f]{64}$/D', $generation->revision)) {
            throw new \InvalidArgumentException('Unverified or stale mapping');
        }
        return $generation;
    }

    private function checked(array $mapping) {
        $courseid = $mapping['courseid'] ?? null;
        $generation = is_int($courseid) ? $this->registered($courseid) : false;
        if (!$generation || self::token($generation) !== $mapping ||
                course_links::mapped_team($courseid) !== $generation->teamid) {
            throw new \InvalidArgumentException('Unverified or stale mapping');
        }
        return $generation;
    }

    private function mutate(int $courseid, callable $change) {
        return $this->transaction([$courseid], $change);
    }

    private function transaction(array $courseids, callable $change) {
        return $this->locked($courseids, fn() => $this->own_transaction($change));
    }

    /** One lock acquisition path for import, ordinary storage and execution. */
    private function locked(array $courseids, callable $change) {
        global $DB;
        if ($DB->is_transaction_started()) {
            throw new \RuntimeException('Membership storage requires its own transaction');
        }
        $locks = [];
        try {
            foreach ($courseids as $courseid) {
                $lock = $this->locks->get_lock('course-' . $courseid, 0);
                if (!$lock) {
                    throw new \RuntimeException('Membership storage busy');
                }
                $locks[] = $lock;
            }
            return $change();
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    private function own_transaction(callable $change) {
        global $DB;
        if ($DB->is_transaction_started()) {
            throw new \RuntimeException('Membership storage requires its own transaction');
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            $result = $change();
            $transaction->allow_commit();
            return $result;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    private function require_execution(array $mapping): void {
        if ($this->executionmapping !== $mapping) {
            throw new \RuntimeException('Membership execution lock required');
        }
    }

    private function execution_mutate(array $mapping, callable $change) {
        $this->require_execution($mapping);
        return $this->own_transaction($change);
    }

    /** Both retry entry points preserve the same serial, due-time, attempts and backoff invariants. */
    private function retry_job(array $mapping, int $serial, int $now): array {
        global $DB;
        $generation = $this->checked($mapping);
        $row = $DB->get_record('local_teamscsv_dirty', ['generationid' => $generation->id]);
        if (!$row || (int)$row->serial !== $serial) {
            throw new \InvalidArgumentException('Stale membership retry');
        }
        if ((int)$row->nextattempt > $now) {
            throw new \InvalidArgumentException('Membership retry not due');
        }
        $row->attempts = (int)$row->attempts + 1;
        $row->serial = (int)$row->serial + 1;
        $row->nextattempt = $now + min(3600, 60 * (2 ** min(6, $row->attempts - 1)));
        $DB->update_record('local_teamscsv_dirty', $row);
        return self::job($mapping, $row);
    }

    /** Reuse conflict/enrolment/generation validation for intent AND both confirmation entry points. */
    private function write_managed(array $mapping, int $userid, string $objectid, string $role, int $now,
            bool $intent = false): void {
        global $DB;
        $generation = $this->checked($mapping);
        $row = $DB->get_record('local_teamscsv_managed', ['generationid' => $generation->id, 'objectid' => $objectid]);
        if ($row && (int)$row->userid !== $userid) {
            throw new \InvalidArgumentException('Conflicting managed identity');
        }
        if (($intent || !$row || $row->released) &&
                !is_enrolled(\context_course::instance($mapping['courseid']), $userid, '', true)) {
            throw new \InvalidArgumentException('Not a current course participant');
        }
        if ($intent && $row && !$row->released) {
            return; // Existing responsibility already covers a crash; retain the previous confirmed role.
        }
        $record = (object)['generationid' => $generation->id, 'userid' => $userid, 'objectid' => $objectid,
            'role' => $role, 'confirmedat' => $intent ? 0 : $now, 'released' => 0];
        if ($row) {
            $record->id = $row->id;
            $DB->update_record('local_teamscsv_managed', $record);
        } else {
            $DB->insert_record('local_teamscsv_managed', $record);
        }
    }

    private function release_managed(array $mapping, int $userid, string $objectid, int $now): void {
        global $DB;
        $generation = $this->checked($mapping);
        $row = $DB->get_record('local_teamscsv_managed',
            ['generationid' => $generation->id, 'userid' => $userid, 'objectid' => $objectid]);
        if (!$row) {
            throw new \InvalidArgumentException('Unknown managed identity');
        }
        $row->released = 1;
        $row->confirmedat = $now;
        $DB->update_record('local_teamscsv_managed', $row);
    }

    /** All queue creation/update goes through this one function, within the course lock and transaction. */
    private function dirty(int $generationid, int $now) {
        global $DB;
        $row = $DB->get_record('local_teamscsv_dirty', ['generationid' => $generationid]);
        if ($row) {
            $row->serial = (int)$row->serial + 1;
            $DB->update_record('local_teamscsv_dirty', $row); // Never reset persistent attempts or backoff.
        } else {
            $row = (object)['generationid' => $generationid, 'serial' => 1, 'attempts' => 0, 'nextattempt' => $now];
            $row->id = $DB->insert_record('local_teamscsv_dirty', $row);
        }
        return $row;
    }

    private static function token($row): array {
        return ['courseid' => (int)$row->courseid, 'teamid' => $row->teamid, 'revision' => $row->revision];
    }

    private static function job(array $mapping, $row): array {
        return ['mapping' => $mapping, 'serial' => (int)$row->serial, 'attempts' => (int)$row->attempts,
            'nextattempt' => (int)$row->nextattempt];
    }

    private static function uuid(string $id): bool {
        return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $id);
    }

    private static function bounds(int $now, int $limit = 25): void {
        if ($now < 0 || $limit < 1 || $limit > 25) {
            throw new \InvalidArgumentException('Invalid storage bounds');
        }
    }

    private static function confirmation(int $userid, string $objectid, $confirmed, int $now): void {
        self::bounds($now);
        if ($confirmed !== true) {
            throw new \InvalidArgumentException('Unconfirmed membership state');
        }
        if ($userid < 1 || !self::uuid($objectid)) {
            throw new \InvalidArgumentException('Invalid managed identity');
        }
    }
}
