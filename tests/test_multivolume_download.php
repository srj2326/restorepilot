<?php
// Regression test for the "Advanced downloads" partial-archive side of the
// multi-volume download fix. build_partial_zip() used to open a backup's
// base file directly through a raw ZipArchive, so a partial download
// (database/plugins/themes/uploads only) silently omitted any matching
// content that happened to land in a volume other than the first — the same
// bug that hit the "Download Full Backup" button, covered separately by
// test_combined_download.php / test_combined_manifest_restore.php (those
// exercise write_combined_volumes() and the manifest rewrite specifically;
// this file covers build_partial_zip(), list_backups()'s total-size
// reporting, and open_backup_archive()'s missing-middle-volume detection,
// none of which those two touch).
//
// Note: the original file of this name was lost from the scratch directory
// mid-session for unexplained reasons (confirmed not a broad cleanup —
// every sibling test file, including same-age ones, survived intact).
// Rewritten from the current plugin source rather than reconstructed from
// memory of whatever its exact prior content was.

$site_root = '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public';
require $site_root . '/wp-load.php';

function call_private($method, array $args = []) {
  $ref = new ReflectionMethod('RestorePilot_Backup_Migration', $method);
  $ref->setAccessible(true);
  return $ref->invokeArgs(null, $args);
}

$failures = [];
function check($label, $cond) {
  global $failures;
  echo ($cond ? 'PASS' : 'FAIL') . "  $label\n";
  if (!$cond) $failures[] = $label;
}

$content_dir = call_private('content_dir');

// --- Fixture: tagged content in two categories, spread over many small
// volumes so at least one category is very likely to span more than one
// physical volume — confirmed empirically below (Test B), never assumed. --
$targets = [
  'plugins' => 'plugins/rp-multivol-test-plugin',
  'uploads' => 'uploads/rp-multivol-test-uploads',
];
$expected_hashes = []; // part => [archive-relative name => sha1]
foreach ($targets as $part => $rel_dir) {
  $dir = $content_dir . '/' . $rel_dir;
  if (is_dir($dir)) system('rm -rf ' . escapeshellarg($dir));
  mkdir($dir, 0777, true);
  $expected_hashes[$part] = [];
  // Each category must be comfortably larger than one volume, or it can only
  // span a boundary by luck of where it happens to land. It asked for 200 KB
  // volumes and silently got 1 MB -- RestorePilot_Backup_Volume_Writer floors
  // the split at max(1048576, ...) -- so 541 KB of fixture fitted inside a
  // single volume and only straddled a boundary when the rest of the archive
  // pushed it across one. It stopped doing that the moment a WooCommerce
  // database went in front of it, and Test B correctly refused to pass on a
  // scenario it had not managed to create. ~3 MB per category cannot fit in a
  // 1 MB volume however the boundaries fall.
  for ($i = 0; $i < 40; $i++) {
    $name = "f$i.bin";
    $bytes = random_bytes(60000 + $i * 1000);
    file_put_contents("$dir/$name", $bytes);
    $expected_hashes[$part]["files/wp-content/$rel_dir/$name"] = sha1($bytes);
  }
}
echo 'Fixture: ' . array_sum(array_map('count', $expected_hashes)) . " tagged files across plugins/uploads.\n";

// Below the writer's own 1 MB floor, so this is really a request for the
// smallest volumes the plugin will make.
add_filter('restorepilot_backup_volume_bytes', function () { return 200 * 1024; });

$backup_result = call_private('create_backup_package', [true, '', [], false, false, ['triggered_by' => 'multivolume-download-test']]);
check('Fixture backup created', !empty($backup_result['file']));
$backup_name = $backup_result['file'];
$base_path = call_private('backup_dir') . '/' . $backup_name;
$discovered = call_private('discover_volumes', [$base_path]);
$volume_count = count($discovered['paths']);
echo "Volumes created: $volume_count\n";
check('Backup spans more than one volume (fixture is big enough)', $volume_count > 1);

// === Test A: list_backups() reports the TRUE total size and volume count,
// not just volume 1's — this is the exact user-facing symptom of the
// original bug (the button showed the correct total while only ever
// delivering the first volume). =============================================
$real_total_size = 0;
foreach ($discovered['paths'] as $p) { $real_total_size += filesize($p); }
$first_volume_size = filesize($discovered['paths'][0]);

$listed = call_private('list_backups');
$entry = null;
foreach ($listed as $item) {
  if ($item['name'] === $backup_name) { $entry = $item; break; }
}
check('Fixture backup appears in list_backups()', $entry !== null);
if ($entry !== null) {
  check('list_backups() reports the correct volume count', (int) $entry['volumes'] === $volume_count);
  check('list_backups() reports size as the sum of every volume, not just the first', (int) $entry['size'] === $real_total_size);
  check('Reported size is bigger than volume 1 alone (proves it summed, not just took one)', (int) $entry['size'] > $first_volume_size);
}

// === Test B: which category actually spans more than one physical volume,
// checked directly via ZipArchive on each volume (ground truth, independent
// of the plugin's own read path) — not assumed, so this test is valid
// regardless of the plugin's internal entry-write order. ====================
$name_to_volume = []; // archive-relative name => volume index (0-based)
foreach ($discovered['paths'] as $vi => $vp) {
  $za = new ZipArchive();
  $za->open($vp);
  for ($i = 0; $i < $za->numFiles; $i++) {
    $name_to_volume[$za->getNameIndex($i)] = $vi;
  }
  $za->close();
}

