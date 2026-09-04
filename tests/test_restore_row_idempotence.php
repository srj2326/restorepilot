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
 * A restore that redoes part of a chunk must not fail because of it.
 *
 * The restore is resumable but was not idempotent. It records where to carry
 * on by counting the rows it has already written, then writes the next one
 * with a plain INSERT -- which only holds if a row is never written twice.
 *
 * It is written twice routinely. dispatch_restore_worker() sends a
 * fire-and-forget loopback request AND schedules a cron fallback five seconds
 * later, while a chunk runs for about twenty; so a second worker arrives at
 * every chunk boundary of every restore, and whether it lands is down to
 * whether a non-blocking HTTP request happened to be delivered. When it did,
 * the two workers met on the same scratch table and the restore died with
 * "Duplicate entry" -- then died again with "Table doesn't exist", because the
 * first worker's error handler had dropped the scratch tables the second was
 * still writing to.
 *
 * Both failures come from the same assumption. The fix is not to make the race
 * impossible -- a resumable process cannot rely on never repeating itself --
 * but to make repeating harmless: a row that is already present is the outcome
 * the insert wanted, so it is not an error. Every other database error still
 * is, which is the part worth pinning down, since a fix that swallows real
 * problems would be worse than the bug.
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
$table = $wpdb->prefix . 'rp_idem_test';
$create = "CREATE TABLE `$table` (\n"
        . "  `id` bigint(20) unsigned NOT NULL,\n"
        . "  `title` text,\n"
        . "  PRIMARY KEY (`id`)\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$wpdb->query("DROP TABLE IF EXISTS `$table`");
$wpdb->query($create);
register_shutdown_function(function () use ($wpdb, $table) {
    $wpdb->query("DROP TABLE IF EXISTS `$table`");
});

$plan = ['tmp_table' => $table, 'create' => $create, 'row_count' => 0];

// ── The helper the fix rests on ────────────────────────────────────────────
echo "=== recognising a row that is already there ===\n";
$row = ['id' => 42, 'title' => 'hello'];

check('A row that has never been written is not reported as present',
    priv('restore_row_already_present', [$plan, $row]) === false);

$wpdb->insert($table, $row);
check('THE FIX: a row already in the table is recognised as already restored',
    priv('restore_row_already_present', [$plan, $row]) === true);

// The identity is the key, not the payload: a second worker replaying the same
// source row writes the same primary key, and that is what makes it a repeat.
check('Recognised by primary key, not by the whole row matching',
    priv('restore_row_already_present', [$plan, ['id' => 42, 'title' => 'different']]) === true);

check('A different key is still absent',
    priv('restore_row_already_present', [$plan, ['id' => 43, 'title' => 'hello']]) === false);

// ── It must not paper over a real problem ──────────────────────────────────
// This is the part that matters most. A fix that treats every failed insert as
// "probably fine" would hide genuine corruption behind a restore that reports
// success, which is worse than the bug it replaces.
echo "\n=== other database errors are still errors ===\n";
$no_key_table = $wpdb->prefix . 'rp_idem_nokey';
$no_key_create = "CREATE TABLE `$no_key_table` (`n` int) ENGINE=InnoDB";
$wpdb->query("DROP TABLE IF EXISTS `$no_key_table`");
$wpdb->query($no_key_create);
register_shutdown_function(function () use ($wpdb, $no_key_table) {
    $wpdb->query("DROP TABLE IF EXISTS `$no_key_table`");
});

check('A table with no key at all reports nothing as already present',
    priv('restore_row_already_present', [
        ['tmp_table' => $no_key_table, 'create' => $no_key_create, 'row_count' => 0],
        ['n' => 1],
    ]) === false,
    'without a key there is no way to tell a repeat from a new row, so the error must stand');

// A row whose key column is missing from the payload cannot be identified.
check('A row missing its key column reports nothing as already present',
    priv('restore_row_already_present', [$plan, ['title' => 'no id here']]) === false);

// A table that has been dropped -- the second failure seen in the real log --
// must not be mistaken for "the row is already there".
$wpdb->query("DROP TABLE IF EXISTS `$no_key_table`");
check('A missing table is not mistaken for a row already being present',
    priv('restore_row_already_present', [
        ['tmp_table' => $no_key_table, 'create' => $no_key_create, 'row_count' => 0],
        ['n' => 1],
    ]) === false);

// ── Locale independence ────────────────────────────────────────────────────
// The obvious implementation reads $wpdb->last_error for "Duplicate entry",
// which is an English string MySQL is free to translate. This asks the table
// instead, so it holds wherever the server is configured.
echo "\n=== it asks the table, not the error message ===\n";
// Comments are stripped first. The assertion is about what the code does, and
// the docblock explaining why we do NOT match that string would otherwise fail
// its own check -- which it did, the first time this ran.
$code = '';
foreach (['/includes/trait-restore.php', '/includes/trait-database.php'] as $file) {
    foreach (token_get_all(file_get_contents(dirname(__DIR__) . $file)) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) { continue; }
            $code .= $token[1];
        } else {
            $code .= $token;
        }
    }
}
check('The decision does not depend on parsing an error string',
    stripos($code, 'Duplicate entry') === false,
    'a localised MySQL server would defeat a string match');

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
