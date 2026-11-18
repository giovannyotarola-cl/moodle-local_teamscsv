<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// In-memory DML/XMLDB/lock models only: no Moodle bootstrap, real DB, Graph or disk writes.
define('MOODLE_INTERNAL', true);
define('SITEID', 1);
define('MATURITY_ALPHA', 50);
foreach (['XMLDB_TYPE_INTEGER', 'XMLDB_TYPE_CHAR', 'XMLDB_TYPE_TEXT', 'XMLDB_NOTNULL',
        'XMLDB_SEQUENCE', 'XMLDB_KEY_PRIMARY', 'XMLDB_INDEX_UNIQUE', 'XMLDB_INDEX_NOTUNIQUE'] as $constant) {
    define($constant, $constant);
}
class xmldb_field {
    public function __construct(public $name, public $type = null, public $length = null,
            public $unsigned = null, public $notnull = null, public $sequence = null, public $default = null) {}
}
class xmldb_table {
    public $fields = [];
    public $indexes = [];
    public function __construct(public $name) {}
    public function add_field($name, ...$args) { $this->fields[$name] = new xmldb_field($name, ...$args); }
    public function add_key($name, $type, $fields) { $this->indexes[$name] = ['unique' => true, 'columns' => $fields]; }
    public function add_index($name, $type, $fields) {
        $this->indexes[$name] = ['unique' => $type === XMLDB_INDEX_UNIQUE, 'columns' => $fields];
    }
}
class memory_schema {
    public $tables = [];
    public $creates = [];
    public function __construct(private $db) {}
    public function table_exists($table) { return isset($this->tables[$table->name]); }
    public function field_exists($table, $field) { return isset($this->tables[$table->name]->fields[$field->name]); }
    public function create_table($table) {
        expect(!$this->table_exists($table), 'upgrade recreated a table');
        $this->tables[$table->name] = unserialize(serialize($table));
        $this->db->rows[$table->name] = [];
        $this->creates[] = $table->name;
    }
    public function rename_field($table, $field, $replacement) {
        $fields = &$this->tables[$table->name]->fields;
        $fields[$replacement] = clone $fields[$field->name];
        $fields[$replacement]->name = $replacement;
        unset($fields[$field->name]);
        foreach ($this->db->rows[$table->name] as $row) {
            $row->$replacement = $row->{$field->name};
            unset($row->{$field->name});
        }
    }
}
class memory_transaction {
    private $before;
    public function __construct(private $db) { $this->before = $db->snapshot(); }
    public function allow_commit() {
        if ($this->db->beforecommit) { ($this->db->beforecommit)(); }
        $this->db->transactiondepth--;
    }
    public function rollback($error) {
        $this->db->restore($this->before);
        $this->db->transactiondepth--;
        throw $error;
    }
}
class memory_db {
    public $rows = [];
    public $writes = 0;
    public $lastlimit = 0;
    public $failtable = null;
    public $transactiondepth = 0;
    public $beforecommit;
    public $physicalstagemetadata = false;
    private $manager;
    public function __construct() { $this->manager = new memory_schema($this); }
    public function get_manager() { return $this->manager; }
    public function snapshot() { return serialize([$this->rows, $this->writes]); }
    public function restore($snapshot) { [$this->rows, $this->writes] = unserialize($snapshot); }
    public function is_transaction_started() { return $this->transactiondepth > 0; }
    public function start_delegated_transaction() {
        $this->transactiondepth++;
        return new memory_transaction($this);
    }
    public function get_columns($name) {
        $result = [];
        foreach ($this->manager->tables[$name]->fields as $field) {
            $result[$field->name] = (object)['meta_type' => $field->type === XMLDB_TYPE_CHAR ? 'C' :
                ($field->type === XMLDB_TYPE_TEXT ? 'X' : 'I'), 'max_length' => (int)$field->length,
                'not_null' => (bool)$field->notnull, 'auto_increment' => (bool)$field->sequence,
                'unique' => $field->name === 'id', 'has_default' => $field->default !== null,
                'default_value' => $field->default];
        }
        if ($this->physicalstagemetadata && in_array($name, ['local_teamscsv_page', 'local_teamscsv_pageid'], true)) {
            $result['id']->meta_type = 'R'; // MariaDB's autoincrement primary key metadata.
            foreach ($result as $field => $column) {
                if ($column->meta_type === 'C' && !$column->has_default) {
                    $column->has_default = true; // Implicit empty default for NOT NULL CHAR.
                    $column->default_value = '';
                }
            }
        }
        return $result;
    }
    public function get_indexes($name) { return $this->manager->tables[$name]->indexes; }
    public function get_records($table, $conditions = [], $sort = '', $fields = '*', $from = 0, $limit = 0) {
        $rows = array_filter($this->rows[$table] ?? [], static function($row) use ($conditions) {
            foreach ($conditions as $key => $value) { if (($row->$key ?? null) != $value) { return false; } }
            return true;
        });
        if ($sort !== '') {
            uasort($rows, static function($a, $b) use ($sort) {
                foreach (explode(',', $sort) as $field) {
                    [$field, $direction] = array_pad(explode(' ', trim($field)), 2, 'ASC');
                    if ($a->$field != $b->$field) { return ($a->$field <=> $b->$field) * ($direction === 'DESC' ? -1 : 1); }
                }
                return 0;
            });
        }
        return array_map(static fn($row) => clone $row, array_slice($rows, $from, $limit ?: null, true));
    }
    public function get_record($table, $conditions) {
        $rows = $this->get_records($table, $conditions);
        return reset($rows) ?: false;
    }
    public function record_exists($table, $conditions) { return (bool)$this->get_record($table, $conditions); }
    public function insert_record($table, $record) {
        if ($this->failtable === $table) { throw new RuntimeException('Injected write failure'); }
        if ($table === 'local_teamscsv_managed') {
            expect(strlen($record->role) <= 6, 'managed role exceeds existing schema width');
        }
        $record = clone $record;
        $record->id = count($this->rows[$table] ?? []) + 1;
        foreach ($this->get_indexes($table) as $index) {
            if (!$index['unique']) { continue; }
            $conditions = [];
            foreach ($index['columns'] as $column) { $conditions[$column] = $record->$column; }
            expect(!$this->record_exists($table, $conditions), 'duplicate unique key');
        }
        $this->rows[$table][$record->id] = $record;
        $this->writes++;
        return $record->id;
    }
    public function update_record($table, $record) {
        if ($this->failtable === $table) { throw new RuntimeException('Injected write failure'); }
        if ($table === 'local_teamscsv_managed' && isset($record->role)) {
            expect(strlen($record->role) <= 6, 'managed role exceeds existing schema width');
        }
        foreach (get_object_vars($record) as $key => $value) { $this->rows[$table][$record->id]->$key = $value; }
        $this->writes++;
    }
    public function delete_records($table, $conditions) {
        if ($this->failtable === $table) { throw new RuntimeException('Injected write failure'); }
        foreach ($this->get_records($table, $conditions) as $row) { unset($this->rows[$table][$row->id]); }
        $this->writes++;
    }
    public function get_records_sql($sql, $params, $from = 0, $limit = 0) {
        expect($from === 0 && $limit >= 1 && $limit <= 25, 'query not DB-bounded');
        $this->lastlimit = $limit;
        $rows = [];
        if (str_contains($sql, 'FROM {local_teamscsv_map}')) {
            expect(str_contains($sql, 'm.id > :afterid') && str_contains($sql, 'm.id <= :ceiling') &&
                str_contains($sql, 'ORDER BY m.id'), 'fallback lacks bounded indexed cursor');
            foreach ($this->get_records('local_teamscsv_map', [], 'id ASC') as $row) {
                if ($row->id > $params['afterid'] && $row->id <= $params['ceiling']) { $rows[] = $row; }
            }
        } else if (str_contains($sql, 'FROM {local_teamscsv_dirty}')) {
            expect(str_contains($sql, 'q.nextattempt <= :now') && str_contains($sql, 'COUNT(*)') &&
                str_contains($sql, '{course}') && str_contains($sql, '{local_o365_objects}') &&
                str_contains($sql, 'ORDER BY q.nextattempt, q.id'), 'due query lacks live filtering/fair ordering');
            foreach ($this->rows['local_teamscsv_dirty'] as $queue) {
                $generation = $this->get_record('local_teamscsv_generation', ['id' => $queue->generationid]);
                if (!$generation || $queue->nextattempt > $params['now'] ||
                        !$this->record_exists('local_teamscsv_map', ['courseid' => $generation->courseid,
                            'generationid' => $generation->id]) ||
                        \local_teamscsv\course_links::mapped_team($generation->courseid) !== $generation->teamid) { continue; }
                $rows[] = (object)array_merge(get_object_vars($queue), ['courseid' => $generation->courseid,
                    'teamid' => $generation->teamid, 'revision' => $generation->revision]);
            }
            usort($rows, static fn($a, $b) => [$a->nextattempt, $a->id] <=> [$b->nextattempt, $b->id]);
        } else {
            expect(str_contains($sql, 'FROM {local_teamscsv_managed}') &&
                str_contains($sql, 'h.id > :afterid') && str_contains($sql, 'ORDER BY h.id'), 'unbounded audit query');
            foreach ($this->rows['local_teamscsv_managed'] as $history) {
                $generation = $this->get_record('local_teamscsv_generation', ['id' => $history->generationid]);
                if ($generation->courseid === $params['courseid'] && $history->id > $params['afterid']) {
                    $rows[] = (object)array_merge(get_object_vars($history), ['courseid' => $generation->courseid,
                        'teamid' => $generation->teamid, 'revision' => $generation->revision]);
                }
            }
            usort($rows, static fn($a, $b) => $a->id <=> $b->id);
        }
        return array_slice($rows, 0, $limit);
    }
}
class memory_lock {
    public function __construct(private $factory, private $key) {}
    public function release() {
        expect(!$GLOBALS['DB']->is_transaction_started(), 'storage lock released before commit/rollback');
        unset($this->factory->held[$this->key]);
    }
}
class memory_locks {
    public $held = [];
    public function get_lock($key, $timeout) {
        expect($timeout === 0, 'storage should not wait indefinitely');
        if (isset($this->held[$key])) { return false; }
        $this->held[$key] = true;
        return new memory_lock($this, $key);
    }
}
class context_course {
    public static function instance($id) { return (object)['id' => $id]; }
}
function is_enrolled($context, $userid, $capability = '', $active = false) {
    expect($active === true, 'adoption must require active course participation');
    return in_array($userid, $GLOBALS['participants'][$context->id] ?? [], true);
}
function upgrade_plugin_savepoint($ok, $version, $type, $plugin) { $GLOBALS['savepoints'][] = $version; }
function expect($ok, $reason) { if (!$ok) { throw new RuntimeException($reason); } }
function silent(callable $call) {
    ob_start();
    try { return $call(); } finally { expect(ob_get_clean() === '', 'storage emitted output'); }
}
function rejected(callable $call, $message) {
    global $DB;
    $before = $DB->snapshot();
    try { silent($call); throw new RuntimeException('accepted rejected input'); }
    catch (Throwable $e) { expect($e->getMessage() === $message, 'unexpected rejection: ' . $e->getMessage()); }
    expect($DB->snapshot() === $before, 'rejection changed stored data or counters');
}
require_once __DIR__ . '/../classes/course_links.php';
require_once __DIR__ . '/../classes/membership_store.php';
require_once __DIR__ . '/../db/upgrade.php';
use local_teamscsv\membership_store;

