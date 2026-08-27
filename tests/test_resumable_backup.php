<?php
// Standalone test of resumable backup-side execution. Run against the
// sunhsine-bkp Local site's live wp-load.php, but requires the plugin file
// from its real location under morecalculators-dev directly (no copy/install
// needed — only static methods are called).

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

function get_private_static($prop) {
  $ref = new ReflectionProperty('RestorePilot_Backup_Migration', $prop);
  $ref->setAccessible(true);
  return $ref->getValue();
}

$failures = [];
function check($label, $cond) {
  global $failures;
  if ($cond) {
    echo "PASS  $label\n";
  } else {
    echo "FAIL  $label\n";
    $failures[] = $label;
  }
}

// --- Fixture setup -----------------------------------------------------
// Selection granularity is a whole top-level wp-content folder (that is
// what sanitize_selected_backup_paths()/list_backup_file_items() actually
// accept — an individual file or nested subfolder path is rejected, which
// an earlier version of this fixture ran into), so the fixture is two
// top-level folders, each with nested content.
$content_dir = call_private('content_dir');
$roots = [$content_dir . '/rp-resume-test-a', $content_dir . '/rp-resume-test-b'];
foreach ($roots as $root) {
  if (is_dir($root)) {
    system('rm -rf ' . escapeshellarg($root));
  }
  mkdir($root, 0777, true);
  mkdir($root . '/subdir', 0777, true);
}

$expected_hashes = [];
$selected_paths = ['rp-resume-test-a', 'rp-resume-test-b'];

foreach ($roots as $root) {
  $rel_root = basename($root);
  // Flat files directly under the selected folder (exercises
  // add_directory_to_zip's own top level, SELF_FIRST iteration order).
  for ($i = 1; $i <= 20; $i++) {
    $name = sprintf('flat-%02d.bin', $i);
    $bytes = random_bytes(20000 + $i * 500);
    file_put_contents($root . '/' . $name, $bytes);
    $expected_hashes['files/wp-content/' . $rel_root . '/' . $name] = sha1($bytes);
  }
  // Nested files (exercises the every-25-items check across a deeper walk).
  for ($i = 1; $i <= 30; $i++) {
    $name = sprintf('sub-%02d.bin', $i);
    $bytes = random_bytes(8000 + $i * 300);
    file_put_contents($root . '/subdir/' . $name, $bytes);
    $expected_hashes['files/wp-content/' . $rel_root . '/subdir/' . $name] = sha1($bytes);
  }
}

echo 'Fixture: ' . count($expected_hashes) . " files created.\n";

// Force many chunk yields and several volume rollovers. A budget of 0 would
// never let even the first file through (the very first in-loop check would
// already be "expired" relative to itself), so this uses a small positive
// value instead — small enough to force many yields across the fixture,
// large enough to guarantee real forward progress each chunk.
add_filter('restorepilot_backup_chunk_seconds', function () {
  return 0.02;
});
add_filter('restorepilot_backup_volume_bytes', function () {
  return 200 * 1024; // 200 KB — well below the fixture's total size
});

// dispatch_backup_worker()'s loopback POST is a real, non-blocking HTTP
// request to this same site — left unblocked, the live PHP-FPM worker
// actually executes it concurrently with this script's own sequential
// resumption loop, racing on the same job. This test drives resumptions
// itself, so the real dispatch must be short-circuited.
add_filter('pre_http_request', function () {
  return new WP_Error('blocked_for_test', 'Loopback dispatch blocked for resumption test.');
}, 10, 3);

$job_id = 'rp-resume-test-' . wp_generate_uuid4();
$token = wp_generate_password(32, false, false);
call_private('set_backup_job', [$job_id, [
  'status' => 'queued',
  'phase' => 'queued',
  'progress' => 5,
  'message' => 'queued',
  'include_files' => true,
  'file_selection_enabled' => true,
  'selected_paths' => $selected_paths,
  'files_scanned' => 0,
  'bytes_scanned' => 0,
  'token' => $token,
  'created' => time(),
  'updated' => time(),
]]);

// --- Drive resumptions ---------------------------------------------------
// Each call is exactly what one real PHP process (the first loopback/cron
// dispatch, or a later resumption) does. Calling it repeatedly in one script
// simulates the real gaps between processes without waiting on actual cron
// timing — the code path executed per call is identical either way.
$max_iterations = 1000;
$iterations = 0;
do {
  $iterations++;
  RestorePilot_Backup_Migration::run_backup_job($job_id, $token);
  $job = call_private('get_backup_job', [$job_id]);
  $status = $job['status'] ?? '(missing)';
} while ($status === 'running' && $iterations < $max_iterations);

