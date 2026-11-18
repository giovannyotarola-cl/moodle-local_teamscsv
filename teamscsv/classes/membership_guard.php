<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_teamscsv;

defined('MOODLE_INTERNAL') || die();

/** Exact reviewed upstream artifact, not a configurable bypass or a version-number-only assertion. */
final class membership_guard {
    // Derived offline by exact-context application of patches/local_o365-managed-membership.patch
    // to ZIP 0835, SHA256 21584c98e162403426a2cd6c5ef1d178aae65cdbc5ee63138594c180b0c5fa2c.
    private const FILES = [
        'feature/coursesync/main.php' => '14b7e7894c79326fe9e03d0d63872e8957e3217787e5f376ca13af956358fcad',
        'feature/coursesync/utils.php' => 'cb804ac7a6009b72f5fb0cd8753808acda151892019183531ea80136eec3d8fa',
        'rest/o365api.php' => 'f498a96a82cde043f2de2b0f7bd2e21bda4d409a447a4b65198d2593028b764e',
    ];

    /** Read-only, uncached; local modifications and partial installations require operator review. */
    public static function require_installed(): void {
        global $CFG;
        $utils = \local_o365\feature\coursesync\utils::class;
        $client = \local_o365\rest\unified::class;
        if (!method_exists($client, 'managed_membership_request') ||
                !(new \ReflectionMethod($client, 'managed_membership_request'))->isPublic() ||
                (new \ReflectionMethod($client, 'managed_membership_request'))->isStatic() ||
                !is_callable([$utils, 'is_membership_managed']) ||
                !defined($utils . '::MANAGED_MEMBERSHIP_GUARD_VERSION') ||
                constant($utils . '::MANAGED_MEMBERSHIP_GUARD_VERSION') !== 1) {
            throw new \RuntimeException('Reviewed local_o365 membership isolation patch is required.');
        }
        foreach (self::FILES as $name => $expected) {
            $path = $CFG->dirroot . '/local/o365/classes/' . $name;
            if (!is_file($path) || !is_readable($path) || @hash_file('sha256', $path) !== $expected) {
                throw new \RuntimeException('Reviewed local_o365 membership isolation patch is required.');
            }
        }
    }

    /** Both course and group-only upstream writers must see the staged durable registration. */
    public static function assert_isolated(int $courseid, string $teamid): void {
        self::require_installed();
        if (\local_o365\feature\coursesync\utils::is_membership_managed($courseid) !== true ||
                \local_o365\feature\coursesync\utils::is_membership_managed(0, $teamid) !== true) {
            throw new \RuntimeException('Upstream membership isolation could not be verified.');
        }
    }
}
