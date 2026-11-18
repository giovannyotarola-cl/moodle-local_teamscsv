<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_teamscsv;

defined('MOODLE_INTERNAL') || die();

/** Bounded, resumable read-only discovery of General channel links. */
final class link_discovery {
    private $get;

    public function __construct(callable $get) {
        $this->get = $get;
    }

    public function discover(string $teamid, ?array $cursor = null, int $budget = 12): array {
        if ($teamid === '' || $budget < 1 || $budget > 100) {
            throw new \InvalidArgumentException('Invalid discovery arguments');
        }
        $team = rawurlencode($teamid);
        $state = $cursor ?? ['stage' => 'channel', 'team' => $teamid, 'seen' => [], 'queue' => []];
        $this->validatecursor($state, $teamid);
        $candidates = []; $warnings = []; $requests = 0; $batchseen = []; 
        while ($requests < $budget && $state['stage'] !== 'done') {
            $stage = $state['stage'];
            if ($stage === 'channel') {
                $path = '/teams/' . $team . '/primaryChannel';
            } else if ($stage === 'messages') {
                $path = $state['next'] ?? $this->base($team, $state['channel']) . '/messages?$expand=replies';
            } else if ($stage === 'replies') {
                $path = array_shift($state['queue']);
                if ($path === null) {
                    $state['stage'] = isset($state['rootnext']) ? 'messages' : 'folder';
                    if (isset($state['rootnext'])) {
                        $state['next'] = $state['rootnext']; unset($state['rootnext']);
                    }
                    continue;
                }
            } else if ($stage === 'folder') {
                $path = $this->base($team, $state['channel']) . '/filesFolder';
            } else if ($stage === 'children') {
                $path = $state['next'] ?? $state['childpath'];
            } else {
                throw new \InvalidArgumentException('Invalid discovery cursor stage');
            }
            $this->validatecursor($state, $teamid);
            $response = ($this->get)($path);
            $requests++;
            if (isset($response['error']) || !is_array($response)) {
                if ($stage === 'folder' && ($response['error']['code'] ?? '') === 'itemNotFound') {
                    $warnings[] = 'General files folder is pending';
                    $state['stage'] = 'done';
                    continue;
                }
                throw new \RuntimeException('Graph discovery failed');
            }
            if ($stage === 'channel') {
                if (empty($response['id']) || !is_string($response['id'])) {
                    throw new \RuntimeException('General channel is missing');
                }
                $state['channel'] = $response['id']; $state['stage'] = 'messages';
            } else if ($stage === 'messages' || $stage === 'replies') {
                if (!isset($response['value']) || !is_array($response['value'])) {
                    throw new \RuntimeException('Graph messages are missing');
                }
                $base = $this->base($team, $state['channel']) . '/messages';
                foreach ($response['value'] as $message) {
                    $this->meetings($message, $state, $candidates, $warnings, $batchseen);
                    if ($stage === 'messages') {
                        foreach ($message['replies'] ?? [] as $reply) {
                            $this->meetings($reply, $state, $candidates, $warnings, $batchseen);
                        }
                        if (isset($message['replies@odata.nextLink'])) {
                            if (empty($message['id']) || count($state['queue']) >= 100) {
                                throw new \RuntimeException('Too many reply pages');
                            }
                            $state['queue'][] = $this->next($message['replies@odata.nextLink'],
                                $base . '/' . rawurlencode($message['id']) . '/replies');
                        }
                    }
                }
                $next = isset($response['@odata.nextLink']) ? $this->next($response['@odata.nextLink'],
                    $stage === 'messages' ? $base : explode('?', $path, 2)[0]) : null;
                if ($stage === 'messages') {
                    unset($state['next'], $state['rootnext']);
                    if ($next !== null) { $state['rootnext'] = $next; }
                    $state['stage'] = $state['queue'] ? 'replies' : ($next !== null ? 'messages' : 'folder');
                    if (!$state['queue'] && $next !== null) {
                        $state['next'] = $next; unset($state['rootnext']);
                    }
                } else if ($next !== null) {
                    array_unshift($state['queue'], $next);
                }
            } else if ($stage === 'folder') {
                if (empty($response['id']) || empty($response['parentReference']['driveId']) ||
                        empty($response['webUrl'])) {
                    throw new \RuntimeException('Graph files folder is malformed');
                }
                // Reuse the primary channel's actual filesFolder; never guess a SharePoint path.
                $this->candidate('material', $response['webUrl'], (string)$response['id'],
                    $state, $candidates, $warnings, $batchseen);
                $state['folderurl'] = $response['webUrl'];
                $state['childpath'] = '/drives/' . rawurlencode($response['parentReference']['driveId']) .
                    '/items/' . rawurlencode($response['id']) . '/children';
                $state['stage'] = 'children';
            } else {
                if (!isset($response['value']) || !is_array($response['value'])) {
                    throw new \RuntimeException('Graph folder children are missing');
                }
                foreach ($response['value'] as $item) {
                    if (!isset($item['folder']) || !in_array($item['name'] ?? '', ['Recordings', 'Grabaciones'], true)) {
                        continue;
                    }
                    if (!empty($item['webUrl']) && $this->childurl($state['folderurl'], $item['webUrl'], $item['name'])) {
                        $this->candidate('recordings', $item['webUrl'], (string)($item['id'] ?? ''),
                            $state, $candidates, $warnings, $batchseen);
                    } else {
                        $warnings[] = 'Recordings folder link is unavailable or outside General';
                    }
                }
                $state['next'] = isset($response['@odata.nextLink']) ?
                    $this->next($response['@odata.nextLink'], $state['childpath']) : null;
                if ($state['next'] === null) { unset($state['next']); $state['stage'] = 'done'; }
            }
        }
        return ['candidates' => $candidates, 'cursor' => $state['stage'] === 'done' ? null : $state,
            'complete' => $state['stage'] === 'done', 'requests' => $requests, 'warnings' => $warnings];
    }

