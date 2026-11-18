<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// No Moodle bootstrap, database, OAuth or Graph access.
if (!defined('MOODLE_INTERNAL')) {
    define('MOODLE_INTERNAL', true);
}
if (!class_exists(\local_teamscsv\cache_cli::class)) {
    require_once(__DIR__ . '/../classes/cache_cli.php');
}
$help = "Usage: php local/teamscsv/cli/refresh_cache.php --confirm-idle\n" .
    "Update only the local_o365 groups/Teams cache. Does not sync courses or memberships.\n" .
    "--confirm-idle  Confirm no web refresh, cache task or course sync is running.\n" .
    "--help, -h      Show this help without loading Moodle or contacting Microsoft.\n" .
    "Use a persistent SSH session (tmux/screen). The CLI lock does not block upstream jobs.\n";
$cases = [
    [[], true, 2, '', "Refusing to start: --confirm-idle is required.\n", 0],
    [['--help'], true, 0, $help, '', 0],
    [['-h'], true, 0, $help, '', 0],
    [['--unknown'], true, 2, '', "Unknown or repeated option: --unknown\n", 0],
    [['--confirm-idle', '--confirm-idle'], true, 2, '', "Unknown or repeated option: --confirm-idle\n", 0],
    [['--confirm-idle'], true, 0, "Starting cache refresh only.\nCache refresh completed successfully.\n", '', 1],
    [['--confirm-idle'], false, 1, "Starting cache refresh only.\n", "Cache refresh did not complete successfully. Check upstream output and Moodle logs.\n", 1],
    [['--confirm-idle'], null, 1, "Starting cache refresh only.\n", "Cache refresh did not complete successfully. Check upstream output and Moodle logs.\n", 1],
    [['--confirm-idle'], 'exception', 1, "Starting cache refresh only.\n", "Cache refresh failed. Check upstream output and Moodle logs; do not start a parallel refresh.\n", 1],
];
$failures = 0;
foreach ($cases as $index => [$args, $result, $expectedcode, $expectedout, $expectederr, $expectedcalls]) {
    $calls = 0;
    $out = fopen('php://temp', 'r+');
    $err = fopen('php://temp', 'r+');
    $action = function() use (&$calls, $result) {
        $calls++;
        if ($result === 'exception') {
            throw new RuntimeException('Do not expose secrets in exception text');
        }
        return $result;
    };
    $code = \local_teamscsv\cache_cli::execute($args, $action, $out, $err);
    rewind($out);
    rewind($err);
    $ok = $code === $expectedcode && stream_get_contents($out) === $expectedout &&
        stream_get_contents($err) === $expectederr && $calls === $expectedcalls;
    fclose($out);
    fclose($err);
    echo ($ok ? 'PASS ' : 'FAIL ') . 'cache CLI case ' . $index . PHP_EOL;
    $failures += $ok ? 0 : 1;
}
exit($failures ? 1 : 0);
