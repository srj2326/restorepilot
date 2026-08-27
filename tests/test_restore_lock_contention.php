<?php
// Verifies a second restore attempt is rejected while the first is
// genuinely between chunks (not actively executing, just paused waiting
// for its next resumption) — the restore-side counterpart to the backup
// lock-contention test. Uses a large-enough fixture and small chunk budget,
// in-process, to reliably land mid-restore before checking.

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

global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'rp_lock_test_opt_%'");
$values = [];
for ($i = 0; $i < 800; $i++) {
  $values[] = $wpdb->prepare('(%s, %s, %s)', 'rp_lock_test_opt_' . $i, 'v' . $i, 'no');
}
foreach (array_chunk($values, 200) as $batch) {
  $wpdb->query("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES " . implode(',', $batch));
}

$backup_result = call_private('create_backup_package', [true, '', [], false, false, ['triggered_by' => 'restore-lock-test']]);
$backup_path = call_private('backup_dir') . '/' . $backup_result['file'];

add_filter('pre_http_request', function () {
  return new WP_Error('blocked_for_test', 'Loopback dispatch blocked for test.');
}, 10, 3);
// Small enough that the very first call below reliably yields before
// finishing (needed for the mid-restore contention check just after it),
// but not so small relative to real per-row/per-skip costs that the drain
// loop's per-resumption progress becomes negligible: the row-skip catch-up
// phase always restarts from a fresh SELECT COUNT(*) each resumption (see
// restore_database()'s docblock), so it only converges once a single
// chunk's budget covers the remaining skip distance — true almost
// immediately at production's real ~20s budget, but not guaranteed at an
// artificially tiny one like the 0.02s this used previously, which could
// leave a table's catch-up re-skipping the identical prefix every
// resumption and advancing barely at all over thousands of iterations.
// This site's real table count has grown well past what 0.5s used to
// comfortably cover (this plugin set now carries ~149 tables) — confirmed
// the hard way: got stuck at 13/296 after the full 200-iteration cap,
// leaving its lock held and cascading failures into every test that ran
// after it in the same suite. See project memory on matching this budget
// to the actual data volume rather than the smallest value that forces a
// yield.
add_filter('restorepilot_restore_chunk_seconds', function () { return 3.0; });

call_private('ensure_storage');
$restore_zip_path = call_private('storage_dir') . '/restore-upload-' . wp_generate_uuid4() . '.zip';
copy($backup_path, $restore_zip_path);

$job_id_1 = 'rp-lock-restore-1-' . wp_generate_uuid4();
$token_1 = wp_generate_password(32, false, false);
call_private('set_restore_job', [$job_id_1, [
  'status' => 'queued', 'phase' => 'queued', 'phase_label' => 'Queued', 'progress' => 5, 'message' => 'queued',
  'restore_zip_path' => $restore_zip_path,
  'auto_detect_urls' => true, 'restore_files' => false,
  'source_url' => '', 'target_url' => '',
  'token' => $token_1, 'poll_token' => wp_generate_password(32, false, false),
  'created' => time(), 'updated' => time(),
]]);

RestorePilot_Backup_Migration::run_restore_job($job_id_1, $token_1);
$job_1 = call_private('get_restore_job', [$job_id_1]);
check('Job 1 is running (yielded at least once, not complete/error)', ($job_1['status'] ?? '') === 'running');

try {
  call_private('acquire_restore_lock', ['some-other-job']);
  check('A second restore is rejected while job 1 is between chunks', false);
} catch (RuntimeException $e) {
  check('A second restore is rejected while job 1 is between chunks', true);
}

// Drain job 1 to completion.
$iterations = 1;
while (($job_1['status'] ?? '') === 'running' && $iterations < 200) {
  RestorePilot_Backup_Migration::run_restore_job($job_id_1, $token_1);
  $job_1 = call_private('get_restore_job', [$job_id_1]);
  $iterations++;
  if ($iterations % 200 === 0) {
    $cp = $job_1['checkpoint'] ?? [];
    echo "  [iter $iterations] status=" . ($job_1['status'] ?? '?') . ' phase=' . ($job_1['phase'] ?? '?')
      . ' database_done=' . var_export($cp['database_done'] ?? null, true)
      . ' completed_tables=' . count($cp['completed_tables'] ?? []) . '/' . count($cp['restore_plan']['plans'] ?? []) . "\n";
  }
}
echo "Resumptions: $iterations, final status: " . ($job_1['status'] ?? '?') . "\n";
check('Job 1 reached complete', ($job_1['status'] ?? '') === 'complete');
check('Restore lock released once job 1 truly finishes', empty(get_option('restorepilot_restore_lock', [])));

// A fresh lock acquisition should now succeed cleanly.
try {
  $t = call_private('acquire_restore_lock', ['some-other-job']);
  check('A fresh restore can start once the previous one is truly done', $t !== '');
  call_private('release_restore_lock', [$t]);
} catch (RuntimeException $e) {
  check('A fresh restore can start once the previous one is truly done', false);
}

// Cleanup
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'rp_lock_test_opt_%'");
foreach (call_private('discover_volumes', [$backup_path])['paths'] as $p) { @unlink($p); }
@unlink($restore_zip_path);
delete_option('restorepilot_restore_job_' . sanitize_key($job_id_1));
$status_file_ref = new ReflectionMethod('RestorePilot_Backup_Migration', 'restore_status_file');
$status_file_ref->setAccessible(true);
@unlink($status_file_ref->invoke(null, $job_id_1));
wp_clear_scheduled_hook('restorepilot_cron_restore_job', [$job_id_1, $token_1]);
// Defensive: if job 1 never actually reached completion (or 'some-other-job'
// somehow left its own lock behind), force both clear so a failed run here
// never leaves the shared test site stuck for whatever runs next.
call_private('force_release_restore_locks', [$job_id_1]);
call_private('force_release_restore_locks', ['some-other-job']);

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
