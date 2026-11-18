<?php
// SPDX-License-Identifier: GPL-3.0-or-later
defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core\hook\navigation\secondary_extend::class,
        'callback' => \local_teamscsv\navigation_priority::class . '::extend',
    ],
];
