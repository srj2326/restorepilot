<?php
$site_root = '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public';
$plugin_file = '/Users/surajitroy/Local Sites/morecalculators-dev/app/public/wp-content/plugins/restorepilot-backup-migration/restorepilot-backup-migration.php';
require $site_root . '/wp-load.php';
require_once $plugin_file;

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

$expected_hashes = json_decode(file_get_contents(sys_get_temp_dir() . '/rp_expected_hashes.json'), true);
$content_dir = call_private('content_dir');
$root = $content_dir . '/rp-restore-files-test';

$missing = [];
$mismatched = [];
foreach ($expected_hashes as $name => $expected_sha1) {
  $path = $root . '/' . $name;
  if (!is_file($path)) { $missing[] = $name; continue; }
  if (sha1_file($path) !== $expected_sha1) { $mismatched[] = $name; }
}
check('Every fixture file restored (of ' . count($expected_hashes) . ')', count($missing) === 0);
if ($missing) echo 'Missing: ' . implode(', ', array_slice($missing, 0, 10)) . "\n";
check('Every restored file byte-for-byte correct', count($mismatched) === 0);
if ($mismatched) echo 'Mismatched: ' . implode(', ', array_slice($mismatched, 0, 10)) . "\n";

$rtmp = $wpdb_check = null;
global $wpdb;
$rtmp = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}restorepilot_rtmp\\_%'");
$rold = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}restorepilot_rold\\_%'");
check('No leftover scratch tables', empty($rtmp) && empty($rold));
check('Maintenance mode off', !is_file(ABSPATH . '.maintenance'));
check('Restore lock cleared', empty(get_option('restorepilot_restore_lock', [])));

// Cleanup
system('rm -rf ' . escapeshellarg($root));
$backup_path = trim(file_get_contents(sys_get_temp_dir() . '/rp_backup_path.txt'));
foreach (call_private('discover_volumes', [$backup_path])['paths'] as $p) { @unlink($p); }
$restore_zip_path = trim(file_get_contents(sys_get_temp_dir() . '/rp_restore_zip_path.txt'));
@unlink($restore_zip_path);
$job_id = trim(file_get_contents(sys_get_temp_dir() . '/rp_job_id.txt'));
delete_option('restorepilot_restore_job_' . sanitize_key($job_id));
$status_file_ref = new ReflectionMethod('RestorePilot_Backup_Migration', 'restore_status_file');
$status_file_ref->setAccessible(true);
@unlink($status_file_ref->invoke(null, $job_id));
foreach (['rp_job_id.txt', 'rp_token.txt', 'rp_backup_path.txt', 'rp_restore_zip_path.txt', 'rp_expected_hashes.json'] as $f) {
  @unlink(sys_get_temp_dir() . '/' . $f);
}

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
