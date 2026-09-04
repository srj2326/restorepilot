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

// Focused test of restore_files()'s index-based resumability: back up a
// tagged test folder under wp-content, delete/corrupt it locally, restore
// with a small chunk budget so extraction spans several chunks, verify
// every file lands back byte-for-byte correct with no duplicates or gaps —
// proving the plain integer index checkpoint (start_index) works across
// many resumptions, not just in the trivial single-chunk case.
//
// Each simulated chunk is run in its OWN fresh PHP process (see
// run_one_restore_chunk.php) rather than being driven by a tight in-process
// loop. That's not incidental: maybe_touch_restore_job()'s checkpoint save
// is throttled by a function-local `static $last_touch`, which only ever
// resets when the PHP process itself restarts — true for every real chunk
// (a fresh loopback HTTP request or WP-Cron invocation) but NOT true for a
// same-process loop calling run_restore_job() directly many times, which
// keeps the static alive and can suppress literally every checkpoint save
// after the first if the whole loop runs faster than the 5-second throttle
// window — a livelock that was specific to that driving method, not to the
// plugin. A previous version of this test did exactly that and got stuck
// forever at files_index=0.

$site_root = '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public';
$plugin_file = '/Users/surajitroy/Local Sites/morecalculators-dev/app/public/wp-content/plugins/restorepilot-backup-migration/restorepilot-backup-migration.php';
$runner = __DIR__ . '/run_one_restore_chunk.php';
$php_bin = '/Users/surajitroy/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php';
$sock = '/Users/surajitroy/Library/Application Support/Local/run/gKsH4-EmV/mysql/mysqld.sock';

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

// --- Fixture: a tagged top-level wp-content folder with many small files -
$content_dir = call_private('content_dir');
$root = $content_dir . '/rp-restore-files-test';
if (is_dir($root)) system('rm -rf ' . escapeshellarg($root));
mkdir($root, 0777, true);

$expected_hashes = [];
for ($i = 1; $i <= 150; $i++) {
  $name = sprintf('file-%03d.bin', $i);
  $bytes = random_bytes(500 + $i * 20);
  file_put_contents($root . '/' . $name, $bytes);
  $expected_hashes[$name] = sha1($bytes);
}
echo 'Fixture: ' . count($expected_hashes) . " files created.\n";

// --- Back up all of wp-content (not a partial selection): a selected-content
// backup that excludes even one available folder is marked non-restorable,
// which validate_backup_zip()'s require_full_restore check would then reject.
$backup_result = call_private('create_backup_package', [true, '', [], false, false, ['triggered_by' => 'restore-files-test']]);
check('Fixture backup created', !empty($backup_result['file']));
$backup_path = call_private('backup_dir') . '/' . $backup_result['file'];

// --- Corrupt: delete the whole folder locally ----------------------------
system('rm -rf ' . escapeshellarg($root));
check('Fixture folder is gone before restore', !is_dir($root));

// Snapshot which rollback points already exist so cleanup can remove only
// the one(s) THIS restore creates, never anything pre-existing.
$rollback_dir = call_private('rollback_dir');
$rollback_before = glob($rollback_dir . '/*.zip') ?: [];

// --- Resumable restore, files only this time, one fresh process per chunk -
call_private('ensure_storage');
$restore_zip_path = call_private('storage_dir') . '/restore-upload-' . wp_generate_uuid4() . '.zip';
copy($backup_path, $restore_zip_path);

$job_id = 'rp-restore-files-test-' . wp_generate_uuid4();
$token = wp_generate_password(32, false, false);
call_private('set_restore_job', [$job_id, [
  'status' => 'queued', 'phase' => 'queued', 'phase_label' => 'Queued', 'progress' => 5, 'message' => 'queued',
  'restore_zip_path' => $restore_zip_path,
  'auto_detect_urls' => true,
  'restore_files' => true,
  'source_url' => '', 'target_url' => '',
  'token' => $token, 'poll_token' => wp_generate_password(32, false, false),
  'created' => time(), 'updated' => time(),
]]);

