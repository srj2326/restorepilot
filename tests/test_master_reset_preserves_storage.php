<?php
// Regression test for the bug just hit live: Master Reset's "wipe all
// uploads" step used to delete wp-content/uploads/restorepilot-backup-
// migration/ along with everything else — destroying stored backups,
// rollback points, and any file placed there for Advanced restore
// settings > Server backup path. Verifies master_reset_wipe_dir() (the
// helper behind that step) now specifically preserves RestorePilot's own
// storage directory while still wiping every other sibling under uploads,
// WITHOUT running the rest of the (much more destructive, DB-truncating)
// handle_master_reset() flow.

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

$upload = wp_upload_dir(null, false);
$uploads_base = $upload['basedir'];
$storage_dir = call_private('storage_dir');
echo "uploads_base: $uploads_base\n";
echo "storage_dir:  $storage_dir\n";

// --- Fixture: dummy sibling content directly under uploads, simulating real
// media, PLUS a marker file inside RestorePilot's own storage dir, PLUS a
// fake "server backup path" file sitting at the storage dir's root exactly
// like the one the user placed there. ---
$dummy_media_dir = $uploads_base . '/2026';
$dummy_media_file = $uploads_base . '/rp-test-stray-file.txt';
@mkdir($dummy_media_dir, 0777, true);
file_put_contents($dummy_media_dir . '/photo.jpg', 'fake image bytes');
file_put_contents($dummy_media_file, 'a stray file directly under uploads');

if (!is_dir($storage_dir)) { mkdir($storage_dir, 0777, true); }
$marker_in_backups = $storage_dir . '/backups';
@mkdir($marker_in_backups, 0777, true);
file_put_contents($marker_in_backups . '/existing-backup-marker.zip', 'pretend backup bytes');
$staged_restore_file = $storage_dir . '/staged-for-restore-test.zip';
file_put_contents($staged_restore_file, 'pretend large restore-staged file');

check('Fixture: dummy media dir exists before wipe', is_dir($dummy_media_dir));
check('Fixture: dummy stray file exists before wipe', is_file($dummy_media_file));
check('Fixture: staged restore file exists before wipe', is_file($staged_restore_file));
check('Fixture: existing backup marker exists before wipe', is_file($marker_in_backups . '/existing-backup-marker.zip'));

// --- The actual call under test (mirrors handle_master_reset() step 4) ----
$content_dir = call_private('content_dir');
$result = call_private('master_reset_wipe_dir', [$uploads_base, $content_dir]);
check('master_reset_wipe_dir() reports success', $result === true);

// --- Everything OUTSIDE RestorePilot's storage must be gone --------------
check('Dummy media directory was removed', !is_dir($dummy_media_dir));
check('Dummy stray file was removed', !is_file($dummy_media_file));

// --- RestorePilot's own storage directory must survive, contents intact ---
check('RestorePilot storage directory itself still exists', is_dir($storage_dir));
check('Existing backup marker survived the wipe', is_file($marker_in_backups . '/existing-backup-marker.zip'));
check('Staged restore file survived the wipe (the exact file that was lost live)', is_file($staged_restore_file));
check('Staged restore file content is untouched', file_get_contents($staged_restore_file) === 'pretend large restore-staged file');

// --- Cleanup: remove only the test fixtures we created --------------------
@unlink($staged_restore_file);
@unlink($marker_in_backups . '/existing-backup-marker.zip');
@rmdir($marker_in_backups);

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
