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
 * A bad row has to be caught while the restore is still only a plan.
 *
 * RP-006. Preflight checked that every column name in a row looked like a
 * MySQL identifier, and stopped there. It never asked whether the column
 * existed in the table the row claimed to belong to. A row naming
 * `wp_not_a_real_column` is a perfectly plausible identifier, so it travelled
 * through the whole side-effect-free plan and failed at $wpdb->insert().
 *
 * By then a rollback point has been written and maintenance mode is on. The
 * difference matters: rejecting an archive before touching anything is an error
 * message, and rejecting it halfway through replacing the database is an
 * outage that has to be recovered from.
 *
 * The archive carries each table's CREATE statement, so the columns are known
 * without asking the live database -- which is what makes this checkable at
 * plan time.
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
$tmp = sys_get_temp_dir() . '/rp-row-schema-' . getmypid();
mkdir($tmp, 0755, true);
register_shutdown_function(function () use ($tmp) {
    foreach (glob($tmp . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($tmp);
});

// ── The column reader, judged against MySQL itself ─────────────────────────
echo "=== reading a table's columns from its CREATE statement ===\n";
$create_row = $wpdb->get_row("SHOW CREATE TABLE {$wpdb->posts}", ARRAY_N);
$parsed = array_keys(priv('create_table_columns', [$create_row[1]]));
$actual = $wpdb->get_col("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '{$wpdb->posts}'");
sort($parsed); sort($actual);
check('Every column MySQL reports is found, and nothing else',
    $parsed === $actual, sprintf('parsed %d, information_schema %d', count($parsed), count($actual)));

// Index and constraint lines open with KEY or PRIMARY rather than a column, and
// must not be mistaken for columns.
$with_keys = "CREATE TABLE `t` (\n  `id` bigint NOT NULL,\n  `slug` varchar(200) NOT NULL,\n"
           . "  PRIMARY KEY (`id`),\n  UNIQUE KEY `slug` (`slug`),\n  KEY `idx` (`slug`)\n) ENGINE=InnoDB";
check('Key and index clauses are not read as columns',
    array_keys(priv('create_table_columns', [$with_keys])) === ['id', 'slug']);

// ── An archive naming a column that does not exist ─────────────────────────
echo "\n=== an archive whose row names a column the table does not have ===\n";

function make_archive(string $path, string $ndjson): void {
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', wp_json_encode([
        'plugin' => konst('SLUG'), 'generator' => 'RestorePilot', 'version' => '0.5.7',
        'backup_type' => 'full', 'site_url' => home_url(),
        'database_format' => 'ndjson', 'database_parts' => 1, 'table_count' => 1,
        'table_prefix' => $GLOBALS['wpdb']->prefix,
        'created' => time(),
    ]));
    $zip->addFromString('database/database-0001.ndjson', $ndjson);
    $zip->close();
}

$prefix = $wpdb->prefix;
$create = "CREATE TABLE `{$prefix}rp_schema_demo` (\n  `id` bigint(20) NOT NULL,\n  `title` text\n) ENGINE=InnoDB";

$bad = $tmp . '/bad-column.zip';
make_archive($bad,
    wp_json_encode(['t' => 'table', 'name' => $prefix . 'rp_schema_demo', 'create' => $create]) . "\n"
  . wp_json_encode(['t' => 'row', 'd' => ['id' => 1, 'title' => 'fine']]) . "\n"
  . wp_json_encode(['t' => 'row', 'd' => ['id' => 2, 'not_a_real_column' => 'x']]) . "\n");

/** Build the plan — the step that must reject this, before anything changes. */
function plan(string $path) {
    try {
        $zip = priv('open_backup_archive', [$path]);
        $manifest = json_decode($zip->get_from_name('manifest.json'), true);
        // The prefix the archive was made with, read the way perform_restore()
        // reads it. Passing an empty string instead made every table name fail
        // to match, so the plan objected that core tables were missing -- a
        // true statement about the wrong thing, and my error rather than the
        // plugin's.
        $prefix = isset($manifest['table_prefix']) ? (string) $manifest['table_prefix'] : '';
        priv('build_restore_plan', [$zip, $manifest, $prefix]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

$r = plan($bad);
check('THE FIX: the plan refuses it, naming the column',
    !$r['ok'] && stripos($r['error'], 'not_a_real_column') !== false,
    $r['ok'] ? 'accepted — it would have failed at the insert instead' : $r['error']);

// The point is WHERE it is refused. Nothing may have been created by then.
check('And nothing was created on the way to refusing it',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE '%restorepilot\\_rtmp\\_%'") === 0,
    'a plan that leaves scratch tables behind is not side-effect-free');

// ── A legitimate archive is unaffected ─────────────────────────────────────
echo "\n=== and an archive whose rows match its tables ===\n";
$good = $tmp . '/good.zip';
make_archive($good,
    wp_json_encode(['t' => 'table', 'name' => $prefix . 'rp_schema_demo', 'create' => $create]) . "\n"
  . wp_json_encode(['t' => 'row', 'd' => ['id' => 1, 'title' => 'fine']]) . "\n"
  . wp_json_encode(['t' => 'row', 'd' => ['id' => 2, 'title' => 'also fine']]) . "\n");
$r = plan($good);
// It will still be refused for lacking the core WordPress tables, which is a
// different and correct objection -- what matters is that it is not this one.
check('A row using only real columns is not the thing refused',
    $r['ok'] || stripos($r['error'], 'does not have') === false,
    $r['ok'] ? 'planned' : $r['error']);

// A row may legitimately omit columns: a table with defaults does not require
// every column in every row.
$partial = $tmp . '/partial.zip';
make_archive($partial,
    wp_json_encode(['t' => 'table', 'name' => $prefix . 'rp_schema_demo', 'create' => $create]) . "\n"
  . wp_json_encode(['t' => 'row', 'd' => ['id' => 3]]) . "\n");
$r = plan($partial);
check('A row that omits a column is still allowed',
    $r['ok'] || stripos($r['error'], 'does not have') === false,
    $r['ok'] ? 'planned' : $r['error']);

// ── A real backup of this site still plans ─────────────────────────────────
echo "\n=== a backup this plugin made ===\n";
$backup = priv('create_backup_package', [true]);
$path = !empty($backup['file']) ? rtrim(priv('backup_dir'), '/') . '/' . $backup['file'] : '';
if ($path !== '' && is_file($path)) {
    $r = plan($path);
    check('It plans without objection', $r['ok'], $r['ok'] ? '' : $r['error']);
    foreach (priv('volume_paths_for', [$path]) as $p) { @unlink($p); }
    priv('clear_restore_table_journal');
} else {
    check('It plans without objection', false, 'could not create a backup to check');
}

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
