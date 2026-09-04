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
 * Stubbed-WordPress load smoke test.
 * Confirms the plugin file loads + bootstraps under PHP 8.2 with no fatal
 * (undefined function at load time, class-declaration error, etc.) — the
 * "fatal on activation" class of failure WordPress.org reviewers reject on.
 * It does NOT exercise runtime handlers (those need a real WP + DB).
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$plugin = '/Users/surajitroy/Local Sites/morecalculators-dev/app/public/wp-content/plugins/restorepilot-backup-migration/restorepilot-backup-migration.php';

// Minimal WP environment.
define('ABSPATH', '/tmp/wp-abspath/');
define('WPINC', 'wp-includes');
define('WP_CONTENT_DIR', '/tmp/wp-abspath/wp-content');
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('MINUTE_IN_SECONDS', 60);

// Record hooks the bootstrap registers so we can sanity-check callbacks resolve.
$GLOBALS['__hooks'] = [];

// No-op / passthrough stubs for every WP function the plugin can touch at load time.
$noops = [
  'add_action', 'add_filter', 'remove_action', 'remove_filter', 'do_action', 'apply_filters',
  'register_activation_hook', 'register_deactivation_hook', 'register_uninstall_hook',
  'load_plugin_textdomain', 'add_shortcode',
];
foreach ($noops as $fn) {
  if (!function_exists($fn)) {
    eval("function $fn() { if ('$fn' === 'add_action' || '$fn' === 'add_filter') { \$a = func_get_args(); \$GLOBALS['__hooks'][] = \$a; } return true; }");
  }
}
// Path/URL helpers.
if (!function_exists('plugin_basename')) { function plugin_basename($f){ return basename(dirname($f)) . '/' . basename($f); } }
if (!function_exists('plugin_dir_path')) { function plugin_dir_path($f){ return rtrim(dirname($f), '/').'/'; } }
if (!function_exists('plugin_dir_url'))  { function plugin_dir_url($f){ return 'http://example.test/wp-content/plugins/'.basename(dirname($f)).'/'; } }
if (!function_exists('plugins_url'))     { function plugins_url($p='', $f=''){ return 'http://example.test/wp-content/plugins/'.basename(dirname($f)).'/'.ltrim($p,'/'); } }
// i18n passthroughs.
if (!function_exists('__'))            { function __($s,$d=null){ return $s; } }
if (!function_exists('_e'))            { function _e($s,$d=null){ echo $s; } }
if (!function_exists('_n'))            { function _n($a,$b,$n,$d=null){ return $n==1?$a:$b; } }
if (!function_exists('_x'))            { function _x($s,$c,$d=null){ return $s; } }
if (!function_exists('esc_html__'))    { function esc_html__($s,$d=null){ return $s; } }
if (!function_exists('esc_attr__'))    { function esc_attr__($s,$d=null){ return $s; } }
if (!function_exists('esc_html_e'))    { function esc_html_e($s,$d=null){ echo $s; } }
if (!function_exists('esc_attr_e'))    { function esc_attr_e($s,$d=null){ echo $s; } }
if (!function_exists('esc_html'))      { function esc_html($s){ return $s; } }
if (!function_exists('esc_attr'))      { function esc_attr($s){ return $s; } }
if (!function_exists('esc_url'))       { function esc_url($s){ return $s; } }

// Load it.
require $plugin;

echo "LOAD_OK\n";
echo 'class RestorePilot_Backup_Migration exists: ' . (class_exists('RestorePilot_Backup_Migration') ? 'yes' : 'NO') . "\n";
echo 'bootstrap fn exists: ' . (function_exists('restorepilot_backup_migration_bootstrap') ? 'yes' : 'NO') . "\n";
echo 'hooks registered at load: ' . count($GLOBALS['__hooks']) . "\n";

// Verify every registered add_action/add_filter callback actually resolves
// (catches a hook pointing at a renamed/removed method — a real activation risk).
$bad = [];
foreach ($GLOBALS['__hooks'] as $h) {
  $cb = $h[1] ?? null;
  if (is_array($cb) && count($cb) === 2) {
    [$cls, $m] = $cb;
    $cls = is_object($cls) ? get_class($cls) : $cls;
    if (!method_exists($cls, $m)) { $bad[] = (is_string($cls)?$cls:'?') . '::' . $m; }
  } elseif (is_string($cb) && strpos($cb, '::') === false && !function_exists($cb)) {
    $bad[] = $cb . '()';
  }
}
echo 'unresolved hook callbacks: ' . (count($bad) ? implode(', ', $bad) : 'none') . "\n";
