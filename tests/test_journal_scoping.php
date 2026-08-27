<?php
/**
 * Two restores can overlap in ordinary use: abandoning a stuck restore
 * releases the locks but does not stop the worker already mid-chunk, so the
 * next restore starts while the previous one is still writing.
 *
 * The scratch-table journal used to be a single shared list, so the second
 * restore's opening sweep dropped the first one's live tables and failed it
 * mid-insert with "table doesn't exist". These checks put two jobs in the
 * journal at once and assert the sweep leaves a running one alone.
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
$prefix = $wpdb->prefix;
$TMP = constant('RestorePilot_Backup_Migration::RESTORE_TMP_TABLE_MARKER');

// Real tables, so "was it dropped" is a fact rather than a claim.
function make_table($name) {
    global $wpdb;
    $wpdb->query("CREATE TABLE IF NOT EXISTS `$name` (id INT PRIMARY KEY)");
    return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name));
}
function table_exists_now($name) {
    global $wpdb;
    return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name));
}

$running_job  = 'rp-journal-running-'  . wp_generate_password(6, false, false);
$finished_job = 'rp-journal-finished-' . wp_generate_password(6, false, false);
$new_job      = 'rp-journal-new-'      . wp_generate_password(6, false, false);

$running_table  = $prefix . $TMP . 'running1';
$finished_table = $prefix . $TMP . 'finished1';

check('Fixture: a running restore\'s scratch table exists', make_table($running_table));
check('Fixture: a finished restore\'s scratch table exists', make_table($finished_table));

// One restore still going, one already over.
call_private('set_restore_job', [$running_job,  ['status' => 'running',  'created' => time()]]);
call_private('set_restore_job', [$finished_job, ['status' => 'complete', 'created' => time()]]);

call_private('journal_restore_scratch_tables', [$running_job,  [$running_table]]);
call_private('journal_restore_scratch_tables', [$finished_job, [$finished_table]]);

$journal = get_option(constant('RestorePilot_Backup_Migration::RESTORE_TABLE_JOURNAL_OPTION'), []);
check('Both restores are recorded separately, not one overwriting the other',
    isset($journal[$running_job], $journal[$finished_job]),
    'journal keys: ' . implode(', ', array_keys((array) $journal)));

// A third restore starts and sweeps, exactly as it would in real use.
call_private('sweep_stale_restore_tables', [$prefix, $new_job]);

check('THE BUG: a running restore\'s live table is NOT dropped',
    table_exists_now($running_table),
    table_exists_now($running_table) ? 'still there, as it must be' : 'it was dropped mid-restore');
check('A finished restore\'s leftover table IS dropped',
    !table_exists_now($finished_table));

$after = get_option(constant('RestorePilot_Backup_Migration::RESTORE_TABLE_JOURNAL_OPTION'), []);
check('The running restore stays in the journal after the sweep',
    isset($after[$running_job]));
check('The finished restore is removed from the journal',
    !isset($after[$finished_job]));

// Clearing one restore's entry must not disturb another's.
call_private('journal_restore_scratch_tables', [$new_job, [$prefix . $TMP . 'new1']]);
call_private('clear_restore_table_journal', [$new_job]);
$after2 = get_option(constant('RestorePilot_Backup_Migration::RESTORE_TABLE_JOURNAL_OPTION'), []);
check('Clearing one restore\'s journal leaves another\'s intact',
    isset($after2[$running_job]) && !isset($after2[$new_job]));

// Cleanup.
foreach ([$running_table, $finished_table] as $t) { $wpdb->query("DROP TABLE IF EXISTS `$t`"); }
foreach ([$running_job, $finished_job, $new_job] as $j) {
    delete_option('restorepilot_restore_job_' . sanitize_key($j));
}
delete_option(constant('RestorePilot_Backup_Migration::RESTORE_TABLE_JOURNAL_OPTION'));

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
