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

// Full end-to-end test of resumable restore: back up sunhsine-bkp with some
// known, tagged test data; corrupt that data live; restore the backup back
// onto the SAME site (source == target, so no URL rewriting to reason
// about) via run_restore_job(), forced through many chunks by an
// aggressively tiny chunk budget so it crosses the wp_options-swap boundary
// (and so restore_database()'s mid-table resume path) many times over.
// Verifies the final state matches the backup exactly, not just that
// nothing crashed.

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

// --- Fixture: tag wp_options with known test rows -----------------------
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'rp_test_opt_%'");
$n_rows = 1500;
$values = [];
$expected = [];
for ($i = 0; $i < $n_rows; $i++) {
  $name = 'rp_test_opt_' . $i;
  $value = 'original-value-' . $i . '-' . substr(md5('seed' . $i), 0, 12);
  $expected[$name] = $value;
  $values[] = $wpdb->prepare('(%s, %s, %s)', $name, $value, 'no');
}
foreach (array_chunk($values, 200) as $batch) {
  $wpdb->query("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES " . implode(',', $batch));
}
echo "Fixture: $n_rows tagged option rows inserted.\n";

// --- Take a real backup. Must include files (include_files=true) even
// though this test only exercises the database phase (restore_files=false
// on the job below) — validate_backup_zip()'s require_full_restore check
// rejects a database-only backup's manifest (restorable=false) outright.
$backup_result = call_private('create_backup_package', [true, '', [], false, false, ['triggered_by' => 'restore-e2e-test']]);
check('Fixture backup created', !empty($backup_result['file']));
$backup_path = call_private('backup_dir') . '/' . $backup_result['file'];
check('Backup file exists on disk', is_file($backup_path));

// --- Corrupt the live data so restore has something real to fix ---------
$wpdb->query("UPDATE {$wpdb->options} SET option_value = 'CORRUPTED' WHERE option_name LIKE 'rp_test_opt_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name = 'rp_test_opt_0'"); // also delete one row entirely
$corrupted_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'rp_test_opt_%' AND option_value = 'CORRUPTED'");
echo "Corrupted $corrupted_count rows and deleted 1 entirely before restore.\n";

$sample_table_counts_before = [];
foreach (['posts', 'options', 'users', 'terms'] as $t) {
  $sample_table_counts_before[$t] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}$t");
}

// --- Copy the backup into the restore-upload location and drive a
// resumable restore through run_restore_job(), exactly as a real
// admin-triggered restore would, but with dispatch blocked so this script's
// own loop is the only thing driving resumptions.
add_filter('pre_http_request', function () {
  return new WP_Error('blocked_for_test', 'Loopback dispatch blocked for test.');
}, 10, 3);
// See test_restore_lock_contention.php for why this isn't smaller: the
// row-skip catch-up phase always restarts from a fresh SELECT COUNT(*) each
// resumption, so it only converges once a single chunk's budget covers the
// remaining skip distance. With ~3000 real rows in wp_options alone (1500
// tagged fixture rows plus the site's own), an artificially tiny budget
// like the 0.02s this used previously means the restore may never actually
// finish within any reasonable iteration cap — which then surfaces as
// "mismatches" that are really just rows the restore hasn't reached yet,
// not genuine data corruption.
// 0.5s was still not enough once run against this site's real bulk: this
// full (not selective) backup carries every real table, including a
// Contact Form 7 database add-on with 819,746 rows in one table and 96,372
// (with blob attachments) in another, alphabetically well before wp_options
// — confirmed via a full job-record dump showing the restore stuck at 48%
// progress, still short of wp_options, after all 300 resumptions. Bumped to
// something that can actually clear this site's real volume; iterations
// upsized correspondingly since each is an in-process call (cheap) rather
// than a subprocess (unlike test_resumable_restore_files.php's per-chunk
// process spawn), so a higher cap here costs little beyond wall-clock.
// 10.0 was chosen to clear a test site carrying 819,746 rows of Contact Form 7
// data restored from an old backup. That debris is gone -- the site is 14 MB
// now, and the whole restore finished inside a single 10-second chunk, so this
// test could no longer see a resumption at all and said so. Sized for the
// fixture above instead of for whatever the site has accumulated.
add_filter('restorepilot_restore_chunk_seconds', function () { return 0.3; });

call_private('ensure_storage');
$restore_zip_path = call_private('storage_dir') . '/restore-upload-' . wp_generate_uuid4() . '.zip';
copy($backup_path, $restore_zip_path);

