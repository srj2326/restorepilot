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

require_once __DIR__ . '/env.php';

/**
 * Returns the test site to a state a test can start from.
 *
 * A test that dies partway through a restore leaves maintenance mode on, locks
 * held, and active_plugins naming fixture plugins whose files it already
 * deleted. Every test after it then dies inside wp-load and is recorded as its
 * own failure -- one casualty reported today as five, and the real one was the
 * first, not the loudest.
 *
 * Deliberately talks to the database directly and does not boot WordPress:
 * booting is the thing that fails when the site is in this state.
 *
 * Usage: reset_site_state.php [plugin/plugin.php ...]
 *   Any extra arguments name plugins to leave active, on top of RestorePilot
 *   itself -- the preconditions of the test that is about to run.
 */

$socket = rp_test_socket();
$site   = rp_test_site();
$self   = 'restorepilot-backup-migration/restorepilot-backup-migration.php';

$db = @new mysqli('localhost', 'root', 'root', 'local', null, $socket);
if ($db->connect_errno) {
    fwrite(STDERR, "reset: cannot reach the database: {$db->connect_error}\n");
    exit(1);
}

$cleared = [];

// 0. Stop anything still running BEFORE deleting the state it depends on.
//
// A test's restore does not end when the test process does: the loopback
// hands each chunk to a background HTTP worker, and those keep going. Deleting
// their job records out from under them leaves them writing into a world that
// has been reset, which then breaks the *next* test rather than the one that
// spawned them.
//
// Marking the job terminal first is what actually stops the chain --
// run_restore_job() bails on a terminal status -- which is the same order
// handle_abandon_restore() uses, and for the same reason.
$running = $db->query("SELECT option_name, option_value FROM wp_options
                       WHERE option_name LIKE 'restorepilot_restore_job_%'
                          OR option_name LIKE 'restorepilot_backup_job_%'");
$marked = 0;
while ($running && ($row = $running->fetch_assoc())) {
    $job = @unserialize($row['option_value']);
    if (!is_array($job)) { continue; }
    $status = (string) ($job['status'] ?? '');
    if (!in_array($status, ['queued', 'running'], true)) { continue; }
    $job['status'] = 'error';
    $job['phase'] = 'error';
    $job['message'] = 'Ended by the test harness between tests.';
    $stmt = $db->prepare('UPDATE wp_options SET option_value = ? WHERE option_name = ?');
    $ser = serialize($job);
    $stmt->bind_param('ss', $ser, $row['option_name']);
    $stmt->execute();
    $marked++;
}
if ($marked) {
    $cleared[] = $marked . ' running job(s) stopped';
    // Give a worker already inside a chunk time to reach its next boundary and
    // see the terminal status, rather than racing it to the delete below.
    sleep(2);
}

// 1. Maintenance mode, both halves: the drop-in file and the option the
//    plugin's own gate reads. Missing the option is what left the site
//    serving the maintenance page after the file was deleted.
if (file_exists($site . '/.maintenance')) {
    @unlink($site . '/.maintenance');
    $cleared[] = '.maintenance';
}

$options = [
    "option_name LIKE 'restorepilot_restore_job_%'",
    "option_name LIKE 'restorepilot_backup_job_%'",
    "option_name LIKE '%restore_lock%'",
    "option_name LIKE '%backup_lock%'",
    "option_name LIKE 'restorepilot_restore_worker_%'",
    "option_name LIKE 'restorepilot_backup_worker_%'",
    "option_name = 'restorepilot_maintenance_until'",
    "option_name = 'restorepilot_restore_table_journal'",
    "option_name = 'restorepilot_deferred_active_plugins'",
    "option_name = 'restorepilot_restore_success_notice'",
];
$db->query('DELETE FROM wp_options WHERE ' . implode(' OR ', $options));
if ($db->affected_rows > 0) { $cleared[] = $db->affected_rows . ' option(s)'; }

// 2. Scratch tables from a restore that never finished. Left in place they
//    both consume space and confuse the next restore's own sweep.
$res = $db->query("SELECT table_name FROM information_schema.tables
                   WHERE table_schema = 'local'
                     AND (table_name LIKE '%restorepilot_rtmp%' OR table_name LIKE '%restorepilot_rpold%')");
$dropped = 0;
while ($res && ($row = $res->fetch_row())) {
    $db->query('DROP TABLE IF EXISTS `' . $row[0] . '`');
    $dropped++;
}
if ($dropped) { $cleared[] = $dropped . ' scratch table(s)'; }

// 3. active_plugins is SET, not merely pruned.
//
//    Pruning entries whose files are gone is necessary -- WordPress tries to
//    include them and dies -- but it only ever removes, and a plugin whose
//    files exist survives forever once anything activates it. That is not
//    hypothetical: test_woocommerce_restore restores a backup taken with
//    WooCommerce active, so WooCommerce stayed active for every test that
//    followed, and Action Scheduler's 'shutdown' hook then queried a database
//    connection those test processes had already closed and died there --
//    after their own checks had passed. Adding preconditions without also
//    removing what the previous test left behind fixed nothing.
//
//    So the list becomes exactly RestorePilot plus whatever this test asked
//    for, and each test starts from the same known set rather than from the
//    residue of the one before it.
$res = $db->query("SELECT option_value FROM wp_options WHERE option_name = 'active_plugins'");
$row = $res ? $res->fetch_row() : null;
if ($row) {
    $active = @unserialize($row[0]);
    if (is_array($active)) {
        $baseline = array_merge([$self], array_slice($argv, 1));
        $kept = [];
        foreach ($baseline as $entry) {
            // Never name a file that is not there; that is the failure the
            // pruning existed to prevent, and it applies to the baseline too.
            if (is_string($entry) && $entry !== '' && !in_array($entry, $kept, true)
                && is_file($site . '/wp-content/plugins/' . $entry)) {
                $kept[] = $entry;
            }
        }
        if ($kept !== $active) {
            $stmt = $db->prepare("UPDATE wp_options SET option_value = ? WHERE option_name = 'active_plugins'");
            $ser = serialize($kept);
            $stmt->bind_param('s', $ser);
            $stmt->execute();
            $cleared[] = 'active_plugins (' . count($active) . ' -> ' . count($kept) . ')';
        }
    }
}

// 4. Status and token files belonging to jobs that no longer exist.
// Read from the option rather than assumed, because backups no longer live
// under uploads: they are kept beside the site now, where the web server has
// no URL for them. Left hardcoded, this quietly stopped cleaning anything at
// all -- status files, poll tokens and abandoned upload zips accumulated
// across the whole suite, and the first test to notice was one whose timing
// depends on starting from a clean directory.
$storage = $site . '/wp-content/uploads/restorepilot-backup-migration';
$res = $db->query("SELECT option_value FROM wp_options WHERE option_name = 'restorepilot_storage_path'");
$row = $res ? $res->fetch_row() : null;
if ($row && is_string($row[0]) && $row[0] !== '' && is_dir($row[0])) {
    $storage = rtrim($row[0], '/');
}
$files = 0;
foreach (['restore-status-*.json', 'poll-token-*.txt', 'restore-upload-*.zip'] as $pattern) {
    foreach (glob($storage . '/' . $pattern) ?: [] as $f) { @unlink($f); $files++; }
}
if ($files) { $cleared[] = $files . ' stale file(s)'; }

$db->close();
echo $cleared ? ('  reset: ' . implode(', ', $cleared) . "\n") : '';
exit(0);