function install_tables() {
    $tables = [];
    foreach (simplexml_load_file(__DIR__ . '/../db/install.xml')->TABLES->TABLE as $xml) {
        $table = new xmldb_table((string)$xml['NAME']);
        foreach ($xml->FIELDS->FIELD as $field) {
            $types = ['int' => XMLDB_TYPE_INTEGER, 'char' => XMLDB_TYPE_CHAR, 'text' => XMLDB_TYPE_TEXT];
            $table->add_field((string)$field['NAME'], $types[(string)$field['TYPE']],
                isset($field['LENGTH']) ? (string)$field['LENGTH'] : null, null,
                (string)$field['NOTNULL'] === 'true' ? XMLDB_NOTNULL : null,
                (string)$field['SEQUENCE'] === 'true' ? XMLDB_SEQUENCE : null,
                isset($field['DEFAULT']) ? (string)$field['DEFAULT'] : null);
        }
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        foreach ($xml->INDEXES->INDEX as $index) {
            $table->add_index((string)$index['NAME'], (string)$index['UNIQUE'] === 'true' ?
                XMLDB_INDEX_UNIQUE : XMLDB_INDEX_NOTUNIQUE, explode(',', (string)$index['FIELDS']));
        }
        $tables[$table->name] = $table;
    }
    return $tables;
}
function database($legacyonly = false) {
    $GLOBALS['DB'] = new memory_db();
    $GLOBALS['savepoints'] = [];
    foreach (install_tables() as $table) {
        if (!$legacyonly || in_array($table->name, ['local_teamscsv_scan', 'local_teamscsv_link', 'local_teamscsv_tablink'])) {
            $GLOBALS['DB']->get_manager()->create_table($table);
        }
    }
    return $GLOBALS['DB'];
}
function link_course($courseid, $teamid) {
    global $DB;
    $DB->rows['course'][$courseid] = (object)['id' => $courseid];
    $DB->rows['local_o365_objects'][$courseid * 2] = (object)['id' => $courseid * 2,
        'type' => 'group', 'subtype' => 'course', 'moodleid' => $courseid, 'objectid' => $teamid];
    $DB->rows['local_o365_objects'][$courseid * 2 + 1] = (object)['id' => $courseid * 2 + 1,
        'type' => 'group', 'subtype' => 'courseteam', 'moodleid' => $courseid, 'objectid' => $teamid];
    $GLOBALS['participants'][$courseid] = [21, 22];
}
$team = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
$other = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
$object = 'cccccccc-cccc-cccc-cccc-cccccccccccc';
$object2 = 'dddddddd-dddd-dddd-dddd-dddddddddddd';

