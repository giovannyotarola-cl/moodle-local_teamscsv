<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Public pure API only: no Moodle, Graph, persistence or personal identities.
define('MOODLE_INTERNAL', true);
require_once __DIR__ . '/../classes/membership_plan.php';

use local_teamscsv\membership_plan;

function same($expected, $actual, string $label): void {
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': ' . var_export($actual, true));
    }
}
function uid(int $n): string {
    return sprintf('00000000-0000-0000-0000-%012d', $n);
}
function evidence(array $desired = [], array $managed = [], array $owners = [], array $members = []): array {
    return ['mapping' => ['courseid' => 7, 'teamid' => uid(100), 'revision' => 'fixture-v1'],
        'verifiedmapping' => ['courseid' => 7, 'teamid' => uid(100), 'revision' => 'fixture-v1'],
        'desired' => $desired, 'managed' => $managed, 'owners' => $owners, 'members' => $members,
        'complete' => ['participants' => true, 'identities' => true, 'history' => true,
            'owners' => true, 'members' => true]];
}
function op(string $action, string $id): array {
    return ['action' => $action, 'id' => $id];
}
function plan(array $input): array {
    $before = serialize($input);
    ob_start();
    try {
        return membership_plan::build($input);
    } finally {
        same('', ob_get_clean(), 'API emits no output');
        same($before, serialize($input), 'Input unchanged');
    }
}
function rejected(array $input, string $message): void {
    try {
        plan($input);
    } catch (InvalidArgumentException $e) {
        same($message, $e->getMessage(), 'Exact rejection');
        return;
    }
    throw new RuntimeException('Expected rejection');
}
$a = uid(1); $b = uid(2); $c = uid(3); $outsideowner = uid(8); $outsidemember = uid(9);
$input = evidence([$b => 'member', $a => 'owner'], [], [$outsideowner], [$outsidemember]);
$p = plan($input);
same([op('add_member', $a), op('add_member', $b), op('add_owner', $a)], $p['operations'], 'Initial additions');
same([$outsideowner, $a], $p['predicted']['owners'], 'Unrelated owner preserved');
same([$outsidemember, $a, $b], $p['predicted']['members'], 'Unrelated member preserved');
same(['adopt' => [$a, $b], 'release' => [], 'retain' => [$a, $b]], $p['baseline'], 'Initial adoption intent');
same($input['mapping'], $p['mapping'], 'Mapping bound result');
same([], $p['blocked'], 'No blocked removals');
$input['desired'] = [$a => 'owner', $b => 'member'];
same($p, plan($input), 'Role-map ordering does not change plan');
$next = evidence([$a => 'owner', $b => 'member'], [$a, $b], $p['predicted']['owners'], $p['predicted']['members']);
$q = plan($next);
same([], $q['operations'], 'Unchanged state is idempotent');
same(['adopt' => [], 'release' => [], 'retain' => [$a, $b]], $q['baseline'], 'Existing baseline retained');
$next['desired'][$c] = 'member';
same([op('add_member', $c)], plan($next)['operations'], 'Subsequent addition');
echo "PASS additions, adoption, outsiders, deterministic no-op\n";

$p = plan(evidence([$b => 'owner'], [$a, $b, $c], [$outsideowner, $a], [$outsidemember, $a, $b, $c]));
same([op('add_owner', $b), op('remove_owner', $a), op('remove_member', $a), op('remove_member', $c)],
    $p['operations'], 'Replacement precedes managed departure');
same(['owners' => [$outsideowner, $b], 'members' => [$outsidemember, $b]], $p['predicted'], 'Only managed departures removed');
same(['adopt' => [], 'release' => [$a, $c], 'retain' => [$b]], $p['baseline'], 'Release intent');
$p = plan(evidence([$a => 'member', $b => 'owner'], [$a, $b], [$a], [$a, $b]));
same([op('add_owner', $b), op('remove_owner', $a)], $p['operations'], 'Both role transitions');
same(['owners' => [$b], 'members' => [$a, $b]], $p['predicted'], 'Demotion retains membership');
$p = plan(evidence([$b => 'owner'], [], [], []));
same([op('add_member', $b), op('add_owner', $b)], $p['operations'], 'Owner also needs membership');
foreach ([[], [$a => 'member']] as $desired) {
    $p = plan(evidence($desired, [$a], [$a], [$a]));
    same([], $p['operations'], 'Final owner not removed');
    same([['id' => $a, 'reason' => 'last_owner']], $p['blocked'], 'Final owner reported');
    same(['owners' => [$a], 'members' => [$a]], $p['predicted'], 'Final owner state intact');
    same(['adopt' => [], 'release' => [], 'retain' => [$a]], $p['baseline'], 'Blocked history retained');
}
$p = plan(evidence([], [$a, $b], [$b, $a], [$a, $b]));
same([op('remove_owner', $a), op('remove_member', $a)], $p['operations'], 'Multiple departing owners retain one');
same(['adopt' => [], 'release' => [$a], 'retain' => [$b]], $p['baseline'], 'Final historical owner retained');
$p = plan(evidence([], [], [$outsideowner], [$outsidemember]));
same([], $p['operations'], 'Unknown historical users never adopted');
same(['adopt' => [], 'release' => [], 'retain' => []], $p['baseline'], 'Outsiders never enter history');
$p = plan(evidence([], [$c], [$outsideowner], []));
same([$c], $p['baseline']['release'], 'Already absent managed user can be released');
echo "PASS departures, transitions, owner ordering and final-owner guard\n";

$valid = evidence([], [$a], [$outsideowner], [$a]);
foreach (array_keys($valid['complete']) as $key) {
    foreach ([false, null, 1] as $value) {
        $bad = $valid; $bad['complete'][$key] = $value;
        rejected($bad, 'Incomplete membership evidence');
    }
    $bad = $valid; unset($bad['complete'][$key]);
    rejected($bad, 'Incomplete membership evidence');
}
foreach (['courseid' => 8, 'teamid' => uid(101), 'revision' => 'fixture-v2'] as $key => $value) {
    $bad = $valid; $bad['verifiedmapping'][$key] = $value;
    rejected($bad, 'Unverified or stale mapping');
}
$bad = $valid; $bad['verifiedmapping'] = null;
rejected($bad, 'Unverified or stale mapping');
foreach (['managed', 'owners', 'members'] as $key) {
    $bad = $valid; $bad[$key] = [$a, $a];
    rejected($bad, 'Invalid identity set');
    $bad[$key] = ['unresolved'];
    rejected($bad, 'Invalid identity set');
}
foreach ([[$a => 'teacher'], ['' => 'member'], [$a => null]] as $desired) {
    $bad = $valid; $bad['desired'] = $desired;
    rejected($bad, 'Invalid desired roles');
}
$bad = $valid; $bad['mapping']['courseid'] = 0;
rejected($bad, 'Unverified or stale mapping');
echo "PASS incomplete identities/reads/history and invalid/stale inputs fail closed\n";