echo "Resumptions taken: $iterations\n";
echo 'Final status: ' . $status . "\n";
if ($status !== 'complete') {
  echo 'Final job record: ' . print_r($job, true) . "\n";
}

check('Job reached complete status', $status === 'complete');
check('Took more than one resumption (yielding actually happened)', $iterations > 1);

if ($status === 'complete') {
  $final_zip_path = call_private('backup_dir') . '/' . $job['file'];
  $discovered = call_private('discover_volumes', [$final_zip_path]);
  echo 'Volumes: ' . count($discovered['paths']) . "\n";
  check('More than one volume was created', count($discovered['paths']) > 1);

  $zip = call_private('open_backup_archive', [$final_zip_path]);
  check('Archive opens', $zip instanceof RestorePilot_Backup_Archive);

  $seen_counts = [];
  for ($i = 0; $i < $zip->num_files(); $i++) {
    $name = $zip->get_name_index($i);
    $seen_counts[$name] = ($seen_counts[$name] ?? 0) + 1;
  }

  echo 'Total entries in archive: ' . count($seen_counts) . "\n";
  echo 'Entry names (first 20): ' . implode(', ', array_slice(array_keys($seen_counts), 0, 20)) . "\n";

  $dupes = array_filter($seen_counts, static fn($c) => $c > 1);
  check('No duplicate entries in the archive', count($dupes) === 0);
  if ($dupes) {
    echo 'Duplicated names: ' . implode(', ', array_slice(array_keys($dupes), 0, 10)) . "\n";
  }

  $missing = [];
  $mismatched = [];
  foreach ($expected_hashes as $name => $expected_sha1) {
    if (!isset($seen_counts[$name])) {
      $missing[] = $name;
      continue;
    }
    $data = $zip->get_from_name($name);
    if ($data === false || sha1($data) !== $expected_sha1) {
      $mismatched[] = $name;
    }
  }
  check('Every fixture file present', count($missing) === 0);
  if ($missing) {
    echo 'Missing (first 10): ' . implode(', ', array_slice($missing, 0, 10)) . "\n";
  }
  check('Every fixture file byte-for-byte correct', count($mismatched) === 0);
  if ($mismatched) {
    echo 'Mismatched (first 10): ' . implode(', ', array_slice($mismatched, 0, 10)) . "\n";
  }

  $manifest_raw = $zip->get_from_name('manifest.json');
  $manifest = is_string($manifest_raw) ? json_decode($manifest_raw, true) : null;
  check('Manifest present and volumes count matches', is_array($manifest) && (int) $manifest['volumes'] === count($discovered['paths']));

  // validate_backup_zip() re-checks structural validity (part existence,
  // entry counts, etc.) the same way a real restore attempt would before
  // touching anything.
  try {
    $validated = call_private('validate_backup_zip', [$zip, true, false]);
    check('validate_backup_zip() accepts the resumed archive', is_array($validated) && $validated['table_count'] > 0);
  } catch (Throwable $e) {
    check('validate_backup_zip() accepts the resumed archive', false);
    echo 'validate_backup_zip() threw: ' . $e->getMessage() . "\n";
  }

  $zip->close();

  // No stray journals anywhere under storage.
  $storage_dir = call_private('storage_dir');
  $stray_journals = glob($storage_dir . '/*.journal') ?: [];
  $stray_journals = array_merge($stray_journals, glob($storage_dir . '/**/*.journal') ?: []);
  check('No leftover .journal files after completion', count($stray_journals) === 0);
  if ($stray_journals) {
    echo 'Stray journals: ' . implode(', ', $stray_journals) . "\n";
  }

  // Cleanup the produced backup.
  foreach ($discovered['paths'] as $p) {
    @unlink($p);
  }
}

// Cleanup fixtures, job option, and any cron event left scheduled from the
// last dispatch_backup_worker() call, regardless of outcome.
foreach ($roots as $root) {
  system('rm -rf ' . escapeshellarg($root));
}
delete_option('restorepilot_backup_job_' . sanitize_key($job_id));
wp_clear_scheduled_hook('restorepilot_cron_backup_job', [$job_id, $token]);

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