// Fresh install and every supported oldversion path agree; pre-existing plugin rows survive byte-for-byte.
$schema = install_tables();
foreach ([2026060400, 2026060402, 2026060403, 2026060404, 2026060405] as $oldversion) {
    $DB = database(true);
    $DB->rows['local_teamscsv_scan'] = [(object)['id' => 9, 'courseid' => 6, 'scan_cursor' => '{"page":2}', 'message' => null]];
    $DB->rows['local_teamscsv_link'] = [(object)['id' => 30, 'cmid' => 406, 'url' => 'legacy']];
    $DB->rows['local_teamscsv_tablink'] = [(object)['id' => 31, 'url' => 'tab', 'urlhash' => 'preserve']];
    if ($oldversion <= 2026060402) {
        $DB->get_manager()->rename_field(new xmldb_table('local_teamscsv_scan'), new xmldb_field('scan_cursor'), 'cursor');
    }
    $legacy = serialize([$DB->rows['local_teamscsv_link'], $DB->rows['local_teamscsv_tablink']]);
    expect(xmldb_local_teamscsv_upgrade($oldversion) === true, 'upgrade failed');
    expect(serialize([$DB->rows['local_teamscsv_link'], $DB->rows['local_teamscsv_tablink']]) === $legacy &&
        $DB->rows['local_teamscsv_scan'][0]->scan_cursor === '{"page":2}' &&
        $DB->rows['local_teamscsv_scan'][0]->id === 9 && $DB->rows['local_teamscsv_scan'][0]->message === null,
        'existing scan/link/tablink data changed');
    foreach ($schema as $name => $table) {
        $actual = $DB->get_columns($name); $expected = array_keys($table->fields);
        $names = array_keys($actual); sort($names); sort($expected);
        expect($names === $expected && $DB->get_indexes($name) === $table->indexes, 'install/upgrade shape differs: ' . $name);
        foreach ($table->fields as $field) {
            expect($actual[$field->name]->not_null === (bool)$field->notnull &&
                ($field->type !== XMLDB_TYPE_CHAR || $actual[$field->name]->max_length === (int)$field->length), 'field shape differs');
        }
    }
    $before = $DB->snapshot(); $creates = $DB->get_manager()->creates;
    xmldb_local_teamscsv_upgrade(2026060405); // Schema present, savepoint lost.
    expect($DB->snapshot() === $before && $DB->get_manager()->creates === $creates, 're-entry changed data/schema');
    $GLOBALS['savepoints'] = [];
    xmldb_local_teamscsv_upgrade(2026060407);
    expect($DB->snapshot() === $before && $GLOBALS['savepoints'] === [], 'current version not a no-op');
}
$DB = database();
unset($DB->get_manager()->tables['local_teamscsv_page'], $DB->get_manager()->tables['local_teamscsv_pageid']);
$DB->rows['local_teamscsv_managed'][9] = (object)['id' => 9, 'generationid' => 7, 'userid' => 21,
    'objectid' => $object, 'role' => 'owner', 'confirmedat' => 100, 'released' => 0];