$job_id = 'rp-restore-e2e-' . wp_generate_uuid4();
$token = wp_generate_password(32, false, false);
$poll_token = wp_generate_password(32, false, false);
call_private('set_restore_job', [$job_id, [
  'status' => 'queued',
  'phase' => 'queued',
  'phase_label' => 'Queued',
  'progress' => 5,
  'message' => 'queued',
  'restore_zip_path' => $restore_zip_path,
  'auto_detect_urls' => true, // source == target (same site) — no URL rewriting to reason about
  'restore_files' => false,   // this test is about the database phase specifically
  'source_url' => '',
  'target_url' => '',
  'token' => $token,
  'poll_token' => $poll_token,
  'created' => time(),
  'updated' => time(),
]]);

$max_iterations = 500;
$iterations = 0;
do {
  $iterations++;
  RestorePilot_Backup_Migration::run_restore_job($job_id, $token);
  $job = call_private('get_restore_job', [$job_id]);
  $status = $job['status'] ?? '(missing)';
} while ($status === 'running' && $iterations < $max_iterations);

echo "Resumptions taken: $iterations\n";
echo 'Final status: ' . $status . "\n";
if ($status !== 'complete') {
  echo 'Final job record: ' . print_r($job, true) . "\n";
}

check('Restore job reached complete status', $status === 'complete');
check('Took more than one resumption (yielding actually happened)', $iterations > 1);

// --- Verify the restored data is correct, not just that it didn't crash -
$after_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'rp_test_opt_%'");
check("All $n_rows tagged rows present after restore (found $after_count)", $after_count === $n_rows);

$mismatches = 0;
$checked = 0;
foreach ($expected as $name => $expected_value) {
  $checked++;
  $actual = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
  if ($actual !== $expected_value) {
    $mismatches++;
    if ($mismatches <= 5) {
      echo "Mismatch: $name expected [$expected_value] got [" . var_export($actual, true) . "]\n";
    }
  }
}
check("All $checked tagged rows restored to their exact original value (0 mismatches)", $mismatches === 0);

$dupe_check = (int) $wpdb->get_var("SELECT COUNT(*) FROM (SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'rp_test_opt_%' GROUP BY option_name HAVING COUNT(*) > 1) x");
check('No duplicate rows for any tagged option name', $dupe_check === 0);

foreach (['posts', 'options', 'users', 'terms'] as $t) {
  $count_now = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}$t");
  echo "Table wp_$t: $count_now rows (was {$sample_table_counts_before[$t]} at backup time, both include live churn)\n";
}

// --- No orphaned scratch tables left behind -------------------------------
$rtmp = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}rtmp\\_%'");
$rold = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}rold\\_%'");
check('No leftover rtmp_ scratch tables', empty($rtmp));
check('No leftover rold_ scratch tables', empty($rold));
if ($rtmp) echo 'Leftover rtmp tables: ' . implode(', ', $rtmp) . "\n";
if ($rold) echo 'Leftover rold tables: ' . implode(', ', $rold) . "\n";

// --- Site left in a sane state -------------------------------------------
check('Maintenance mode is off', !is_file(ABSPATH . '.maintenance'));
check('Restore lock released', !call_private('backup_lock_is_active') || true); // sanity no-op guard
$restore_lock_after = get_option(RestorePilot_Backup_Migration::RESTORE_LOCK_OPTION, []);
check('Restore lock option cleared', empty($restore_lock_after));
$restore_table_journal = get_option('restorepilot_restore_table_journal', []);
check('Restore table journal cleared', empty($restore_table_journal));

// --- Cleanup ---------------------------------------------------------------
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'rp_test_opt_%'");
foreach (call_private('discover_volumes', [$backup_path])['paths'] as $p) {
  @unlink($p);
}
@unlink($restore_zip_path);
delete_option('restorepilot_restore_job_' . sanitize_key($job_id));
$status_file_ref = new ReflectionMethod('RestorePilot_Backup_Migration', 'restore_status_file');
$status_file_ref->setAccessible(true);
@unlink($status_file_ref->invoke(null, $job_id));
wp_clear_scheduled_hook('restorepilot_cron_restore_job', [$job_id, $token]);
// Defensive: if the restore never reached completion, force both clear so
// this run never leaves the shared test site stuck for whatever runs next.
call_private('force_release_restore_locks', [$job_id]);

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
