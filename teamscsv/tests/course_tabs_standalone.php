<?php
// Isolated PHP stdin fixture. Actual public callback, controller and display are exercised;
// no Moodle bootstrap, browser, Graph client, disk writes or remote application is loaded.
namespace {
define('MOODLE_INTERNAL', true);
define('SITEID', 1);
define('PARAM_INT', 1);
define('PARAM_ALPHA', 2);
define('CONTEXT_COURSE', 50);

function check_tabs($ok, $reason) { if (!$ok) { throw new \RuntimeException($reason); } }
function required_param($key, $type) { return $GLOBALS['request'][$key] ?? null; }
function get_course($id) { return $GLOBALS['DB']->get_record('course', ['id' => $id]); }
function require_login($course = null, $autologinguest = true, $cm = null, $setwantsurltome = true) {
    $GLOBALS['loginchecks']++;
    if (!$course || (int)$course->id !== (int)$GLOBALS['request']['id'] || !$GLOBALS['allowed']) {
        throw new \RuntimeException('Course access denied');
    }
}
function get_string($key, $component = '', $a = null) {
    return $component === 'local_teamscsv' ? ($GLOBALS['strings'][$key] ?? '[[missing:' . $key . ']]') : $key;
}
function format_string($s) { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function s($s) { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function get_config($component, $key = null) { return false; } // Scanner is disabled throughout.
function redirect($url) { throw new \RuntimeException('Unexpected redirect'); }
class context_course {
    public $contextlevel = CONTEXT_COURSE;
    public $instanceid;
    public function __construct($id) { $this->instanceid = $id; }
    public static function instance($id) { return new self($id); }
}
class moodle_url {
    private $path;
    private $params;
    public function __construct($path, $params = []) { $this->path = $path; $this->params = $params; }
    public function __toString() { return $this->path . ($this->params ? '?' . http_build_query($this->params) : ''); }
    public function out($escaped = true) { return $escaped ? s((string)$this) : (string)$this; }
}
class navigation_node {
    const TYPE_COURSE = 20;
    const TYPE_CUSTOM = 0;
    public $key;
    public $text;
    public $action;
    public $children = [];
    public function __construct($text, $action = null, $key = null) {
        $this->text = $text; $this->action = $action; $this->key = $key;
    }
    public static function create($text, $action = null, $type = null, $shorttext = null, $key = null) {
        return new self($text, $action, $key);
    }
    public function add($text, $action = null, $type = null, $shorttext = null, $key = null) {
        $node = new self($text, $action, $key);
        $this->children[] = $node;
        return $node;
    }
}
class settings_navigation {
    public $courseadmin;
    public function __construct() { $this->courseadmin = new navigation_node('Course admin', null, 'courseadmin'); }
    public function find($key, $type = null) {
        return $key === 'courseadmin' ? $this->courseadmin : null;
    }
}
class html_writer {
    public static function link($url, $text, $attributes = []) {
        $attr = '';
        foreach ($attributes as $name => $value) { $attr .= ' ' . s($name) . '="' . s($value) . '"'; }
        return '<a href="' . s((string)$url) . '"' . $attr . '>' . $text . '</a>';
    }
    public static function tag($tag, $text, $attributes = []) {
        $attr = '';
        foreach ($attributes as $name => $value) { $attr .= ' ' . s($name) . '="' . s($value) . '"'; }
        return '<' . $tag . $attr . '>' . $text . '</' . $tag . '>';
    }
    public static function div($text, $class = '') { return self::tag('div', $text, ['class' => $class]); }
}
class tabs_output {
    public function header() { return '<main>'; }
    public function footer() { return '</main>'; }
    public function heading($text) { return '<h1>' . s($text) . '</h1>'; }
}
class tabs_page {
    public $settingsnav;
    public $url;
    public $title;
    public $heading;
    public $context;
    public function __construct() { $this->settingsnav = new settings_navigation(); }
    public function set_url($url) { $this->url = $url; }
    public function set_context($context) { $this->context = $context; }
    public function set_title($title) { $this->title = $title; }
    public function set_heading($heading) { $this->heading = $heading; }
    public function set_pagelayout($layout) {}
}
class tabs_db {
    public $rows = [];
    public $lookups = [];
    public $writes = 0;
    public function __construct() { $this->reset(); }
    public function reset() {
        $this->rows = ['course' => [], 'local_o365_objects' => [], 'local_teamscsv_tablink' => []];
        $this->lookups = []; $this->writes = 0;
    }
    public function get_records($table, $conditions = null, $sort = '', $fields = '*', $limitfrom = 0, $limitnum = 0) {
        $this->lookups[] = [$table, $conditions];
        $result = array_values(array_filter($this->rows[$table] ?? [], static function($row) use ($conditions) {
            foreach ($conditions ?? [] as $name => $value) {
                if (!property_exists($row, $name) || $row->$name != $value) { return false; }
            }
            return true;
        }));
        return $limitnum ? array_slice($result, $limitfrom, $limitnum) : $result;
    }
    public function get_record($table, $conditions, $fields = '*', $strictness = 0) {
        return $this->get_records($table, $conditions)[0] ?? false;
    }
    public function record_exists($table, $conditions) { return (bool)$this->get_record($table, $conditions); }
    public function get_records_sql($sql, $params = [], $limitfrom = 0, $limitnum = 0) {
        $this->lookups[] = ['SQL:' . $sql, $params];
        throw new \RuntimeException('Unexpected SQL in isolated fixture; add a bounded, faithful mapping stub');
    }
    public function insert_record($table, $data) { $this->writes++; throw new \RuntimeException('Page attempted insert'); }
    public function update_record($table, $data) { $this->writes++; throw new \RuntimeException('Page attempted update'); }
    public function delete_records($table, $conditions) { $this->writes++; throw new \RuntimeException('Page attempted deletion'); }
}
$DB = new tabs_db();
$PAGE = new tabs_page();
$OUTPUT = new tabs_output();
$GLOBALS['allowed'] = true;
$GLOBALS['loginchecks'] = 0;
$GLOBALS['request'] = ['id' => 6, 'kind' => 'meeting'];
$GLOBALS['graphcalls'] = 0;
$encoded = ['COURSE_LINKS' => '__COURSE_LINKS__', 'LIB' => '__LIB__',
    'CONTROLLER' => '__CONTROLLER__', 'PUBLISHER' => '__PUBLISHER__',
    'EN' => '__EN__', 'ES' => '__ES__'];
// Fail before setting any fake records if a functional path has not yet been implemented.
foreach ($encoded as $name => $source) {
    if ($source === '') { throw new \RuntimeException('Missing required course-tab source: ' . $name); }
}
foreach (['PUBLISHER', 'COURSE_LINKS', 'LIB'] as $name) { eval('?>' . base64_decode($encoded[$name])); }
function language_strings($source) {
    $string = [];
    eval('?>' . base64_decode($source));
    return $string;
}
$english = language_strings($encoded['EN']);
$spanish = language_strings($encoded['ES']);
foreach ([$english, $spanish] as $language) {
    check_tabs(in_array('Reuniones Clases Teams', $language, true) &&
        in_array('Grabaciones Clases', $language, true), 'Exact tab labels absent from language');
}
$GLOBALS['strings'] = $english;
check_tabs(function_exists('local_teamscsv_extend_settings_navigation'),
    'Missing supported local-plugin settings-navigation callback');
check_tabs(method_exists('local_teamscsv\\course_links', 'display'), 'Missing public course_links::display');
check_tabs(method_exists('local_teamscsv\\link_publisher', 'is_valid_destination'),
    'Missing public shared destination validator');
$team = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
$otherteam = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
$join = 'https://teams.microsoft.com/l/meetup-join/a/b?context=fixture';
$folder = 'https://site.sharepoint.com/sites/course/Recordings';
$DB->rows['course'] = [(object)['id' => 6, 'fullname' => 'Linked'],
    (object)['id' => 7, 'fullname' => 'Unlinked'], (object)['id' => 8, 'fullname' => 'Other']];
function mapping_rows($id, $team) {
    return [(object)['id' => $id * 2, 'type' => 'group', 'subtype' => 'course',
        'moodleid' => $id, 'objectid' => $team],
        (object)['id' => $id * 2 + 1, 'type' => 'group', 'subtype' => 'courseteam',
            'moodleid' => $id, 'objectid' => $team]];
}
$validmapping = array_merge(mapping_rows(6, $team), mapping_rows(8, $otherteam));
$DB->rows['local_o365_objects'] = $validmapping;
$DB->rows['local_teamscsv_tablink'] = [
    (object)['id' => 1, 'courseid' => 6, 'teamid' => $team, 'kind' => 'meeting', 'urlhash' => hash('sha256', $join), 'url' => $join],
    (object)['id' => 2, 'courseid' => 6, 'teamid' => $team, 'kind' => 'recordings', 'urlhash' => hash('sha256', $folder), 'url' => $folder],
    (object)['id' => 3, 'courseid' => 8, 'teamid' => $otherteam, 'kind' => 'meeting', 'url' => 'https://teams.microsoft.com/l/meetup-join/other/course'],
    (object)['id' => 4, 'courseid' => 6, 'teamid' => $otherteam, 'kind' => 'meeting', 'url' => 'https://teams.microsoft.com/l/meetup-join/other/team'],
    (object)['id' => 5, 'courseid' => 6, 'teamid' => $team, 'kind' => 'meeting', 'url' => 'javascript:alert(1)'],
    (object)['id' => 6, 'courseid' => 6, 'teamid' => $team, 'kind' => 'meeting', 'url' => 'https://evil.example/?x=<script>'],
];
function lookup_count($table) {
    global $DB;
    return count(array_filter($DB->lookups, static function($entry) use ($table) {
        return $entry[0] === $table || str_contains($entry[0], '{' . $table . '}');
    }));
}
function no_side_effects($previous, $label) {
    global $DB;
    check_tabs($DB->writes === $previous && $GLOBALS['graphcalls'] === 0, $label . ' wrote data or called Graph');
}
$context = context_course::instance(6);
$settings = new settings_navigation();
$before = $DB->writes;
local_teamscsv_extend_settings_navigation($settings, $context);
local_teamscsv_extend_settings_navigation($settings, $context);
check_tabs(count($settings->courseadmin->children) === 3, 'Linked course needs exactly three courseadmin children, even on repeat');
foreach ([['Reuniones Clases Teams', 'meeting'], ['Grabaciones Clases', 'recordings'],
    ['Material de Clases', 'material']] as $index => [$label, $kind]) {
    $node = $settings->courseadmin->children[$index];
    check_tabs($node->text === $label && str_contains((string)$node->action, '/links.php?id=6&kind=' . $kind),
        'Incorrect course-scoped tab label or destination: ' . $kind);
}
$unlinked = new settings_navigation();
local_teamscsv_extend_settings_navigation($unlinked, context_course::instance(7));
check_tabs(count($unlinked->courseadmin->children) === 0, 'Unlinked course gained tabs');
no_side_effects($before, 'Navigation');
echo "PASS navigation labels/courseadmin/linked-only\n";
function display_case($id, $kind, $allowed = true) {
    global $DB;
    $GLOBALS['request'] = ['id' => $id, 'kind' => $kind];
    $GLOBALS['allowed'] = $allowed;
    $GLOBALS['loginchecks'] = 0;
    $DB->lookups = [];
    ob_start();
    try { \local_teamscsv\course_links::display($id, $kind); $error = null; }
    catch (\Throwable $e) { $error = $e; }
    return [ob_get_clean(), $error];
}
$before = $DB->writes;
[$output, $error] = display_case(6, 'meeting', false);
check_tabs($error !== null && $output === '' && $GLOBALS['loginchecks'] > 0 && lookup_count('local_teamscsv_tablink') === 0,
    'Denied course leaked output or queried stored links before login');
no_side_effects($before, 'Denied display');
[$output, $error] = display_case(6, 'meeting');
check_tabs($error === null && $GLOBALS['loginchecks'] > 0 && substr_count($output, 'href="' . s($join) . '"') === 1 &&
    !str_contains($output, $folder) && !str_contains($output, 'other/course') &&
    !str_contains($output, 'other/team') && !str_contains($output, 'javascript:') &&
    !str_contains($output, 'evil.example') && !str_contains($output, '<script>'),
    'Meeting page failed isolation, escaping, or stored destination validation');
check_tabs((bool)preg_match('~<a\\b[^>]*href="' . preg_quote(s($join), '~') . '"[^>]*>~', $output, $anchor) &&
    str_contains($anchor[0], 'target="_blank"') &&
    str_contains($anchor[0], 'noopener') && str_contains($anchor[0], 'noreferrer'),
    'External link missing safe new-window attributes');
no_side_effects($before, 'Meeting display');
[$output, $error] = display_case(6, 'recordings');
check_tabs($error === null && substr_count($output, 'href="' . s($folder) . '"') === 1 &&
    !str_contains($output, $join), 'Recordings page mixed kind or omitted folder');
no_side_effects($before, 'Recordings display');
echo "PASS authorized filtered displays, unsafe URLs, scanner disabled\n";
// Public controller is evaluated only after the fixture strips the exact config bootstrap.
$GLOBALS['request'] = ['id' => 6, 'kind' => 'meeting'];
$GLOBALS['allowed'] = true;
$DB->lookups = [];
$before = $DB->writes;
ob_start();
try { eval('?>' . base64_decode($encoded['CONTROLLER'])); $error = null; }
catch (\Throwable $e) { $error = $e; }
$output = ob_get_clean();
check_tabs($error === null && str_contains($output, 'href="' . s($join) . '"'),
    'Thin links.php controller did not render public display');
no_side_effects($before, 'Controller');
echo "PASS thin controller\n";
foreach ([[], [mapping_rows(6, $team)[0]],
    array_merge(mapping_rows(6, $team), [mapping_rows(6, $otherteam)[1]]),
    [(object)['id' => 12, 'type' => 'group', 'subtype' => 'course', 'moodleid' => 6, 'objectid' => $team],
     (object)['id' => 13, 'type' => 'group', 'subtype' => 'other', 'moodleid' => 6, 'objectid' => $team]]] as $mapping) {
    $DB->rows['local_o365_objects'] = array_merge($mapping, mapping_rows(8, $otherteam));
    [$output, $error] = display_case(6, 'meeting');
    check_tabs(!str_contains($output, 'href="' . s($join) . '"') &&
        !str_contains($output, 'other/team') && lookup_count('local_teamscsv_tablink') === 0,
        'Missing/ambiguous/changed Team mapping disclosed links');
    $settings = new settings_navigation();
    local_teamscsv_extend_settings_navigation($settings, context_course::instance(6));
    check_tabs($settings->courseadmin->children === [], 'Invalid mapping exposed navigation');
    no_side_effects($before, 'Invalid mapping');
}
$DB->rows['local_o365_objects'] = array_merge(mapping_rows(6, $otherteam), mapping_rows(8, $otherteam));
[$output, $error] = display_case(6, 'meeting');
check_tabs($error === null && !str_contains($output, $join) &&
    str_contains($output, 'other/team') && !str_contains($output, 'other/course'),
    'Changed current Team did not isolate stored links');
no_side_effects($before, 'Changed Team');
$DB->rows['local_o365_objects'] = $validmapping;
[$output, $error] = display_case(8, 'meeting');
check_tabs($error === null && str_contains($output, 'other/course') &&
    !str_contains($output, $join) && !str_contains($output, 'other/team'),
    'Current course isolation failed');
no_side_effects($before, 'Other course');
[$output, $error] = display_case(6, 'invalid');
check_tabs(!str_contains($output, $join) && !str_contains($output, $folder) &&
    lookup_count('local_teamscsv_tablink') === 0, 'Invalid kind disclosed links');
no_side_effects($before, 'Invalid kind');
$DB->rows['local_teamscsv_tablink'] = [];
[$output, $error] = display_case(6, 'meeting');
check_tabs($error === null && $output !== '' && !str_contains($output, '[[missing:') &&
    !str_contains($output, '<a href='), 'Empty page lacks localized explanation');
no_side_effects($before, 'Empty page');
echo "PASS fail-closed mapping/kind and empty page\n";
// All kinds have descriptive localized native buttons; numbered valid links remain distinguishable.
foreach ([[$english, ['meeting' => 'Join the class in Teams', 'recordings' => 'Open class recordings',
        'material' => 'Open class material']],
    [$spanish, ['meeting' => 'Unirse a la clase en Teams', 'recordings' => 'Abrir grabaciones de clases',
        'material' => 'Abrir material de clases']]] as [$language, $labels]) {
    $GLOBALS['strings'] = $language;
    foreach ($labels as $kind => $label) {
        $url = $kind === 'meeting' ? $join : ($kind === 'recordings' ? $folder :
            'https://site.sharepoint.com/sites/course/Shared%20Documents/General');
        $url2 = $kind === 'meeting' ? $join . '&second=1' : str_replace('/sites/course/', '/sites/course2/', $url);
        $DB->rows['local_teamscsv_tablink'] = [
            (object)['id' => 1, 'courseid' => 6, 'teamid' => $team, 'kind' => $kind, 'url' => 'javascript:bad'],
            (object)['id' => 2, 'courseid' => 6, 'teamid' => $team, 'kind' => $kind, 'url' => $url],
            (object)['id' => 3, 'courseid' => 6, 'teamid' => $team, 'kind' => $kind, 'url' => $url2],
            (object)['id' => 4, 'courseid' => 8, 'teamid' => $team, 'kind' => $kind, 'url' => $url . '/foreign'],
            (object)['id' => 5, 'courseid' => 6, 'teamid' => $otherteam, 'kind' => $kind, 'url' => $url . '/stale'],
        ];
        $snapshot = serialize($DB->rows);
        [$output, $error] = display_case(6, $kind);
        check_tabs($error === null && substr_count($output, 'class="btn btn-primary"') === 2 &&
            str_contains($output, '>' . $label . ' (1)</a>') && str_contains($output, '>' . $label . ' (2)</a>') &&
            str_contains($output, 'href="' . s($url) . '"') && str_contains($output, 'href="' . s($url2) . '"') &&
            !str_contains(strip_tags($output), 'https://') && !str_contains($output, '/foreign') &&
            !str_contains($output, '/stale') && substr_count($output, 'rel="noopener noreferrer"') === 2 &&
            serialize($DB->rows) === $snapshot, 'Localized buttons/isolation/history failed: ' . $kind);
        no_side_effects($before, 'Buttons');
        [$output, $error] = display_case(6, $kind, false);
        check_tabs($error !== null && $output === '' && lookup_count('local_teamscsv_tablink') === 0,
            'Denied button page disclosed links');
        $DB->rows['local_teamscsv_tablink'] = [$DB->rows['local_teamscsv_tablink'][1]];
        [$output, $error] = display_case(6, $kind);
        check_tabs($error === null && str_contains($output, '>' . $label . '</a>'), 'Single button label changed');
    }
}
echo "PASS localized descriptive native buttons, numbering, material isolation\n";
}