// This site's real table count has grown well past what 0.3s/60 iterations
// used to comfortably cover (this plugin set now carries ~149 tables) —
// confirmed the hard way: stuck at 13/163 tables after the full 60-iteration
// cap, leaving its lock held and cascading failures into every test that ran
// after it in the same suite. Each iteration is also its own real subprocess
// here, not an in-process call, so the per-chunk budget compounds with real
// bootstrap overhead — sized accordingly.
$chunk_seconds = 2.0;
// 150 got to 144/149 tables and stopped just short — bumped for real margin
// to cover the rest of the database phase plus the entire 150-file files
// phase after it.
$max_iterations = 300;
$iterations = 0;
$status = 'running';
global $wpdb;
do {
  $iterations++;
  $cmd = sprintf(
    '%s -d %s -d %s %s %s %s %s 2>&1',
    escapeshellarg($php_bin),
    escapeshellarg('mysqli.default_socket=' . $sock),
    escapeshellarg('pdo_mysql.default_socket=' . $sock),
    escapeshellarg($runner),
    escapeshellarg($job_id),
    escapeshellarg($token),
    escapeshellarg((string) $chunk_seconds)
  );
  // The child needs its own independent, durable DB connection. An open
  // connection inherited from THIS (parent) process across shell_exec()'s
  // fork somehow interferes with the child's ability to commit its own
  // writes (confirmed empirically: identical child code invoked while the
  // parent holds its connection open silently loses every write it makes;
  // invoked with the parent's connection closed first, it persists
  // correctly every time). wpdb reconnects lazily on its own next query,
  // so this is a no-op for the parent beyond that.
  $wpdb->close();
  $chunk_output = shell_exec($cmd);
  // get_restore_job() reads through get_option(), which checks THIS
  // process's own in-memory object cache first — still holding whatever
  // this parent last saw (the 'queued' state from set_restore_job() above,
  // on iteration 1) regardless of what the child just wrote to the actual
  // database. Bust it before every read so each poll reflects the child's
  // real, current state instead of a permanently stale first snapshot.
  wp_cache_delete(call_private('restore_job_option', [$job_id]), 'options');
  $job = call_private('get_restore_job', [$job_id]);
  $status = $job['status'] ?? '(missing)';
  if (trim((string) $chunk_output) !== '') {
    echo "  [chunk $iterations stderr/stdout] " . trim($chunk_output) . "\n";
  }
  if ($iterations % 10 === 0 || $status !== 'running') {
    $cp = $job['checkpoint'] ?? [];
    echo "  [iter $iterations] status=$status phase=" . ($job['phase'] ?? '?')
      . ' database_done=' . var_export($cp['database_done'] ?? null, true)
      . ' completed_tables=' . count($cp['completed_tables'] ?? []) . '/' . count($cp['restore_plan']['plans'] ?? [])
      . ' files_done=' . var_export($cp['files_done'] ?? null, true)
      . ' files_index=' . ($cp['files_index'] ?? '?') . "\n";
  }
} while ($status === 'running' && $iterations < $max_iterations);

echo "Resumptions taken: $iterations\n";
echo 'Final status: ' . $status . "\n";
if ($status !== 'complete') {
  echo 'Final job status/phase: ' . ($job['status'] ?? '?') . '/' . ($job['phase'] ?? '?') . ' message=' . ($job['message'] ?? '?') . "\n";
}
check('Restore job reached complete status', $status === 'complete');
check('Took more than one resumption', $iterations > 1);

$missing = [];
$mismatched = [];
$extra = is_dir($root) ? array_values(array_diff(scandir($root), ['.', '..'])) : [];
foreach ($expected_hashes as $name => $expected_sha1) {
  $path = $root . '/' . $name;
  if (!is_file($path)) {
    $missing[] = $name;
    continue;
  }
  if (sha1_file($path) !== $expected_sha1) {
    $mismatched[] = $name;
  }
}
check('Every fixture file restored', count($missing) === 0);
if ($missing) echo 'Missing: ' . implode(', ', array_slice($missing, 0, 10)) . "\n";
check('Every restored file byte-for-byte correct', count($mismatched) === 0);
if ($mismatched) echo 'Mismatched: ' . implode(', ', array_slice($mismatched, 0, 10)) . "\n";
check('No unexpected extra files', count($extra) === count($expected_hashes));
if (count($extra) !== count($expected_hashes)) echo 'Extra count: ' . count($extra) . ' vs expected ' . count($expected_hashes) . "\n";

$stray_tmp = glob($root . '/*.restorepilot-tmp-*') ?: [];
check('No leftover .restorepilot-tmp-* files', count($stray_tmp) === 0);

// --- Cleanup ---------------------------------------------------------------
system('rm -rf ' . escapeshellarg($root));
foreach (call_private('discover_volumes', [$backup_path])['paths'] as $p) {
  @unlink($p);
}
@unlink($restore_zip_path);
// Remove the status file BEFORE the option, not after. get_restore_job()
// falls back to the status file whenever the option is missing, and
// update_restore_job() then writes BOTH back — so deleting the option
// while its status file is still on disk leaves a window where any
// straggler write resurrects the option from the file. Observed for real:
// a completed run left its job option behind despite delete_option()
// having been called on it. Deleting the file first closes that window.
$status_file_ref = new ReflectionMethod('RestorePilot_Backup_Migration', 'restore_status_file');
$status_file_ref->setAccessible(true);
@unlink($status_file_ref->invoke(null, $job_id));
// The plugin deliberately keeps the poll-token file after a restore
// completes (so a browser can finish polling) and only clears it via the
// user-triggered "clean temp files" maintenance action — correct for
// production, but it means a test that creates jobs must remove its own,
// or they accumulate in the shared storage dir run after run.
$poll_token_file_ref = new ReflectionMethod('RestorePilot_Backup_Migration', 'poll_token_file');
$poll_token_file_ref->setAccessible(true);
@unlink($poll_token_file_ref->invoke(null, $job_id));
delete_option('restorepilot_restore_job_' . sanitize_key($job_id));
wp_clear_scheduled_hook('restorepilot_cron_restore_job', [$job_id, $token]);

// Whether the restore completed naturally (which already self-releases the
// lock and disables maintenance mode) or got stuck, force both clear so a
// stuck run never leaves the shared test site in maintenance mode for
// whatever runs next — safe to call unconditionally either way.
call_private('force_release_restore_locks', [$job_id]);

// Remove only the rollback point(s) THIS run created, never anything that
// existed before it (rollback points are meant to persist — that is by
// design, not something a real restore would ever clean up on its own).
$rollback_after = glob($rollback_dir . '/*.zip') ?: [];
foreach (array_diff($rollback_after, $rollback_before) as $f) {
  @unlink($f);
}

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
