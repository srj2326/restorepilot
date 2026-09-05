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
 * A legacy backup too large for this server is refused, not attempted.
 *
 * RP-039. Archives from much older versions carry the whole database as one
 * database.json, which has to be handed to json_decode() in one piece. The
 * ceiling on that was a flat 2 GB -- a sanity limit, not a memory limit, and
 * far above what a 256 MB PHP process can decode. A perfectly valid old backup
 * could therefore be accepted, a rollback point written, maintenance mode
 * switched on, and the process then killed part way through with the site
 * already in pieces.
 *
 * The ceiling is derived from the running memory_limit now. This runs the case
 * that matters in a child process with a small limit, because the value has to
 * come from a real ini setting rather than one the test asserts about itself.
 *
 * Both directions: an archive over the ceiling is refused with a message that
 * says what to do, and one under it is not refused for this reason.
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

$tmp = sys_get_temp_dir() . '/rp-legacy-limit-' . getmypid();
@mkdir($tmp, 0755, true);
register_shutdown_function(function () use ($tmp) {
    foreach (glob($tmp . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($tmp);
});

/**
 * A legacy-format archive: manifest with no database_parts, plus one
 * database.json of roughly $mb megabytes.
 */
function make_legacy_archive(string $path, int $mb): void {
    global $wpdb;
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', wp_json_encode([
        'plugin' => 'restorepilot-backup-migration', 'generator' => 'RestorePilot',
        'version' => '0.2.0', 'backup_type' => 'full', 'site_url' => home_url(),
        'table_prefix' => $wpdb->prefix, 'table_count' => 1, 'created' => time(),
    ]));

    // Built as text rather than encoded from an array, so the process making
    // the fixture does not need the memory the fixture is about to describe.
    $row  = wp_json_encode(['id' => 1, 'payload' => str_repeat('x', 1000)]);
    $rows = (int) (($mb * 1024 * 1024) / (strlen($row) + 1));
    $body = '{"tables":{"' . $wpdb->prefix . 'legacy":{"rows":[';
    $zip->addFromString('database.json', $body . implode(',', array_fill(0, $rows, $row)) . ']}}}');
    $zip->close();
}

function priv($name, $args = []) {
    $m = new ReflectionMethod('RestorePilot_Backup_Migration', $name);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
}

// ── Two archives, either side of the ceiling a 64M process gets (8 MB) ─────
echo "=== building legacy-format fixtures ===\n";
$over  = $tmp . '/legacy-over.zip';
$under = $tmp . '/legacy-under.zip';
make_legacy_archive($over, 12);
make_legacy_archive($under, 2);

function uncompressed_size(string $archive): int {
    $z = new ZipArchive();
    if ($z->open($archive) !== true) { return 0; }
    $stat = $z->statName('database.json');
    $z->close();
    return is_array($stat) ? (int) $stat['size'] : 0;
}
$over_size  = uncompressed_size($over);
$under_size = uncompressed_size($under);
printf("  over:  %s uncompressed\n  under: %s uncompressed\n", size_format($over_size), size_format($under_size));

// A validator run inside a process with a small memory_limit, which is the
// whole point: the ceiling has to come from that process's own ini setting.
$probe = $tmp . '/validate.php';
file_put_contents($probe, "<?php\n"
    . "require_once " . var_export(__DIR__ . '/env.php', true) . ";\n"
    . "rp_test_boot();\n"
    . "\$m = new ReflectionMethod('RestorePilot_Backup_Migration', 'open_backup_archive');\n"
    . "\$m->setAccessible(true);\n"
    . "\$v = new ReflectionMethod('RestorePilot_Backup_Migration', 'validate_backup_zip');\n"
    . "\$v->setAccessible(true);\n"
    . "\$c = new ReflectionMethod('RestorePilot_Backup_Migration', 'legacy_json_ceiling');\n"
    . "\$c->setAccessible(true);\n"
    . "echo 'CEILING:', (int) \$c->invoke(null), \"\\n\";\n"
    . "try {\n"
    . "  \$zip = \$m->invoke(null, \$argv[1]);\n"
    . "  \$v->invoke(null, \$zip, true, false, false);\n"
    . "  echo 'ACCEPTED';\n"
    . "} catch (Throwable \$e) { echo 'REFUSED:', \$e->getMessage(); }\n");

function run_probe(string $probe, string $archive, string $limit): string {
    $out = [];
    exec(rp_test_php_command($probe, escapeshellarg($archive), 'memory_limit=' . $limit) . ' 2>&1', $out);
    return implode("\n", $out);
}

echo "\n=== a legacy archive larger than a 64M process can decode ===\n";
$result = run_probe($probe, $over, '64M');
if (preg_match('/CEILING:(\d+)/', $result, $m)) {
    printf("  ceiling at 64M: %s\n", size_format((int) $m[1]));
}
$tail = trim(preg_replace('/^CEILING:\d+\s*/', '', $result));
check('THE FIX: it is refused rather than attempted',
    strpos($tail, 'REFUSED:') === 0,
    substr($tail, 0, 190));
check('And the message says why, and what to do about it',
    stripos($tail, 'single-document') !== false
    && stripos($tail, 'memory') !== false
    && (stripos($tail, 'fresh backup') !== false || stripos($tail, 'Raise memory_limit') !== false));

echo "\n=== and one that fits ===\n";
$result_ok = run_probe($probe, $under, '64M');
$tail_ok = trim(preg_replace('/^CEILING:\d+\s*/', '', $result_ok));
check('A legacy archive under the ceiling is not refused for its size',
    strpos($tail_ok, 'REFUSED:') !== 0 || stripos($tail_ok, 'single-document') === false,
    substr($tail_ok, 0, 190));

echo "\n=== the same oversized archive on a host with more memory ===\n";
$result_big = run_probe($probe, $over, '1024M');
$tail_big = trim(preg_replace('/^CEILING:\d+\s*/', '', $result_big));
check('It is not refused for its size where the memory exists',
    stripos($tail_big, 'single-document') === false,
    substr($tail_big, 0, 190) ?: '(accepted)');

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
