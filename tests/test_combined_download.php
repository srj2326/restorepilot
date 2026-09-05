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

require_once __DIR__ . '/env.php';

// Verifies the NEW single-file streaming reconstruction for multi-volume
// backup downloads: write_combined_volumes() (the core loop behind
// serve_combined_volume_download()) must produce ONE valid zip file whose
// contents are byte-identical to what's spread across every source volume,
// using RestorePilot_Backup_Zip_Writer::create_streaming() the same way the
// real HTTP download does (just writing to a local file instead of
// php://output so this test can inspect the result afterward).

$site_root = rp_test_site();
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

// --- Fixture: tagged content spread across many small volumes --------------
$targets = [
  'plugins/rp-combine-test-plugin' => 30,
  'uploads/rp-combine-test-uploads' => 50,
  'mu-plugins/rp-combine-test-mu' => 20,
  'themes/rp-combine-test-theme' => 10,
];
$expected_hashes = [];
foreach ($targets as $rel_dir => $count) {
  $dir = $content_dir . '/' . $rel_dir;
  if (is_dir($dir)) system('rm -rf ' . escapeshellarg($dir));
  mkdir($dir, 0777, true);
  for ($i = 0; $i < $count; $i++) {
    $name = "f$i.bin";
    $bytes = random_bytes(15000 + $i * 400);
    file_put_contents("$dir/$name", $bytes);
    $expected_hashes["files/wp-content/$rel_dir/$name"] = sha1($bytes);
  }
}
echo 'Fixture: ' . count($expected_hashes) . " tagged files across plugins/uploads/mu-plugins/themes.\n";

add_filter('restorepilot_backup_volume_bytes', function () { return 200 * 1024; });

$backup_result = call_private('create_backup_package', [true, '', [], false, false, ['triggered_by' => 'combined-download-test']]);
check('Fixture backup created', !empty($backup_result['file']));
$backup_name = $backup_result['file'];
$base_path = call_private('backup_dir') . '/' . $backup_name;
$discovered = call_private('discover_volumes', [$base_path]);
$volume_count = count($discovered['paths']);
echo "Volumes created: $volume_count\n";
check('Backup spans more than one volume (fixture is big enough)', $volume_count > 1);

// Total entry count across every volume, read independently via ZipArchive
// (not via the plugin's own read facade) as a ground truth to compare
// against after combining.
$expected_total_entries = 0;
$expected_names = [];
foreach ($discovered['paths'] as $vp) {
  $za = new ZipArchive();
  $za->open($vp);
  $expected_total_entries += $za->numFiles;
  for ($i = 0; $i < $za->numFiles; $i++) {
    $expected_names[$za->getNameIndex($i)] = true;
  }
  $za->close();
}
echo "Total entries across all volumes (ground truth): $expected_total_entries\n";
echo "Unique names across all volumes: " . count($expected_names) . "\n";

// --- Test A: write_combined_volumes() reconstructs one valid archive -------
$combined_path = sys_get_temp_dir() . '/rp-combined-test-' . uniqid() . '.zip';
$archive = call_private('open_backup_archive', [$base_path]);
$out_handle = fopen($combined_path, 'wb');
$writer_ref = new ReflectionMethod('RestorePilot_Backup_Zip_Writer', 'create_streaming');
$writer_ref->setAccessible(true);
$writer = $writer_ref->invoke(null, $out_handle);

$t0 = microtime(true);
call_private('write_combined_volumes', [$archive, $writer]);
$elapsed = microtime(true) - $t0;
$archive->close();
// write_combined_volumes() calls $writer->close(), which already fclose()s
// $out_handle (the same underlying resource) — nothing left to close here.

$combined_size = filesize($combined_path);
echo "Combined file written in " . round($elapsed, 2) . "s, size " . $combined_size . " bytes.\n";
check('Combined file was written and is non-empty', $combined_size !== false && $combined_size > 0);

// --- Test B: PHP's own ZipArchive can open it and sees every entry ---------
$check_za = new ZipArchive();
$opened = $check_za->open($combined_path, ZipArchive::CHECKCONS);
check('Combined zip opens cleanly under ZipArchive::CHECKCONS (consistency-checked)', $opened === true);

