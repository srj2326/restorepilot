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

require_once __DIR__ . '/env.php';

// Isolated test of the riskiest piece of restore-side resumability: does a
// job's checkpoint (and its auth token) actually survive
// purge_foreign_runtime_state(), which unconditionally wipes
// RESTORE_LOCK_OPTION and (before today's fix) every restorepilot_restore_job_*
// option including the currently-running restore's own? A real restore calls
// this right after the database swap. No zip/database/files involved here —
// just the job-record + lock survival mechanics in isolation.

$site_root = rp_test_site();
$plugin_file = rp_test_plugin_file();

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

$job_id = 'rp-checkpoint-survival-' . wp_generate_uuid4();
$token = wp_generate_password(32, false, false);
$poll_token = wp_generate_password(32, false, false);

$checkpoint = [
  'restore_zip_path' => '/tmp/fake.zip',
  'manifest' => ['home_url' => 'https://example.test'],
  'backup_prefix' => 'wp_',
  'restore_plan' => ['restore_id' => 'abc123', 'plans' => [['old_table' => 'wp_options', 'tmp_table' => 'wp_rtmp_abc123_0']], 'plan_by_table' => ['wp_options' => 0]],
  'source_url' => 'https://example.test',
  'target_url' => 'https://example.test',
  'lock_token' => 'lock-token-value',
  'rollback_created' => true,
  'database_done' => false,
  'completed_tables' => ['wp_options'],
  'files_needed' => true,
  'files_done' => false,
  'files_index' => 42,
  'resumption' => 3,
];

call_private('set_restore_job', [$job_id, [
  'status' => 'running',
  'phase' => 'database',
  'phase_label' => 'Restoring database',
  'progress' => 55,
  'message' => 'mid-flight',
  'restore_zip_path' => '/tmp/fake.zip',
  'auto_detect_urls' => true,
  'restore_files' => true,
  'source_url' => 'https://example.test',
  'target_url' => 'https://example.test',
  'token' => $token,
  'poll_token' => $poll_token,
  'created' => time() - 120,
  'updated' => time(),
  'checkpoint' => $checkpoint,
]]);

// Establish the lock this job is supposedly holding, exactly as
// perform_restore() would have via acquire_restore_lock() earlier.
update_option(RestorePilot_Backup_Migration::RESTORE_LOCK_OPTION, [
  'started' => time(),
  'job_id' => sanitize_key($job_id),
  'token' => 'lock-token-value',
], false);

$before = call_private('get_restore_job', [$job_id]);
check('Job readable from DB option before any wipe', $before['status'] === 'running' && ($before['checkpoint']['resumption'] ?? null) === 3);

// This is the exact call perform_restore() makes right after the database
// swap. Before today's fix it would have wholesale-deleted this job's own
// option (matching 'restorepilot_restore_job_%') and the lock option, with
// no exception for the restore currently running it.
call_private('purge_foreign_runtime_state', [$job_id, 'lock-token-value']);

// Was the DB option actually spared (the primary fix), or did it get wiped
// and this is only surviving via the status-file fallback (the secondary,
// defense-in-depth fix)? Check both explicitly, since either one alone
// would make the test below pass even if the other were broken.
$option_name = call_private('restore_job_option', [$job_id]);
$raw_option = get_option($option_name, '__missing__');
check('DB option itself was spared by the exclusion fix (not just recovered from the file)', $raw_option !== '__missing__' && is_array($raw_option));

$status_file_job = call_private('read_restore_status_file', [$job_id]);
check('Status file independently carries the full record too (defense in depth)', ($status_file_job['checkpoint']['resumption'] ?? null) === 3 && !empty($status_file_job['token']));

$after = call_private('get_restore_job', [$job_id]);
check('get_restore_job() still returns the SAME job after the purge', $after['status'] === 'running');
check('Token survived (this is what run_restore_job() authenticates the next resumption against)', ($after['token'] ?? '') === $token);
check('poll_token survived', ($after['poll_token'] ?? '') === $poll_token);
check('restore_zip_path survived (perform_restore() needs this to reopen the archive)', ($after['restore_zip_path'] ?? '') === '/tmp/fake.zip');
check('checkpoint.restore_plan survived intact', ($after['checkpoint']['restore_plan']['restore_id'] ?? '') === 'abc123');
check('checkpoint.completed_tables survived', ($after['checkpoint']['completed_tables'] ?? []) === ['wp_options']);
check('checkpoint.files_index survived', ($after['checkpoint']['files_index'] ?? null) === 42);
check('checkpoint.lock_token survived', ($after['checkpoint']['lock_token'] ?? '') === 'lock-token-value');

// The lock itself: purge_foreign_runtime_state() unconditionally deletes
// RESTORE_LOCK_OPTION (it cannot exclude it by name the way it excludes the
// job record), then re-establishes it under the same token immediately
// after. Verify it is back, under the SAME token, not just present.
$lock_after = get_option(RestorePilot_Backup_Migration::RESTORE_LOCK_OPTION, []);
check('Restore lock was re-established after being wiped', is_array($lock_after) && !empty($lock_after['started']));
check('Re-established lock has the SAME token (release_restore_lock() must still match it later)', ($lock_after['token'] ?? '') === 'lock-token-value');

// A second, unrelated restore attempt must still be rejected in the window
// right after purge_foreign_runtime_state() ran — proving the lock wasn't
// left briefly absent for a race to slip through.
try {
  call_private('acquire_restore_lock', ['some-other-job']);
  check('A second restore is still rejected immediately after the purge', false);
} catch (RuntimeException $e) {
  check('A second restore is still rejected immediately after the purge', true);
}

// Cleanup.
delete_option($option_name);
delete_option(RestorePilot_Backup_Migration::RESTORE_LOCK_OPTION);
$status_file_path_ref = new ReflectionMethod('RestorePilot_Backup_Migration', 'restore_status_file');
$status_file_path_ref->setAccessible(true);
@unlink($status_file_path_ref->invoke(null, $job_id));

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
