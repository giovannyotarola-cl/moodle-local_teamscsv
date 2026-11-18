<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_teamscsv\privacy;

defined('MOODLE_INTERNAL') || die();

/** No plugin-owned persistent personal data storage. */
final class provider implements \core_privacy\local\metadata\null_provider {
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
