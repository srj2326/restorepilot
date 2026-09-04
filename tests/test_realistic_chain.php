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
 * A restore driven the way production drives one: queue it, trigger the first
 * chunk over HTTP, then let the loopback/cron chain carry it. Nothing here
 * calls run_restore_job in a loop -- doing that alongside real loopbacks is
 * what produced the concurrent workers earlier, and it is not how the plugin
 * is ever actually run.
 *
 * Measures the gap between a chunk finishing and the next one starting, which
 * is the thing the loopback fix exists to close, and checks the restore came
 * out correct.
 */

define('WP_USE_THEMES', false);
require_once '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public/wp-load.php';

$failures = [];
function check(string $label, bool $ok) {
    global $failures;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if (!$ok) { $failures[] = $label; }
}
function call_private($name, $args = []) {
    $m = new ReflectionMethod('RestorePilot_Backup_Migration', $name);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
}

global $wpdb;
$log_file = call_private('log_file');
$log_start = file_exists($log_file) ? filesize($log_file) : 0;

// A marker that must survive: proves the restore actually replaced the data
// rather than merely reporting success.
$marker = 'chain-test-' . wp_generate_password(8, false, false);
update_option('rp_chain_marker', $marker, false);

$backups = glob(call_private('storage_dir') . '/backups/*.zip');
usort($backups, function ($a, $b) { return filemtime($b) - filemtime($a); });
if (!$backups) { echo "SKIP  no backup available\n"; exit(0); }

$restore_zip = call_private('storage_dir') . '/restore-upload-' . wp_generate_uuid4() . '.zip';
copy($backups[0], $restore_zip);

$job_id     = 'rp-chain-' . wp_generate_uuid4();
$token      = wp_generate_password(32, false, false);
$poll_token = wp_generate_password(32, false, false);

call_private('set_restore_job', [$job_id, [
    'status' => 'queued', 'phase' => 'queued', 'progress' => 0, 'message' => 'Queued',
    'restore_zip_path' => $restore_zip,
    'auto_detect_urls' => true, 'restore_files' => false, 'create_new_admin' => false,
    'token' => $token, 'poll_token' => $poll_token, 'created' => time(),
]]);
call_private('write_poll_token_file', [$job_id, $poll_token]);

// One trigger over HTTP, exactly as the browser does. Then hands off.
$url = admin_url('admin-ajax.php');
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['action' => 'restorepilot_run_restore_job', 'job_id' => $job_id, 'token' => $token]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 2,
]);
curl_exec($ch);
curl_close($ch);
echo "First chunk triggered over HTTP; the chain now runs on its own.\n";

// Poll from the outside, the way the browser polls, and nudge cron the way a
// real visitor's page load would.
$deadline = time() + 1500;
$status = '';
while (time() < $deadline) {
    sleep(3);
    $c = curl_init(home_url('/wp-cron.php?doing_wp_cron=' . microtime(true)));
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
    curl_exec($c);
    curl_close($c);

    $job = call_private('get_restore_job', [$job_id]);
    $status = $job['status'] ?? '';
    if (in_array($status, ['complete', 'error', 'stale'], true)) { break; }
}
echo "Final status: $status\n";
if (!empty($job['message'])) { echo "Message: {$job['message']}\n"; }
echo "\n";

check('The restore completed', $status === 'complete');

// Did it actually replace the data? The marker was set after the backup, so a
// real restore removes it.
$wpdb->flush();
wp_cache_flush();
$still = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'rp_chain_marker'));
check('The restore genuinely replaced the database (post-backup marker is gone)', $still !== $marker);

// No duplicate-key or concurrency damage.
$new_log = file_get_contents($log_file, false, null, $log_start);
check('No duplicate-key error during the restore', stripos($new_log, 'Duplicate entry') === false);
check('No job failure logged', stripos($new_log, 'Restore job failed') === false);

// Exactly one worker per resumption -- the thing that went wrong before.
$starts = [];
foreach (explode("\n", $new_log) as $line) {
    if (strpos($line, $job_id) === false) { continue; }
    if (preg_match('/Restore runner started.*resumption (\d+)/', $line, $m)) {
        $starts[$m[1]] = ($starts[$m[1]] ?? 0) + 1;
    }
}
$dupes = array_filter($starts, function ($n) { return $n > 1; });
check('No resumption was started by two workers', empty($dupes));
if ($dupes) { echo '  resumptions started more than once: ' . wp_json_encode($dupes) . "\n"; }

// The gap the fix targets.
$gaps = []; $finished_at = null;
foreach (explode("\n", $new_log) as $line) {
    if (strpos($line, $job_id) === false) { continue; }
    if (!preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) UTC\]/', $line, $m)) { continue; }
    $t = strtotime($m[1] . ' UTC');
    if (strpos($line, 'chunk finished, continuing') !== false) { $finished_at = $t; }
    elseif (strpos($line, 'Restore runner started') !== false && $finished_at !== null) {
        $gaps[] = $t - $finished_at;
        $finished_at = null;
    }
}
if ($gaps) {
    printf("\nGaps between chunk-finished and next-chunk-started: %s\n", implode('s, ', $gaps) . 's');
    printf("max %ds, average %.1fs across %d handover(s)\n", max($gaps), array_sum($gaps) / count($gaps), count($gaps));
    check('Handovers no longer wait the full 5s cron fallback', max($gaps) < 5);
} else {
    echo "\nnote: no handovers recorded (restore finished in a single chunk)\n";
}

// Cleanup.
@unlink($restore_zip);
delete_option('restorepilot_restore_job_' . sanitize_key($job_id));
@unlink(call_private('restore_status_file', [$job_id]));
call_private('force_release_restore_locks', [$job_id]);
delete_option('rp_chain_marker');

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
