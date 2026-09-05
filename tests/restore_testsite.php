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

/**
 * Puts the test site's database back from the backup taken moments before the
 * Master Reset, so the suite has real data to work against again.
 *
 * Database only: the plugin's own files on disk are the fixed ones, and the
 * backup holds the version that shipped the self-deletion bug.
 */

require_once __DIR__ . '/env.php';
rp_test_boot();

function call_private($name, $args = []) {
    $m = new ReflectionMethod('RestorePilot_Backup_Migration', $name);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
}

global $wpdb;
printf("Before: %d tables\n", (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()"));

$backups = glob(call_private('storage_dir') . '/backups/*.zip');
usort($backups, function ($a, $b) { return filemtime($b) - filemtime($a); });
if (!$backups) { fwrite(STDERR, "no backup\n"); exit(1); }
echo 'Restoring from: ' . basename($backups[0]) . "\n";

$restore_zip = call_private('storage_dir') . '/restore-upload-' . wp_generate_uuid4() . '.zip';
copy($backups[0], $restore_zip);

$job_id = 'rp-testsite-restore-' . wp_generate_uuid4();
$token  = wp_generate_password(32, false, false);
call_private('set_restore_job', [$job_id, [
    'status' => 'queued', 'phase' => 'queued', 'progress' => 0, 'message' => 'Queued',
    'restore_zip_path' => $restore_zip,
    'auto_detect_urls' => true,
    'restore_files' => false,
    'create_new_admin' => false,
    'token' => $token,
    'poll_token' => wp_generate_password(32, false, false),
    'created' => time(),
]]);

$log = call_private('log_file');
$log_start = file_exists($log) ? filesize($log) : 0;

echo "Restoring";
$deadline = time() + 2400;
$chunks = 0;
do {
    call_private('run_restore_job', [$job_id, $token]);
    $chunks++;
    $job = call_private('get_restore_job', [$job_id]);
    $status = $job['status'] ?? '';
    if ($chunks % 5 === 0) { echo '.'; }
} while (!in_array($status, ['complete', 'error', 'stale'], true) && time() < $deadline);

echo "\nChunks: $chunks | status: $status\n";
if (!empty($job['message'])) { echo "Message: {$job['message']}\n"; }

$new_log = file_get_contents($log, false, null, $log_start);
$dupes = stripos($new_log, 'Duplicate entry') !== false;
echo 'Duplicate-key errors during this restore: ' . ($dupes ? "YES -- the concurrency fix did not hold\n" : "none\n");

$wpdb->flush();
printf("After: %d tables\n", (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()"));
$big = $wpdb->get_var("SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'wp_cf7_vdata_entry'");
if ($big) {
    printf("wp_cf7_vdata_entry rows: %s\n", number_format((int) $wpdb->get_var('SELECT COUNT(*) FROM wp_cf7_vdata_entry')));
}

@unlink($restore_zip);
delete_option('restorepilot_restore_job_' . sanitize_key($job_id));
call_private('force_release_restore_locks', [$job_id]);
exit($status === 'complete' && !$dupes ? 0 : 1);
