<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Isolated XMLDB API model; never loads Moodle or accesses a real database.
define('MOODLE_INTERNAL', true);
foreach (['XMLDB_TYPE_INTEGER', 'XMLDB_TYPE_CHAR', 'XMLDB_TYPE_TEXT', 'XMLDB_NOTNULL',
        'XMLDB_SEQUENCE', 'XMLDB_KEY_PRIMARY', 'XMLDB_INDEX_UNIQUE', 'XMLDB_INDEX_NOTUNIQUE'] as $constant) {
    define($constant, $constant);
}
class xmldb_field {
    public $name;
    public $type;
    public function __construct($name, $type = null, ...$unused) { $this->name = $name; $this->type = $type; }
}
class xmldb_table {
    public $name;
    public $fields = [];
    public $indexes = [];
    public function __construct($name) { $this->name = $name; }
    public function add_field($name, ...$args) { $this->fields[$name] = $args; }
    public function add_key($name, $type, $fields) {
        if ($type !== XMLDB_KEY_PRIMARY) { throw new RuntimeException('Unsupported fixture key'); }
        $this->add_index($name, XMLDB_INDEX_UNIQUE, $fields);
    }
    public function add_index($name, ...$args) { $this->indexes[$name] = $args; }
}
class schema_manager {
    public $tables = [];
    public $rows = [];
    public $renames = [];
    public $creates = [];
    public $indexes = [];
    public function table_exists($table) { return isset($this->tables[$table->name]); }
    public function field_exists($table, $field) { return isset($this->tables[$table->name][$field->name]); }
    public function create_table($table) {
        if ($this->table_exists($table)) { throw new RuntimeException('Unexpected table recreation'); }
        $this->tables[$table->name] = $table->fields;
        $this->indexes[$table->name] = $table->indexes;
        $this->rows[$table->name] = [];
        $this->creates[] = $table->name;
    }
    public function rename_field($table, $field, $newname) {
        if (!$this->field_exists($table, $field) || isset($this->tables[$table->name][$newname])) {
            throw new RuntimeException('Unsafe rename');
        }
        $definition = $this->tables[$table->name][$field->name];
        unset($this->tables[$table->name][$field->name]);
        $this->tables[$table->name][$newname] = $definition;
        foreach ($this->rows[$table->name] as &$row) {
            $row[$newname] = $row[$field->name];
            unset($row[$field->name]);
        }
        $this->renames[] = [$table->name, $field->name, $newname];
    }
}
class upgrade_db {
    public $manager;
    public function __construct($manager) { $this->manager = $manager; }
    public function get_manager() { return $this->manager; }
    public function get_columns($name) {
        $types = [XMLDB_TYPE_INTEGER => 'I', XMLDB_TYPE_CHAR => 'C', XMLDB_TYPE_TEXT => 'X'];
        $columns = [];
        foreach ($this->manager->tables[$name] as $field => $definition) {
            if (!is_array($definition) || !isset($types[$definition[0]])) {
                throw new RuntimeException('Unmodelled fixture column: ' . $name . '.' . $field);
            }
            [$type, $length, , $notnull, $sequence, $default] = array_pad($definition, 6, null);
            $unique = false;
            foreach ($this->get_indexes($name) as $index) {
                $unique = $unique || ($index['unique'] && $index['columns'] === [$field]);
            }
            $columns[$field] = (object)['meta_type' => $types[$type], 'max_length' => (int)$length,
                'not_null' => (bool)$notnull, 'auto_increment' => (bool)$sequence,
                'unique' => $unique, 'has_default' => $default !== null, 'default_value' => $default];
        }
        return $columns;
    }
    public function get_indexes($name) {
        $indexes = [];
        foreach ($this->manager->indexes[$name] ?? [] as $index => [$type, $fields]) {
            if (!in_array($type, [XMLDB_INDEX_UNIQUE, XMLDB_INDEX_NOTUNIQUE], true)) {
                throw new RuntimeException('Unmodelled fixture index');
            }
            $indexes[$index] = ['unique' => $type === XMLDB_INDEX_UNIQUE, 'columns' => $fields];
        }
        return $indexes;
    }
}
function upgrade_plugin_savepoint($ok, $version, $type, $plugin) {
    $GLOBALS['savepoints'][] = [$ok, $version, $type, $plugin];
}
function check_upgrade($ok, $name) {
    if (!$ok) { fwrite(STDERR, 'FAIL ' . $name . PHP_EOL); exit(1); }
    echo 'PASS ' . $name . PHP_EOL;
}
function seed_upgrade($columns) {
    $manager = new schema_manager();
    $manager->tables['local_teamscsv_scan'] = array_fill_keys($columns, true);
    $manager->tables['local_teamscsv_link'] = array_fill_keys(['id', 'courseid', 'cmid'], true);
    $manager->rows['local_teamscsv_scan'] = [
        ['id' => 9, 'courseid' => 6, 'teamid' => 'fixture-team', 'cursor' => null,
            'lastchecked' => 100, 'status' => 'pending', 'message' => null],
        ['id' => 10, 'courseid' => 12, 'teamid' => 'other-team', 'cursor' => '{"stage":"messages","seen":{}}',
            'lastchecked' => 200, 'status' => 'progress', 'message' => 'preserve'],
    ];
    $manager->rows['local_teamscsv_link'] = [
        ['id' => 1, 'courseid' => 6, 'cmid' => 406], ['id' => 2, 'courseid' => 6, 'cmid' => 407],
    ];
    $GLOBALS['savepoints'] = [];
    $GLOBALS['DB'] = new upgrade_db($manager);
    return $manager;
}
function rejected_upgrade($oldversion, $message) {
    $before = serialize([$GLOBALS['DB']->manager, $GLOBALS['savepoints']]);
    ob_start();
    try { xmldb_local_teamscsv_upgrade($oldversion); $error = null; }
    catch (Throwable $e) { $error = [get_class($e), $e->getMessage()]; }
    $output = ob_get_clean();
    check_upgrade($error === [RuntimeException::class, $message] && $output === '' &&
        serialize([$GLOBALS['DB']->manager, $GLOBALS['savepoints']]) === $before,
        $message . ' rejected silently without schema, row or counter changes');
}
eval('?>' . base64_decode('__UPGRADE__'));
$membershiptables = ['local_teamscsv_map', 'local_teamscsv_generation', 'local_teamscsv_managed',
    'local_teamscsv_dirty', 'local_teamscsv_mscan'];
