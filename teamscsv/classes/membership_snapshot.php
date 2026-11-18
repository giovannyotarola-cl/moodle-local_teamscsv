<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_teamscsv;

use local_o365\feature\coursesync\utils;

defined('MOODLE_INTERNAL') || die();

/** Read/plan only. A snapshot is NOT execution authority; see MEMBERSHIP-SYNC.md. */
final class membership_snapshot {
    private const MAX_PAGES = 20;
    private const PAGE_SIZE = 999;
    private const MAX_BODY = 4194304;
    private $get;
    private membership_store $store;

    /** GET receives a relative /groups/... path and returns ['status' => 200, 'ok' => true, 'body' => raw JSON].
     * The transport must make at most one request, without redirects or hidden retries.
     * No default0835 apicall wrapper: its recursive429 retry defeats a hard request bound.
     */
    public function __construct(callable $get, ?membership_store $store = null, private int $maxpages = self::MAX_PAGES) {
        if ($maxpages < 2 || $maxpages > self::MAX_PAGES) {
            throw new \InvalidArgumentException('Invalid membership page limit');
        }
        $this->get = $get;
        $this->store = $store ?? new membership_store();
    }

    /** Exhaustive evidence or an exception. ownerslast keeps the last remote read immediately before DELETE. */
    public function read(array $mapping, bool $ownerslast = false, ?int $serial = null, ?int $now = null): array {
        if ($serial !== null && ($now === null || $now < 0)) {
            throw new \InvalidArgumentException('Invalid staged membership time');
        }
        $before = $this->checked($mapping);
        [$desired, $userids] = $this->participants($before['courseid']);
        $managed = $this->history($before, $userids);
        $identityhash = $serial === null ? null : self::identityhash($desired, $userids, $managed);
        $requests = 0;
        if ($ownerslast) {
            $members = $this->remote($before['teamid'], 'members', $requests, $before, $serial, $identityhash, $now);
            $owners = $this->remote($before['teamid'], 'owners', $requests, $before, $serial, $identityhash, $now);
        } else {
            $owners = $this->remote($before['teamid'], 'owners', $requests, $before, $serial, $identityhash, $now);
            $members = $this->remote($before['teamid'], 'members', $requests, $before, $serial, $identityhash, $now);
        }
        if ($this->participants($before['courseid']) !== [$desired, $userids]) {
            throw new \RuntimeException('Course participants changed during membership read.');
        }
        if ($this->history($before, $userids) !== $managed) {
            throw new \RuntimeException('Managed history changed during membership read.');
        }
        $after = $this->checked($before);
        return ['mapping' => $before, 'verifiedmapping' => $after, 'desired' => $desired, 'userids' => $userids,
            'managed' => array_keys($managed), 'owners' => $owners, 'members' => $members,
            'complete' => ['participants' => true, 'identities' => true, 'history' => true,
                'owners' => true, 'members' => true]];
    }

    /** Resume untrusted page traversal only. Completion is NOT a planning or deletion snapshot. */
    public function probe_staged(array $mapping, int $serial, int $budget, int $now): array {
        if ($budget < 1 || $budget > 12) {
            throw new \InvalidArgumentException('Invalid membership request budget');
        }
        $before = $this->checked($mapping);
        [$desired, $userids] = $this->participants($before['courseid']);
        $managed = $this->history($before, $userids);
        $identityhash = self::identityhash($desired, $userids, $managed);
        $requests = 0; $complete = true;
        foreach (['owners', 'members'] as $role) {
            $stage = $this->store->stage_progress($before, $serial, $role);
            if (!$stage) {
                if ($role === 'owners') {
                    throw new \RuntimeException('Stale staged membership page');
                }
                // The previous run may have filled the owner pages exactly at the request cap.
                $path = '/groups/' . $before['teamid'] . '/members?$top=' . self::PAGE_SIZE;
            } else {
                if ($stage['identityhash'] !== $identityhash || $now < $stage['startedat'] ||
                        $now - $stage['startedat'] > 86400) {
                    throw new \RuntimeException('Stale staged membership identity or continuation');
                }
                $path = $stage['complete'] ? null : $stage['nextpath'];
            }
            while ($path !== null) {
                if ($requests >= $budget) {
                    $complete = false;
                    break;
                }
                $requestpath = $path;
                $requests++;
                $page = self::decode(($this->get)($path));
                $ids = [];
                foreach ($page->value as $record) {
                    if (!$record instanceof \stdClass || ($record->{'@odata.type'} ?? null) !== '#microsoft.graph.user' ||
                            !self::uuid($record->id ?? null) || isset($ids[$record->id])) {
                        throw new \RuntimeException('Invalid Graph membership response');
                    }
                    $ids[$record->id] = true;
                }
                $path = null;
                if (property_exists($page, '@odata.nextLink')) {
                    if (!is_string($page->{'@odata.nextLink'}) || $page->{'@odata.nextLink'} === '') {
                        throw new \RuntimeException('Invalid Graph membership response');
                    }
                    [$path] = self::next($page->{'@odata.nextLink'}, '/groups/' . $before['teamid'] . '/' . $role);
                }
                $this->store->stage_page($before, $serial, $role, $requestpath, array_keys($ids), $path, $now, $identityhash);
            }
            if (!$complete) { break; }
        }
        if ($this->participants($before['courseid']) !== [$desired, $userids] ||
                $this->history($before, $userids) !== $managed || $this->checked($before) !== $before) {
            throw new \RuntimeException('Course participants changed during staged read');
        }
        return ['complete' => $complete, 'requests' => $requests];
    }

