<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_teamscsv;

defined('MOODLE_INTERNAL') || die();

/** Command boundary, testable without Moodle or Microsoft. */
final class cache_cli {
    public static function execute(array $args, callable $refresh, $stdout, $stderr): int {
        $seen = [];
        foreach ($args as $arg) {
            if (!in_array($arg, ['--confirm-idle', '--help', '-h'], true) || isset($seen[$arg])) {
                fwrite($stderr, 'Unknown or repeated option: ' . $arg . "\n");
                return 2;
            }
            $seen[$arg] = true;
        }
        if (isset($seen['--help']) || isset($seen['-h'])) {
            fwrite($stdout, "Usage: php local/teamscsv/cli/refresh_cache.php --confirm-idle\n" .
                "Update only the local_o365 groups/Teams cache. Does not sync courses or memberships.\n" .
                "--confirm-idle  Confirm no web refresh, cache task or course sync is running.\n" .
                "--help, -h      Show this help without loading Moodle or contacting Microsoft.\n" .
                "Use a persistent SSH session (tmux/screen). The CLI lock does not block upstream jobs.\n");
            return 0;
        }
        if (!isset($seen['--confirm-idle'])) {
            fwrite($stderr, "Refusing to start: --confirm-idle is required.\n");
            return 2;
        }
        fwrite($stdout, "Starting cache refresh only.\n");
        try {
            if ($refresh() !== true) {
                fwrite($stderr, "Cache refresh did not complete successfully. Check upstream output and Moodle logs.\n");
                return 1;
            }
        } catch (\Throwable $e) {
            // Avoid exposing credentials or tokens carried by an upstream exception message.
            fwrite($stderr, "Cache refresh failed. Check upstream output and Moodle logs; do not start a parallel refresh.\n");
            return 1;
        }
        fwrite($stdout, "Cache refresh completed successfully.\n");
        return 0;
    }
}
