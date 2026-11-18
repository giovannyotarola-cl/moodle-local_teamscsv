<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_teamscsv;

defined('MOODLE_INTERNAL') || die();

/** CSV validation and a version-pinned adapter for local_o365 5.0.7. */
final class importer {
    const MAXROWS = 100;
    const MAXBYTES = 1048576;

    /** Parse UTF-8 comma-separated CSV without touching Moodle or Microsoft. */
    public static function parse(string $content): array {
        if (strlen($content) > self::MAXBYTES || !preg_match('//u', $content)) {
            throw new \InvalidArgumentException('CSV must be UTF-8 and no larger than 1 MiB.');
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        try {
            if (fgetcsv($stream, 0, ',', '"', '') !== ['course_id', 'team_name']) {
                throw new \InvalidArgumentException('Expected header: course_id,team_name');
            }
            $rows = [];
            $courses = [];
            $teams = [];
            while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== 2 || trim($values[0]) === '' || trim($values[1]) === '') {
                    throw new \InvalidArgumentException('Each record must contain a course ID and a Team name.');
                }
                [$course, $team] = array_map('trim', $values);
                $course = self::validate_course_id($course);
                if (isset($courses[$course]) || isset($teams[$team])) {
                    throw new \InvalidArgumentException('Duplicate course ID or Team name in CSV.');
                }
                $courses[$course] = $teams[$team] = true;
                $rows[] = ['course_id' => $course, 'team_name' => $team];
                if (count($rows) > self::MAXROWS) {
                    throw new \InvalidArgumentException('Maximum 100 records per import.');
                }
            }
            if (!$rows) {
                throw new \InvalidArgumentException('CSV contains no records.');
            }
            return $rows;
        } finally {
            fclose($stream);
        }
    }

    /** Validate before integer conversion or database lookup; never coerce malformed IDs. */
    public static function validate_course_id($value): int {
        if ((!is_string($value) && !is_int($value)) ||
                !preg_match('/^[1-9][0-9]*$/D', (string)$value) ||
                filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new \InvalidArgumentException('Course ID must be a positive integer without leading zeros.');
        }
        return (int)$value;
    }

    /** Never change the global sync direction or silently support a different schema. */
    public static function check_environment(): void {
        if ((int)get_config('local_o365', 'version') !== 2025040835) {
            throw new \RuntimeException('This adapter requires local_o365 version 2025040835 exactly.');
        }
        // is_connected() may refresh and store an OAuth token: keep preview strictly local/read-only.
        if (!\local_o365\utils::is_configured() || !\local_o365\feature\coursesync\utils::is_enabled()) {
            throw new \RuntimeException('Configure Microsoft 365 and enable Course Sync first.');
        }
        if ((int)get_config('local_o365', 'courseusersyncdirection') !== 1) {
            throw new \RuntimeException('Course user sync behavior must already be From Moodle to Teams.');
        }
        membership_guard::require_installed();
    }

    /** Resolve names exactly, including every matching cache entry; never pick the first. */
    public static function preview(array $rows): array {
        global $DB;
        self::check_environment();
        $plan = [];
        foreach ($rows as $row) {
            $courseid = self::validate_course_id($row['course_id']);
            $course = $DB->get_record('course', ['id' => $courseid]);
            if (!$course || $courseid === SITEID) {
                throw new \RuntimeException('Course ID not found or is the site course: ' . $courseid);
            }
            if (!$course->visible && !get_config('local_o365', 'sync_hidden_courses')) {
                throw new \RuntimeException('Hidden course requires Sync hidden courses: ' . $course->id);
            }
            $teams = array_values($DB->get_records('local_o365_groups_cache', ['name' => $row['team_name'], 'has_team' => 1]));
            $teams = array_values(array_filter($teams, function($t) use ($row) {
                return $t->name === $row['team_name'];
            }));
            if (count($teams) !== 1) {
                throw new \RuntimeException('Team name not found or ambiguous in cache: ' . $row['team_name']);
            }
            $team = $teams[0];
            if ((int)($team->locked ?? TEAM_LOCKED_STATUS_UNKNOWN) !== TEAM_UNLOCKED ||
                    !empty($team->not_found_since) ||
                    !preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $team->objectid)) {
                throw new \RuntimeException('Team is locked, unavailable, or has an invalid ID: ' . $team->name);
            }
            // Refuse every existing connection, including stale partial connections. No relinking or deletion.
            if ($DB->record_exists('local_o365_objects', ['type' => 'group', 'moodleid' => $course->id]) ||
                    $DB->record_exists('local_o365_objects', ['type' => 'group', 'objectid' => $team->objectid]) ||
                    $DB->record_exists('local_o365_course_request', ['courseid' => $course->id]) ||
                    $DB->record_exists('local_o365_course_request', ['teamoid' => $team->objectid])) {
                throw new \RuntimeException('Existing connection or course request; resolve manually: ' . $course->id);
            }
            if (!\local_o365\feature\coursesync\utils::get_team_owner_object_ids_by_course_id((int)$course->id)) {
                throw new \RuntimeException('Course needs a Microsoft-connected Team owner: ' . $course->id);
            }
            $plan[] = ['course_name' => $course->fullname, 'team_name' => $team->name,
                'courseid' => (int)$course->id, 'teamid' => $team->objectid];
        }
        if (count(array_unique(array_column($plan, 'courseid'))) !== count($plan) ||
                count(array_unique(array_column($plan, 'teamid'))) !== count($plan)) {
            throw new \RuntimeException('Multiple records resolve to the same course or Team.');
        }
        return $plan;
    }

    /** Stage mappings, isolation registry and dirty work atomically. No Microsoft operations or executor yet. */
    public static function apply(array $rows, array $expected): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/local/o365/lib.php');
        if (!is_siteadmin()) {
            throw new \required_capability_exception(\context_system::instance(), 'moodle/site:config', 'nopermissions', '');
        }
        require_capability('moodle/site:config', \context_system::instance());
        // This plugin's lock cannot lock the upstream UI. Operators must isolate the import window.
        $factory = \core\lock\lock_config::get_lock_factory('local_teamscsv');
        $lock = $factory->get_lock('import', 0);
        if (!$lock) {
            throw new \RuntimeException('Another CSV import is running.');
        }
        try {
            // Preserve preview's existing rejection order before deriving the lock set; recheck under locks below.
            $preflight = self::preview($rows);
            if ($preflight !== $expected) {
                throw new \RuntimeException('Preview changed. Upload the CSV again before applying.');
            }
            if (!$preflight) {
                return 0;
            }
            $courseids = array_column($preflight, 'courseid');
            $store = new membership_store();
            return $store->import_transaction($courseids, function() use ($rows, $expected, $store) {
                global $DB;
                $plan = self::preview($rows);
                if ($plan !== $expected) {
                    throw new \RuntimeException('Preview changed. Upload the CSV again before applying.');
                }
                foreach ($plan as $row) {
                    foreach (['course', 'courseteam'] as $subtype) {
                        $record = (object)['type' => 'group', 'subtype' => $subtype,
                            'objectid' => $row['teamid'], 'moodleid' => $row['courseid'],
                            'o365name' => $row['team_name'], 'tenant' => '', 'metadata' => null,
                            'timecreated' => time(), 'timemodified' => time()];
                        $DB->insert_record('local_o365_objects', $record);
                    }
                    \local_o365\feature\coursesync\utils::set_course_sync_enabled($row['courseid'], true);
                    // Reuse generation/history/dirty invariants without a nested register() transaction.
                    $store->register_imported_course($row['courseid'], $row['teamid'], time());
                    membership_guard::assert_isolated($row['courseid'], $row['teamid']);
                }
                // The store's dirty job is the queue. Never queue the old destructive upstream task.
                return count($plan);
            });
        } finally {
            $lock->release();
        }
    }
}