    /** Advisory positives and negatives for adding only; intentionally omits plan.complete. */
    public function staged_addition_state(array $mapping, int $serial, int $now): array {
        $before = $this->checked($mapping);
        [$desired, $userids] = $this->participants($before['courseid']);
        $managed = $this->history($before, $userids);
        $identityhash = self::identityhash($desired, $userids, $managed);
        $remote = [];
        foreach (['owners', 'members'] as $role) {
            $progress = $this->store->stage_progress($before, $serial, $role);
            if (!$progress || !$progress['complete'] || $progress['identityhash'] !== $identityhash ||
                    $now < $progress['startedat'] || $now - $progress['startedat'] > 86400) {
                throw new \RuntimeException('Stale staged membership identity or continuation');
            }
            $remote[$role] = $this->store->staged_ids($before, $serial, $role);
        }
        if ($this->participants($before['courseid']) !== [$desired, $userids] ||
                $this->history($before, $userids) !== $managed || $this->checked($before) !== $before) {
            throw new \RuntimeException('Course participants changed during staged read');
        }
        return ['desired' => $desired, 'userids' => $userids, 'managed' => $this->store->managed($before),
            'owners' => $remote['owners'], 'members' => $remote['members']];
    }

    private static function identityhash(array $desired, array $userids, array $managed): string {
        return hash('sha256', serialize([$desired, $userids, $managed]));
    }

    public function plan(array $mapping): array {
        return membership_plan::build($this->read($mapping));
    }

    private function checked(array $mapping): array {
        membership_guard::require_installed();
        self::version();
        if (!is_int($mapping['courseid'] ?? null) || $this->store->current($mapping['courseid']) !== $mapping) {
            throw new \InvalidArgumentException('Unverified or stale mapping');
        }
        membership_guard::assert_isolated($mapping['courseid'], $mapping['teamid']);
        return $mapping;
    }

    private function participants(int $courseid): array {
        global $DB;
        // Exact0835 helpers exhaust active enrolments, capability-filtered, excluding deleted users.
        $roles = [];
        foreach (['owner' => utils::get_team_owner_user_ids_by_course_id($courseid),
                'member' => utils::get_team_member_user_ids_by_course_id($courseid)] as $role => $ids) {
            $seen = [];
            foreach ($ids as $id) {
                $userid = self::userid($id);
                if (!$userid || isset($seen[$userid])) {
                    throw new \RuntimeException('Invalid course participants');
                }
                $seen[$userid] = true;
                $roles[$userid] ??= $role; // Owner precedence, not an identity collapse.
            }
        }
        $desired = []; $userids = [];
        foreach ($roles as $userid => $role) {
            $users = $DB->get_records('user', ['id' => $userid, 'deleted' => 0]);
            $identities = $DB->get_records('local_o365_objects', ['type' => 'user', 'moodleid' => $userid]);
            if (count($users) !== 1 || count($identities) !== 1) {
                throw new \RuntimeException('Ambiguous participant identity');
            }
            $identity = reset($identities);
            $id = $identity->objectid ?? null;
            if (!self::uuid($id) || isset($desired[$id])) {
                throw new \RuntimeException('Ambiguous participant identity');
            }
            $collisions = self::identity_rows($id);
            $unique = reset($collisions);
            if (count($collisions) !== 1 || self::userid($unique->moodleid) !== $userid || $unique->objectid !== $id) {
                throw new \RuntimeException('Ambiguous participant identity');
            }
            $desired[$id] = $role;
            $userids[$id] = $userid;
        }
        ksort($desired, SORT_STRING);
        ksort($userids, SORT_STRING);
        return [$desired, $userids];
    }

