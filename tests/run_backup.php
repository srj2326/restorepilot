<?php
require __DIR__ . '/bootstrap.php';

$ref = new ReflectionMethod('RestorePilot_Backup_Migration', 'create_backup_package');
$ref->setAccessible(true);

$start = microtime(true);
$result = $ref->invoke(null, true, '', [], false, true, ['triggered_by' => 'manual', 'filename' => 'live-test-backup.zip']);
$elapsed = round(microtime(true) - $start, 2);

echo "Backup result: " . json_encode($result) . "\n";
echo "Elapsed: {$elapsed}s\n";
