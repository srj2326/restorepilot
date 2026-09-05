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
 * Every restore chunk sat idle for five seconds before the next one started.
 *
 * The loopback that should have started it immediately was dispatched from
 * inside the worker lock, so it arrived while the lock was still held, failed
 * to acquire it, and returned silently. The +5s cron fallback did the work
 * instead — on every chunk, of every backup and every restore.
 *
 * This measures the real gap from the plugin's own log rather than trusting
 * the code to be right.
 */

require_once __DIR__ . '/env.php';
rp_test_boot();

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

// ── Structural: the dispatch must come after the lock release ─────────────
$src = file_get_contents(rp_test_plugin_file());

foreach ([
    'restore' => ['release_restore_worker_lock', 'dispatch_restore_worker', 'run_restore_job'],
    'backup'  => ['release_backup_worker_lock', 'dispatch_backup_worker', 'run_backup_job'],
] as $side => [$release, $dispatch, $fn]) {
    $start = strpos($src, 'public static function ' . $fn . '(');
    // The method body runs to the next "public static function" after it.
    $next  = strpos($src, "\n  public static function ", $start + 10);
    $body  = substr($src, $start, $next - $start);

    $rel = strpos($body, $release . '($job_id)');
    $dis = strrpos($body, $dispatch . '($job_id, $token)');
    check("$side: dispatch happens AFTER the worker lock is released", $rel !== false && $dis !== false && $dis > $rel);
    check("$side: the yield path sets a flag rather than dispatching inline",
        strpos($body, '$dispatch_next_chunk = true;') !== false);
}

// ── Behavioural: measure the real gap between chunks ──────────────────────
$log_file = call_private('log_file');
$before_size = file_exists($log_file) ? filesize($log_file) : 0;

$backups = glob(call_private('storage_dir') . '/backups/*.zip');
usort($backups, function ($a, $b) { return filemtime($b) - filemtime($a); });
if (!$backups) { echo "SKIP  no backup available to restore\n"; exit(empty($failures) ? 0 : 1); }

$restore_zip = call_private('storage_dir') . '/restore-upload-' . wp_generate_uuid4() . '.zip';
copy($backups[0], $restore_zip);

$job_id = 'rp-loopback-' . wp_generate_uuid4();
$token  = wp_generate_password(32, false, false);
call_private('set_restore_job', [$job_id, [
    'status' => 'queued', 'phase' => 'queued', 'progress' => 0, 'message' => 'Queued',
    'restore_zip_path' => $restore_zip,
    'auto_detect_urls' => true, 'restore_files' => true, 'create_new_admin' => false,
    'token' => $token, 'poll_token' => wp_generate_password(32, false, false),
    'created' => time(),
]]);

echo "\nRunning a real restore and timing the gaps between chunks...\n";
$deadline = time() + 1500;
do {
    call_private('run_restore_job', [$job_id, $token]);
    $job = call_private('get_restore_job', [$job_id]);
    $status = $job['status'] ?? '';
} while (!in_array($status, ['complete', 'error', 'stale'], true) && time() < $deadline);
echo "Restore status: $status\n\n";

// Read only the lines this run appended.
$new = file_get_contents($log_file, false, null, $before_size);
$lines = array_filter(explode("\n", $new), function ($l) use ($job_id) { return strpos($l, $job_id) !== false; });

$gaps = [];
$finished_at = null;
foreach ($lines as $line) {
    if (!preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) UTC\]/', $line, $m)) { continue; }
    $t = strtotime($m[1] . ' UTC');
    if (strpos($line, 'chunk finished, continuing') !== false) { $finished_at = $t; }
    elseif (strpos($line, 'Restore runner started') !== false && $finished_at !== null) {
        $gaps[] = $t - $finished_at;
        $finished_at = null;
    }
}

if (!$gaps) {
    echo "note: this run was driven directly, so no dispatch gaps were recorded\n";
} else {
    echo 'Gaps between chunk-finished and next-runner-started: ' . implode('s, ', $gaps) . "s\n";
    $max = max($gaps);
    $avg = array_sum($gaps) / count($gaps);
    printf("max %ds, average %.1fs across %d chunk(s)\n\n", $max, $avg, count($gaps));
    check('No chunk waits the full 5s cron fallback any more', $max < 5);
}

// Cleanup.
@unlink($restore_zip);
delete_option('restorepilot_restore_job_' . sanitize_key($job_id));
@unlink(call_private('restore_status_file', [$job_id]));
call_private('force_release_restore_locks', [$job_id]);

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