    /** Validate associations again after remote reads; a reassigned historical ID is never deletion scope. */
    private function history(array $mapping, array $userids): array {
        $managed = [];
        foreach ($this->store->managed($mapping) as $row) {
            $id = $row['objectid'] ?? null;
            $userid = self::userid($row['userid'] ?? null);
            if (!$userid || !self::uuid($id) || isset($managed[$id]) ||
                    (isset($userids[$id]) && $userids[$id] !== $userid)) {
                throw new \RuntimeException('Invalid managed history');
            }
            // Old identities can lack a local row, but an existing reassignment is not safe scope.
            $identities = self::identity_rows($id);
            if (count($identities) > 1) {
                throw new \RuntimeException('Invalid managed history');
            }
            foreach ($identities as $identity) {
                if (self::userid($identity->moodleid) !== $userid || $identity->objectid !== $id) {
                    throw new \RuntimeException('Invalid managed history');
                }
            }
            $managed[$id] = $userid;
        }
        return $managed;
    }

    private static function identity_rows(string $id): array {
        global $DB;
        // Detect case aliases on both case-sensitive and case-insensitive database collations.
        return $DB->get_records_select('local_o365_objects', 'type = :type AND LOWER(objectid) = :objectid',
            ['type' => 'user', 'objectid' => $id]);
    }

    private function remote(string $teamid, string $role, int &$requests, ?array $mapping = null,
            ?int $serial = null, ?string $identityhash = null, ?int $now = null): array {
        $endpoint = '/groups/' . $teamid . '/' . $role;
        $path = $endpoint . '?$top=' . self::PAGE_SIZE;
        $ids = []; $tokens = []; $total = null;
        do {
            if ($requests >= $this->maxpages) {
                throw new \RuntimeException('Graph membership page limit exceeded');
            }
            $requests++;
            $requestpath = $path;
            $page = self::decode(($this->get)($path));
            if (property_exists($page, '@odata.count')) {
                $count = $page->{'@odata.count'};
                if (!is_int($count) || $count < 0 || ($total !== null && $total !== $count)) {
                    throw new \RuntimeException('Invalid Graph membership response');
                }
                $total = $count;
            }
            $pageids = [];
            foreach ($page->value as $record) {
                if (!$record instanceof \stdClass || ($record->{'@odata.type'} ?? null) !== '#microsoft.graph.user' ||
                        !self::uuid($record->id ?? null) || isset($ids[$record->id])) {
                    throw new \RuntimeException('Invalid Graph membership response');
                }
                $ids[$record->id] = true;
                $pageids[] = $record->id;
            }
            $path = null;
            if (property_exists($page, '@odata.nextLink')) {
                if (!is_string($page->{'@odata.nextLink'}) || $page->{'@odata.nextLink'} === '') {
                    throw new \RuntimeException('Invalid Graph membership response');
                }
                [$path, $token] = self::next($page->{'@odata.nextLink'}, $endpoint);
                if (isset($tokens[$token])) {
                    throw new \RuntimeException('Unsafe Graph membership pagination');
                }
                $tokens[$token] = true;
            }
            if ($total !== null && (count($ids) > $total || ($path === null && count($ids) !== $total))) {
                throw new \RuntimeException('Invalid Graph membership response');
            }
            if ($serial !== null) {
                $this->store->stage_page($mapping, $serial, $role, $requestpath, $pageids, $path,
                    $now, $identityhash);
            }
        } while ($path !== null);
        return array_keys($ids);
    }

