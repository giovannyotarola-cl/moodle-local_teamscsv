<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_teamscsv;

defined('MOODLE_INTERNAL') || die();

/** Events are hints, never exactly-once membership writes. The daily fallback remains mandatory. */
final class observers {
    public static function membership_changed($event): void {
        if (!membership_runner::enabled() || (int)$event->contextlevel !== CONTEXT_COURSE ||
                (int)$event->contextinstanceid !== (int)$event->courseid) {
            return;
        }
        try {
            membership_runner::queue_dirty((int)$event->courseid);
        } catch (\Throwable $e) {
            // Never abort the enrolment/role transaction or assume queue delivery. No store mutation
            // or Graph here; guarded daily traversal repairs missing hints after recovery.
        }
    }
}