$before = $DB->snapshot();
expect(xmldb_local_teamscsv_upgrade(2026060406) === true && $DB->snapshot() === $before &&
    isset($DB->get_manager()->tables['local_teamscsv_page'], $DB->get_manager()->tables['local_teamscsv_pageid']) &&
    $GLOBALS['savepoints'] === [2026060407], '406-to-407 upgrade lost existing responsibility');
// Real MariaDB get_indexes() omits PRIMARY and returns physical, not XMLDB, index names.
$DB = database();
$DB->rows['local_teamscsv_managed'][9] = (object)['id' => 9, 'generationid' => 7, 'userid' => 21,
    'objectid' => $object, 'role' => 'owner', 'confirmedat' => 100, 'released' => 0];
foreach (['page' => ['generationrole', 'mdl_locateampage_genrol_uix'],
        'pageid' => ['pageobject', 'mdl_locateampage_pagobj_uix']] as $suffix => [$logical, $physical]) {
    $table = $DB->get_manager()->tables['local_teamscsv_' . $suffix];
    $table->indexes[$physical] = $table->indexes[$logical];
    unset($table->indexes[$logical], $table->indexes['primary']);
}
$DB->physicalstagemetadata = true;
$before = $DB->snapshot(); $creates = $DB->get_manager()->creates;
expect(xmldb_local_teamscsv_upgrade(2026060406) === true && $DB->snapshot() === $before &&
    $DB->get_manager()->creates === $creates && $GLOBALS['savepoints'] === [2026060407],
    '407 re-entry rejected valid physical indexes or changed managed rows');
$DB->get_manager()->tables['local_teamscsv_page']->indexes['mdl_locateampage_genrol_uix']['unique'] = false;
rejected(fn() => xmldb_local_teamscsv_upgrade(2026060406),
    'Conflicting staged membership schema: local_teamscsv_page');
$DB->get_manager()->tables['local_teamscsv_page']->indexes['mdl_locateampage_genrol_uix']['unique'] = true;
$DB->get_manager()->tables['local_teamscsv_page']->fields['role']->default = 'unexpected';
rejected(fn() => xmldb_local_teamscsv_upgrade(2026060406),
    'Conflicting staged membership schema: local_teamscsv_page');
expect($GLOBALS['savepoints'] === [2026060407], 'invalid staged index/default saved another version');
$DB = new memory_db(); xmldb_local_teamscsv_upgrade(2026060400);
expect(count($DB->get_manager()->tables) === count($schema), 'empty old install not upgraded');
$plugin = new stdClass(); require __DIR__ . '/../version.php';
expect($plugin->version >= 2026060407 && (string)simplexml_load_file(__DIR__ . '/../db/install.xml')['VERSION'] ===
    (string)$plugin->version && end($GLOBALS['savepoints']) === 2026060407, 'version/savepoint mismatch');
foreach (['field', 'type', 'length', 'null', 'sequence', 'default', 'index', 'extraunique'] as $conflict) {
    $DB = database(); $table = $DB->get_manager()->tables['local_teamscsv_map'];
    if ($conflict === 'field') { unset($table->fields['generationid']); }
    if ($conflict === 'type') { $table->fields['courseid']->type = XMLDB_TYPE_CHAR; }
    if ($conflict === 'length') { $DB->get_manager()->tables['local_teamscsv_generation']->fields['revision']->length = '12'; }
    if ($conflict === 'null') { $table->fields['courseid']->notnull = null; }
    if ($conflict === 'sequence') { $table->fields['id']->sequence = null; }
    if ($conflict === 'default') { $table->fields['courseid']->default = '0'; }
    if ($conflict === 'index') { $table->indexes['courseuniq']['unique'] = false; }
    if ($conflict === 'extraunique') { $table->add_index('wronguniq', XMLDB_INDEX_UNIQUE, ['generationid']); }
    rejected(fn() => xmldb_local_teamscsv_upgrade(2026060405), 'Conflicting membership schema: ' .
        ($conflict === 'length' ? 'local_teamscsv_generation' : 'local_teamscsv_map'));
    expect($GLOBALS['savepoints'] === [], 'conflict saved upgrade version');
}
// Matching pre-scheduled 0406 tables plus a missing cursor table re-enter from the VM0404 path.
$DB = database();
$DB->rows['local_teamscsv_generation'] = [9 => (object)['id' => 9, 'courseid' => 6, 'teamid' => $team,
    'revision' => str_repeat('f', 64), 'timecreated' => 100]];
$DB->rows['local_teamscsv_map'] = [3 => (object)['id' => 3, 'courseid' => 6, 'generationid' => 9]];
$DB->rows['local_teamscsv_managed'] = [8 => (object)['id' => 8, 'generationid' => 9, 'userid' => 21,
    'objectid' => $object, 'role' => 'intent', 'confirmedat' => 0, 'released' => 0]];
$DB->rows['local_teamscsv_dirty'] = [2 => (object)['id' => 2, 'generationid' => 9, 'serial' => 5,
    'attempts' => 3, 'nextattempt' => 200]];
