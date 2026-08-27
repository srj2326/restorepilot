<?php
// Verifies two things the file-content test above can't see directly:
// 1) the site-wide backup lock stays held across a yield (a second backup
//    attempt must be rejected mid-job, not just while a chunk is actively
//    running), and is released once the job truly finishes.
// 2) canceling a job mid-way through a multi-volume backup cleans up every
//    temp volume it created, not just the first.

$site_root = '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public';
$plugin_file = '/Users/surajitroy/Local Sites/morecalculators-dev/app/public/wp-content/plugins/restorepilot-backup-migration/restorepilot-backup-migration.php';

require $site_root . '/wp-load.php';
if (!class_exists('RestorePilot_Backup_Migration')) {
  require_once $plugin_file;
}

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

add_filter('pre_http_request', function () {
  return new WP_Error('blocked_for_test', 'Loopback dispatch blocked for test.');
}, 10, 3);
add_filter('restorepilot_backup_chunk_seconds', function () { return 0.02; });
add_filter('restorepilot_backup_volume_bytes', function () { return 200 * 1024; });

$content_dir = call_private('content_dir');
$root = $content_dir . '/rp-lock-test';
if (is_dir($root)) {
  system('rm -rf ' . escapeshellarg($root));
}
mkdir($root, 0777, true);
// Large enough that job 3 (below) is still genuinely mid-flight, several
// volumes in, by the time it gets canceled — a fixture that finishes in a
// resumption or two would never actually test cancellation of an in-progress
// multi-volume backup, just the already-covered single-volume case.
for ($i = 1; $i <= 300; $i++) {
  file_put_contents($root . '/f' . $i . '.bin', random_bytes(15000 + $i * 400));
}

function make_job($job_id, $token, array $selected_paths) {
  call_private('set_backup_job', [$job_id, [
    'status' => 'queued', 'phase' => 'queued', 'progress' => 5, 'message' => 'queued',
    'include_files' => true, 'file_selection_enabled' => true,
    'selected_paths' => $selected_paths,
    'files_scanned' => 0, 'bytes_scanned' => 0,
    'token' => $token, 'created' => time(), 'updated' => time(),
  ]]);
}

// --- Part 1: lock stays held across a yield -----------------------------
$job_id_1 = 'rp-lock-test-1-' . wp_generate_uuid4();
$token_1 = wp_generate_password(32, false, false);
make_job($job_id_1, $token_1, ['rp-lock-test']);

RestorePilot_Backup_Migration::run_backup_job($job_id_1, $token_1);
$job_1 = call_private('get_backup_job', [$job_id_1]);
check('Job 1 yielded at least once (still running after one call)', ($job_1['status'] ?? '') === 'running');

check('Global backup lock is held while job 1 is between chunks', call_private('backup_lock_is_active'));

// A second, unrelated backup attempt must be rejected while job 1 is
// merely between chunks, not actively executing right this instant.
$job_id_2 = 'rp-lock-test-2-' . wp_generate_uuid4();
try {
  call_private('acquire_backup_lock', [$job_id_2]);
  check('A second backup is rejected while job 1 is between chunks', false);
} catch (RuntimeException $e) {
  check('A second backup is rejected while job 1 is between chunks', true);
}

// Drain job 1 to completion.
$iterations = 1;
while (($job_1['status'] ?? '') === 'running' && $iterations < 1000) {
  RestorePilot_Backup_Migration::run_backup_job($job_id_1, $token_1);
  $job_1 = call_private('get_backup_job', [$job_id_1]);
  $iterations++;
}
check('Job 1 reached complete', ($job_1['status'] ?? '') === 'complete');
check('Global backup lock is released once job 1 truly finishes', !call_private('backup_lock_is_active'));

if (($job_1['status'] ?? '') === 'complete') {
  $final_zip_path = call_private('backup_dir') . '/' . $job_1['file'];
  foreach (call_private('discover_volumes', [$final_zip_path])['paths'] as $p) {
    @unlink($p);
  }
}
delete_option('restorepilot_backup_job_' . sanitize_key($job_id_1));
wp_clear_scheduled_hook('restorepilot_cron_backup_job', [$job_id_1, $token_1]);

// A fresh lock acquisition should now succeed cleanly.
try {
  $t = call_private('acquire_backup_lock', [$job_id_2]);
  check('A fresh backup can start once the previous one is truly done', $t !== '');
  call_private('release_backup_lock', [$t]);
} catch (RuntimeException $e) {
  check('A fresh backup can start once the previous one is truly done', false);
}

// --- Part 2: canceling mid-way cleans up every volume --------------------
$job_id_3 = 'rp-lock-test-3-' . wp_generate_uuid4();
$token_3 = wp_generate_password(32, false, false);
make_job($job_id_3, $token_3, ['rp-lock-test']);

// Run enough resumptions to get past at least one volume rollover before
// canceling, so this actually exercises multi-volume cleanup (what the
// discover_volumes()/.zip-suffix fix above was for), not just a single file.
$zip_path = '';
$volumes_before_cancel = [];
for ($i = 0; $i < 30; $i++) {
  RestorePilot_Backup_Migration::run_backup_job($job_id_3, $token_3);
  $job_3 = call_private('get_backup_job', [$job_id_3]);
  if (($job_3['status'] ?? '') !== 'running') {
    break;
  }
  $zip_path = $job_3['checkpoint']['zip_path'] ?? '';
  $volumes_before_cancel = $zip_path ? call_private('discover_volumes', [$zip_path])['paths'] : [];
  if (count($volumes_before_cancel) > 1) {
    break;
  }
}
check('Job 3 is running (yielded at least once)', ($job_3['status'] ?? '') === 'running');
check('More than one temp volume exists before cancellation', count($volumes_before_cancel) > 1);
echo 'Temp volumes before cancel: ' . count($volumes_before_cancel) . "\n";

// Cancel, then let the next resumption discover and act on it.
call_private('update_backup_job', [$job_id_3, ['status' => 'canceled']]);
RestorePilot_Backup_Migration::run_backup_job($job_id_3, $token_3);
$job_3 = call_private('get_backup_job', [$job_id_3]);
check('Job 3 status is canceled', ($job_3['status'] ?? '') === 'canceled');

$remaining = [];
foreach ($volumes_before_cancel as $v) {
  if (is_file($v)) $remaining[] = $v;
  if (is_file($v . '.journal')) $remaining[] = $v . '.journal';
}
check('Every temp volume (and journal) was cleaned up on cancellation', count($remaining) === 0);
if ($remaining) {
  echo 'Left behind: ' . implode(', ', $remaining) . "\n";
}
check('Global backup lock released after cancellation', !call_private('backup_lock_is_active'));

delete_option('restorepilot_backup_job_' . sanitize_key($job_id_3));
wp_clear_scheduled_hook('restorepilot_cron_backup_job', [$job_id_3, $token_3]);

system('rm -rf ' . escapeshellarg($root));

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