$stagetables = ['local_teamscsv_page', 'local_teamscsv_pageid'];
$additions = array_merge(['local_teamscsv_tablink'], $membershiptables, $stagetables);
$columns = ['id', 'courseid', 'teamid', 'cursor', 'lastchecked', 'status', 'message'];
$manager = seed_upgrade($columns);
$before = $manager->rows;
check_upgrade(xmldb_local_teamscsv_upgrade(2026060402) === true, 'upgrade returns true');
check_upgrade(!isset($manager->tables['local_teamscsv_scan']['cursor']) &&
    isset($manager->tables['local_teamscsv_scan']['scan_cursor']), 'physical field renamed');
$expected = $before['local_teamscsv_scan'];
foreach ($expected as &$row) { $row['scan_cursor'] = $row['cursor']; unset($row['cursor']); }
unset($row);
check_upgrade($manager->rows['local_teamscsv_scan'] === $expected, 'scan IDs, null/JSON, state bytes preserved');
check_upgrade($manager->rows['local_teamscsv_link'] === $before['local_teamscsv_link'], 'resources 406 and 407 preserved');
check_upgrade($manager->creates === $additions &&
    $manager->renames === [['local_teamscsv_scan', 'cursor', 'scan_cursor']],
    'only additive tab/membership creation and native field rename');
check_upgrade($GLOBALS['savepoints'] === [[true, 2026060403, 'local', 'teamscsv'],
    [true, 2026060404, 'local', 'teamscsv'], [true, 2026060406, 'local', 'teamscsv'],
    [true, 2026060407, 'local', 'teamscsv']], 'version saved after each additive migration');
$tabfields = array_keys($manager->tables['local_teamscsv_tablink'] ?? []);
check_upgrade($tabfields === ['id', 'courseid', 'teamid', 'kind', 'urlhash', 'url', 'timecreated'] &&
    !in_array('cmid', $tabfields, true), 'tab schema fields have no cmid');
$hasunique = false;
foreach ($manager->indexes['local_teamscsv_tablink'] ?? [] as $index) {
    $hasunique = $hasunique || ($index[0] === XMLDB_INDEX_UNIQUE &&
        $index[1] === ['courseid', 'teamid', 'kind', 'urlhash']);
}
check_upgrade($hasunique, 'dedupe uniqueness across course/team/kind/hash');
$GLOBALS['savepoints'] = [];
$before = serialize($manager);
xmldb_local_teamscsv_upgrade(2026060407);
check_upgrade(serialize($manager) === $before && count($manager->renames) === 1 &&
    $manager->creates === $additions && $GLOBALS['savepoints'] === [], 'current version is a no-op');
