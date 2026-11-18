<?php
// SPDX-License-Identifier: GPL-3.0-or-later
defined('MOODLE_INTERNAL') || die();
$tasks = [
    ['classname' => '\\local_teamscsv\\task\\discover_links', 'blocking' => 0,
        'minute' => '*/15', 'hour' => '*', 'day' => '*', 'month' => '*', 'dayofweek' => '*'],
    ['classname' => '\\local_teamscsv\\task\\reconcile_memberships', 'blocking' => 0,
        'minute' => '*', 'hour' => '*', 'day' => '*', 'month' => '*', 'dayofweek' => '*'],
];
