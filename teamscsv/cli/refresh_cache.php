<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// This entry point intentionally parses help/confirmation before bootstrapping Moodle.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
define('CLI_SCRIPT', true);
define('MOODLE_INTERNAL', true);
require_once(__DIR__ . '/../classes/cache_cli.php');

$refresh = function() {
    global $CFG, $DB;
    // Run as Moodle's web user and from its root; never change config.php permissions.
    require(__DIR__ . '/../../../config.php');
    require_once($CFG->dirroot . '/local/o365/lib.php');
    if ((int)get_config('local_o365', 'version') !== 2025040835) {
        fwrite(STDERR, "Unsupported local_o365 version; expected 2025040835.\n");
        return false;
    }
    // This lock only serializes this CLI. Upstream web/cron refreshes do not acquire it.
    $factory = \core\lock\lock_config::get_lock_factory('local_teamscsv');
    $lock = $factory->get_lock('cache_refresh', 0);
    if (!$lock) {
        fwrite(STDERR, "Another cache CLI is running.\n");
        return false;
    }
    try {
        raise_memory_limit(MEMORY_HUGE);
        set_time_limit(0);
        $client = \local_o365\feature\coursesync\utils::get_graphclient();
        if (!$client) {
            fwrite(STDERR, "Graph client unavailable. Check Microsoft 365 configuration and Course Sync.\n");
            return false;
        }
        // Force refresh bypasses only upstream's five-minute automatic refresh throttle.
        // This does not call sync_courses(), alter enrolments, or synchronize memberships.
        return \local_o365\utils::update_groups_cache($client, 0, true);
    } finally {
        $lock->release();
    }
};
exit(\local_teamscsv\cache_cli::execute(array_slice($argv, 1), $refresh, STDOUT, STDERR));
