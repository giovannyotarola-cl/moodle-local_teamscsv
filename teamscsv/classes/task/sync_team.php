<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_teamscsv\task;

defined('MOODLE_INTERNAL') || die();

/** Retired adhoc route: validate untrusted queued data, but perform no remote work or success recording. */
final class sync_team extends \core\task\adhoc_task {
    public function execute() {
        \local_teamscsv\importer::check_environment();
        $row = $this->get_custom_data();
        if (!is_object($row) || !is_int($row->courseid ?? null) ||
                !is_string($row->teamid ?? null) || !is_string($row->revision ?? null)) {
            throw new \InvalidArgumentException('Unverified or stale mapping');
        }
        $mapping = ['courseid' => $row->courseid, 'teamid' => $row->teamid, 'revision' => $row->revision];
        if ((new \local_teamscsv\membership_store())->current($row->courseid) !== $mapping) {
            throw new \InvalidArgumentException('Unverified or stale mapping');
        }
        if (!\local_o365\feature\coursesync\utils::is_course_sync_enabled($row->courseid)) {
            throw new \RuntimeException('Course sync disabled; refusing Microsoft operations.');
        }
        \local_teamscsv\membership_guard::assert_isolated($row->courseid, $row->teamid);
        // The persistent dirty job remains untouched for T2c. No client, full sync, app install or confirmation.
        mtrace('CSV membership staged; executor unavailable. No Teams changes performed.');
    }
}
