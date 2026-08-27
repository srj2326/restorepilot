<?php
/**
 * A worker's own progress update could undo an operator's cancel.
 *
 * update_backup_job() reads the job, merges the changes in, and writes the
 * whole record back. The read went through the option cache, so a worker that
 * read the job when its chunk began kept merging over a snapshot taken before
 * the cancel was written -- and writing "running" back over it. Cancelling did
 * not fail to be noticed; it was overwritten, by the very worker it was meant
 * to stop, within five seconds of being clicked.
 *
 * That matters more than it first sounds, because it sits upstream of the
 * cancel check. Making that check read past the cache is correct and does
 * nothing on its own: it reads the database faithfully, and finds "running"
 * there because the worker put it back.
 *
 * Two defences are tested here, because either alone leaves a hole:
 *   - the re-read before merging is fresh, so the merge starts from what is
 *     actually stored rather than from what this process last saw;
 *   - a status the operator set is not silently replaced by a merge that was
 *     never about the status at all, which still holds if the write lands in
 *     the moment between another process's read and its write.
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
function priv($name, $args = []) {
    $m = new ReflectionMethod('RestorePilot_Backup_Migration', $name);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
}

global $wpdb;

/** What another request would do: write straight to the row, behind this process's cache. */
function write_externally(string $option, array $job): void {
    global $wpdb;
    $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($job)], ['option_name' => $option]);
}

/** What is actually stored, regardless of what this process believes. */
function stored(string $option): array {
    global $wpdb;
    $raw = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option));
    $val = maybe_unserialize((string) $raw);
    return is_array($val) ? $val : [];
}

// ── Backup: a cancel must survive the worker's next progress update ─────────
echo "=== a cancel, against the worker's own progress updates ===\n";
$job_id = 'rp-lost-backup-' . wp_generate_password(6, false, false);
$option = priv('backup_job_option', [$job_id]);
priv('set_backup_job', [$job_id, [
    'status' => 'running', 'progress' => 10, 'message' => 'Working', 'created' => time(),
]]);

// The worker reads the job as its chunk begins. This is the read that used to
// poison everything after it.
$seen = priv('get_backup_job', [$job_id]);
check('The worker starts from a running job', ($seen['status'] ?? '') === 'running');

// The operator cancels, from a different request.
write_externally($option, ['status' => 'canceled', 'progress' => 10, 'message' => 'Canceled', 'created' => time()]);
check('The cancel reached the database', (stored($option)['status'] ?? '') === 'canceled');

// The worker ticks its progress forward, saying nothing about status.
priv('update_backup_job', [$job_id, ['progress' => 40, 'message' => 'Still working']]);

check('THE FIX: the worker\'s progress update does not resurrect the cancelled job',
    (stored($option)['status'] ?? '') === 'canceled',
    'status is now: ' . var_export(stored($option)['status'] ?? null, true));
check('...and the progress it was actually trying to record still landed',
    (int) (stored($option)['progress'] ?? 0) === 40);

// And the whole point: the guard downstream now sees the cancel.
usleep(1100000); // past the guard's one-second throttle
$threw = false;
try { priv('throw_if_backup_cancelled', [$job_id]); } catch (Throwable $e) { $threw = true; }
check('End to end: the worker stops, which is what the button is for', $threw);
delete_option($option);

// ── The same, for a restore that was ended ─────────────────────────────────
echo "\n=== a restore ended from the maintenance page ===\n";
$job_id = 'rp-lost-restore-' . wp_generate_password(6, false, false);
$option = priv('restore_job_option', [$job_id]);
priv('set_restore_job', [$job_id, [
    'status' => 'running', 'phase' => 'database', 'progress' => 50, 'created' => time(),
]]);
priv('get_restore_job', [$job_id]);  // worker's chunk-start read

write_externally($option, ['status' => 'error', 'phase' => 'error', 'progress' => 50, 'created' => time()]);
priv('update_restore_job', [$job_id, ['progress' => 70, 'message' => 'Importing tables']]);

check('THE FIX: an ended restore is not put back to running by a progress update',
    (stored($option)['status'] ?? '') === 'error',
    'status is now: ' . var_export(stored($option)['status'] ?? null, true));

// set_restore_job() also writes a status file, which polling falls back to
// when the database record is gone. Reviving the record but not the file --
// or the reverse -- would leave the two disagreeing about whether the restore
// is still going.
$from_file = priv('read_restore_status_file', [$job_id]);
check('The status file agrees with the database, rather than still saying running',
    ($from_file['status'] ?? 'error') === 'error',
    'file says: ' . var_export($from_file['status'] ?? null, true));
delete_option($option);

// ── A status the caller does mean to set must still be set ─────────────────
// The protection above must not turn into "a terminal job can never change",
// or a finished restore could never be marked complete and a retry could
// never restart a failed one.
echo "\n=== deliberate status changes still work ===\n";
$job_id = 'rp-lost-explicit-' . wp_generate_password(6, false, false);
$option = priv('backup_job_option', [$job_id]);
priv('set_backup_job', [$job_id, ['status' => 'running', 'progress' => 90, 'created' => time()]]);
priv('update_backup_job', [$job_id, ['status' => 'complete', 'progress' => 100]]);
check('A worker can still mark its own job complete',
    (stored($option)['status'] ?? '') === 'complete');

priv('update_backup_job', [$job_id, ['status' => 'running', 'progress' => 0]]);
check('And a terminal job can still be explicitly restarted, not frozen forever',
    (stored($option)['status'] ?? '') === 'running');
delete_option($option);

// ── The merge itself must start from what is stored ────────────────────────
// Distinct from the status protection: a field this process never knew about
// must not be erased just because its cached copy predates it.
echo "\n=== the merge starts from what is stored, not from a stale copy ===\n";
$job_id = 'rp-lost-merge-' . wp_generate_password(6, false, false);
$option = priv('backup_job_option', [$job_id]);
priv('set_backup_job', [$job_id, ['status' => 'running', 'progress' => 10, 'created' => time()]]);
priv('get_backup_job', [$job_id]);  // cache it

write_externally($option, [
    'status' => 'running', 'progress' => 10, 'created' => time(),
    'checkpoint' => 'table-42',   // written by another worker; this process has never seen it
]);
priv('update_backup_job', [$job_id, ['message' => 'Continuing']]);
check('A field another worker wrote is not erased by this one\'s merge',
    (stored($option)['checkpoint'] ?? '') === 'table-42',
    'checkpoint is now: ' . var_export(stored($option)['checkpoint'] ?? null, true));
delete_option($option);

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