$manager = seed_upgrade(array_map(function($name) { return $name === 'cursor' ? 'scan_cursor' : $name; }, $columns));
foreach ($manager->rows['local_teamscsv_scan'] as &$row) { $row['scan_cursor'] = $row['cursor']; unset($row['cursor']); }
unset($row);
$before = $manager->rows;
xmldb_local_teamscsv_upgrade(2026060402);
check_upgrade($manager->renames === [] && $manager->rows['local_teamscsv_scan'] === $before['local_teamscsv_scan'] &&
    $manager->rows['local_teamscsv_link'] === $before['local_teamscsv_link'] &&
    $manager->creates === $additions && $GLOBALS['savepoints'] === [
        [true, 2026060403, 'local', 'teamscsv'], [true, 2026060404, 'local', 'teamscsv'],
        [true, 2026060406, 'local', 'teamscsv'], [true, 2026060407, 'local', 'teamscsv']],
    'safe re-entry when rename happened before savepoint');
$GLOBALS['savepoints'] = [];
$before = serialize($manager);
xmldb_local_teamscsv_upgrade(2026060403);
check_upgrade(serialize($manager) === $before && $manager->creates === $additions &&
    $GLOBALS['savepoints'] === [[true, 2026060404, 'local', 'teamscsv'],
        [true, 2026060406, 'local', 'teamscsv'], [true, 2026060407, 'local', 'teamscsv']],
    're-entry from 403 preserves existing tab and legacy rows');
foreach ([
    [array_merge($columns, ['scan_cursor']), 'Both cursor columns exist; preserve both and request administrator review.'],
    [array_values(array_diff($columns, ['cursor'])), 'Scan cursor column missing; upgrade stopped without rebuilding data.'],
] as [$ambiguous, $message]) {
    seed_upgrade($ambiguous);
    rejected_upgrade(2026060402, $message);
}
$manager = new schema_manager();
$DB = new upgrade_db($manager);
$GLOBALS['savepoints'] = [];
xmldb_local_teamscsv_upgrade(2026060400);
check_upgrade(isset($manager->tables['local_teamscsv_scan']['scan_cursor']) &&
    !isset($manager->tables['local_teamscsv_scan']['cursor']) &&
    isset($manager->tables['local_teamscsv_link']) &&
    isset($manager->tables['local_teamscsv_tablink']), 'older install reaches additive schema');
$schema = json_decode(base64_decode('__INSTALL_FIELDS__'), true);
check_upgrade($schema['version'] === '2026060407', 'fresh schema targets additive 407');
$expectedfields = $schema['fields'];
check_upgrade(isset($expectedfields['local_teamscsv_tablink']) &&
    $expectedfields['local_teamscsv_tablink'] ===
        ['id', 'courseid', 'teamid', 'kind', 'urlhash', 'url', 'timecreated'],
    'fresh tab table excludes cmid and includes required fields');
check_upgrade(in_array(['UNIQUE' => 'true', 'FIELDS' => 'courseid,teamid,kind,urlhash'],
    $schema['tabindexes'], true), 'fresh tab uniqueness matches migration');
foreach ($expectedfields as $table => $fields) {
    $actual = array_keys($manager->tables[$table]); sort($actual); sort($fields);
    check_upgrade($actual === $fields, 'fresh/upgrade field names match ' . $table);
    $columns = $DB->get_columns($table);
    $valid = true;
    foreach ($schema['columns'][$table] as $field => $spec) {
        $column = $columns[$field];
        $valid = $valid && $column->meta_type === ['int' => 'I', 'char' => 'C', 'text' => 'X'][$spec['TYPE']] &&
            $column->max_length === (int)($spec['LENGTH'] ?? 0) &&
            $column->not_null === ($spec['NOTNULL'] === 'true') &&
            $column->auto_increment === ($spec['SEQUENCE'] === 'true') &&
            $column->has_default === isset($spec['DEFAULT']) &&
            $column->default_value === ($spec['DEFAULT'] ?? null) && $column->unique === ($field === 'id' ||
                in_array(['unique' => true, 'columns' => [$field]], $schema['indexes'][$table], true));
    }
    $indexes = $DB->get_indexes($table);
    check_upgrade(($indexes['primary'] ?? null) === ['unique' => true, 'columns' => ['id']],
        'fresh/upgrade primary key matches ' . $table);
    unset($indexes['primary']);
    check_upgrade($valid && $indexes === $schema['indexes'][$table], 'fresh/upgrade metadata and indexes match ' . $table);
}
check_upgrade(array_keys($manager->tables) === array_keys($expectedfields), 'exact three legacy plus seven membership tables');
check_upgrade($GLOBALS['savepoints'] === array_map(static fn($v) => [true, $v, 'local', 'teamscsv'],
    [2026060402, 2026060403, 2026060404, 2026060406, 2026060407]),
    'older install records every savepoint in order');
