<?php
/**
 * Recovers the test site from the rollback point the failed restore left
 * behind. Runs on the shipped 0.5.1 code, single process, no loopback.
 */

define('WP_USE_THEMES', false);
require_once '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public/wp-load.php';

function call_private($name, $args = []) {
    $m = new ReflectionMethod('RestorePilot_Backup_Migration', $name);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
}

global $wpdb;
printf("Before: %d posts, %d users, %d options\n",
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}"),
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}"));

$points = call_private('list_restore_rollback_points');
if (!$points) { fwrite(STDERR, "No rollback point available\n"); exit(1); }
$point = $points[0];
echo "Recovering from: " . basename($point['path']) . " (" . round($point['size'] / 1048576) . " MB)\n";

// Clear anything left over from the failed run.
call_private('force_release_restore_locks', ['']);
foreach ($wpdb->get_col("SHOW TABLES LIKE '%restorepilot_rtmp%'") as $t) {
    $wpdb->query("DROP TABLE IF EXISTS `$t`");
}

$job_id = 'rp-recovery-' . wp_generate_uuid4();
$token  = wp_generate_password(32, false, false);
call_private('set_restore_job', [$job_id, [
    'status' => 'queued', 'phase' => 'queued', 'progress' => 0, 'message' => 'Queued',
    'restore_zip_path' => $point['path'],
    'auto_detect_urls' => true,
    'restore_files' => false,          // database-only: the files were never the problem
    'create_new_admin' => false,
    'token' => $token,
    'poll_token' => wp_generate_password(32, false, false),
    'created' => time(),
]]);

echo "Recovering";
$deadline = time() + 1800;
$chunks = 0;
do {
    call_private('run_restore_job', [$job_id, $token]);
    $chunks++;
    $job = call_private('get_restore_job', [$job_id]);
    $status = $job['status'] ?? '';
    if ($chunks % 5 === 0) { echo "."; }
} while (!in_array($status, ['complete', 'error', 'stale'], true) && time() < $deadline);

echo "\nChunks: $chunks\nStatus: $status\n";
if (!empty($job['message'])) { echo "Message: {$job['message']}\n"; }

// Re-read counts on a fresh connection view.
$wpdb->flush();
printf("After: %d posts, %d users, %d options\n",
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}"),
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}"));

delete_option('restorepilot_restore_job_' . sanitize_key($job_id));
call_private('force_release_restore_locks', [$job_id]);
echo $status === 'complete' ? "RECOVERY_OK\n" : "RECOVERY_FAILED\n";
exit($status === 'complete' ? 0 : 1);
