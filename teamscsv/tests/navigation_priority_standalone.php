<?php
// Isolated hook contract checks; not a Moodle bootstrap or browser test.
namespace core\hook\navigation {
    final class secondary_extend {
        public function __construct(private object $view) {}
        public function get_secondaryview(): object { return $this->view; }
    }
}
namespace {
    define('MOODLE_INTERNAL', true);
    define('SITEID', 1);
    class context_course {}
    function get_string($key, $component = '') {
        return $GLOBALS['navstrings'][$key] ?? $key;
    }
    class priority_children implements \IteratorAggregate {
        public array $nodes = [];
        public function getIterator(): \Traversable { return new \ArrayIterator($this->nodes); }
        public function remove($key, $type): void {
            $this->nodes = array_values(array_filter($this->nodes,
                fn($node) => $node->key !== $key || $node->type !== $type));
        }
    }
    class priority_node {
        public priority_children $children;
        public bool $forceintomoremenu = false;
        public int $type = 0;
        public string $text;
        public string $shorttext;
        public function __construct(public string $key) {
            $this->children = new priority_children();
            $this->text = $this->shorttext = $key;
        }
        public function add_node($node, $beforekey = null): void {
            $position = count($this->children->nodes);
            foreach ($this->children->nodes as $index => $existing) {
                if ($existing->key === $beforekey) { $position = $index; break; }
            }
            array_splice($this->children->nodes, $position, 0, [$node]);
        }
    }
    function expect_priority(bool $condition, string $message): void {
        if (!$condition) { throw new \RuntimeException($message); }
    }
    $root = dirname(__DIR__);
    foreach (['en', 'es', 'es_mx'] as $language) {
        $string = [];
        require $root . '/lang/' . $language . '/local_teamscsv.php';
        expect_priority(($string['navmeeting'] ?? null) === 'Reuniones' &&
            ($string['navrecordings'] ?? null) === 'Grabaciones' &&
            ($string['navmaterial'] ?? null) === 'Material', 'Missing short navigation labels: ' . $language);
        expect_priority($string['tabmeeting'] === 'Reuniones Clases Teams' &&
            $string['tabrecordings'] === 'Grabaciones Clases' && $string['tabmaterial'] === 'Material de Clases',
            'Page headings changed');
    }
    $GLOBALS['navstrings'] = $string;
    expect_priority(is_file($root . '/db/hooks.php'), 'Missing hook registration');
    require $root . '/db/hooks.php';
    expect_priority(count($callbacks) === 1, 'Unexpected hook registrations');
    expect_priority($callbacks[0]['hook'] === \core\hook\navigation\secondary_extend::class,
        'Wrong hook');
    require $root . '/classes/navigation_priority.php';
    $callback = $callbacks[0]['callback'];
    $PAGE = (object)['context' => new context_course(), 'course' => (object)['id' => 122, 'format' => 'onetopic']];
    $wanted = ['coursehome', 'local_teamscsv_meeting', 'local_teamscsv_recordings',
        'local_teamscsv_material', 'courseoverview'];
    $original = ['coursehome', 'editsettings', 'participants', 'grades', 'courseoverview',
        'competencies', 'otherplugin', 'local_teamscsv_meeting', 'local_teamscsv_recordings',
        'local_teamscsv_material'];
    function make_priority_view(array $keys): priority_node {
        $view = new priority_node('root');
        foreach ($keys as $key) { $view->add_node(new priority_node($key)); }
        return $view;
    }
    $view = make_priority_view($original);
    $objects = [];
    foreach ($view->children as $node) {
        $objects[$node->key] = $node;
        $node->add_node(new priority_node('child-' . $node->key));
        $node->children->nodes[0]->forceintomoremenu = $node->key === 'courseoverview';
    }
    $beforechildren = serialize(array_map(fn($node) => $node->children, $objects));
    $callback(new \core\hook\navigation\secondary_extend($view));
    $keys = array_map(fn($node) => $node->key, $view->children->nodes);
    expect_priority($keys === array_merge($wanted, ['editsettings', 'participants', 'grades',
        'competencies', 'otherplugin']), 'Incorrect priority order');
    $shortlabels = ['local_teamscsv_meeting' => 'Reuniones',
        'local_teamscsv_recordings' => 'Grabaciones', 'local_teamscsv_material' => 'Material'];
    foreach ($view->children as $node) {
        $label = $shortlabels[$node->key] ?? $node->key;
        expect_priority($node->text === $label && $node->shorttext === $label, 'Incorrect menu-only label');
        expect_priority($node === $objects[$node->key], 'Node identity changed');
        expect_priority($node->forceintomoremenu === !in_array($node->key, $wanted, true), 'Wrong More flag');
    }
    expect_priority($beforechildren === serialize(array_map(fn($node) => $node->children, $objects)),
        'Submenu flags or content changed');
    $once = serialize($view);
    $callback(new \core\hook\navigation\secondary_extend($view));
    expect_priority(serialize($view) === $once, 'Repeated hook changed tree');
    echo "PASS order, More flags, identity, submenu preservation and idempotence\n";

    foreach (['participants', 'grades', 'competencies', 'courseoverview'] as $missing) {
        $restricted = make_priority_view(array_values(array_diff($original, [$missing])));
        $callback(new \core\hook\navigation\secondary_extend($restricted));
        $keys = array_map(fn($node) => $node->key, $restricted->children->nodes);
        expect_priority(!in_array($missing, $keys, true) && count($keys) === count($original) - 1,
            'Added unavailable capability-filtered node');
    }
    foreach (['coursehome', 'local_teamscsv_meeting', 'local_teamscsv_recordings', 'local_teamscsv_material'] as $missing) {
        $unlinked = make_priority_view(array_values(array_diff($original, [$missing])));
        $before = serialize($unlinked);
        $callback(new \core\hook\navigation\secondary_extend($unlinked));
        expect_priority(serialize($unlinked) === $before, 'Incomplete navigation was changed');
    }
    foreach (['site', 'singleactivity', 'othercontext'] as $case) {
        $PAGE->course = (object)['id' => $case === 'site' ? SITEID : 122,
            'format' => $case === 'singleactivity' ? 'singleactivity' : 'onetopic'];
        $PAGE->context = $case === 'othercontext' ? new \stdClass() : new context_course();
        $excluded = make_priority_view($original);
        $before = serialize($excluded);
        $callback(new \core\hook\navigation\secondary_extend($excluded));
        expect_priority(serialize($excluded) === $before, 'Excluded context changed');
    }
    echo "PASS permission-filtered nodes, missing plugin nodes and excluded contexts\n";
}
