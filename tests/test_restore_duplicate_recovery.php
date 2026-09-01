<?php
/**
 * Forces the collision instead of waiting for it, and checks the restore
 * survives.
 *
 * The unit test proves restore_row_already_present() answers correctly. That
 * is not the same as proving the restore reaches it and carries on, and five
 * green runs of the sequence that used to fail proved nothing either: the log
 * recorded no duplicates at all, so the path may simply never have been taken.
 * An intermittent fault does not get to be declared fixed by runs that did not
 * reproduce it.
 *
 * So the collision is manufactured, using the resume logic's own arithmetic. A
 * worker returning to a half-written scratch table sets
 *
 *     $skip_remaining = SELECT COUNT(*) FROM tmp_table
 *
 * and skips that many rows of the export. Delete one row from the middle of a
 * live scratch table and the count drops by one, so the next chunk skips one
 * fewer row than it actually wrote -- and writes the last one again. That is
 * precisely what two workers do to each other, arrived at deliberately.
 *
 * What this does and does not show, having been run both ways:
 *
 *   - it reaches the fix, and the fix does its work -- the log line asserted at
 *     the end is written only when a row was found already present;
 *   - it does NOT prove the fix is necessary. Run against the commit before it,
 *     the restore still completed: that run staged its collision on a 39-row
 *     table already finished by the time the next chunk read it, where the
 *     fixed run used one of 10,261 rows. Which table is picked, and whether a
 *     collision follows, varies per run.
 *
 * Left in as a check that a restore survives a scratch table that no longer
 * matches its own row count, which is worth having on its own. Making it
 * reliably reproduce the collision is unfinished work.
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
$log_file = priv('log_file');

// A full backup, not a database-only one. The obvious shortcut here is
// create_backup_package(false), which is faster and is refused on the way back
// in: "This does not look like a complete RestorePilot backup." That check is
// right -- a restore should not accept half an archive -- and the shortcut was
// wrong. The database phase runs first regardless, so the scratch tables this
// needs appear early.
echo "Taking a full backup...\n";
$backup = priv('create_backup_package', [true]);
$backup_path = !empty($backup['file'])
    ? rtrim(priv('backup_dir'), '/') . '/' . $backup['file']
    : '';
check('Fixture backup created', $backup_path !== '' && is_file($backup_path));
if ($backup_path === '') {
    echo "\n1 FAILURE(S): no backup to restore\n";
    exit(1);
}

$restore_zip = priv('storage_dir') . '/restore-upload-' . wp_generate_uuid4() . '.zip';
copy($backup_path, $restore_zip);
$job_id = 'rp-dup-' . wp_generate_uuid4();
$token  = wp_generate_password(32, false, false);
priv('set_restore_job', [$job_id, [
    'status' => 'queued', 'phase' => 'queued', 'progress' => 0, 'message' => 'Queued',
    'restore_zip_path' => $restore_zip,
    'auto_detect_urls' => true, 'restore_files' => true, 'create_new_admin' => false,
    'token' => $token, 'poll_token' => wp_generate_password(32, false, false), 'created' => time(),
]]);

register_shutdown_function(function () use ($restore_zip, $job_id) {
    @unlink($restore_zip);
    delete_option('restorepilot_restore_job_' . sanitize_key($job_id));
    priv('force_release_restore_locks', [$job_id]);
});

// Emptied rather than measured. The obvious approach is to remember the length
// now and read from there afterwards, but write_log() trims the file once it
// passes its cap -- which a restore this long does -- and every offset into it
// then points somewhere else.
if (is_file($log_file)) { file_put_contents($log_file, ''); }

// ── Run chunks until a scratch table has rows worth deleting from ──────────
echo "\nRunning until a scratch table has rows...\n";
$scratch = '';
$scratch_create = '';
$deadline = time() + 900;
$chunks = 0;
do {
    priv('run_restore_job', [$job_id, $token]);
    $chunks++;
    $status = priv('get_restore_job', [$job_id, true])['status'] ?? '';

    // The table currently being written, which is the highest-numbered one --
    // not just any scratch table with rows in it. A table the restore has
    // already finished is recorded in the checkpoint's completed_tables and is
    // never revisited, so removing a row from one of those changes nothing
    // except the restored data. That is what the first version of this test
    // did: it deleted from a completed table, the restore sailed past without
    // ever looking, and the pass meant nothing.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $candidates = $wpdb->get_col("SHOW TABLES LIKE '" . $wpdb->esc_like($wpdb->prefix) . "%restorepilot\\_rtmp\\_%'");
    // It also has to have a primary key. Plenty of plugin tables have none: the
    // first in-progress table this found had 4002 rows and no key at all, so
    // there was nothing for a second write to collide on. Where there is no key
    // the fix deliberately declines to act, which is correct and cannot be
    // demonstrated by staging a collision.
    $best_index = -1;
    foreach ((array) $candidates as $t) {
        if (!preg_match('/_(\d+)$/', $t, $m)) { continue; }
        $index = (int) $m[1];
        if ($index <= $best_index) { continue; }
        $n = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$t`");
        if ($n < 5) { continue; }
        $create_row = $wpdb->get_row("SHOW CREATE TABLE `$t`", ARRAY_N);
        $create_sql = is_array($create_row) && isset($create_row[1]) ? (string) $create_row[1] : '';
        if (!priv('primary_key_columns', [$create_sql])) { continue; }
        $scratch = $t;
        $best_index = $index;
        $scratch_create = $create_sql;
    }
} while (($scratch === '' || $chunks < 2) && !in_array($status, ['complete', 'error', 'stale'], true) && time() < $deadline);

printf("after %d chunk(s): status=%s, scratch table=%s\n", $chunks, $status, $scratch ?: '(none found)');
check('A scratch table with rows exists mid-restore', $scratch !== '');
check('The restore is still in progress, so there is something to collide with',
    !in_array($status, ['complete', 'error', 'stale'], true), 'status: ' . $status);

if ($scratch === '' || in_array($status, ['complete', 'error', 'stale'], true)) {
    echo "\nSKIP  the restore finished before a collision could be staged\n";
    echo "\n" . count($failures) . " FAILURE(S)\n";
    exit(1);
}

// ── Stage the collision ────────────────────────────────────────────────────
$before = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$scratch`");
$key_cols = priv('primary_key_columns', [$scratch_create]);
$victim_key = $key_cols ? $key_cols[0] : '';
check('The scratch table has a primary key to collide on', $victim_key !== '');

// A row from the middle, so the rows on either side of it stay put and the
// count is the only thing that moves.
$mid = max(1, (int) floor($before / 2));
$victim = $wpdb->get_var("SELECT `$victim_key` FROM `$scratch` ORDER BY `$victim_key` LIMIT 1 OFFSET $mid");
$wpdb->query($wpdb->prepare("DELETE FROM `$scratch` WHERE `$victim_key` = %s", $victim));
$after = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$scratch`");
$still_there = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM `$scratch` WHERE `$victim_key` = %s", $victim));
printf("staged: %s had %d rows, removed %s=%s, now %d\n", $scratch, $before, $victim_key, $victim, $after);

// Deliberately NOT asserting that the count fell by exactly one. It does not:
// a previous run of this printed "had 16 rows, removed one, now 18", because a
// second worker was inserting into the same table between the test's own two
// queries. That is the collision this test exists for, seen directly -- so the
// count is not something to assert on, and the row's absence is.
check('The chosen row is gone, so the resume count no longer matches what was written',
    $still_there === 0, sprintf('%d rows before, %d after', $before, $after));

// ── Carry on. The next chunk skips one row too few and rewrites the last. ──
echo "\nContinuing the restore...\n";
$deadline = time() + 1800;
do {
    priv('run_restore_job', [$job_id, $token]);
    $job = priv('get_restore_job', [$job_id, true]);
    $status = $job['status'] ?? '';
} while (!in_array($status, ['complete', 'error', 'stale'], true) && time() < $deadline);

printf("final status: %s\n", $status);
$message = (string) ($job['message'] ?? '');

// Not labelled as proof of the fix. Run against the commit before it, this
// check still passed: that run happened to stage its collision on a 39-row
// table which had finished before the next chunk looked at it, where the fixed
// run used one of 10,261 rows. Which table is chosen, and whether a collision
// actually follows, varies per run -- so this says the restore survives a
// staged inconsistency, and no more than that.
check('The restore finishes despite the scratch table no longer matching what was written',
    $status === 'complete', $message);

// The exact error from the real log, which must no longer appear.
check('It does not fail on a duplicate key',
    stripos($message, 'duplicate') === false, $message);

// ── And it says so, rather than passing over it in silence ─────────────────
$new_log = is_file($log_file) ? (string) file_get_contents($log_file) : '';
// This one does discriminate: it can only appear when a row was found already
// present, which is the fix being reached and doing its work. It is the single
// assertion here that failed against the pre-fix code.
check('A repeat, when it happens, is reached by the fix and recorded',
    stripos($new_log, 'already present in') !== false,
    'the log line only exists when restore_row_already_present() returned true');

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
