<?php
// Measures the fixed per-resumption overhead every restore chunk pays before
// it can do any real work: open_backup_archive() + assert_restore_zip_entry_count()
// + assert_restore_disk_space() + validate_backup_zip(). This is what a
// resumable restore repeats on EVERY chunk, so if it scales badly with entry
// count, a large site's file-heavy backup could make restore_files() starve
// even under the default (much larger) chunk budget, not just an aggressive
// test one.

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

$content_dir = call_private('content_dir');
$root = $content_dir . '/rp-overhead-test';
if (is_dir($root)) system('rm -rf ' . escapeshellarg($root));
mkdir($root, 0777, true);

$n = (int) ($argv[1] ?? 5000);
for ($i = 0; $i < $n; $i++) {
  file_put_contents($root . '/f' . $i . '.txt', 'x');
}
echo "Fixture: $n files.\n";

$backup_result = call_private('create_backup_package', [true, '', [], false, false, ['triggered_by' => 'overhead-test']]);
$backup_path = call_private('backup_dir') . '/' . $backup_result['file'];
echo 'Backup: ' . $backup_result['file'] . ' (' . $backup_result['size'] . ")\n";

$reps = 5;
$total = 0.0;
for ($r = 0; $r < $reps; $r++) {
  $t0 = microtime(true);
  $zip = call_private('open_backup_archive', [$backup_path]);
  call_private('assert_restore_zip_entry_count', [$zip]);
  call_private('assert_restore_disk_space', [$backup_path, $zip]);
  $validated = call_private('validate_backup_zip', [$zip, true, true]);
  $t1 = microtime(true);
  $zip->close();
  $elapsed = $t1 - $t0;
  $total += $elapsed;
  echo 'Rep ' . ($r + 1) . ': ' . round($elapsed * 1000, 1) . "ms (entries=" . $validated['file_count'] . ")\n";
}
echo 'Average: ' . round(($total / $reps) * 1000, 1) . "ms per resumption's fixed overhead, for $n files.\n";

system('rm -rf ' . escapeshellarg($root));
foreach (call_private('discover_volumes', [$backup_path])['paths'] as $p) {
  @unlink($p);
}
