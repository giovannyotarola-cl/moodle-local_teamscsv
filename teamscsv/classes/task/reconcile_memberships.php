<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_teamscsv\task;

defined('MOODLE_INTERNAL') || die();

/** Eligible every cron tick, but off until the operator opts in after patch/schema verification. */
final class reconcile_memberships extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('membershiptask', 'local_teamscsv');
    }

    public function execute() {
        $result = (new \local_teamscsv\membership_runner())->run(time());
        if ($result['status'] === 'disabled') {
            return;
        }
        mtrace('Teams membership: checked=' . $result['checked'] . '; jobs=' . $result['jobs'] .
            '; completed=' . $result['completed'] . '; pending=' . $result['pending'] .
            '; requests=' . $result['requests'] . '.');
        foreach ($result['diagnostics'] ?? [] as $diagnostic) {
            mtrace($diagnostic);
        }
        if (in_array($result['status'], ['busy', 'pending'], true)) {
            throw new \RuntimeException('Membership work pending; retry on a later cron run.');
        }
    }
}
