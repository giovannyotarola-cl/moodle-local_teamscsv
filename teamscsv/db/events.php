<?php
// SPDX-License-Identifier: GPL-3.0-or-later
defined('MOODLE_INTERNAL') || die();
$observers = [];
foreach (['user_enrolment_created', 'user_enrolment_deleted', 'user_enrolment_updated',
        'role_assigned', 'role_unassigned'] as $event) {
    $observers[] = ['eventname' => '\\core\\event\\' . $event,
        'callback' => '\\local_teamscsv\\observers::membership_changed'];
}
