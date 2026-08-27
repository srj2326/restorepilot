<?php
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
 */

$socket = '/Users/surajitroy/Library/Application Support/Local/run/gKsH4-EmV/mysql/mysqld.sock';
$site   = '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public';
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

// 3. active_plugins naming fixture plugins whose directories are gone. Left
//    alone, WordPress tries to include files that do not exist.
$res = $db->query("SELECT option_value FROM wp_options WHERE option_name = 'active_plugins'");
$row = $res ? $res->fetch_row() : null;
if ($row) {
    $active = @unserialize($row[0]);
    if (is_array($active)) {
        $kept = array_values(array_filter($active, function ($entry) use ($site) {
            return is_string($entry) && is_file($site . '/wp-content/plugins/' . $entry);
        }));
        if (!in_array($self, $kept, true) && is_file($site . '/wp-content/plugins/' . $self)) {
            $kept[] = $self;
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
$storage = $site . '/wp-content/uploads/restorepilot-backup-migration';
$files = 0;
foreach (['restore-status-*.json', 'poll-token-*.txt', 'restore-upload-*.zip'] as $pattern) {
    foreach (glob($storage . '/' . $pattern) ?: [] as $f) { @unlink($f); $files++; }
}
if ($files) { $cleared[] = $files . ' stale file(s)'; }

$db->close();
echo $cleared ? ('  reset: ' . implode(', ', $cleared) . "\n") : '';
exit(0);