// Populated lost-savepoint re-entry must not rewrite any managed history, retry or daily cursor.
$manager->rows['local_teamscsv_map'] = [['id' => 3, 'courseid' => 6, 'generationid' => 9]];
$manager->rows['local_teamscsv_generation'] = [['id' => 9, 'courseid' => 6, 'teamid' => 'fixture-team',
    'revision' => str_repeat('f', 64), 'timecreated' => 100]];
$manager->rows['local_teamscsv_managed'] = [['id' => 8, 'generationid' => 9, 'userid' => 21,
    'objectid' => 'fixture-user', 'role' => 'intent', 'confirmedat' => 0, 'released' => 0]];
$manager->rows['local_teamscsv_dirty'] = [['id' => 2, 'generationid' => 9, 'serial' => 5, 'attempts' => 3, 'nextattempt' => 200]];
$manager->rows['local_teamscsv_mscan'] = [['id' => 1, 'scan_key' => 'daily', 'afterid' => 3, 'ceiling' => 500, 'lastchecked' => 0]];
$complete = serialize($manager);
foreach ([2026060404, 2026060405, 2026060406, 2026060407] as $version) {
    $GLOBALS['savepoints'] = [];
    $before = serialize($manager);
    xmldb_local_teamscsv_upgrade($version);
    $expected = ($version < 2026060406 ? [[true, 2026060406, 'local', 'teamscsv']] : []);
    if ($version < 2026060407) { $expected[] = [true, 2026060407, 'local', 'teamscsv']; }
    check_upgrade(serialize($manager) === $before && $GLOBALS['savepoints'] === $expected,
        'populated membership and mscan re-entry/no-op from ' . $version);
}
// Model the four-table 406 preparation before the daily cursor was added, still at VM404.
unset($manager->tables['local_teamscsv_mscan'], $manager->indexes['local_teamscsv_mscan'],
    $manager->rows['local_teamscsv_mscan']);
$before = $manager->rows; $creates = $manager->creates; $GLOBALS['savepoints'] = [];
xmldb_local_teamscsv_upgrade(2026060404);
$expected = $before; $expected['local_teamscsv_mscan'] = [];
check_upgrade($manager->rows === $expected && $manager->creates === array_merge($creates, ['local_teamscsv_mscan']) &&
    $GLOBALS['savepoints'] === [[true, 2026060406, 'local', 'teamscsv'],
        [true, 2026060407, 'local', 'teamscsv']],
    'four populated membership tables survive additive mscan creation from 404');
foreach ($membershiptables as $table) {
    foreach (['field', 'type', 'length', 'null', 'sequence', 'default', 'primary', 'index', 'extraunique'] as $conflict) {
        $manager = unserialize($complete); $DB = new upgrade_db($manager); $GLOBALS['savepoints'] = [];
        $field = array_keys($manager->tables[$table])[1];
        if ($conflict === 'length') {
            $chars = array_keys(array_filter($manager->tables[$table], static fn($args) => $args[0] === XMLDB_TYPE_CHAR));
            if (!$chars) { continue; }
            $field = $chars[0]; $manager->tables[$table][$field][1] = '1';
        }
        if ($conflict === 'field') { unset($manager->tables[$table][$field]); }
        if ($conflict === 'type') { $manager->tables[$table][$field][0] = XMLDB_TYPE_TEXT; }
        if ($conflict === 'null') { $manager->tables[$table][$field][3] = null; }
        if ($conflict === 'sequence') { $manager->tables[$table]['id'][4] = null; }
        if ($conflict === 'default') { $manager->tables[$table][$field][5] = '0'; }
        if ($conflict === 'primary') { unset($manager->indexes[$table]['primary']); }
        if ($conflict === 'index') {
            $index = array_keys($schema['indexes'][$table])[0]; unset($manager->indexes[$table][$index]);
        }
        if ($conflict === 'extraunique') { $manager->indexes[$table]['wronguniq'] = [XMLDB_INDEX_UNIQUE, ['id', $field]]; }
        // A missing sibling must not be created before ALL existing membership tables validate.
        $missing = $table === 'local_teamscsv_map' ? 'local_teamscsv_mscan' : 'local_teamscsv_map';
        unset($manager->tables[$missing], $manager->indexes[$missing], $manager->rows[$missing]);
        rejected_upgrade(2026060404, 'Conflicting membership schema: ' . $table);
    }
}
