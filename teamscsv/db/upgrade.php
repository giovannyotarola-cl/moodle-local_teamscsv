<?php
// SPDX-License-Identifier: GPL-3.0-or-later
defined('MOODLE_INTERNAL') || die();

function xmldb_local_teamscsv_upgrade($oldversion) {
    global $DB;
    if ($oldversion < 2026060402) {
        $dbman = $DB->get_manager();
        $tables = [
            'local_teamscsv_scan' => [
                ['courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL],
                ['teamid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL],
                ['cursor', XMLDB_TYPE_TEXT, null, null, null],
                ['lastchecked', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL],
                ['status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL],
                ['message', XMLDB_TYPE_TEXT, null, null, null],
            ],
            'local_teamscsv_link' => [
                ['courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL],
                ['teamid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL],
                ['kind', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL],
                ['urlhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL],
                ['cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL],
                ['url', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL],
                ['timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL],
            ],
        ];
        foreach ($tables as $name => $fields) {
            $table = new xmldb_table($name);
            if (!$dbman->table_exists($table)) {
                $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
                foreach ($fields as [$field, $type, $length, $unsigned, $notnull]) {
                    $table->add_field($field, $type, $length, $unsigned, $notnull, null, null);
                }
                $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
                $table->add_index($name === 'local_teamscsv_scan' ? 'courseuniq' : 'sourceuniq', XMLDB_INDEX_UNIQUE,
                    $name === 'local_teamscsv_scan' ? ['courseid'] : ['courseid', 'teamid', 'kind', 'urlhash']);
                $dbman->create_table($table);
            }
        }
        upgrade_plugin_savepoint(true, 2026060402, 'local', 'teamscsv');
    }
    if ($oldversion < 2026060403) {
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_teamscsv_scan');
        $legacy = new xmldb_field('cursor', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $replacement = new xmldb_field('scan_cursor', XMLDB_TYPE_TEXT, null, null, null, null, null);
        if (!$dbman->table_exists($table)) {
            throw new RuntimeException('Scan state table missing; upgrade stopped without rebuilding data.');
        }
        $haslegacy = $dbman->field_exists($table, $legacy);
        $hasreplacement = $dbman->field_exists($table, $replacement);
        if ($haslegacy && $hasreplacement) {
            throw new RuntimeException('Both cursor columns exist; preserve both and request administrator review.');
        }
        if (!$haslegacy && !$hasreplacement) {
            throw new RuntimeException('Scan cursor column missing; upgrade stopped without rebuilding data.');
        }
        if ($haslegacy) {
            // Native XMLDB quotes the old reserved identifier and preserves rows, IDs and NULL/JSON values.
            $dbman->rename_field($table, $legacy, 'scan_cursor');
        }
        // Safe re-entry: a prior attempt may have renamed the field before recording its savepoint.
        upgrade_plugin_savepoint(true, 2026060403, 'local', 'teamscsv');
    }
    if ($oldversion < 2026060404) {
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_teamscsv_tablink');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('teamid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('kind', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
            $table->add_field('urlhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('url', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('sourceuniq', XMLDB_INDEX_UNIQUE, ['courseid', 'teamid', 'kind', 'urlhash']);
            $dbman->create_table($table);
        }
        // Additive re-entry never rewrites scan state, settings, or legacy module ownership.
        upgrade_plugin_savepoint(true, 2026060404, 'local', 'teamscsv');
    }
    if ($oldversion < 2026060406) {
        $dbman = $DB->get_manager();
        $definitions = [
            'local_teamscsv_map' => [
                ['courseid', XMLDB_TYPE_INTEGER, '10'], ['generationid', XMLDB_TYPE_INTEGER, '10'],
            ],
            'local_teamscsv_generation' => [
                ['courseid', XMLDB_TYPE_INTEGER, '10'], ['teamid', XMLDB_TYPE_CHAR, '36'],
                ['revision', XMLDB_TYPE_CHAR, '64'], ['timecreated', XMLDB_TYPE_INTEGER, '10'],
            ],
            'local_teamscsv_managed' => [
                ['generationid', XMLDB_TYPE_INTEGER, '10'], ['userid', XMLDB_TYPE_INTEGER, '10'],
                ['objectid', XMLDB_TYPE_CHAR, '36'], ['role', XMLDB_TYPE_CHAR, '6'],
                ['confirmedat', XMLDB_TYPE_INTEGER, '10'], ['released', XMLDB_TYPE_INTEGER, '1'],
            ],
            'local_teamscsv_dirty' => [
                ['generationid', XMLDB_TYPE_INTEGER, '10'], ['serial', XMLDB_TYPE_INTEGER, '10'],
                ['attempts', XMLDB_TYPE_INTEGER, '10'], ['nextattempt', XMLDB_TYPE_INTEGER, '10'],
            ],
            // 0406 has not been deployed. Additive from VM0404; matching partial tables re-enter safely.
            'local_teamscsv_mscan' => [
                ['scan_key', XMLDB_TYPE_CHAR, '8'], ['afterid', XMLDB_TYPE_INTEGER, '10'],
                ['ceiling', XMLDB_TYPE_INTEGER, '10'], ['lastchecked', XMLDB_TYPE_INTEGER, '10'],
            ],
        ];
        $indexes = [
            'local_teamscsv_map' => [['courseuniq', true, ['courseid']]],
            'local_teamscsv_generation' => [['revisionuniq', true, ['revision']], ['courseidx', false, ['courseid']]],
            'local_teamscsv_managed' => [
                ['identityuniq', true, ['generationid', 'userid', 'objectid']],
                ['objectuniq', true, ['generationid', 'objectid']], ['activeidx', false, ['generationid', 'released', 'id']],
            ],
            'local_teamscsv_dirty' => [['generationuniq', true, ['generationid']], ['dueidx', false, ['nextattempt', 'id']]],
            'local_teamscsv_mscan' => [['scanuniq', true, ['scan_key']]],
        ];
        $tables = [];
        foreach ($definitions as $name => $fields) {
            $table = new xmldb_table($name);
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            foreach ($fields as [$field, $type, $length]) {
                $table->add_field($field, $type, $length, null, XMLDB_NOTNULL, null, null);
            }
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            foreach ($indexes[$name] as [$index, $unique, $columns]) {
                $table->add_index($index, $unique ? XMLDB_INDEX_UNIQUE : XMLDB_INDEX_NOTUNIQUE, $columns);
            }
            $tables[$name] = $table;
            if (!$dbman->table_exists($table)) {
                continue;
            }
            // Validate every existing new table before creating any missing ones. Never repair by rebuilding.
            $columns = $DB->get_columns($name);
            $expected = array_merge([['id', XMLDB_TYPE_INTEGER, '10']], $fields);
            $valid = count($columns) === count($expected);
            foreach ($expected as [$field, $type, $length]) {
                $column = $columns[$field] ?? null;
                $valid = $valid && $column && $column->meta_type === ($type === XMLDB_TYPE_CHAR ? 'C' : 'I') &&
                    $column->not_null && (bool)$column->auto_increment === ($field === 'id') &&
                    ($type !== XMLDB_TYPE_CHAR || (int)$column->max_length === (int)$length) &&
                    ($field === 'id' ? $column->unique : !$column->has_default);
            }
            $actualindexes = $DB->get_indexes($name);
            foreach ($indexes[$name] as [$index, $unique, $fields]) {
                $found = false;
                foreach ($actualindexes as $actual) {
                    $found = $found || ($actual['columns'] === $fields && (bool)$actual['unique'] === $unique);
                }
                $valid = $valid && $found;
            }
            foreach ($actualindexes as $actual) {
                if (!$actual['unique'] || $actual['columns'] === ['id']) {
                    continue;
                }
                $known = false;
                foreach ($indexes[$name] as [$index, $unique, $fields]) {
                    $known = $known || ($unique && $fields === $actual['columns']);
                }
                $valid = $valid && $known;
            }
            if (!$valid) {
                throw new RuntimeException('Conflicting membership schema: ' . $name);
            }
        }
        foreach ($tables as $table) {
            if (!$dbman->table_exists($table)) {
                $dbman->create_table($table);
            }
        }
        upgrade_plugin_savepoint(true, 2026060406, 'local', 'teamscsv');
    }
    if ($oldversion < 2026060407) {
        $dbman = $DB->get_manager();
        $definitions = [
            'local_teamscsv_page' => [
                ['generationid', XMLDB_TYPE_INTEGER, '10', true],
                ['role', XMLDB_TYPE_CHAR, '7', true],
                ['serial', XMLDB_TYPE_INTEGER, '10', true],
                ['nextpath', XMLDB_TYPE_TEXT, null, false],
                ['seen', XMLDB_TYPE_TEXT, null, true],
                ['pages', XMLDB_TYPE_INTEGER, '10', true],
                ['idcount', XMLDB_TYPE_INTEGER, '10', true],
                ['startedat', XMLDB_TYPE_INTEGER, '10', true],
                ['complete', XMLDB_TYPE_INTEGER, '1', true],
            ],
            'local_teamscsv_pageid' => [
                ['pageid', XMLDB_TYPE_INTEGER, '10', true],
                ['objectid', XMLDB_TYPE_CHAR, '36', true],
            ],
        ];
        $indexes = [
            'local_teamscsv_page' => ['generationrole', ['generationid', 'role']],
            'local_teamscsv_pageid' => ['pageobject', ['pageid', 'objectid']],
        ];
        $tables = [];
        foreach ($definitions as $name => $fields) {
            $table = new xmldb_table($name);
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            foreach ($fields as [$field, $type, $length, $required]) {
                $table->add_field($field, $type, $length, null, $required ? XMLDB_NOTNULL : null, null, null);
            }
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            [$index, $columns] = $indexes[$name];
            $table->add_index($index, XMLDB_INDEX_UNIQUE, $columns);
            $tables[$name] = $table;
            if (!$dbman->table_exists($table)) {
                continue;
            }
            // A partially applied upgrade must match exactly; never rebuild or discard data.
            $actual = $DB->get_columns($name);
            $expected = array_merge([['id', XMLDB_TYPE_INTEGER, '10', true]], $fields);
            $valid = count($actual) === count($expected);
            foreach ($expected as [$field, $type, $length, $required]) {
                $column = $actual[$field] ?? null;
                $typevalid = $column && (($type === XMLDB_TYPE_INTEGER && $field === 'id') ?
                    in_array($column->meta_type, ['I', 'R'], true) :
                    $column->meta_type === ($type === XMLDB_TYPE_CHAR ? 'C' :
                        ($type === XMLDB_TYPE_TEXT ? 'X' : 'I')));
                $valid = $valid && $typevalid && (bool)$column->not_null === $required &&
                    (bool)$column->auto_increment === ($field === 'id') &&
                    ($type !== XMLDB_TYPE_CHAR || (int)$column->max_length === (int)$length) &&
                    ($field === 'id' ? $column->unique : (!$column->has_default ||
                        ($type === XMLDB_TYPE_CHAR && $column->default_value === '')));
            }
            // DML reports physical index names, and some drivers omit PRIMARY altogether.
            $actualindexes = $DB->get_indexes($name);
            $targetcount = 0;
            $idcount = 0;
            foreach ($actualindexes as $actualindex) {
                if (!$actualindex['unique']) {
                    $valid = false;
                } else if ($actualindex['columns'] === $columns) {
                    $targetcount++;
                } else if ($actualindex['columns'] === ['id']) {
                    $idcount++;
                } else {
                    $valid = false;
                }
            }
            $valid = $valid && $targetcount === 1 && $idcount <= 1 &&
                count($actualindexes) === $targetcount + $idcount;
            if (!$valid) {
                throw new RuntimeException('Conflicting staged membership schema: ' . $name);
            }
        }
        foreach ($tables as $table) {
            if (!$dbman->table_exists($table)) {
                $dbman->create_table($table);
            }
        }
        upgrade_plugin_savepoint(true, 2026060407, 'local', 'teamscsv');
    }
    return true;
}
