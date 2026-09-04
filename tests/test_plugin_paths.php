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
 * Splitting the plugin across includes/ silently broke every __FILE__ in it,
 * because that constant answers for the file it is written in. Master Reset
 * used dirname(__FILE__) to identify its own directory so it could skip it,
 * matched nothing, and deleted RestorePilot.
 *
 * Body-for-body comparison could never catch that: the text was identical and
 * only its meaning moved. These checks look at what the paths resolve to.
 */

define('WP_USE_THEMES', false);
require_once '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public/wp-load.php';

$failures = [];
function check(string $label, bool $ok, string $detail = '') {
    global $failures;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if ($detail !== '') { echo '        ' . $detail . "\n"; }
    if (!$ok) { $failures[] = $label; }
}
function call_private($name, $args = []) {
    $m = new ReflectionMethod('RestorePilot_Backup_Migration', $name);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
}

$dir  = call_private('plugin_root_dir');
$file = call_private('plugin_root_file');
$base = call_private('plugin_basename_self');

echo "resolved plugin dir : $dir\n";
echo "resolved plugin file: $file\n";
echo "resolved basename   : $base\n\n";

// --- The path must be the plugin folder, never includes/ ------------------
check('plugin_root_dir() is the plugin folder, not includes/',
    basename($dir) === 'restorepilot-backup-migration',
    'basename is "' . basename($dir) . '"');
check('plugin_root_dir() does not end in includes/', substr($dir, -9) !== '/includes');
check('plugin_root_file() is the main plugin file',
    basename($file) === 'restorepilot-backup-migration.php');
check('plugin_root_file() exists on disk', is_file($file));
check('plugin_basename_self() is folder/file.php as active_plugins stores it',
    $base === 'restorepilot-backup-migration/restorepilot-backup-migration.php',
    'got "' . $base . '"');

// --- THE BUG: Master Reset must recognise its own directory ---------------
$own = realpath($dir);
$plugins_dir = call_private('plugins_dir');
$self_in_list = false;
$would_delete_self = false;
foreach (new DirectoryIterator($plugins_dir) as $item) {
    if ($item->isDot() || !$item->isDir()) { continue; }
    $real = realpath($item->getPathname());
    if ($real === false) { continue; }
    if (basename($real) === 'restorepilot-backup-migration') {
        $self_in_list = true;
        // This is the exact comparison Master Reset makes before deleting.
        if ($real !== $own) { $would_delete_self = true; }
    }
}
if ($self_in_list) {
    check('THE BUG: Master Reset recognises its own directory and would skip it',
        !$would_delete_self,
        $would_delete_self ? 'it would DELETE ITSELF' : 'own dir matches, it is skipped');
} else {
    check('RestorePilot is present in the plugins directory to test against', false,
        'the plugin folder is missing from ' . $plugins_dir);
}

// --- A restore must protect the plugin's own files ------------------------
$own_rel = 'plugins/' . basename($dir) . '/';
check('A restore protects the right folder (plugins/restorepilot-backup-migration/)',
    $own_rel === 'plugins/restorepilot-backup-migration/',
    'got "' . $own_rel . '"');

// --- active_plugins written after a reset must be loadable ---------------
$candidate = WP_PLUGIN_DIR . '/' . $base;
check('The active_plugins entry a reset writes points at a real file',
    is_file($candidate), $candidate);

// --- Guard against this whole class returning ----------------------------
$inc = glob(dirname(dirname($file)) . '/restorepilot-backup-migration/includes/*.php') ?: [];
$offenders = [];
foreach ($inc as $f) {
    $body = file_get_contents($f);
    // The helpers' own fallbacks are the one legitimate use.
    if (basename($f) === 'trait-support.php') { continue; }
    if (preg_match('/__FILE__|__DIR__/', $body)) { $offenders[] = basename($f); }
}
check('No include file uses __FILE__/__DIR__ (they answer for the wrong file there)',
    empty($offenders), $offenders ? implode(', ', $offenders) : 'none');

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
