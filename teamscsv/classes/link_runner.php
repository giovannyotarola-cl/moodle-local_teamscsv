<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_teamscsv;

defined('MOODLE_INTERNAL') || die();

/** Bounded, least-recently-checked traversal; reader and publisher are injectable for isolated tests. */
final class link_runner {
    private $discover;
    private $publish;

    public function __construct(callable $discover, callable $publish) {
        $this->discover = $discover;
        $this->publish = $publish;
    }

    private static function mapping(int $courseid, string $teamid): bool {
        global $DB;
        if ($courseid === SITEID || !$DB->record_exists('course', ['id' => $courseid])) {
            return false;
        }
        $rows = $DB->get_records('local_o365_objects', ['type' => 'group', 'moodleid' => $courseid]);
        if (count($rows) !== 2) {
            return false;
        }
        $found = [];
        foreach ($rows as $row) {
            if (!in_array($row->subtype, ['course', 'courseteam'], true) || $row->objectid !== $teamid ||
                    isset($found[$row->subtype])) {
                return false;
            }
            $found[$row->subtype] = true;
        }
        return count($found) === 2;
    }

    public function run(int $maxcourses = 5, int $budget = 12): void {
        global $DB;
        $maxcourses = max(1, min(25, $maxcourses));
        $budget = max(1, min(50, $budget));
        // Select only valid, unambiguous pairs at the database before applying the page limit.
        // This avoids fetching every mapping into PHP and prevents invalid rows starving the page.
        $sql = "SELECT pairs.courseid AS id, pairs.teamid
                  FROM (SELECT o.moodleid AS courseid, MIN(o.objectid) AS teamid
                          FROM {local_o365_objects} o
                         WHERE o.type = :grouptype AND o.moodleid <> :siteid
                      GROUP BY o.moodleid
                        HAVING COUNT(*) = 2
                           AND SUM(CASE WHEN o.subtype = :course THEN 1 ELSE 0 END) = 1
                           AND SUM(CASE WHEN o.subtype = :courseteam THEN 1 ELSE 0 END) = 1
                           AND MIN(o.objectid) = MAX(o.objectid)) pairs
                  JOIN {course} c ON c.id = pairs.courseid
             LEFT JOIN {local_teamscsv_scan} s ON s.courseid = pairs.courseid
              ORDER BY COALESCE(s.lastchecked, 0), pairs.courseid";
        $params = ['grouptype' => 'group', 'siteid' => SITEID,
            'course' => 'course', 'courseteam' => 'courseteam'];
        foreach ($DB->get_records_sql($sql, $params, 0, $maxcourses) as $pair) {
            $courseid = (int)$pair->id;
            $teamid = (string)$pair->teamid;
            $state = $DB->get_record('local_teamscsv_scan', ['courseid' => $courseid]);
            try {
                if (!self::mapping($courseid, $teamid)) {
                    throw new \RuntimeException('Mapping changed.');
                }
                $cursor = null;
                if ($state && $state->teamid === $teamid && $state->scan_cursor !== null) {
                    $cursor = json_decode($state->scan_cursor, true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($cursor)) {
                        throw new \RuntimeException('Invalid stored cursor.');
                    }
                }
                $result = ($this->discover)($teamid, $cursor, $budget);
                self::validate_result($result, $budget);
                if (!self::mapping((int)$courseid, $teamid)) {
                    throw new \RuntimeException('Mapping changed.');
                }
                foreach ($result['candidates'] as $candidate) {
                    if (!self::mapping((int)$courseid, $teamid)) {
                        throw new \RuntimeException('Mapping changed.');
                    }
                    ($this->publish)((int)$courseid, $teamid, $candidate);
                }
                $next = !empty($result['complete']) ? null : json_encode($result['cursor'], JSON_THROW_ON_ERROR);
                $status = !empty($result['complete']) ? 'pending' : 'progress';
                $message = null;
            } catch (\Throwable $e) {
                $next = $state && $state->teamid === $teamid ? $state->scan_cursor : null;
                $status = 'error';
                $message = 'Discovery or publication failed; inspect configuration and retry.';
            }
            $record = (object)['courseid' => (int)$courseid, 'teamid' => $teamid, 'scan_cursor' => $next,
                'lastchecked' => time(), 'status' => $status, 'message' => $message];
            if ($state) {
                $record->id = $state->id;
                $DB->update_record('local_teamscsv_scan', $record);
            } else {
                $DB->insert_record('local_teamscsv_scan', $record);
            }
            mtrace('Teams links: one course checked; status=' . $status . '.');
        }
    }

    private static function validate_result($result, int $budget): void {
        if (!is_array($result) || !isset($result['candidates'], $result['complete'],
                $result['requests'], $result['warnings']) || !is_array($result['candidates']) ||
                !is_bool($result['complete']) || !is_int($result['requests']) ||
                $result['requests'] < 0 || $result['requests'] > $budget || !is_array($result['warnings']) ||
                !array_is_list($result['candidates']) || !array_is_list($result['warnings']) ||
                !array_key_exists('cursor', $result) ||
                ($result['complete'] ? $result['cursor'] !== null : !is_array($result['cursor']))) {
            throw new \RuntimeException('Invalid discovery result.');
        }
        foreach ($result['warnings'] as $warning) {
            if (!is_string($warning)) {
                throw new \RuntimeException('Invalid discovery warning.');
            }
        }
        foreach ($result['candidates'] as $candidate) {
            if (!is_array($candidate) || !isset($candidate['kind'], $candidate['url'], $candidate['sourceid']) ||
                    !in_array($candidate['kind'], ['meeting', 'recordings', 'material'], true) ||
                    !is_string($candidate['url']) || !is_string($candidate['sourceid'])) {
                throw new \RuntimeException('Invalid discovery candidate.');
            }
        }
    }
}
