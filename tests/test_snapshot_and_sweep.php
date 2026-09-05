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
 * Two things a backup said about itself that were not checked.
 *
 * RP-005. The export opened a consistent snapshot and then cleared the error
 * state on the very next line, so a snapshot that never opened looked exactly
 * like one that did. The archive was labelled consistent either way. For a
 * backup that is not a reporting detail: without a snapshot, tables are read
 * one after another, and a site being written to during the export can produce
 * an archive whose tables disagree -- while the operator has been told the
 * opposite.
 *
 * RP-022. Scratch tables are named by truncating the site prefix to keep the
 * unique suffix inside MySQL's 64-character identifier limit. The sweep that
 * removes leftovers required the name to begin with the whole, untruncated
 * prefix -- which for any prefix long enough to need truncating can never be
 * true. Those tables were never dropped, and the journal naming them was
 * cleared immediately afterwards, so nothing remained that knew they existed.
 * The exporter skips them by marker, so they would sit in the database forever
 * without showing up anywhere.
 */

require_once __DIR__ . '/env.php';
rp_test_boot();

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
function konst(string $name) {
    return (new ReflectionClass('RestorePilot_Backup_Migration'))->getConstant($name);
}

global $wpdb;
$TMP = konst('RESTORE_TMP_TABLE_MARKER');
$OLD = konst('RESTORE_OLD_TABLE_MARKER');

// ── RP-022: which names the sweep will claim as its own ────────────────────
echo "=== recognising our own scratch tables ===\n";

$short = 'wp_';
$name_short = priv('temporary_table_name', [$short, 'abc123', 0]);
check('A name built with a short prefix is recognised',
    priv('is_own_scratch_table_name', [$name_short, $short]) === true, $name_short);

// The case that could never work. Long enough that the generator has to cut
// into the prefix to fit the suffix.
$long = 'wp_a_very_long_site_table_prefix_for_this_installation_';
$name_long = priv('temporary_table_name', [$long, 'abc123', 0]);
printf("  long prefix (%d chars) produces: %s (%d chars)\n", strlen($long), $name_long, strlen($name_long));
check('The generated name really is truncated, so this is the case that mattered',
    strpos($name_long, $long) !== 0);
check('THE FIX: a truncated name is still recognised as ours',
    priv('is_own_scratch_table_name', [$name_long, $long]) === true, $name_long);

$name_old = priv('old_table_name', [$long, 'abc123', 3]);
check('Both markers are recognised, not only the temporary one',
    priv('is_own_scratch_table_name', [$name_old, $long]) === true, $name_old);

// ── And it must not claim anything else ────────────────────────────────────
echo "\n=== and refusing everything else ===\n";
check('A table belonging to another site prefix is not ours',
    priv('is_own_scratch_table_name', ['other_' . $TMP . 'abc123_0', 'wp_']) === false);
check('An ordinary table is not ours',
    priv('is_own_scratch_table_name', ['wp_posts', 'wp_']) === false);
// Ownership is the marker behind this site's prefix, not a guessed tail
// format -- matching the exact shape the current generator makes would orphan
// leftovers from any earlier one, which is the failure this sweep exists to
// prevent. Unsafe identifiers are still refused outright.
check('A name with characters no identifier may contain is not ours',
    priv('is_own_scratch_table_name', ['wp_' . $TMP . 'not a valid tail', 'wp_']) === false);
check('The marker with nothing after it is not ours',
    priv('is_own_scratch_table_name', ['wp_' . $TMP, 'wp_']) === false);
check('An identifier with unsafe characters is refused outright',
    priv('is_own_scratch_table_name', ['wp_' . $TMP . 'abc;DROP', 'wp_']) === false);

// ── The sweep actually drops one, end to end ───────────────────────────────
echo "\n=== sweeping a leftover from a long-prefix install ===\n";
$victim = priv('temporary_table_name', [$wpdb->prefix . 'padding_padding_padding_padding_', 'sweep01', 7]);
$victim = substr($victim, 0, 64);
$wpdb->query("DROP TABLE IF EXISTS `$victim`");
$wpdb->query("CREATE TABLE `$victim` (`id` int) ENGINE=InnoDB");
check('A leftover scratch table exists to be swept', priv('table_exists', [$victim]), $victim);

update_option(konst('RESTORE_TABLE_JOURNAL_OPTION'), ['rp-gone-job' => [$victim]], false);
priv('sweep_stale_restore_tables', [$wpdb->prefix . 'padding_padding_padding_padding_', '']);

check('THE FIX: it is dropped rather than orphaned', !priv('table_exists', [$victim]), $victim);
check('And the journal no longer names it',
    get_option(konst('RESTORE_TABLE_JOURNAL_OPTION'), []) === [] || !in_array($victim, (array) (get_option(konst('RESTORE_TABLE_JOURNAL_OPTION'), [])['rp-gone-job'] ?? []), true));
$wpdb->query("DROP TABLE IF EXISTS `$victim`");
delete_option(konst('RESTORE_TABLE_JOURNAL_OPTION'));

// ── RP-005: the snapshot is checked, and recorded ──────────────────────────
echo "\n=== what the backup says about its own consistency ===\n";
$src = file_get_contents(dirname(__DIR__) . '/includes/trait-backup.php');

// The defect was ordering: last_error cleared after the statement, throwing
// away the only evidence of whether it worked.
$clear_before = strpos($src, "\$wpdb->last_error = '';\n      \$snapshot_started = \$wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT')");
check('THE FIX: the error state is cleared before the snapshot, not after',
    $clear_before !== false,
    'clearing it afterwards is what made a failed snapshot invisible');
check('The result of the statement is actually read', strpos($src, '$snapshot_started') !== false);
check('A transaction that never opened is not committed',
    strpos($src, "if (!empty(\$snapshot_started)) {\n        \$wpdb->query('COMMIT');") !== false);

$backup = priv('create_backup_package', [false]);
$path = !empty($backup['file']) ? rtrim(priv('backup_dir'), '/') . '/' . $backup['file'] : '';
check('A backup was created to inspect', $path !== '' && is_file($path));

if ($path !== '' && is_file($path)) {
    $zip = new ZipArchive();
    $zip->open($path);
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
    $zip->close();

    check('The archive records whether it was taken from a consistent snapshot',
        is_array($manifest) && array_key_exists('consistent_snapshot', $manifest),
        'a log on the machine that made it is no use months later on another server');
    check('And on this server, which is InnoDB, it says it was',
        !empty($manifest['consistent_snapshot']),
        var_export($manifest['consistent_snapshot'] ?? null, true));

    foreach (priv('volume_paths_for', [$path]) as $p) { @unlink($p); }
}

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