unset($DB->get_manager()->tables['local_teamscsv_mscan']);
$before = $DB->snapshot(); xmldb_local_teamscsv_upgrade(2026060404);
expect($DB->snapshot() === $before, 'adding fallback table altered populated membership state');
foreach (['field', 'index'] as $conflict) {
    $DB = database(); $table = $DB->get_manager()->tables['local_teamscsv_mscan'];
    if ($conflict === 'field') { $table->fields['lastchecked']->notnull = null; }
    else { $table->indexes['scanuniq']['unique'] = false; }
    rejected(fn() => xmldb_local_teamscsv_upgrade(2026060404), 'Conflicting membership schema: local_teamscsv_mscan');
    expect($GLOBALS['savepoints'] === [], 'conflicting fallback schema recorded savepoint');
}
echo "PASS additive schema, oldversion paths, re-entry, conflicts and legacy preservation\n";

$DB = database(); $locks = new memory_locks(); $store = new membership_store($locks);
link_course(6, $team);
rejected(fn() => $store->current(6), 'Unverified or stale mapping'); // O365 pair is not a generation.
$unregistered = ['courseid' => 6, 'teamid' => $team, 'revision' => str_repeat('a', 64)];
rejected(fn() => $store->mark_dirty($unregistered, 100), 'Unverified or stale mapping');
rejected(fn() => $store->record_confirmed($unregistered, 21, $object, 'owner', true, 100), 'Unverified or stale mapping');
expect($store->due(100) === [], 'unregistered O365 mapping was scheduled');
$mapping = silent(fn() => $store->register(6, $team, 100));
expect($store->current(6) === $mapping && preg_match('/^[0-9a-f]{64}$/D', $mapping['revision']), 'invalid registry token');
$dirty = $store->due(100, 25);
expect(count($dirty) === 1 && $dirty[0]['mapping'] === $mapping && $dirty[0]['attempts'] === 0, 'registration not dirty');
rejected(fn() => $store->record_confirmed($mapping, 21, $object, 'owner', false, 100), 'Unconfirmed membership state');
rejected(fn() => $store->record_confirmed($mapping, 21, $object, 'owner', 1, 100), 'Unconfirmed membership state');
rejected(fn() => $store->record_confirmed($mapping, 99, $object, 'owner', true, 100), 'Not a current course participant');
rejected(fn() => $store->record_confirmed($mapping, 0, $object, 'owner', true, 100), 'Invalid managed identity');
rejected(fn() => $store->record_confirmed($mapping, 21, strtoupper($object), 'owner', true, 100), 'Invalid managed identity');
rejected(fn() => $store->record_confirmed($mapping, 21, $object, 'admin', true, 100), 'Invalid membership role');
silent(fn() => $store->record_confirmed($mapping, 21, $object, 'owner', true, 100));
expect(count($store->managed($mapping)) === 1 && $store->managed($mapping)[0]['role'] === 'owner', 'confirmed owner missing');
rejected(fn() => $store->record_confirmed($mapping, 22, $object, 'member', true, 101), 'Conflicting managed identity');
silent(fn() => $store->record_confirmed($mapping, 21, $object2, 'member', true, 101));
expect(count($store->managed($mapping)) === 2, 'identity change lost previous responsibility');
rejected(fn() => $store->release_confirmed($mapping, 21, $object, false, 102), 'Unconfirmed membership state');
silent(fn() => $store->record_confirmed($mapping, 21, $object, 'member', true, 102));
expect($store->managed($mapping)[0]['role'] === 'member', 'confirmed demotion not stored');
silent(fn() => $store->release_confirmed($mapping, 21, $object, true, 103));
expect(count($store->managed($mapping)) === 1 && $store->audit(6, 0, 25)[0]['released'] === 1, 'release erased history');
rejected(fn() => $store->release_confirmed($mapping, 22, $other, true, 103), 'Unknown managed identity');
$second = $store->register(6, $team, 104);
expect($second['revision'] !== $mapping['revision'] && $store->managed($second) === [], 'same-Team relink reused generation');
rejected(fn() => $store->mark_dirty($mapping, 104), 'Unverified or stale mapping');
rejected(fn() => $store->record_confirmed($mapping, 21, $object, 'member', true, 104), 'Unverified or stale mapping');
rejected(fn() => $store->release_confirmed($mapping, 21, $object2, true, 104), 'Unverified or stale mapping');
link_course(6, $other); $third = $store->register(6, $other, 105);
expect($third['revision'] !== $second['revision'] && $store->managed($third) === [] &&
    count($store->audit(6, 0, 25)) === 2 && $store->audit(6, 0, 25)[0]['teamid'] === $team &&
    $store->audit(6, 0, 25)[1]['revision'] === $mapping['revision'] && count($store->due(105, 25)) === 1,
    'cross-Team relink lost/mixed history or selected archived jobs');
rejected(fn() => $store->managed($second), 'Unverified or stale mapping');
$firstpage = $store->audit(6, 0, 1);
expect(count($firstpage) === 1 && count($store->audit(6, $firstpage[0]['id'], 1)) === 1 &&
    $store->audit(7) === [], 'audit pagination/course isolation failed');
$before = $DB->snapshot(); xmldb_local_teamscsv_upgrade(2026060405);
expect($DB->snapshot() === $before && $store->current(6) === $third && count($store->audit(6)) === 2,
    'populated membership tables changed on upgrade re-entry');
echo "PASS confirmed participant ownership, identity changes, releases and immutable generations\n";