$spanning_part = null;
foreach ($expected_hashes as $part => $hashes) {
  $volumes_used = [];
  foreach (array_keys($hashes) as $name) {
    if (isset($name_to_volume[$name])) { $volumes_used[$name_to_volume[$name]] = true; }
  }
  if (count($volumes_used) > 1) { $spanning_part = $part; break; }
}
check("At least one content type's files genuinely span more than one physical volume (proves the scenario, not just the split)", $spanning_part !== null);

// === Test C: build_partial_zip() for the spanning category recovers EVERY
// tagged file, not just the ones that happened to land in volume 1 — the
// actual regression this test exists to catch. ==============================
if ($spanning_part !== null) {
  $tmp_partial = call_private('build_partial_zip', [$base_path, $spanning_part]);
  check('build_partial_zip() produced a file', is_file($tmp_partial));

  $za = new ZipArchive();
  $opened = $za->open($tmp_partial);
  check('The partial zip opens cleanly', $opened === true);
  if ($opened === true) {
    $missing = [];
    $mismatched = [];
    foreach ($expected_hashes[$spanning_part] as $name => $expected_sha1) {
      $data = $za->getFromName($name);
      if ($data === false) { $missing[] = $name; continue; }
      if (sha1($data) !== $expected_sha1) { $mismatched[] = $name; }
    }
    check("Every '$spanning_part' file is present in the partial zip, including ones from a later volume", count($missing) === 0);
    if ($missing) echo '  Missing: ' . implode(', ', array_slice($missing, 0, 8)) . "\n";
    check("Every '$spanning_part' file in the partial zip is byte-identical", count($mismatched) === 0);

    // The OTHER category's files must NOT leak into this partial zip.
    $other_part = $spanning_part === 'plugins' ? 'uploads' : 'plugins';
    $leaked = 0;
    foreach (array_keys($expected_hashes[$other_part]) as $name) {
      if ($za->getFromName($name) !== false) { $leaked++; }
    }
    check("No '$other_part' files leaked into the '$spanning_part' partial zip", $leaked === 0);
    $za->close();
  }
  @unlink($tmp_partial);
}

// === Test D: a single-volume backup's partial download is unaffected
// (regression check, mirrors the equivalent check in the sibling combined-
// download tests). ===========================================================
remove_all_filters('restorepilot_backup_volume_bytes');
$small_dir = $content_dir . '/plugins/rp-multivol-single-test';
if (is_dir($small_dir)) system('rm -rf ' . escapeshellarg($small_dir));
mkdir($small_dir, 0777, true);
file_put_contents($small_dir . '/f0.bin', random_bytes(1000));
$small_backup = call_private('create_backup_package', [true, '', [], false, false, ['triggered_by' => 'multivolume-download-test-single']]);
check('Single-volume fixture backup created', !empty($small_backup['file']));
$small_base = call_private('backup_dir') . '/' . $small_backup['file'];
$small_discovered = call_private('discover_volumes', [$small_base]);
check('A normal-sized backup still produces exactly one volume', count($small_discovered['paths']) === 1);

$small_partial = call_private('build_partial_zip', [$small_base, 'plugins']);
check('build_partial_zip() still works on a single-volume backup', is_file($small_partial) && filesize($small_partial) > 0);
@unlink($small_partial);

// === Test E: a backup missing a MIDDLE volume is detected and rejected, not
// silently truncated or restored/downloaded as a shorter, incomplete set. ===
if ($volume_count >= 3) {
  $middle_volume_path = $discovered['paths'][1]; // index 1 = volume 2
  $holding = $middle_volume_path . '.held-aside';
  rename($middle_volume_path, $holding);

  $threw = false;
  $msg = '';
  try {
    call_private('open_backup_archive', [$base_path]);
  } catch (RuntimeException $e) {
    $threw = true;
    $msg = $e->getMessage();
  }
  check('open_backup_archive() rejects a set with a missing middle volume instead of silently truncating', $threw);
  if ($threw) echo "  Message: $msg\n";

  $partial_threw = false;
  try {
    $p = call_private('build_partial_zip', [$base_path, $spanning_part ?? 'plugins']);
    @unlink($p);
  } catch (RuntimeException $e) {
    $partial_threw = true;
  }
  check('build_partial_zip() also refuses a set with a missing middle volume, rather than silently producing an incomplete partial archive', $partial_threw);

  rename($holding, $middle_volume_path);
} else {
  echo "SKIP  missing-middle-volume test (fixture only produced $volume_count volumes, need >= 3)\n";
}

// --- Cleanup -----------------------------------------------------------------
foreach (array_values($targets) as $rel_dir) {
  system('rm -rf ' . escapeshellarg($content_dir . '/' . $rel_dir));
}
system('rm -rf ' . escapeshellarg($small_dir));
foreach ($discovered['paths'] as $p) { @unlink($p); }
foreach ($small_discovered['paths'] as $p) { @unlink($p); }

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
