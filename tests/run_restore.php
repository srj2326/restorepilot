<?php
require __DIR__ . '/bootstrap.php';

$backup_path = '/Users/surajitroy/Local Sites/morecalculators-dev/app/public/wp-content/uploads/restorepilot-backup-migration/backups/live-test-backup.zip';

$ref = new ReflectionMethod('RestorePilot_Backup_Migration', 'perform_restore');
$ref->setAccessible(true);

$home = home_url();

$start = microtime(true);
try {
  $result = $ref->invoke(null, $backup_path, false, true, '', $home, $home);
  echo "Restore result: " . json_encode($result) . "\n";
} catch (Throwable $e) {
  echo "RESTORE THREW: " . get_class($e) . ": " . $e->getMessage() . "\n";
}
$elapsed = round(microtime(true) - $start, 2);
echo "Elapsed: {$elapsed}s\n";
