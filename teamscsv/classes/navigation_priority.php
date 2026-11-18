<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_teamscsv;

defined('MOODLE_INTERNAL') || die();

/** Prioritize existing TeamsCSV nodes without granting access or changing links. */
final class navigation_priority {
    public static function extend(\core\hook\navigation\secondary_extend $hook): void {
        global $PAGE;
        if (!($PAGE->context instanceof \context_course) || (int)$PAGE->course->id === SITEID ||
                $PAGE->course->format === 'singleactivity') {
            return;
        }
        $view = $hook->get_secondaryview();
        $priority = ['coursehome', 'local_teamscsv_meeting', 'local_teamscsv_recordings',
            'local_teamscsv_material', 'courseoverview'];
        $nodes = [];
        foreach ($view->children as $node) {
            $nodes[$node->key] = $node;
        }
        // Our existing navigation callback performs the Team mapping checks.
        // Do not affect unlinked courses or partial/non-course navigation trees.
        foreach (array_slice($priority, 0, 4) as $required) {
            if (!isset($nodes[$required])) {
                return;
            }
        }
        // Short labels belong to this menu only; full page headings and buttons
        // continue using their existing language keys.
        foreach (['meeting', 'recordings', 'material'] as $kind) {
            $node = $nodes['local_teamscsv_' . $kind];
            $node->text = $node->shorttext = get_string('nav' . $kind, 'local_teamscsv');
        }
        $beforekey = null;
        foreach ($nodes as $key => $node) {
            $preferred = in_array($key, $priority, true);
            // This public flag affects only this node. The setter is recursive and
            // would also alter unrelated submenu preferences.
            $node->forceintomoremenu = !$preferred;
            if ($preferred) {
                $view->children->remove($node->key, $node->type);
            } else if ($beforekey === null) {
                $beforekey = $node->key;
            }
        }
        foreach ($priority as $key) {
            if (isset($nodes[$key])) {
                $view->add_node($nodes[$key], $beforekey);
            }
        }
    }
}