    /** Persisted cursors are data, never authority to request another Graph endpoint. */
    private function validatecursor(array $state, string $teamid): void {
        if (($state['team'] ?? null) !== $teamid || !isset($state['stage'], $state['seen'], $state['queue']) ||
                !is_array($state['seen']) || !is_array($state['queue']) || count($state['seen']) > 256 ||
                count($state['queue']) > 100 || !in_array($state['stage'],
                    ['channel', 'messages', 'replies', 'folder', 'children', 'done'], true)) {
            throw new \RuntimeException('Invalid discovery cursor');
        }
        if ($state['stage'] === 'channel') { return; }
        if (empty($state['channel']) || !is_string($state['channel'])) {
            throw new \RuntimeException('Invalid discovery channel');
        }
        $messages = $this->base(rawurlencode($teamid), $state['channel']) . '/messages';
        foreach (['next', 'rootnext'] as $key) {
            if (isset($state[$key])) {
                $endpoint = $key === 'rootnext' || $state['stage'] === 'messages' ? $messages :
                    ($state['stage'] === 'children' ? ($state['childpath'] ?? '') : '');
                if (!$this->relativepath($state[$key], $endpoint)) {
                    throw new \RuntimeException('Unsafe discovery cursor path');
                }
            }
        }
        foreach ($state['queue'] as $replypath) {
            if (!is_string($replypath) || !preg_match('~^' . preg_quote($messages, '~') .
                    '/([^/?]+)/replies(?:\?.*)?$~D', $replypath, $match) ||
                    rawurlencode(rawurldecode($match[1])) !== $match[1]) {
                throw new \RuntimeException('Unsafe discovery reply cursor');
            }
        }
        if ($state['stage'] === 'children' && (!isset($state['childpath']) ||
                !is_string($state['childpath']) ||
                !preg_match('~^/drives/[^/?]+/items/[^/?]+/children$~D', $state['childpath']))) {
            throw new \RuntimeException('Unsafe discovery folder cursor');
        }
    }