if ($opened === true) {
  check('Combined zip has the same entry count as the sum of all volumes', $check_za->numFiles === $expected_total_entries);

  $missing = [];
  $mismatched = [];
  foreach ($expected_hashes as $name => $expected_sha1) {
    $data = $check_za->getFromName($name);
    if ($data === false) { $missing[] = $name; continue; }
    if (sha1($data) !== $expected_sha1) { $mismatched[] = $name; }
  }
  check('Every tagged file is present in the combined zip', count($missing) === 0);
  if ($missing) echo "  Missing: " . implode(', ', array_slice($missing, 0, 8)) . (count($missing) > 8 ? '...' : '') . "\n";
  check('Every tagged file is byte-identical in the combined zip', count($mismatched) === 0);
  if ($mismatched) echo "  Mismatched: " . implode(', ', array_slice($mismatched, 0, 8)) . "\n";

  // manifest.json must be present exactly once (it lives in the final
  // volume only) and readable, since restore relies on it.
  $manifest_raw = $check_za->getFromName('manifest.json');
  check('manifest.json is present and valid JSON in the combined zip', is_string($manifest_raw) && is_array(json_decode($manifest_raw, true)));

  // No duplicate names (would indicate an entry got re-emitted).
  $names_seen = [];
  $dupes = 0;
  for ($i = 0; $i < $check_za->numFiles; $i++) {
    $n = $check_za->getNameIndex($i);
    if (isset($names_seen[$n])) { $dupes++; } else { $names_seen[$n] = true; }
  }
  check('No duplicate entry names in the combined zip', $dupes === 0);

  $check_za->close();
}

// --- Test C: system tools agree it's a valid zip ----------------------------
exec('which unzip 2>/dev/null', $o1, $r1);
if ($r1 === 0) {
  exec('unzip -tq ' . escapeshellarg($combined_path) . ' 2>&1', $unzip_out, $unzip_rc);
  check('system unzip -t reports the combined zip as OK', $unzip_rc === 0);
  if ($unzip_rc !== 0) echo "  unzip output: " . implode("\n  ", array_slice($unzip_out, -10)) . "\n";
} else {
  echo "SKIP  system unzip not available\n";
}

exec('which zip 2>/dev/null', $o2, $r2);
if ($r2 === 0) {
  exec('zip -T ' . escapeshellarg($combined_path) . ' 2>&1', $zipT_out, $zipT_rc);
  check('system zip -T reports the combined zip as OK', $zipT_rc === 0);
  if ($zipT_rc !== 0) echo "  zip -T output: " . implode("\n  ", array_slice($zipT_out, -10)) . "\n";
} else {
  echo "SKIP  system zip not available\n";
}

// --- Test D: addFileFromStream() throws on a known_size/actual mismatch ----
// Simulates a stale/incorrect stat from the source archive: claims a small
// stream is >4GB. This must fail loudly rather than silently emit a local
// header that disagrees with the real data.
$guard_path = sys_get_temp_dir() . '/rp-combined-guard-test-' . uniqid() . '.zip';
$guard_handle = fopen($guard_path, 'wb');
$guard_writer = $writer_ref->invoke(null, $guard_handle);
$fake_stream = fopen('php://memory', 'r+b');
fwrite($fake_stream, 'tiny actual content');
rewind($fake_stream);

$threw = false;
$msg = '';
try {
  $add_ref = new ReflectionMethod('RestorePilot_Backup_Zip_Writer', 'addFileFromStream');
  $add_ref->setAccessible(true);
  $add_ref->invokeArgs($guard_writer, [$fake_stream, 'fake-large-file.bin', time(), 5000000000]);
} catch (RuntimeException $e) {
  $threw = true;
  $msg = $e->getMessage();
}
check('addFileFromStream() throws when known_size disagrees with actual bytes read', $threw);
if ($threw) echo "  Exception message: $msg\n";
fclose($fake_stream);
@fclose($guard_handle);
@unlink($guard_path);

// --- Test E: is_follow_on_volume() / volume resolution sanity --------------
check('Base backup name is NOT treated as a follow-on volume', call_private('is_follow_on_volume', [basename($base_path)]) === false);
if ($volume_count > 1) {
  $second_volume_name = basename($discovered['paths'][1]);
  check('Volume 2 filename IS treated as a follow-on volume', call_private('is_follow_on_volume', [$second_volume_name]) === true);
}

// --- Regression: single-volume backups are unaffected -----------------------
remove_all_filters('restorepilot_backup_volume_bytes');
$small_backup = call_private('create_backup_package', [false, '', [], false, false, ['triggered_by' => 'combined-download-test-single']]);
$small_base = call_private('backup_dir') . '/' . $small_backup['file'];
$small_discovered = call_private('discover_volumes', [$small_base]);
check('A normal-sized backup still produces exactly one volume', count($small_discovered['paths']) === 1);

// --- Cleanup -----------------------------------------------------------------
foreach (array_keys($targets) as $rel_dir) {
  system('rm -rf ' . escapeshellarg($content_dir . '/' . $rel_dir));
}
foreach ($discovered['paths'] as $p) { @unlink($p); }
foreach ($small_discovered['paths'] as $p) { @unlink($p); }
@unlink($combined_path);

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
