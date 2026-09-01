<?php
/**
 * Why test_multivolume_download cannot build its scenario any more.
 *
 * It asserts that at least one content type's files land across a volume
 * boundary, and that assertion started failing once the WooCommerce store was
 * restored ahead of it. The backup still splits -- volume_count > 1 passes --
 * so the split works; something about where the fixture files end up does not.
 *
 * Reports rather than concludes: entry counts per volume, where the fixture's
 * own files actually landed, and how many of the names the test looks for
 * exist in the archive at all.
 */

define('WP_USE_THEMES', false);
require_once '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public/wp-load.php';

function priv($m, array $a = []) {
    $r = new ReflectionMethod('RestorePilot_Backup_Migration', $m);
    $r->setAccessible(true);
    return $r->invokeArgs(null, $a);
}

$content_dir = priv('content_dir');

// Same fixture the real test builds.
$targets = [
    'plugins' => 'plugins/rp-multivol-test-plugin',
    'uploads' => 'uploads/rp-multivol-test-uploads',
];
$expected = [];
foreach ($targets as $part => $rel) {
    $dir = $content_dir . '/' . $rel;
    if (is_dir($dir)) { system('rm -rf ' . escapeshellarg($dir)); }
    mkdir($dir, 0777, true);
    $expected[$part] = [];
    for ($i = 0; $i < 40; $i++) {
        $bytes = random_bytes(8000 + $i * 300);
        file_put_contents("$dir/f$i.bin", $bytes);
        $expected[$part]["files/wp-content/$rel/f$i.bin"] = strlen($bytes);
    }
}
printf("fixture: %d files, %s per category\n",
    count($expected['plugins']) + count($expected['uploads']),
    size_format(array_sum($expected['plugins'])));

// How big is everything else now? This is what changed.
printf("database estimate : %s\n", size_format(priv('estimate_database_size')));
printf("wp-content estimate: %s\n", size_format(priv('estimate_directory_size', [$content_dir])));

add_filter('restorepilot_backup_volume_bytes', function () { return 200 * 1024; });

$t0 = microtime(true);
$result = priv('create_backup_package', [true, '', [], false, false, ['triggered_by' => 'multivolume-diagnostic']]);
printf("\nbackup: %s (%.0fs)\n", $result['file'] ?? '(none)', microtime(true) - $t0);

$base = priv('backup_dir') . '/' . ($result['file'] ?? '');
$vols = priv('discover_volumes', [$base])['paths'];
printf("volumes: %d\n\n", count($vols));

$name_to_volume = [];
$per_volume = [];
foreach ($vols as $vi => $vp) {
    $za = new ZipArchive();
    if ($za->open($vp) !== true) { echo "  volume $vi: UNREADABLE\n"; continue; }
    $per_volume[$vi] = $za->numFiles;
    for ($i = 0; $i < $za->numFiles; $i++) {
        $name_to_volume[$za->getNameIndex($i)] = $vi;
    }
    $za->close();
}
printf("entries in archive: %d across %d volumes\n", count($name_to_volume), count($per_volume));

$prefixes = [];
foreach (array_keys($name_to_volume) as $n) {
    $top = explode('/', $n)[0];
    $prefixes[$top] = ($prefixes[$top] ?? 0) + 1;
}
echo "top-level entry kinds:\n";
foreach ($prefixes as $k => $v) { printf("  %-24s %d\n", $k, $v); }

echo "\nwhere the fixture landed:\n";
foreach ($expected as $part => $names) {
    $vols_used = [];
    $found = 0;
    foreach (array_keys($names) as $n) {
        if (isset($name_to_volume[$n])) { $found++; $vols_used[$name_to_volume[$n]] = true; }
    }
    ksort($vols_used);
    printf("  %-8s %d/%d present, volumes: %s\n",
        $part, $found, count($names),
        $vols_used ? implode(',', array_keys($vols_used)) : '(none)');
}

// If none were found, the names themselves are the mismatch -- show what the
// archive actually calls the fixture, so a renamed prefix is obvious.
$sample = [];
foreach (array_keys($name_to_volume) as $n) {
    if (strpos($n, 'rp-multivol-test') !== false) { $sample[] = $n; }
    if (count($sample) >= 3) { break; }
}
echo "\nfixture entries as the archive names them:\n";
echo $sample ? ('  ' . implode("\n  ", $sample) . "\n") : "  (the fixture is not in the archive at all)\n";

foreach ($vols as $vp) { @unlink($vp); }
foreach ($targets as $rel) { system('rm -rf ' . escapeshellarg($content_dir . '/' . $rel)); }
echo "\ndone\n";
