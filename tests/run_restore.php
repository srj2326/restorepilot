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

// Hand-run helper: name the archive to restore.
$backup_path = $argv[1] ?? '';
if ($backup_path === '' || !is_file($backup_path)) {
  fwrite(STDERR, "usage: run_restore.php <backup.zip>\n");
  exit(1);
}

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
