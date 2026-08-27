<?php
/**
 * A worker reads the job record before it takes the lock, and must read it
 * again afterwards -- because by then another worker may have finished a chunk
 * and moved the checkpoint on. Reading it again is not enough on its own:
 * get_option() caches per process, so the second read returns the first read's
 * copy and the worker resumes from a position already completed. That is what
 * put two workers on the same chunk and collided them on a duplicate key.
 *
 * These checks exercise the caching directly, because the broken version and
 * the fixed one are indistinguishable by reading the code.
 */

define('WP_USE_THEMES', false);
require_once '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public/wp-load.php';

$failures = [];
function check(string $label, bool $ok, string $detail = '') {
    global $failures;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if ($detail !== '') { echo '        ' . $detail . "\n"; }
    if (!$ok) { $failures[] = $label; }
}
function call_private($name, $args = []) {
    $m = new ReflectionMethod('RestorePilot_Backup_Migration', $name);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
}

global $wpdb;

foreach (['restore', 'backup'] as $kind) {
    echo "=== $kind job ===\n";
    $job_id = "rp-freshread-$kind-" . wp_generate_password(8, false, false);
    $option = call_private($kind . '_job_option', [$job_id]);

    call_private('set_' . $kind . '_job', [$job_id, [
        'status' => 'running',
        'checkpoint' => ['resumption' => 1],
        'created' => time(),
    ]]);

    // What a worker does before taking the lock: reads, and caches.
    $before = call_private('get_' . $kind . '_job', [$job_id]);
    check("$kind: pre-lock read sees resumption 1",
        (int) ($before['checkpoint']['resumption'] ?? 0) === 1);

    // Another worker finishes a chunk and advances the record, writing straight
    // to the database the way a separate process would -- this process's cache
    // knows nothing about it.
    $stored = get_option($option);
    $stored['checkpoint']['resumption'] = 2;
    $wpdb->update($wpdb->options,
        ['option_value' => maybe_serialize($stored)],
        ['option_name' => $option]);

    // An ordinary re-read still answers from cache. Demonstrated, not assumed.
    $plain = call_private('get_' . $kind . '_job', [$job_id]);
    check("$kind: a plain re-read is stale (this is why \$fresh exists)",
        (int) ($plain['checkpoint']['resumption'] ?? 0) === 1,
        'plain re-read returned resumption ' . ($plain['checkpoint']['resumption'] ?? '?'));

    // THE FIX: the post-lock read must see what is actually in the database.
    $fresh = call_private('get_' . $kind . '_job', [$job_id, true]);
    check("$kind: THE FIX -- a fresh read sees resumption 2",
        (int) ($fresh['checkpoint']['resumption'] ?? 0) === 2,
        'fresh read returned resumption ' . ($fresh['checkpoint']['resumption'] ?? '?'));

    delete_option($option);
    echo "\n";
}

// The worker itself must use the fresh form; passing $fresh only where it is
// harmless would leave the original bug in place.
$src = '';
foreach (glob('/Users/surajitroy/Local Sites/morecalculators-dev/app/public/wp-content/plugins/restorepilot-backup-migration/includes/*.php') as $f) {
    $src .= file_get_contents($f);
}
check('run_restore_job re-reads with $fresh after taking the lock',
    strpos($src, 'get_restore_job($job_id, true)') !== false);
check('run_backup_job re-reads with $fresh after taking the lock',
    strpos($src, 'get_backup_job($job_id, true)') !== false);

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