    private function relativepath($path, string $endpoint): bool {
        return is_string($path) && $endpoint !== '' &&
            ($path === $endpoint || str_starts_with($path, $endpoint . '?'));
    }

    private function base(string $team, string $channel): string {
        return '/teams/' . $team . '/channels/' . rawurlencode($channel);
    }

    private function next(string $url, string $endpoint): string {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' ||
                ($parts['host'] ?? '') !== 'graph.microsoft.com' || isset($parts['port']) ||
                isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) ||
                ($parts['path'] ?? '') !== '/v1.0' . $endpoint) {
            throw new \RuntimeException('Unsafe Graph pagination link');
        }
        return $endpoint . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private function meetings(array $message, array &$state, array &$candidates, array &$warnings,
            array &$batchseen): void {
        $text = html_entity_decode((string)($message['body']['content'] ?? ''), ENT_QUOTES | ENT_HTML5);
        foreach ($message['attachments'] ?? [] as $attachment) {
            $text .= ' ' . html_entity_decode((string)($attachment['content'] ?? ''), ENT_QUOTES | ENT_HTML5);
        }
        // Plain text, HTML attributes and JSON-escaped slashes are all common in Graph content.
        $text = str_replace('\\/', '/', $text);
        preg_match_all('~https://[^\s<>"\'\\\\]+~i', $text, $matches);
        foreach ($matches[0] as $raw) {
            $url = rtrim($raw, '.,;)]}');
            $parts = parse_url($url);
            if ($parts === false || ($parts['host'] ?? '') !== 'teams.microsoft.com' ||
                    ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['fragment'])) {
                continue;
            }
            if (!preg_match('~^/l/meetup-join/([^/]+)/[^/]+$~', $parts['path'] ?? '', $match) ||
                    rawurldecode($match[1]) !== $state['channel']) {
                $warnings[] = 'Unverifiable Teams meeting link skipped';
                continue;
            }
            $this->candidate('meeting', $url, (string)($message['id'] ?? ''), $state, $candidates, $warnings,
                $batchseen);
        }
    }

    private function childurl(string $parent, string $child, string $name): bool {
        $a = parse_url($parent); $b = parse_url($child);
        if ($a === false || $b === false || ($a['scheme'] ?? '') !== 'https' ||
                ($b['scheme'] ?? '') !== 'https' || !isset($a['host'], $b['host']) ||
                !str_ends_with(strtolower($a['host']), '.sharepoint.com') ||
                strtolower($a['host']) !== strtolower($b['host'])) {
            return false;
        }
        foreach ([$a, $b] as $parts) {
            if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) ||
                    isset($parts['fragment']) || isset($parts['query'])) { return false; }
        }
        $prefix = rtrim($a['path'] ?? '', '/') . '/';
        if (!str_starts_with($b['path'] ?? '', $prefix)) { return false; }
        $segment = substr($b['path'], strlen($prefix));
        $decoded = rawurldecode($segment);
        return $decoded === $name && $decoded !== '.' && $decoded !== '..' &&
            !str_contains($decoded, '/') && !str_contains($decoded, '\\') &&
            !str_contains($decoded, '%') && !preg_match('/[\x00-\x1f]/', $decoded);
    }

    private function candidate(string $kind, string $url, string $sourceid, array &$state,
            array &$candidates, array &$warnings, array &$batchseen): void {
        // Material is optional; strict rejection must not suppress existing meeting/recording discovery.
        if ($kind === 'material' && !link_publisher::is_valid_destination($kind, $url)) {
            $warnings[] = 'Material folder link is unavailable or unsafe';
            return;
        }
        $key = hash('sha256', $kind . "\0" . $url);
        if (isset($state['seen'][$key]) || isset($batchseen[$key])) { return; }
        $batchseen[$key] = true;
        if (count($state['seen']) >= 256) {
            array_shift($state['seen']); // Bounded replay cache, not a limit on published candidates.
        }
        $state['seen'][$key] = true;
        $candidates[] = ['kind' => $kind, 'url' => $url, 'sourceid' => $sourceid];
    }
}
