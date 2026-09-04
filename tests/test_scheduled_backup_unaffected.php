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

// Confirms the job_id-less callers (scheduled/cron backup, rollback-point
// snapshot) are immune to the chunk budget — they have no resumption
// mechanism, so a yield there would orphan the lock and every volume
// instead of pausing. With an aggressively tiny chunk budget that forces
// yields constantly on the async job path, these should still complete in
// one uninterrupted call, exactly like before resumability existed.

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

add_filter('restorepilot_backup_chunk_seconds', function () { return 0.001; });
add_filter('restorepilot_backup_volume_bytes', function () { return 200 * 1024; });

$content_dir = call_private('content_dir');
$root = $content_dir . '/rp-scheduled-test';
if (is_dir($root)) system('rm -rf ' . escapeshellarg($root));
mkdir($root, 0777, true);
for ($i = 1; $i <= 60; $i++) {
  file_put_contents($root . '/f' . $i . '.bin', random_bytes(20000 + $i * 500));
}

// Directly exercises the same call handle_scheduled_backup() makes:
// create_backup_package(true, '', [], false, ...) — job_id is '', include_files
// is true, no selection (backs up all of wp-content, same as scheduled does).
try {
  $result = call_private('create_backup_package', [true, '', [], false, false, ['triggered_by' => 'scheduled-test']]);
  check('Scheduled-style backup (no job_id) completes in one call despite tiny chunk budget', is_array($result) && !empty($result['file']));
  echo 'Result: ' . print_r($result, true) . "\n";

  check('Global backup lock is not left held after a job_id-less backup', !call_private('backup_lock_is_active'));

  if (!empty($result['file'])) {
    $final_zip_path = call_private('backup_dir') . '/' . $result['file'];
    $volumes = call_private('discover_volumes', [$final_zip_path])['paths'];
    echo 'Volumes produced: ' . count($volumes) . "\n";
    foreach ($volumes as $v) {
      @unlink($v);
      @unlink($v . '.journal');
    }
  }
} catch (Throwable $e) {
  check('Scheduled-style backup (no job_id) completes in one call despite tiny chunk budget', false);
  echo 'Threw: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
  check('Global backup lock is not left held after a job_id-less backup', !call_private('backup_lock_is_active'));
}

system('rm -rf ' . escapeshellarg($root));

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