$live = $DB->rows['local_o365_objects'];
foreach (['missing', 'single', 'duplicate', 'different', 'wrongsubtype', 'nocourse'] as $invalid) {
    $DB->rows['local_o365_objects'] = unserialize(serialize($live));
    if ($invalid === 'missing') { $DB->rows['local_o365_objects'] = []; }
    if ($invalid === 'single') { unset($DB->rows['local_o365_objects'][13]); }
    if ($invalid === 'duplicate') { $DB->rows['local_o365_objects'][14] = clone $DB->rows['local_o365_objects'][13]; }
    if ($invalid === 'different') { $DB->rows['local_o365_objects'][13]->objectid = $team; }
    if ($invalid === 'wrongsubtype') { $DB->rows['local_o365_objects'][13]->subtype = 'other'; }
    if ($invalid === 'nocourse') { unset($DB->rows['course'][6]); }
    rejected(fn() => $store->mark_dirty($third, 106), 'Unverified or stale mapping');
    rejected(fn() => $store->register(6, $other, 106), 'Unverified or stale mapping');
    expect($store->due(106, 25) === [], 'invalid mapping was due');
    $DB->rows['course'][6] = (object)['id' => 6];
}
$DB->rows['local_o365_objects'] = $live;
$registry = unserialize(serialize($DB->rows['local_teamscsv_map']));
$generations = unserialize(serialize($DB->rows['local_teamscsv_generation']));
foreach (['absent', 'wrongcourse', 'badrevision'] as $corruption) {
    $DB->rows['local_teamscsv_map'] = unserialize(serialize($registry));
    $DB->rows['local_teamscsv_generation'] = unserialize(serialize($generations));
    if ($corruption === 'absent') { $DB->rows['local_teamscsv_map'][1]->generationid = 999; }
    if ($corruption === 'wrongcourse') { $DB->rows['local_teamscsv_generation'][3]->courseid = 7; }
    if ($corruption === 'badrevision') { $DB->rows['local_teamscsv_generation'][3]->revision = ''; }
    rejected(fn() => $store->mark_dirty($third, 106), 'Unverified or stale mapping');
    rejected(fn() => $store->register(6, $other, 106), 'Unverified or stale mapping');
    expect($store->due(106) === [], 'inconsistent generation scheduled');
}
$DB->rows['local_teamscsv_map'] = $registry;
$DB->rows['local_teamscsv_generation'] = $generations;
$outer = $DB->start_delegated_transaction();
rejected(fn() => $store->mark_dirty($third, 106), 'Membership storage requires its own transaction');
$outer->allow_commit();
$DB->failtable = 'local_teamscsv_dirty';
rejected(fn() => $store->register(6, $other, 107), 'Injected write failure');
$DB->failtable = null;
expect($store->current(6) === $third && $locks->held === [], 'failed registration did not roll back/unlock');
$lock = $locks->get_lock('course-6', 0);
rejected(fn() => $store->mark_dirty($third, 107), 'Membership storage busy');
$lock->release();
$job = $store->mark_dirty($third, 108);
$newjob = $store->mark_dirty($third, 108);
expect($newjob['serial'] === $job['serial'] + 1 && count($store->due(108, 25)) === 1, 'dirty jobs not deduplicated');
rejected(fn() => $store->retry($third, $job['serial'], 108), 'Stale membership retry');
$job = $store->retry($third, $newjob['serial'], 108);
expect($job['attempts'] === 1 && $job['nextattempt'] === 168 && $store->due(167, 25) === [], 'first retry/backoff wrong');
$job = $store->mark_dirty($third, 109);
expect($job['attempts'] === 1 && $job['nextattempt'] === 168, 'dirty event reset backoff');
$store = new membership_store($locks); // Simulate another process using persisted queue state.
$job = $store->retry($third, $job['serial'], 168);
expect($job['attempts'] === 2 && $job['nextattempt'] === 288, 'retry state did not persist');
rejected(fn() => $store->retry($third, $job['serial'], 287), 'Membership retry not due');
for ($attempt = 3; $attempt <= 10; $attempt++) {
    $previous = $job['nextattempt'];
    $job = $store->retry($third, $job['serial'], $previous);
    expect($job['nextattempt'] - $previous === min(3600, 60 * (2 ** min(6, $attempt - 1))), 'retry delay/cap wrong');
}
expect($job['attempts'] === 10, 'bounded exponential retries not retained');
rejected(fn() => $store->due(100, 0), 'Invalid storage bounds');
rejected(fn() => $store->due(100, 26), 'Invalid storage bounds');
rejected(fn() => $store->mark_dirty($third, -1), 'Invalid storage bounds');
echo "PASS fail-closed mappings, transaction rollback, lock contention, dedupe and persistent retries\n";

$DB = database(); $locks = new memory_locks(); $store = new membership_store($locks);
link_course(6, $team); link_course(7, $other);
$first = $store->register(6, $team, 100);
$store->record_confirmed($first, 21, $object, 'owner', true, 100);
rejected(fn() => $store->register_imported_course(6, $team, 101), 'Import registration requires the held import transaction');
$outer = $DB->start_delegated_transaction();
rejected(fn() => $store->import_transaction([6], fn() => null), 'Membership storage requires its own transaction');
$outer->allow_commit();
$lock = $locks->get_lock('course-7', 0);
rejected(fn() => $store->import_transaction([6, 7], fn() => null), 'Membership storage busy');
expect(array_keys($locks->held) === ['course-7'], 'partial lock acquisition leaked'); $lock->release();
$DB->failtable = 'local_teamscsv_dirty';
rejected(fn() => $store->import_transaction([6], fn() => $store->register_imported_course(6, $team, 101)),
    'Injected write failure');
$DB->failtable = null;
expect($store->current(6) === $first && count($store->managed($first)) === 1 && $locks->held === [],
    'failed imported generation lost responsibility');
