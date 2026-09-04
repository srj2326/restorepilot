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
 * Archives built to break the restore, rather than to be restored.
 *
 * Every limit the restore applies to a backup was taken from the backup's own
 * manifest. MAX_RESTORE_TABLE_COUNT was checked against `table_count`, a number
 * the archive supplies about itself; nothing counted the table records actually
 * streamed. So a manifest saying "1 table" could sit in front of a database
 * export containing a million, and the plan would grow in memory until PHP
 * stopped.
 *
 * The line reader had the same shape of problem from the other direction:
 *
 *     while (($line = fgets($stream)) !== false)
 *
 * fgets with no length reads to the next newline however far away that is. One
 * crafted line with no newline in it is read whole into memory before anything
 * has had a chance to object to its size.
 *
 * Neither needs a hostile author to reach: a truncated or corrupted archive
 * produces the same shapes. But an administrator can be talked into uploading a
 * file, and "the manifest said it was small" is not a defence.
 *
 * The fixtures below are built by hand rather than by the backup writer,
 * because the backup writer will not produce them -- which is the point.
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
function konst(string $name) {
    return (new ReflectionClass('RestorePilot_Backup_Migration'))->getConstant($name);
}

$tmp = sys_get_temp_dir() . '/rp-archive-limits-' . getmypid();
mkdir($tmp, 0755, true);
register_shutdown_function(function () use ($tmp) {
    foreach (glob($tmp . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($tmp);
});

/**
 * A backup-shaped archive. $lines is the raw NDJSON body; passing it directly
 * is what lets these fixtures say things a real export never would.
 */
function make_archive(string $path, array $manifest, string $ndjson): void {
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', wp_json_encode($manifest));
    $zip->addFromString('database/database-0001.ndjson', $ndjson);
    $zip->close();
}

function base_manifest(array $over = []): array {
    return array_merge([
        // Without this, validation refuses the archive as "not a valid
        // RestorePilot backup" before reaching any of the limits under test --
        // which is correct of it, and made the first run of this file prove
        // nothing.
        'plugin'          => konst('SLUG'),
        'generator'       => 'RestorePilot',
        'version'         => '0.5.5',
        'backup_type'     => 'full',
        'site_url'        => home_url(),
        'database_format' => 'ndjson',
        'database_parts'  => 1,
        'table_count'     => 1,
        'created'         => time(),
    ], $over);
}

/** Run validation the way a restore does, and report what stopped it. */
function validate(string $path): array {
    $zip = null;
    try {
        $zip = priv('open_backup_archive', [$path]);
        $result = priv('validate_backup_zip', [$zip, true, false]);
        return ['ok' => true, 'result' => $result];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/** Stream the export the way a restore does, counting what comes back. */
function stream_it(string $path): array {
    try {
        $zip = priv('open_backup_archive', [$path]);
        $manifest = json_decode($zip->get_from_name('manifest.json'), true);
        $tables = 0; $rows = 0;
        priv('stream_database_records', [$zip, $manifest, function ($type) use (&$tables, &$rows) {
            if ($type === 'table') { $tables++; } else { $rows++; }
        }]);
        return ['ok' => true, 'tables' => $tables, 'rows' => $rows];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

// ── A manifest that understates how many tables it carries ─────────────────
echo "=== a manifest claiming one table, carrying far more ===\n";
$limit = (int) konst('MAX_RESTORE_TABLE_COUNT');
$over  = $limit + 50;

$lines = '';
for ($i = 0; $i < $over; $i++) {
    $lines .= wp_json_encode(['t' => 'table', 'name' => "wp_fake_$i", 'create' => "CREATE TABLE `wp_fake_$i` (`id` int)"]) . "\n";
}
$understated = $tmp . '/understated.zip';
make_archive($understated, base_manifest(['table_count' => 1]), $lines);

$v = validate($understated);
echo '  validation: ' . ($v['ok'] ? 'accepted' : $v['error']) . "\n";

$s = stream_it($understated);
check('THE FIX: streaming stops once more tables arrive than the limit allows',
    !$s['ok'] && stripos($s['error'], 'table') !== false,
    $s['ok'] ? sprintf('streamed %d tables with a manifest declaring 1', $s['tables']) : $s['error']);

// A backup whose manifest is honest must still restore, or the limit is just
// breaking valid archives.
$honest = $tmp . '/honest.zip';
$small = '';
for ($i = 0; $i < 5; $i++) {
    $small .= wp_json_encode(['t' => 'table', 'name' => "wp_ok_$i", 'create' => "CREATE TABLE `wp_ok_$i` (`id` int)"]) . "\n";
    $small .= wp_json_encode(['t' => 'row', 'table' => "wp_ok_$i", 'r' => ['id' => 1]]) . "\n";
}
make_archive($honest, base_manifest(['table_count' => 5]), $small);
$s = stream_it($honest);
check('An ordinary archive still streams to the end',
    $s['ok'] && $s['tables'] === 5, $s['ok'] ? "{$s['tables']} tables, {$s['rows']} rows" : $s['error']);

// ── One line with no newline in it ─────────────────────────────────────────
echo "\n=== a single record with no end to it ===\n";
// Comfortably past any legitimate row while staying small enough to build here.
$huge = $tmp . '/hugeline.zip';
$giant_value = str_repeat('A', 96 * 1024 * 1024);
make_archive($huge, base_manifest(['table_count' => 1]),
    wp_json_encode(['t' => 'table', 'name' => 'wp_ok', 'create' => 'CREATE TABLE `wp_ok` (`id` int)']) . "\n"
    . '{"t":"row","table":"wp_ok","r":{"id":1,"v":"' . $giant_value . '"}}');
unset($giant_value);

$before = memory_get_peak_usage(true);
$s = stream_it($huge);
$grew = (memory_get_peak_usage(true) - $before) / 1048576;
printf("  peak memory grew by %.0f MB while reading it\n", $grew);
check('THE FIX: an over-long record is refused rather than read whole',
    !$s['ok'] && (stripos($s['error'], 'long') !== false || stripos($s['error'], 'large') !== false),
    $s['ok'] ? 'it was read' : $s['error']);

// ── A legitimately large row must still work ───────────────────────────────
echo "\n=== a big but reasonable row ===\n";
$okbig = $tmp . '/okbig.zip';
$value = str_repeat('B', 2 * 1024 * 1024);
make_archive($okbig, base_manifest(['table_count' => 1]),
    wp_json_encode(['t' => 'table', 'name' => 'wp_ok', 'create' => 'CREATE TABLE `wp_ok` (`id` int)']) . "\n"
    . wp_json_encode(['t' => 'row', 'table' => 'wp_ok', 'r' => ['id' => 1, 'v' => $value]]) . "\n");
unset($value);
$s = stream_it($okbig);
check('A 2 MB row is still restored, not mistaken for an attack',
    $s['ok'] && $s['rows'] === 1, $s['ok'] ? "{$s['rows']} row" : $s['error']);

// ── An archive that expands out of all proportion ──────────────────────────
echo "\n=== an archive that expands out of all proportion ===\n";
// Highly repetitive content deflates enormously. A real export of text
// compresses perhaps ten or twenty to one; this is far beyond that.
$bomb = $tmp . '/bomb.zip';
make_archive($bomb, base_manifest(['table_count' => 1]),
    wp_json_encode(['t' => 'table', 'name' => 'wp_ok', 'create' => 'CREATE TABLE `wp_ok` (`id` int)']) . "\n"
    . str_repeat("0", 200 * 1024 * 1024) . "\n");
$ratio = 0;
$zz = new ZipArchive();
$zz->open($bomb);
$st = $zz->statName('database/database-0001.ndjson');
if ($st && $st['comp_size'] > 0) { $ratio = $st['size'] / $st['comp_size']; }
$zz->close();
printf("  the export entry expands %.0f:1\n", $ratio);

$v = validate($bomb);
check('THE FIX: an archive expanding far beyond any real backup is refused',
    !$v['ok'] && (stripos($v['error'], 'expand') !== false || stripos($v['error'], 'compress') !== false
        || stripos($v['error'], 'large') !== false),
    $v['ok'] ? 'accepted' : $v['error']);

// ── And a real backup is still accepted ────────────────────────────────────
echo "\n=== a real backup of this site ===\n";
$real = priv('create_backup_package', [true]);
$real_path = !empty($real['file']) ? rtrim(priv('backup_dir'), '/') . '/' . $real['file'] : '';
if ($real_path !== '' && is_file($real_path)) {
    // Checked the way a real restore checks it, completeness included.
    $zip = priv('open_backup_archive', [$real_path]);
    try {
        priv('validate_backup_zip', [$zip, true, true]);
        check('A backup this plugin produced still validates', true, 'accepted');
    } catch (Throwable $e) {
        check('A backup this plugin produced still validates', false, $e->getMessage());
    }
    foreach (priv('volume_paths_for', [$real_path]) as $p) { @unlink($p); }
} else {
    check('A backup this plugin produced still validates', false, 'could not create one to check');
}

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
