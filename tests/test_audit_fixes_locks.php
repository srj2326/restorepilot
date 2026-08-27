<?php
// Regression tests for the locking/retention fixes from the full-plugin
// audit: (1) enforce_backup_retention() must not run while a restore is
// active, (2) force_release_restore_locks() must release the worker lock
// too (not just the site lock, which is all 3 restore recovery paths used
// to do), (3) purge_foreign_runtime_state() must not delete the current
// restore's own worker lock, and must re-establish the site lock without a
// delete-then-recreate gap.

$site_root = '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public';
require $site_root . '/wp-load.php';

function call_private($method, array $args = []) {
  $ref = new ReflectionMethod('RestorePilot_Backup_Migration', $method);
  $ref->setAccessible(true);
  return $ref->invokeArgs(null, $args);
}

function get_option_direct($name) {
  // The functions under test delete rows via raw $wpdb->query() (required —
  // see like_prefix_literal()'s docblock), which does not invalidate
  // WordPress's options cache the way delete_option() does. Bypass the
  // cache here so this test reflects the real database state rather than a
  // stale in-process read — confirmed via a direct DB query that the
  // underlying delete is correct; this is a test-script concern only.
  wp_cache_delete($name, 'options');
  return get_option($name, null);
}

$failures = [];
function check($label, $cond) {
  global $failures;
  echo ($cond ? 'PASS' : 'FAIL') . "  $label\n";
  if (!$cond) $failures[] = $label;
}

// Clean slate.
delete_option('restorepilot_restore_lock');
delete_option('restorepilot_backup_lock');

// === Test 1: retention is skipped while a restore lock is active ===========
// create_backup_package() already calls enforce_backup_retention() itself on
// completion (confirmed by grep — this is not test-specific behavior), so
// the restore lock must be acquired BEFORE any fixture backups are created,
// not after — otherwise retention would already have pruned during fixture
// setup, before the assertion ever runs, independent of whether this fix
// works at all.
$fake_job_id = 'audit-test-' . uniqid();
$restore_token = call_private('acquire_restore_lock', [$fake_job_id]);
check('Fake restore lock acquired for test', $restore_token !== '');

$backup_names = [];
for ($i = 0; $i < 3; $i++) {
  $r = call_private('create_backup_package', [false, '', [], false, false, ['triggered_by' => 'audit-lock-test']]);
  check("Fixture backup $i created", !empty($r['file']));
  $backup_names[] = $r['file'];
  usleep(1100000); // ensure distinct mtimes (retention sorts by mtime)
}

$backups_after = call_private('list_backups');
check('Retention did NOT run while restore lock is active (still 3 backups, not pruned to 2)', count($backups_after) === 3);

call_private('release_restore_lock', [$restore_token]);
call_private('enforce_backup_retention');
$backups_after2 = call_private('list_backups');
check('Retention DOES run once the restore lock is released (pruned to 2)', count($backups_after2) === 2);

// === Test 2: force_release_restore_locks() releases the worker lock too ====
$job_id2 = 'audit-worker-test-' . uniqid();
$acquired = call_private('acquire_restore_worker_lock', [$job_id2]);
check('Fixture: worker lock acquired', $acquired === true);
$worker_option = 'restorepilot_restore_worker_' . $job_id2;
check('Fixture: worker lock option exists before cleanup', get_option_direct($worker_option) !== null);

call_private('force_release_restore_locks', [$job_id2]);
check('force_release_restore_locks() removed the worker lock', get_option_direct($worker_option) === null);

// === Test 3: purge_foreign_runtime_state() does not delete the CURRENT
// restore's own worker lock (this is what live-broke earlier this session) =
$job_id3 = 'audit-purge-test-' . uniqid();
call_private('acquire_restore_worker_lock', [$job_id3]);
$worker_option3 = 'restorepilot_restore_worker_' . $job_id3;
check('Fixture: current job worker lock exists before purge', get_option_direct($worker_option3) !== null);

// Also seed an unrelated, foreign worker lock that SHOULD be removed.
update_option('restorepilot_restore_worker_some-foreign-job', ['started' => time()], false);

$fake_token = 'faketoken1234567890123456789012';
call_private('purge_foreign_runtime_state', [$job_id3, $fake_token]);

check("purge_foreign_runtime_state() preserved the CURRENT job's own worker lock", get_option_direct($worker_option3) !== null);
check('purge_foreign_runtime_state() removed a FOREIGN worker lock', get_option_direct('restorepilot_restore_worker_some-foreign-job') === null);

$site_lock_after_purge = get_option_direct('restorepilot_restore_lock');
check('purge_foreign_runtime_state() re-established the site lock with the current job/token', is_array($site_lock_after_purge) && $site_lock_after_purge['job_id'] === $job_id3 && $site_lock_after_purge['token'] === $fake_token);

call_private('release_restore_worker_lock', [$job_id3]);
delete_option('restorepilot_restore_lock');

// --- Cleanup -----------------------------------------------------------------
foreach ($backup_names as $name) {
  $base = call_private('backup_dir') . '/' . $name;
  foreach (call_private('volume_paths_for', [$base]) as $p) { @unlink($p); }
}
delete_option('restorepilot_restore_lock');
delete_option('restorepilot_backup_lock');
delete_option($worker_option);
delete_option($worker_option3);
delete_option('restorepilot_restore_worker_some-foreign-job');

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
