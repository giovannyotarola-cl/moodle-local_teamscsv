<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_teamscsv;

defined('MOODLE_INTERNAL') || die();

/** Persist new course links for tabs without adopting or changing existing URL modules. */
final class link_publisher {
    /** Strict destination validation shared by publication and course display. */
    public static function is_valid_destination(string $kind, string $url): bool {
        $parts = parse_url($url);
        return in_array($kind, ['meeting', 'recordings', 'material'], true) && is_array($parts) &&
            (bool)filter_var($url, FILTER_VALIDATE_URL) && ($parts['scheme'] ?? null) === 'https' &&
            !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port']) &&
            !array_key_exists('fragment', $parts) && !preg_match('/[\\x00-\\x1f\\x7f\\\\]/', $url) &&
            ($kind === 'meeting'
                ? strtolower($parts['host'] ?? '') === 'teams.microsoft.com' &&
                    (bool)preg_match('~^/l/meetup-join/[^/]+/[^/]+~', $parts['path'] ?? '')
                : ($kind === 'material' ? self::is_material_folder($parts)
                    : (bool)preg_match('/(^|\\.)sharepoint\\.com$/i', $parts['host'] ?? '') &&
                        (bool)preg_match('~^/.+/(?:Recordings|Grabaciones)/?$~i', rawurldecode($parts['path'] ?? ''))));
    }

    /** Folder identity comes from filesFolder, not an assumed English folder name. */
    private static function is_material_folder(array $parts): bool {
        if (!str_ends_with(strtolower($parts['host'] ?? ''), '.sharepoint.com') ||
                isset($parts['query']) || empty($parts['path']) || $parts['path'] === '/') {
            return false;
        }
        foreach (explode('/', trim($parts['path'], '/')) as $segment) {
            $decoded = rawurldecode($segment);
            if ($decoded === '' || $decoded === '.' || $decoded === '..' ||
                    str_contains($decoded, '/') || str_contains($decoded, '\\') ||
                    str_contains($decoded, '%') || preg_match('/[\x00-\x1f\x7f]/', $decoded)) {
                return false;
            }
        }
        return true;
    }

    public function publish(int $courseid, string $teamid, array $candidate): void {
        global $DB;
        $kind = $candidate['kind'] ?? null;
        $url = $candidate['url'] ?? null;
        if (!is_string($kind) || !is_string($url) || !self::is_valid_destination($kind, $url)) {
            throw new \InvalidArgumentException('Invalid publication candidate.');
        }
        $key = ['courseid' => $courseid, 'teamid' => $teamid, 'kind' => $kind,
            'urlhash' => hash('sha256', $url)];
        // A legacy association is a tombstone when its module has been edited or removed.
        if ($DB->record_exists('local_teamscsv_link', $key) ||
                $DB->record_exists('local_teamscsv_tablink', $key)) {
            return;
        }
        // If the URL module is installed, an exact manual URL (or interrupted old publication)
        // belongs to its owner. A missing URL module no longer blocks direct tab storage.
        $module = $DB->get_record('modules', ['name' => 'url']);
        if ($module) {
            foreach ($DB->get_records('course_modules', ['course' => $courseid, 'module' => $module->id]) as $cm) {
                $existing = $DB->get_record('url', ['id' => $cm->instance]);
                if ($existing && $existing->externalurl === $url) {
                    return;
                }
            }
        }
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course || $courseid === SITEID) {
            throw new \RuntimeException('Course unavailable.');
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->insert_record('local_teamscsv_tablink', (object)($key + [
                'url' => $url, 'timecreated' => time(),
            ]));
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }
}