$DB->beforecommit = function() use ($locks) {
    expect(isset($locks->held['course-6'], $locks->held['course-7']), 'import locks absent at actual commit');
};
$new = silent(fn() => $store->import_transaction([7, 6], function() use ($store, $team, $other) {
    rejected(fn() => $store->register(6, $team, 102), 'Membership storage requires its own transaction');
    rejected(fn() => $store->register_imported_course(8, $team, 102), 'Import registration requires the held import transaction');
    $new = $store->register_imported_course(6, $team, 102);
    $store->register_imported_course(7, $other, 102);
    return $new;
}));
$DB->beforecommit = null;
rejected(fn() => $store->register_imported_course(6, $team, 103), 'Import registration requires the held import transaction');
$store->import_transaction([6], function() use ($store, $team) {
    rejected(fn() => $store->register_imported_course(6, strtoupper($team), 103), 'Unverified or stale mapping');
    rejected(fn() => $store->register_imported_course(6, $team, -1), 'Invalid storage bounds');
    $otherinstance = new membership_store(new memory_locks());
    rejected(fn() => $otherinstance->register_imported_course(6, $team, 103),
        'Import registration requires the held import transaction');
});
expect($new['revision'] !== $first['revision'] && $store->current(6) === $new && $store->managed($new) === [] &&
    count($store->audit(6)) === 1 && $store->audit(6)[0]['revision'] === $first['revision'] &&
    count($store->due(102)) === 2 && $locks->held === [], 'import registration changed immutable history or queue');
foreach ([[], [6, 6], [SITEID], [0], ['6']] as $invalid) {
    rejected(fn() => $store->import_transaction($invalid, fn() => null), 'Invalid import courses');
}
echo "PASS owned import transaction, commit-held course locks, rollback and immutable history\n";

$DB = database(); $locks = new memory_locks(); $store = new membership_store($locks);
link_course(6, $team); $mapping = $store->register(6, $team, 100); $job = $store->due(100, 1)[0];
rejected(fn() => $store->execution_intents($mapping, $job['serial'], [$object => 21], 100),
    'Membership execution lock required');
rejected(fn() => $store->execution_retry($job, 100), 'Membership execution lock required');
rejected(fn() => $store->execution_complete($mapping, $job['serial'], [], [], [], 100),
    'Membership execution lock required');
$store->execution_lock($job, 100, function($livejob) use ($store, $mapping, $object, $locks) {
    $job = $store->execution_retry($livejob, 100);
    expect(isset($locks->held['course-6']) && !$GLOBALS['DB']->is_transaction_started(), 'execution lock/transaction boundary');
    rejected(fn() => $store->mark_dirty($mapping, 100), 'Membership storage busy');
    rejected(fn() => $store->execution_intents($mapping, $job['serial'], [$object => 99], 100),
        'Not a current course participant');
    $store->execution_intents($mapping, $job['serial'], [$object => 21], 100);
    expect($store->managed($mapping)[0]['role'] === 'intent' && $store->managed($mapping)[0]['confirmedat'] === 0,
        'pending intent not durable planner scope');
    $before = $GLOBALS['DB']->snapshot();
    $store->execution_intents($mapping, $job['serial'], [$object => 21], 100);
    expect($GLOBALS['DB']->snapshot() === $before, 'repeated intent changed responsibility/counters');
    rejected(fn() => $store->execution_intents($mapping, $job['serial'], [$object => 22], 100),
        'Conflicting managed identity');
    $GLOBALS['DB']->failtable = 'local_teamscsv_dirty';
    rejected(fn() => $store->execution_complete($mapping, $job['serial'], [$object => 'member'], [$object => 21], [], 100),
        'Injected write failure');
    $GLOBALS['DB']->failtable = null;
    expect($store->execution_complete($mapping, $job['serial'] - 1, [$object => 'member'], [$object => 21], [], 100) === false,
        'old serial completed dirty work');
    expect($GLOBALS['DB']->snapshot() === $before, 'stale completion changed data/counters');
    expect($store->execution_complete($mapping, $job['serial'], [$object => 'member'], [$object => 21], [], 100),
        'confirmed execution not completed');
});
expect($locks->held === [] && $store->due(100) === [] && $store->managed($mapping)[0]['role'] === 'member',
    'execution did not atomically confirm/complete/unlock');
rejected(fn() => $store->execution_retry($job, 100), 'Membership execution lock required');
echo "PASS execution-held own transactions, pending responsibility, rollback and serial completion\n";

$DB = database(); $store = new membership_store(new memory_locks());
for ($id = 2; $id <= 502; $id++) { link_course($id, $team); $store->register($id, $team, 100); }
// Invalid early rows must be excluded before LIMIT, not consume every page forever.
$DB->rows['local_o365_objects'][5]->subtype = 'other';
$seen = [];
while ($jobs = $store->due(100, 25)) {
    expect(count($jobs) <= 25 && $DB->lastlimit === 25, 'unbounded due scan');
    foreach ($jobs as $job) {
        $id = $job['mapping']['courseid'];
        expect(!isset($seen[$id]), 'starvation/repeated job'); $seen[$id] = true;
        $store->retry($job['mapping'], $job['serial'], 100);
    }
}
expect(count($seen) === 500 && isset($seen[502]) && !isset($seen[2]), '500-course fair progress failed');
$jobs = $store->due(160, 1);
expect(count($jobs) === 1 && $jobs[0]['mapping']['courseid'] === 3, 'due boundary/order mismatch');
echo "PASS 501 mappings, database-bounded fair scans and retry due boundary\n";

