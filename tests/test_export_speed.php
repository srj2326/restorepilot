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
 * Real export of the live database, timed, then verified row-for-row against
 * the source table. Speed is only worth having if the output is identical.
 */

require_once __DIR__ . '/env.php';
rp_test_boot();

global $wpdb;

$TABLE = 'wp_cf7_vdata_entry';
$expected = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$TABLE`");
echo "Source table `$TABLE`: $expected rows\n\n";

$ref = new ReflectionClass('RestorePilot_Backup_Migration');
$m = $ref->getMethod('write_database_export');
$m->setAccessible(true);

$dir = sys_get_temp_dir() . '/rp-export-speed-' . getmypid();
if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    fwrite(STDERR, "Could not create $dir\n");
    exit(1);
}

echo "Exporting the whole database...\n";
$start = microtime(true);
$result = $m->invoke(null, $dir, '');
$elapsed = microtime(true) - $start;

printf("\nFULL DATABASE EXPORT: %.1f seconds\n", $elapsed);

// Count the exported rows for our table across every NDJSON part.
$files = glob($dir . '/*');
$rows_seen = 0;
$ids = [];
$in_table = false;

foreach ($files as $file) {
    if (!is_file($file)) { continue; }
    $fh = fopen($file, 'r');
    if (!$fh) { continue; }
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') { continue; }
        $obj = json_decode($line, true);
        if (!is_array($obj) || !isset($obj['t'])) { continue; }
        if ($obj['t'] === 'table') {
            $in_table = (isset($obj['name']) && $obj['name'] === $TABLE);
            continue;
        }
        if ($in_table && $obj['t'] === 'row' && isset($obj['d'])) {
            $rows_seen++;
            if (isset($obj['d']['id'])) { $ids[$obj['d']['id']] = true; }
        }
    }
    fclose($fh);
}

echo "\n";
$pass = 0; $fail = 0;
function check(string $label, bool $ok) {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; }
}

echo "Exported rows for $TABLE: $rows_seen (expected $expected)\n";
echo "Distinct ids exported: " . count($ids) . "\n\n";

check('Every source row was exported (no rows skipped)', $rows_seen === $expected);
check('No row was exported twice (no duplicates)', count($ids) === $expected);

// Compare the exported id set against the source id set exactly.
$missing = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$TABLE`") - count($ids);
check('Exported id set matches the source id set exactly', $missing === 0);

// Clean up.
foreach (glob($dir . '/*') as $f) { if (is_file($f)) { @unlink($f); } }
@rmdir($dir);

echo "\n";
if ($fail === 0) {
    printf("ALL %d CHECKS PASSED — export took %.1fs\n", $pass, $elapsed);
} else {
    echo "$fail FAILURE(S)\n";
}
exit($fail === 0 ? 0 : 1);
