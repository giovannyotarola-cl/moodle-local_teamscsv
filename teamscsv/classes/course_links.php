<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_teamscsv;

defined('MOODLE_INTERNAL') || die();

/** Read-only course-specific links from the currently verified local_o365 Team. */
final class course_links {
    /** Match link_runner's two-row course/courseteam rule, without trusting a request Team ID. */
    public static function mapped_team(int $courseid): ?string {
        global $DB;
        if ($courseid === SITEID || !$DB->record_exists('course', ['id' => $courseid])) {
            return null;
        }
        $rows = $DB->get_records('local_o365_objects', ['type' => 'group', 'moodleid' => $courseid]);
        if (count($rows) !== 2) {
            return null;
        }
        $found = [];
        $teamid = null;
        foreach ($rows as $row) {
            if (!in_array($row->subtype, ['course', 'courseteam'], true) ||
                    isset($found[$row->subtype]) || !is_string($row->objectid) ||
                    $row->objectid === '' || ($teamid !== null && $teamid !== $row->objectid)) {
                return null;
            }
            $found[$row->subtype] = true;
            $teamid = $row->objectid;
        }
        return count($found) === 2 ? $teamid : null;
    }

    /** Course access is checked before any tablink lookup or page output. */
    public static function display(int $courseid, string $kind): void {
        global $DB, $PAGE, $OUTPUT;
        $course = get_course($courseid);
        require_login($course);
        if (!in_array($kind, ['meeting', 'recordings', 'material'], true)) {
            throw new \moodle_exception('invalidparameter');
        }
        $teamid = self::mapped_team($courseid);
        // Missing or ambiguous mappings have no links to disclose, even if old rows remain.
        $links = $teamid === null ? [] : $DB->get_records('local_teamscsv_tablink',
            ['courseid' => $courseid, 'teamid' => $teamid, 'kind' => $kind], 'id ASC');
        $title = get_string('tab' . $kind, 'local_teamscsv');
        $PAGE->set_url(new \moodle_url('/local/teamscsv/links.php', ['id' => $courseid, 'kind' => $kind]));
        $PAGE->set_context(\context_course::instance($courseid));
        $PAGE->set_title($title);
        $PAGE->set_heading($course->fullname);
        echo $OUTPUT->header();
        echo $OUTPUT->heading($title);
        $destinations = [];
        foreach ($links as $link) {
            $url = $link->url ?? null;
            if (is_string($url) && link_publisher::is_valid_destination($kind, $url)) {
                $destinations[] = $url;
            }
        }
        $label = get_string('open' . $kind, 'local_teamscsv');
        foreach ($destinations as $index => $url) {
            // Number only valid displayed links, in stored order; never expose the raw URL as text.
            $text = $label . (count($destinations) > 1 ? ' (' . ($index + 1) . ')' : '');
            echo \html_writer::div(\html_writer::link($url, s($text),
                ['class' => 'btn btn-primary', 'target' => '_blank', 'rel' => 'noopener noreferrer']), 'mb-3');
        }
        if (!$destinations) {
            echo \html_writer::div(get_string('tablinksempty', 'local_teamscsv'));
        }
        echo $OUTPUT->footer();
    }
}
