<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_teamscsv;

defined('MOODLE_INTERNAL') || die();

/** Pure plan only; see MEMBERSHIP-SYNC.md for evidence and execution requirements. */
final class membership_plan {
    public static function build(array $input): array {
        $mapping = $input['mapping'] ?? null;
        $verified = $input['verifiedmapping'] ?? null;
        if (!is_array($mapping) || !is_array($verified) ||
                !is_int($mapping['courseid'] ?? null) || $mapping['courseid'] < 1 ||
                !self::identity($mapping['teamid'] ?? null) ||
                !is_string($mapping['revision'] ?? null) || trim($mapping['revision']) === '') {
            throw new \InvalidArgumentException('Unverified or stale mapping');
        }
        foreach (['courseid', 'teamid', 'revision'] as $key) {
            if (($verified[$key] ?? null) !== $mapping[$key]) {
                throw new \InvalidArgumentException('Unverified or stale mapping');
            }
        }
        // A missing Microsoft identity is NOT evidence that a participant left the course.
        foreach (['participants', 'identities', 'history', 'owners', 'members'] as $key) {
            if (($input['complete'][$key] ?? null) !== true) {
                throw new \InvalidArgumentException('Incomplete membership evidence');
            }
        }
        $desired = $input['desired'] ?? null;
        if (!is_array($desired)) {
            throw new \InvalidArgumentException('Invalid desired roles');
        }
        foreach ($desired as $id => $role) {
            if (!self::identity($id) || !in_array($role, ['owner', 'member'], true)) {
                throw new \InvalidArgumentException('Invalid desired roles');
            }
        }
        ksort($desired, SORT_STRING);
        $managed = self::identities($input['managed'] ?? null);
        $owners = self::identities($input['owners'] ?? null);
        $members = self::identities($input['members'] ?? null);
        $scope = array_values(array_unique(array_merge(array_keys($desired), $managed)));
        sort($scope, SORT_STRING);
        $operations = []; $blocked = []; $release = [];
        // Establish membership first, then ALL replacement owners before any deletion.
        foreach ($desired as $id => $role) {
            if (!in_array($id, $members, true)) {
                $operations[] = ['action' => 'add_member', 'id' => $id];
                $members[] = $id;
            }
        }
        foreach ($desired as $id => $role) {
            if ($role === 'owner' && !in_array($id, $owners, true)) {
                $operations[] = ['action' => 'add_owner', 'id' => $id];
                $owners[] = $id;
            }
        }
        foreach ($scope as $id) {
            if (($desired[$id] ?? null) === 'owner') {
                continue;
            }
            if (in_array($id, $owners, true)) {
                if (count($owners) === 1) {
                    $blocked[] = ['id' => $id, 'reason' => 'last_owner'];
                    continue; // Also retain membership and historical scope for retry.
                }
                $operations[] = ['action' => 'remove_owner', 'id' => $id];
                $owners = array_values(array_diff($owners, [$id]));
            }
            if (!isset($desired[$id])) {
                if (in_array($id, $members, true)) {
                    $operations[] = ['action' => 'remove_member', 'id' => $id];
                    $members = array_values(array_diff($members, [$id]));
                }
                $release[] = $id;
            }
        }
        return [
            'mapping' => ['courseid' => $mapping['courseid'], 'teamid' => $mapping['teamid'],
                'revision' => $mapping['revision']],
            'operations' => $operations,
            'predicted' => ['owners' => $owners, 'members' => $members],
            'blocked' => $blocked,
            'baseline' => [
                'adopt' => array_values(array_diff(array_keys($desired), $managed)),
                'release' => $release,
                'retain' => array_values(array_diff($scope, $release)),
            ],
        ];
    }

    /** Require canonical object IDs rather than silently conflating ambiguous identities. */
    private static function identity($id): bool {
        return is_string($id) && (bool)preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $id);
    }

    private static function identities($ids): array {
        if (!is_array($ids) || !array_is_list($ids)) {
            throw new \InvalidArgumentException('Invalid identity set');
        }
        foreach ($ids as $id) {
            if (!self::identity($id)) {
                throw new \InvalidArgumentException('Invalid identity set');
            }
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw new \InvalidArgumentException('Invalid identity set');
        }
        return $ids;
    }
}
