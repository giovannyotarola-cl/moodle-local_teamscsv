<?php
// SPDX-License-Identifier: GPL-3.0-or-later
defined('MOODLE_INTERNAL') || die();

/** Add courseadmin children consumed by Moodle's course secondary navigation. */
function local_teamscsv_extend_settings_navigation($settingsnav, $context): void {
    if (!($context instanceof \context_course)) {
        return;
    }
    $courseid = (int)$context->instanceid;
    if (\local_teamscsv\course_links::mapped_team($courseid) === null) {
        return;
    }
    $courseadmin = $settingsnav->find('courseadmin', \navigation_node::TYPE_COURSE);
    if (!$courseadmin) {
        return;
    }
    foreach (['meeting' => 'tabmeeting', 'recordings' => 'tabrecordings', 'material' => 'tabmaterial'] as $kind => $label) {
        $key = 'local_teamscsv_' . $kind;
        // The callback can be called more than once while building navigation.
        $exists = false;
        foreach ($courseadmin->children as $child) {
            if ($child->key === $key) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $courseadmin->add(get_string($label, 'local_teamscsv'),
                new \moodle_url('/local/teamscsv/links.php', ['id' => $courseid, 'kind' => $kind]),
                \navigation_node::TYPE_CUSTOM, null, $key);
        }
    }
}
