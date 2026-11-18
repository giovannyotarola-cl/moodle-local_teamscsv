<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_teamscsv\task;

defined('MOODLE_INTERNAL') || die();

/** Core retries exceptions (including lock contention); no terminal discard of a valid hint. */
final class mark_membership_dirty extends \core\task\adhoc_task {
    public function execute() {
        $data = $this->get_custom_data();
        // Old/pre-upgrade, malformed and retired pointers cannot authorize local or remote writes.
        if (!is_object($data) || !is_int($data->courseid ?? null) || $data->courseid < 1 || $data->courseid === SITEID ||
                !is_int($data->generationid ?? null) || $data->generationid < 1) {
            return;
        }
        \local_teamscsv\membership_runner::mark_dirty($data->courseid, $data->generationid, time());
    }
}
