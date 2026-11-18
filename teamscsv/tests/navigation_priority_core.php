<?php
// Run against an isolated Moodle source tree, without config.php, database or Graph.
// Usage: php navigation_priority_core.php /path/to/moodle
// Exercises real node collections, hook and overflow policy, not full page bootstrap.
define('MOODLE_INTERNAL', true);
define('SITEID', 1);
interface renderable {}
class pix_icon { public array $attributes = []; public function __construct(...$args) {} }
class context_course {}
function isloggedin(): bool { return false; }
function get_string($key, $component = '') { return $GLOBALS['navstrings'][$key] ?? $key; }
$string = [];
require dirname(__DIR__) . '/lang/es/local_teamscsv.php';
$GLOBALS['navstrings'] = $string;
$CFG = (object)['wwwroot' => 'https://example.invalid', 'debug' => 0];
$PAGE = (object)['context' => new context_course(), 'course' => (object)['id' => 122, 'format' => 'onetopic']];
$source = $argv[1] ?? '';
spl_autoload_register(static function(string $class) use ($source): void {
    if (str_starts_with($class, 'core\\')) {
        $path = $source . '/lib/classes/' . str_replace('\\', '/', substr($class, 5)) . '.php';
        if (is_file($path)) { require_once $path; }
    }
});
require $source . '/lib/navigationlib.php';
require $source . '/lib/classes/navigation/views/view.php';
require $source . '/lib/classes/navigation/views/secondary.php';
require $source . '/lib/classes/hook/navigation/secondary_extend.php';
require dirname(__DIR__) . '/classes/navigation_priority.php';
require dirname(__DIR__) . '/db/hooks.php';
$reflection = new ReflectionClass(\core\navigation\views\secondary::class);
$view = $reflection->newInstanceWithoutConstructor();
$view->children = new navigation_node_collection();
$keys = ['coursehome', 'editsettings', 'participants', 'grades', 'courseoverview', 'competencies',
    'local_teamscsv_meeting', 'local_teamscsv_recordings', 'local_teamscsv_material'];
foreach ($keys as $key) {
    $view->add_node(navigation_node::create($key, null, navigation_node::TYPE_CUSTOM, null, $key));
}
$callbacks[0]['callback'](new \core\hook\navigation\secondary_extend($view));
$policy = $reflection->getMethod('force_nodes_into_more_menu');
$policy->setAccessible(true);
$policy->invoke($view, [], 5);
$visible = [];
$hidden = [];
foreach ($view->children as $node) {
    if ($node->forceintomoremenu) { $hidden[] = $node->key; } else { $visible[] = $node->key; }
}
$expected = ['coursehome', 'local_teamscsv_meeting', 'local_teamscsv_recordings',
    'local_teamscsv_material', 'courseoverview'];
if ($visible !== $expected || $hidden !== ['editsettings', 'participants', 'grades', 'competencies']) {
    throw new RuntimeException('Real Moodle navigation policy disagrees with desired order');
}
$shortlabels = ['local_teamscsv_meeting' => 'Reuniones',
    'local_teamscsv_recordings' => 'Grabaciones', 'local_teamscsv_material' => 'Material'];
foreach ($view->children as $node) {
    if (isset($shortlabels[$node->key]) && ($node->text !== $shortlabels[$node->key] ||
            $node->shorttext !== $shortlabels[$node->key])) {
        throw new RuntimeException('Real Moodle node label was not shortened');
    }
}
echo "PASS actual Moodle collection, hook, short labels and five-node overflow policy\n";
