<?php
// SPDX-License-Identifier: GPL-3.0-or-later
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/local/o365/lib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());
if (!is_siteadmin()) {
    throw new required_capability_exception(context_system::instance(), 'moodle/site:config', 'nopermissions', '');
}
admin_externalpage_setup('local_teamscsv');

/** A filepicker is temporary user draft storage, never a server path. */
class local_teamscsv_upload_form extends moodleform {
    public function definition() {
        $mform = $this->_form;
        $mform->addElement('filepicker', 'csv', get_string('csvfile', 'local_teamscsv'), null,
            ['accepted_types' => ['.csv'], 'maxbytes' => \local_teamscsv\importer::MAXBYTES]);
        $mform->addRule('csv', null, 'required', null, 'client');
        $this->add_action_buttons(false, get_string('preview', 'local_teamscsv'));
    }
}

$url = new moodle_url('/local/teamscsv/index.php');
$form = new local_teamscsv_upload_form($url);
$message = '';
$error = '';
$plan = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    try {
        if (optional_param('apply', 0, PARAM_BOOL)) {
            $token = required_param('token', PARAM_ALPHANUM);
            $pending = $SESSION->local_teamscsv_pending ?? null;
            // One-use, expiring, user-bound preview. Do not accept mappings supplied by the browser.
            unset($SESSION->local_teamscsv_pending);
            if (!$pending || !hash_equals($pending['token'], $token) ||
                    $pending['userid'] !== (int)$USER->id || $pending['expires'] < time()) {
                throw new RuntimeException('Preview expired or already used. Upload the CSV again.');
            }
            if (!optional_param('acknowledge', 0, PARAM_BOOL)) {
                throw new RuntimeException('Confirm the import-window and managed-scope acknowledgement first.');
            }
            $count = \local_teamscsv\importer::apply($pending['rows'], $pending['plan']);
            $message = get_string('queued', 'local_teamscsv', $count);
        } else if ($form->get_data()) {
            unset($SESSION->local_teamscsv_pending);
            $rows = \local_teamscsv\importer::parse($form->get_file_content('csv'));
            $plan = \local_teamscsv\importer::preview($rows);
            $SESSION->local_teamscsv_pending = ['rows' => $rows, 'plan' => $plan,
                'userid' => (int)$USER->id, 'expires' => time() + 900, 'token' => bin2hex(random_bytes(32))];
        }
    } catch (Throwable $e) {
        // Errors may contain course identifiers. Moodle escapes notification text below.
        $error = $e->getMessage();
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_teamscsv'));
echo $OUTPUT->notification(get_string('warning', 'local_teamscsv'), 'warning');
if ($error !== '') {
    echo $OUTPUT->notification(s($error), 'error');
}
if ($message !== '') {
    echo $OUTPUT->notification(s($message), 'success');
}
if ($plan !== null) {
    $table = new html_table();
    $table->head = ['Course name', 'Internal course ID', 'Team name', 'Microsoft Team ID'];
    foreach ($plan as $row) {
        $table->data[] = array_map('s', [$row['course_name'], (string)$row['courseid'],
            $row['team_name'], $row['teamid']]);
    }
    echo html_writer::table($table);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
    foreach (['sesskey' => sesskey(), 'apply' => 1, 'token' => $SESSION->local_teamscsv_pending['token']] as $name => $value) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
    }
    echo html_writer::tag('label', html_writer::empty_tag('input',
        ['type' => 'checkbox', 'name' => 'acknowledge', 'value' => 1, 'required' => 'required']) . ' ' .
        s(get_string('acknowledge', 'local_teamscsv')));
    echo html_writer::tag('div', html_writer::tag('button', s(get_string('apply', 'local_teamscsv')),
        ['type' => 'submit', 'class' => 'btn btn-primary mt-3']));
    echo html_writer::end_tag('form');
} else {
    $form->display();
}
echo $OUTPUT->footer();