    private static function decode($response): \stdClass {
        if (!is_array($response) || ($response['status'] ?? null) !== 200 || ($response['ok'] ?? null) !== true ||
                !is_string($response['body'] ?? null) || $response['body'] === '' || strlen($response['body']) > self::MAX_BODY) {
            throw new \RuntimeException('Invalid Graph membership response');
        }
        try {
            // Object decoding keeps {} distinct from [], including value and every record.
            $page = json_decode($response['body'], false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Invalid Graph membership response');
        }
        if (!$page instanceof \stdClass || !is_array($page->value ?? null) || count($page->value) > self::PAGE_SIZE) {
            throw new \RuntimeException('Invalid Graph membership response');
        }
        foreach (get_object_vars($page) as $key => $value) {
            if (!in_array($key, ['value', '@odata.context', '@odata.count', '@odata.nextLink'], true) ||
                    ($key === '@odata.context' && (!is_string($value) || $value === ''))) {
                throw new \RuntimeException('Invalid Graph membership response');
            }
        }
        self::unique_keys($response['body']);
        return $page;
    }

    /** json_decode otherwise overwrites duplicate keys, potentially hiding a continuation or an ID. */
    private static function unique_keys(string $json): void {
        $stack = []; $length = strlen($json);
        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];
            if ($char === '{' || $char === '[') {
                $stack[] = [];
            } else if ($char === '}' || $char === ']') {
                array_pop($stack);
            } else if ($char === '"') {
                $start = $i++;
                while ($i < $length && $json[$i] !== '"') {
                    $i += $json[$i] === '\\' ? 2 : 1;
                }
                $after = $i + 1;
                while ($after < $length && str_contains(" \t\r\n", $json[$after])) { $after++; }
                if ($after < $length && $json[$after] === ':') {
                    $key = json_decode(substr($json, $start, $i - $start + 1), true, 32, JSON_THROW_ON_ERROR);
                    $level = count($stack) - 1;
                    if (isset($stack[$level][$key])) {
                        throw new \RuntimeException('Invalid Graph membership response');
                    }
                    $stack[$level][$key] = true;
                }
            }
        }
    }

    /** Never pass an absolute continuation to a transport or upstream pagination helper. */
    private static function next(string $url, string $endpoint): array {
        $parts = parse_url($url);
        if (strlen($url) > 8192 || preg_match('/[^\x21-\x7e]/', $url) || str_contains($url, '\\') ||
                $parts === false || ($parts['scheme'] ?? '') !== 'https' ||
                ($parts['host'] ?? '') !== 'graph.microsoft.com' || isset($parts['port']) || isset($parts['user']) ||
                isset($parts['pass']) || isset($parts['fragment']) || ($parts['path'] ?? '') !== '/v1.0' . $endpoint) {
            throw new \RuntimeException('Unsafe Graph membership pagination');
        }
        $query = $parts['query'] ?? '';
        if ($query === '' || preg_match('/%(?![0-9a-fA-F]{2})/', $query)) {
            throw new \RuntimeException('Unsafe Graph membership pagination');
        }
        $params = [];
        foreach (explode('&', $query) as $pair) {
            $split = explode('=', $pair, 2);
            $key = rawurldecode($split[0]);
            if (count($split) !== 2 || !in_array($key, ['$skiptoken', '$top'], true) || isset($params[$key])) {
                throw new \RuntimeException('Unsafe Graph membership pagination');
            }
            $params[$key] = rawurldecode($split[1]);
        }
        $token = $params['$skiptoken'] ?? '';
        if (!preg_match('/^[\x21-\x7e]{1,4096}$/D', $token) ||
                (isset($params['$top']) && !preg_match('/^[1-9][0-9]{0,2}$/D', $params['$top']))) {
            throw new \RuntimeException('Unsafe Graph membership pagination');
        }
        return [$endpoint . '?$skiptoken=' . rawurlencode($token) .
            (isset($params['$top']) ? '&$top=' . $params['$top'] : ''), $token];
    }

    private static function userid($id): int {
        if (is_int($id)) { return $id > 0 ? $id : 0; }
        return is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id) ? (filter_var($id, FILTER_VALIDATE_INT) ?: 0) : 0;
    }

    private static function uuid($id): bool {
        return is_string($id) && (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $id);
    }

    private static function version(): void {
        if (!in_array(get_config('local_o365', 'version'), [2025040835, '2025040835'], true)) {
            throw new \RuntimeException('This adapter requires local_o365 version 2025040835 exactly.');
        }
    }
}
