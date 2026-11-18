<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_teamscsv\task;

defined('MOODLE_INTERNAL') || die();

/** Optional periodic discovery; an off switch prevents even authentication. */
final class discover_links extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('autolinkstask', 'local_teamscsv');
    }

    public function execute() {
        if (!get_config('local_teamscsv', 'autolinks_enabled')) {
            return;
        }
        if ((int)get_config('local_o365', 'version') !== 2025040835) {
            throw new \RuntimeException('This adapter requires local_o365 version 2025040835 exactly.');
        }
        $factory = \core\lock\lock_config::get_lock_factory('local_teamscsv');
        $lock = $factory->get_lock('discover_links', 0);
        if (!$lock) {
            return;
        }
        try {
            $api = \local_o365\utils::get_api();
            if (!$api) {
                throw new \RuntimeException('Graph client unavailable.');
            }
            $get = static function(string $path) use ($api): array {
                $body = $api->apicall('get', $path);
                if (!is_string($body)) {
                    throw new \RuntimeException('Graph GET failed.');
                }
                try {
                    $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new \RuntimeException('Graph GET returned invalid JSON.');
                }
                if (!is_array($result) || array_is_list($result)) {
                    throw new \RuntimeException('Graph GET failed.');
                }
                if (isset($result['error'])) {
                    // Only a not-yet-provisioned channel folder is pending. The reader owns
                    // that classification; permission/throttle errors must still fail closed.
                    $pendingfolder = str_ends_with(parse_url($path, PHP_URL_PATH) ?? '', '/filesFolder') &&
                        is_array($result['error']) && ($result['error']['code'] ?? '') === 'itemNotFound';
                    if (!$pendingfolder) {
                        throw new \RuntimeException('Graph GET failed.');
                    }
                }
                return $result;
            };
            $publisher = new \local_teamscsv\link_publisher();
            $reader = new \local_teamscsv\link_discovery($get);
            $runner = new \local_teamscsv\link_runner(
                static function(string $teamid, ?array $cursor, int $budget) use ($reader): array {
                    return $reader->discover($teamid, $cursor, $budget);
                },
                static function(int $courseid, string $teamid, array $candidate) use ($publisher): void {
                    $publisher->publish($courseid, $teamid, $candidate);
                }
            );
            $runner->run((int)(get_config('local_teamscsv', 'autolinks_courses') ?: 5),
                (int)(get_config('local_teamscsv', 'autolinks_requests') ?: 12));
        } finally {
            $lock->release();
        }
    }
}
