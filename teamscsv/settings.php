<?php
// SPDX-License-Identifier: GPL-3.0-or-later
defined('MOODLE_INTERNAL') || die();
if ($hassiteconfig && is_siteadmin()) {
    $ADMIN->add('localplugins', new admin_externalpage('local_teamscsv',
        get_string('pluginname', 'local_teamscsv'), new moodle_url('/local/teamscsv/index.php'),
        'moodle/site:config'));
    $settings = new admin_settingpage('local_teamscsv_links', get_string('autolinkssettings', 'local_teamscsv'));
    $settings->add(new admin_setting_configcheckbox('local_teamscsv/autolinks_enabled',
        get_string('autolinksenabled', 'local_teamscsv'),
        get_string('autolinksdescription', 'local_teamscsv'), 0));
    $settings->add(new admin_setting_configtext('local_teamscsv/autolinks_courses',
        get_string('autolinkscourses', 'local_teamscsv'), '', 5, PARAM_INT));
    $settings->add(new admin_setting_configtext('local_teamscsv/autolinks_requests',
        get_string('autolinksrequests', 'local_teamscsv'), '', 12, PARAM_INT));
    $ADMIN->add('localplugins', $settings);
    $membership = new admin_settingpage('local_teamscsv_membership', get_string('membershipsettings', 'local_teamscsv'));
    $membership->add(new admin_setting_configcheckbox('local_teamscsv/membership_enabled',
        get_string('membershipenabled', 'local_teamscsv'), get_string('membershipdescription', 'local_teamscsv'), 0));
    foreach (['courses' => 5, 'jobs' => 1, 'requests' => 8] as $key => $default) {
        $membership->add(new admin_setting_configtext('local_teamscsv/membership_' . $key,
            get_string('membership' . $key, 'local_teamscsv'),
            get_string('membershipbudgets', 'local_teamscsv'), $default, PARAM_INT));
    }
    $ADMIN->add('localplugins', $membership);
}
