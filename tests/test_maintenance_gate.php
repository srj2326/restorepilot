<?php
// Verifies the redesigned maintenance-mode gate (should_block_for_maintenance())
// correctly exempts exactly the requests a resumable restore's own dispatch
// needs to survive a maintenance window — this is the fix for a real deadlock
// found live: once maintenance mode activated, the loopback dispatch and cron
// fallback that are supposed to carry a restore to its next chunk were
// themselves blocked by that same maintenance mode, so it could never finish
// on its own.

$site_root = '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public';
require $site_root . '/wp-load.php';

function call_private($method, array $args = []) {
  $ref = new ReflectionMethod('RestorePilot_Backup_Migration', $method);
  $ref->setAccessible(true);
  return $ref->invokeArgs(null, $args);
}

$failures = [];
function check($label, $cond) {
  global $failures;
  echo ($cond ? 'PASS' : 'FAIL') . "  $label\n";
  if (!$cond) $failures[] = $label;
}

// Guaranteed cleanup regardless of how this script ends (a real error, an
// unexpected exit from deep in a helper) — otherwise a botched run leaves
// the whole site in maintenance mode for up to an hour, blocking even this
// same test script's own next attempt to run, as happened once already here.
register_shutdown_function(function () {
  delete_option(RestorePilot_Backup_Migration::MAINTENANCE_OPTION);
});

function reset_request_state() {
  $_POST = [];
  if (defined('DOING_AJAX')) { /* can't undefine; tests run in isolated processes below where needed */ }
}

// --- Not in maintenance at all: never blocks, regardless of request shape --
delete_option(RestorePilot_Backup_Migration::MAINTENANCE_OPTION);
$_POST = [];
check('No maintenance option set: not blocked', call_private('should_block_for_maintenance') === false);

$_POST['action'] = 'restorepilot_run_restore_job';
check('No maintenance option set, even with a restore action posted: not blocked', call_private('should_block_for_maintenance') === false);
$_POST = [];

// --- Maintenance active (future expiry): plain request IS blocked ----------
update_option(RestorePilot_Backup_Migration::MAINTENANCE_OPTION, time() + 3600, true);
check('Maintenance active, plain request: blocked', call_private('should_block_for_maintenance') === true);

// --- Maintenance active but expired (past its own 1-hour ceiling): treated
// as not-in-maintenance (the auto-expiry safety net) --------------------
update_option(RestorePilot_Backup_Migration::MAINTENANCE_OPTION, time() - 10, true);
check('Maintenance option present but expired: not blocked', call_private('should_block_for_maintenance') === false);
update_option(RestorePilot_Backup_Migration::MAINTENANCE_OPTION, time() + 3600, true);

// --- The two exempted AJAX actions specifically -----------------------------
// Simulate DOING_AJAX via a subprocess, since the real constant can't be
// unset/changed once defined in this process (and shouldn't be faked with
// define() here — a genuine wp_doing_ajax() check is what production runs).
$php_bin = '/Users/surajitroy/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php';
$sock = '/Users/surajitroy/Library/Application Support/Local/run/gKsH4-EmV/mysql/mysqld.sock';

function run_ajax_scenario($php_bin, $sock, $site_root, $action) {
  $script = <<<PHP
<?php
define('DOING_AJAX', true);
\$_POST['action'] = '$action';
require '$site_root/wp-load.php';
\$ref = new ReflectionMethod('RestorePilot_Backup_Migration', 'should_block_for_maintenance');
\$ref->setAccessible(true);
echo \$ref->invoke(null) ? 'BLOCKED' : 'ALLOWED';
PHP;
  $tmp = tempnam(sys_get_temp_dir(), 'rp_ajax_scenario_') . '.php';
  file_put_contents($tmp, $script);
  $cmd = escapeshellarg($php_bin) . ' -d mysqli.default_socket=' . escapeshellarg($sock)
    . ' -d pdo_mysql.default_socket=' . escapeshellarg($sock) . ' ' . escapeshellarg($tmp) . ' 2>&1';
  $out = trim(shell_exec($cmd));
  @unlink($tmp);
  return $out;
}

$out = run_ajax_scenario($php_bin, $sock, $site_root, 'restorepilot_restore_status');
check("DOING_AJAX + action=restorepilot_restore_status: allowed through (got '$out')", $out === 'ALLOWED');

$out = run_ajax_scenario($php_bin, $sock, $site_root, 'restorepilot_run_restore_job');
check("DOING_AJAX + action=restorepilot_run_restore_job: allowed through (got '$out')", $out === 'ALLOWED');

$out = run_ajax_scenario($php_bin, $sock, $site_root, 'some_other_plugin_action');
// The real init hook fires during wp-load.php itself here and exits before
// this script's own explicit check ever runs — that's the live hook actually
// blocking, not a test artifact, so recognize its rendered page as BLOCKED too.
$really_blocked = $out === 'BLOCKED' || strpos($out, 'Briefly unavailable') !== false;
check("DOING_AJAX + an unrelated action: still blocked (real hook fired and rendered the maintenance page)", $really_blocked);

// --- DOING_CRON exemption (also needs a subprocess: constant can't be reset) -
function run_cron_scenario($php_bin, $sock, $site_root) {
  $script = <<<PHP
<?php
define('DOING_CRON', true);
require '$site_root/wp-load.php';
\$ref = new ReflectionMethod('RestorePilot_Backup_Migration', 'should_block_for_maintenance');
\$ref->setAccessible(true);
echo \$ref->invoke(null) ? 'BLOCKED' : 'ALLOWED';
PHP;
  $tmp = tempnam(sys_get_temp_dir(), 'rp_cron_scenario_') . '.php';
  file_put_contents($tmp, $script);
  $cmd = escapeshellarg($php_bin) . ' -d mysqli.default_socket=' . escapeshellarg($sock)
    . ' -d pdo_mysql.default_socket=' . escapeshellarg($sock) . ' ' . escapeshellarg($tmp) . ' 2>&1';
  $out = trim(shell_exec($cmd));
  @unlink($tmp);
  return $out;
}
$out = run_cron_scenario($php_bin, $sock, $site_root);
check("DOING_CRON: allowed through (got '$out') — this is THE fix for the deadlock", $out === 'ALLOWED');

// --- Cleanup ------------------------------------------------------------------
delete_option(RestorePilot_Backup_Migration::MAINTENANCE_OPTION);
$_POST = [];

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
