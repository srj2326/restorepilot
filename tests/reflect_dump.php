<?php
// Command line only. These scripts live inside the plugin directory so they
// survive alongside the code they test -- which also puts them under the web
// root, where a request could otherwise reach one. Several boot WordPress as
// user 1 and then reset sites, delete users, or set passwords, so reaching one
// over HTTP has to be impossible rather than unlikely. Checked before anything
// else runs, including the WordPress load below.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

/**
 * Dumps the class's entire surface -- every method with a hash of its actual
 * source, every constant with its value, every static property -- so the
 * split version can be compared against the original.
 *
 * Loads the plugin with WordPress's entry points stubbed out, because only the
 * shape of the class matters here, not what it would do when run.
 */

$target = $argv[1];   // plugin root php file

foreach ([
    'add_action', 'add_filter', 'register_activation_hook', 'register_deactivation_hook',
    'register_uninstall_hook', 'add_shortcode', 'load_plugin_textdomain',
] as $fn) {
    if (!function_exists($fn)) {
        eval("function $fn() { return true; }");
    }
}
foreach (['__', 'esc_html__', 'esc_attr__'] as $fn) {
    if (!function_exists($fn)) {
        eval("function $fn(\$s, \$d = '') { return \$s; }");
    }
}
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/fake-wp/'); }
if (!defined('WP_CONTENT_DIR')) { define('WP_CONTENT_DIR', '/tmp/fake-wp/wp-content'); }
if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }
if (!defined('MINUTE_IN_SECONDS')) { define('MINUTE_IN_SECONDS', 60); }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }

require_once $target;

$r = new ReflectionClass('RestorePilot_Backup_Migration');

$methods = [];
foreach ($r->getMethods() as $m) {
    // Read the method's real source and hash it, so a body that changed during
    // the move shows up as a different fingerprint.
    $file = $m->getFileName();
    $src = file($file);
    $body = implode('', array_slice($src, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
    $methods[$m->getName()] = [
        'static'  => $m->isStatic(),
        'vis'     => $m->isPrivate() ? 'private' : ($m->isProtected() ? 'protected' : 'public'),
        'params'  => $m->getNumberOfParameters(),
        'sha'     => sha1($body),
    ];
}
ksort($methods);

$consts = $r->getConstants();
ksort($consts);

$props = [];
foreach ($r->getProperties() as $p) {
    $props[$p->getName()] = ['static' => $p->isStatic()];
}
ksort($props);

echo json_encode([
    'methods'   => $methods,
    'constants' => $consts,
    'props'     => $props,
    'other_classes' => array_values(array_filter(get_declared_classes(), function ($c) {
        return strpos($c, 'RestorePilot') === 0;
    })),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
