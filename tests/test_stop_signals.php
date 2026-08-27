<?php
/**
 * Cancelling a backup, and ending a stuck restore, both work by writing a new
 * status from a different request and expecting the worker to notice.
 *
 * The worker did not notice. get_option() caches per process, so a worker that
 * read the job when its chunk began kept seeing "running" however many times
 * it checked -- and it checks at every table and every row, which reads as
 * though it stops promptly. In practice nothing stopped until the next chunk
 * started in a fresh process, up to a whole chunk later. For a restore that
 * mattered more than lateness: the locks were already released, so a new
 * restore could start beside one still writing.
 *
 * Every check here writes the new status straight to the database, the way
 * another request would, and then asks the worker's own guard whether it
 * noticed.
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

/** Write a status the way a separate request would: straight to the row. */
function write_status_externally(string $option, array $job): void {
    global $wpdb;
    $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($job)], ['option_name' => $option]);
}

/** The guard is throttled to one real read a second; wait it out. */
function past_throttle(): void { usleep(1100000); }

// ── Backup cancellation ────────────────────────────────────────────────────
echo "=== cancelling a running backup ===\n";
$job_id = 'rp-stop-backup-' . wp_generate_password(6, false, false);
$option = priv('backup_job_option', [$job_id]);
priv('set_backup_job', [$job_id, ['status' => 'running', 'created' => time()]]);

$threw = false;
try { priv('throw_if_backup_cancelled', [$job_id]); } catch (Throwable $e) { $threw = true; }
check('A running backup is not stopped for no reason', !$threw);

write_status_externally($option, ['status' => 'canceled', 'created' => time()]);
past_throttle();

$threw = false; $message = '';
try { priv('throw_if_backup_cancelled', [$job_id]); } catch (Throwable $e) { $threw = true; $message = $e->getMessage(); }
check('THE FIX: the worker notices a cancel written by another request', $threw, $message);
delete_option($option);

// ── Restore abandonment ────────────────────────────────────────────────────
echo "\n=== ending a stuck restore ===\n";
$job_id = 'rp-stop-restore-' . wp_generate_password(6, false, false);
$option = priv('restore_job_option', [$job_id]);
priv('set_restore_job', [$job_id, ['status' => 'running', 'created' => time()]]);

$threw = false;
try { priv('throw_if_restore_abandoned', [$job_id]); } catch (Throwable $e) { $threw = true; }
check('A running restore is not stopped for no reason', !$threw);

// This is exactly what handle_abandon_restore() writes.
write_status_externally($option, ['status' => 'error', 'phase' => 'error', 'created' => time()]);
past_throttle();

$threw = false; $message = '';
try { priv('throw_if_restore_abandoned', [$job_id]); } catch (Throwable $e) { $threw = true; $message = $e->getMessage(); }
check('THE FIX: the worker notices the restore was ended', $threw, $message);
check('And says something a person can act on',
    stripos($message, 'rollback') !== false, $message);
delete_option($option);

// ── Success is not abandonment ─────────────────────────────────────────────
// The abandonment check used to treat 'complete' as one of the statuses
// meaning "an administrator ended this restore". So when one worker finished
// normally and a second was still inside its chunk, the second threw, the
// generic handler caught it, and a restore that had *just succeeded* was
// rewritten as failed -- with a message telling the operator to recover their
// database from a rollback point they did not need. Observed for real in the
// WooCommerce restore log: "Restore completed." followed one second later by
// "Restore failed: This restore was ended before it finished."
echo "\n=== a restore another worker already finished ===\n";
$job_id = 'rp-stop-done-' . wp_generate_password(6, false, false);
$option = priv('restore_job_option', [$job_id]);
priv('set_restore_job', [$job_id, ['status' => 'complete', 'phase' => 'complete', 'created' => time()]]);

$thrown = null;
try { priv('throw_if_restore_abandoned', [$job_id]); } catch (Throwable $e) { $thrown = $e; }

check('A finished job still stops this worker', $thrown !== null);
check('THE FIX: it is reported as already finished, not as abandoned',
    $thrown instanceof RestorePilot_Restore_Already_Finished_Exception,
    $thrown ? get_class($thrown) . ': ' . $thrown->getMessage() : 'nothing thrown');
check('And carries no instruction to recover from a rollback point',
    $thrown !== null && stripos($thrown->getMessage(), 'rollback') === false,
    $thrown ? $thrown->getMessage() : '');

// The status on the record is the part that reaches the operator.
check('The job is left saying complete, not rewritten as an error',
    (priv('get_restore_job', [$job_id, true])['status'] ?? '') === 'complete');
delete_option($option);

// ── The guard has to be cheap: it is called per row ────────────────────────
echo "\n=== cost, since these run per row ===\n";
$job_id = 'rp-stop-cost-' . wp_generate_password(6, false, false);
$option = priv('backup_job_option', [$job_id]);
priv('set_backup_job', [$job_id, ['status' => 'running', 'created' => time()]]);

$queries_before = $wpdb->num_queries;
$t0 = microtime(true);
for ($i = 0; $i < 20000; $i++) { priv('throw_if_backup_cancelled', [$job_id]); }
$elapsed = microtime(true) - $t0;
$queries = $wpdb->num_queries - $queries_before;
printf("  20,000 calls: %.2fs, %d database queries\n", $elapsed, $queries);
check('Throttled rather than one query per call', $queries < 100,
    $queries . ' queries for 20,000 calls');
check('Cheap enough to sit in a row loop', $elapsed < 5.0, sprintf('%.2fs', $elapsed));
delete_option($option);

// ── Switching jobs must not inherit the previous throttle ──────────────────
echo "\n=== a new chunk must not inherit the last one's throttle ===\n";
$a = 'rp-stop-a-' . wp_generate_password(6, false, false);
$b = 'rp-stop-b-' . wp_generate_password(6, false, false);
priv('set_backup_job', [$a, ['status' => 'running', 'created' => time()]]);
priv('set_backup_job', [$b, ['status' => 'canceled', 'created' => time()]]);
priv('throw_if_backup_cancelled', [$a]);   // primes the throttle on job A
$threw = false;
try { priv('throw_if_backup_cancelled', [$b]); } catch (Throwable $e) { $threw = true; }
check('A different job is checked immediately, not skipped by the throttle', $threw);
delete_option(priv('backup_job_option', [$a]));
delete_option(priv('backup_job_option', [$b]));

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