$DB = database(); $store = new membership_store(new memory_locks());
for ($id = 2; $id <= 502; $id++) { link_course($id, $team); $store->register($id, $team, 100); }
$seen = []; $callback = function($courseid, $generationid) use (&$seen) {
    expect(!isset($seen[$courseid]), 'course repeated within fallback cycle'); $seen[$courseid] = $generationid;
};
for ($tick = 0; $tick < 21; $tick++) {
    $store = new membership_store(new memory_locks()); // Cursor survives process restarts.
    expect(silent(fn() => $store->fallback(100 + $tick, 25, $callback)) === min(25, 501 - $tick * 25),
        'fallback page count wrong');
}
expect(count($seen) === 501 && isset($seen[502]), 'fallback did not progress across 501 courses');
expect(silent(fn() => $store->fallback(86519, 25, $callback)) === 0, 'fallback repeated before daily boundary');
$seen = [];
expect(silent(fn() => $store->fallback(86520, 25, $callback)) === 25 && isset($seen[2]), 'daily boundary mismatch');
rejected(fn() => $store->fallback(100, 26, $callback), 'Invalid storage bounds');
$before = $DB->snapshot();
rejected(fn() => $store->fallback(86521, 25, fn() => throw new RuntimeException('Queue unavailable')), 'Queue unavailable');
expect($DB->snapshot() === $before, 'failed enqueue advanced cursor');
expect($DB->get_records('local_teamscsv_scan') === [] && $DB->get_records('local_teamscsv_managed') === [],
    'fallback altered unrelated link scan or managed history');
// Timestamp zero is a supported storage boundary, not an uninitialized completed-cycle marker.
$DB = database(); $store = new membership_store(new memory_locks());
link_course(2, $team); $store->register(2, $team, 0); $visits = [];
$enqueue = function($id) use (&$visits) { $visits[] = $id; };
expect($store->fallback(0, 1, $enqueue) === 1 && $store->fallback(1, 1, $enqueue) === 0 && $visits === [2],
    'epoch-zero completion requeued a course before the daily boundary');
echo "PASS durable indexed daily fallback, 501-course progress, restart and enqueue failure\n";

// An incomplete Graph page is checkpointed separately from confirmed managed responsibility.
$DB = database(); $locks = new memory_locks(); $store = new membership_store($locks);
link_course(6, $team); $mapping = $store->register(6, $team, 100);
$job = $store->due(100, 1)[0];
$firstpath = '/groups/' . $team . '/owners?$top=999';
$nextpath = '/groups/' . $team . '/owners?$skiptoken=page2&$top=999';
$progress = $store->execution_lock($job, 100, function($live) use ($store, $mapping, $object, $firstpath, $nextpath) {
    $reserved = $store->execution_retry($live, 100);
    return $store->stage_page($mapping, $reserved['serial'], 'owners', $firstpath, [$object], $nextpath, 100);
});
expect($progress === ['role' => 'owners', 'nextpath' => $nextpath, 'count' => 1, 'complete' => false],
    'incomplete page did not checkpoint its bounded continuation');
expect($store->managed($mapping) === [], 'unverified Graph IDs became managed responsibility');
$before = $DB->snapshot();
$store->execution_lock($store->due(160, 1)[0], 160, function($live) use ($store, $mapping, $object, $nextpath) {
    rejected(fn() => $store->stage_page($mapping, $live['serial'], 'owners',
        '/groups/' . $mapping['teamid'] . '/owners?$skiptoken=wrong', [$object], $nextpath, 160),
        'Stale staged membership page');
});
expect($DB->snapshot() === $before, 'rejected page changed stored data or counters');
echo "PASS incomplete Graph staging and rejected cursor preserve responsibility and counters\n";

$nextjob = $store->due(160, 1)[0];
$progress = $store->execution_lock($nextjob, 160, function($live) use ($store, $mapping, $object2, $nextpath) {
    $reserved = $store->execution_retry($live, 160);
    return $store->stage_page($mapping, $reserved['serial'], 'owners', $nextpath, [$object2], null, 160);
});
expect($progress === ['role' => 'owners', 'nextpath' => null, 'count' => 2, 'complete' => true] &&
    $store->managed($mapping) === [], 'page checkpoint did not survive a retry without adopting outsiders');
$before = $DB->snapshot();
$store->execution_lock($store->due(340, 1)[0], 340, function($live) use ($store, $mapping, $object2, $nextpath) {
    rejected(fn() => $store->stage_page($mapping, $live['serial'], 'owners', $nextpath,
        [$object2], null, 340), 'Stale staged membership page');
});
expect($DB->snapshot() === $before, 'completed staged page accepted a replay');
echo "PASS stage continuation survives retry and completed pages reject replay\n";

$DB = database(); $store = new membership_store(new memory_locks());
link_course(6, $team); $mapping = $store->register(6, $team, 100);
$job = $store->due(100, 1)[0];
$store->execution_lock($job, 100, function($live) use ($store, $mapping, $firstpath, $nextpath, $object) {
    $reserved = $store->execution_retry($live, 100);
    $store->stage_page($mapping, $reserved['serial'], 'owners', $firstpath, [$object], $nextpath, 100);
});
$store->mark_dirty($mapping, 101);
$job = $store->due(160, 1)[0];
$status = $store->execution_lock($job, 160, function($live) use ($store, $mapping) {
    $reserved = $store->execution_retry($live, 160);
    return $store->stage_progress($mapping, $reserved['serial'], 'owners');
});
expect($status === null && $store->managed($mapping) === [],
    'new dirty event retained partial remote evidence or adopted outsiders');
echo "PASS new dirty event invalidates staged remote pages without adopting outsiders\n";
