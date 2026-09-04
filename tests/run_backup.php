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

require __DIR__ . '/bootstrap.php';

$ref = new ReflectionMethod('RestorePilot_Backup_Migration', 'create_backup_package');
$ref->setAccessible(true);

$start = microtime(true);
$result = $ref->invoke(null, true, '', [], false, true, ['triggered_by' => 'manual', 'filename' => 'live-test-backup.zip']);
$elapsed = round(microtime(true) - $start, 2);

echo "Backup result: " . json_encode($result) . "\n";
echo "Elapsed: {$elapsed}s\n";
