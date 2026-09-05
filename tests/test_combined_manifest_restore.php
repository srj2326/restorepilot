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

// Regression test for the exact bug just reported: a combined single-file
// download's embedded manifest.json still claimed the original volume
// count (e.g. 17), so open_backup_archive() — the same completeness check
// used by restore — rejected it as an incomplete multi-volume set even
// though the combined file is, by construction, everything in one piece.
//
// Verifies: (1) the combined file's manifest.json now says volumes=1,
// (2) open_backup_archive() opens the combined file cleanly on its own,
// with NO sibling volume files present at all, (3) every entry is still
// present and byte-correct after the manifest rewrite (i.e. rewriting one
// entry didn't disturb any other entry's data or the central directory).

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
$targets = ['plugins/rp-manifest-test-plugin' => 20, 'uploads/rp-manifest-test-uploads' => 30];
$expected_hashes = [];
foreach ($targets as $rel_dir => $count) {
  $dir = $content_dir . '/' . $rel_dir;
  if (is_dir($dir)) system('rm -rf ' . escapeshellarg($dir));
  mkdir($dir, 0777, true);
  for ($i = 0; $i < $count; $i++) {
    $bytes = random_bytes(15000 + $i * 300);
    file_put_contents("$dir/f$i.bin", $bytes);
    $expected_hashes["files/wp-content/$rel_dir/f$i.bin"] = sha1($bytes);
  }
}

add_filter('restorepilot_backup_volume_bytes', function () { return 150 * 1024; });
$backup_result = call_private('create_backup_package', [true, '', [], false, false, ['triggered_by' => 'manifest-rewrite-test']]);
check('Fixture backup created', !empty($backup_result['file']));
$backup_name = $backup_result['file'];
$base_path = call_private('backup_dir') . '/' . $backup_name;
$discovered = call_private('discover_volumes', [$base_path]);
$volume_count = count($discovered['paths']);
echo "Volumes created: $volume_count\n";
check('Backup spans more than one volume', $volume_count > 1);

// Sanity: confirm the ORIGINAL manifest really does claim the full volume
// count, so the rewrite is actually exercised (not a no-op).
$src_archive = call_private('open_backup_archive', [$base_path]);
$src_manifest = json_decode($src_archive->get_from_name('manifest.json'), true);
check("Original manifest claims volumes=$volume_count before combining", (int) $src_manifest['volumes'] === $volume_count);

// --- Combine, exactly like serve_combined_volume_download() does -----------
$combined_path = sys_get_temp_dir() . '/rp-manifest-combined-' . uniqid() . '.zip';
$out_handle = fopen($combined_path, 'wb');
$writer_ref = new ReflectionMethod('RestorePilot_Backup_Zip_Writer', 'create_streaming');
$writer_ref->setAccessible(true);
$writer = $writer_ref->invoke(null, $out_handle);
call_private('write_combined_volumes', [$src_archive, $writer]);
$src_archive->close();

// --- The actual regression check: does the RESTORE-side completeness check
// now accept this combined file, with NO sibling volumes on disk at all? ---
$combined_dir_listing_before = glob(dirname($combined_path) . '/*');
$threw = false;
$restore_archive = null;
try {
  $restore_archive = call_private('open_backup_archive', [$combined_path]);
} catch (RuntimeException $e) {
  $threw = true;
  echo "open_backup_archive() threw: " . $e->getMessage() . "\n";
}
check('open_backup_archive() accepts the combined file with zero sibling volumes present', !$threw);

if ($restore_archive !== null) {
  $combined_manifest = json_decode($restore_archive->get_from_name('manifest.json'), true);
  check('Combined file\'s manifest.json now says volumes=1', is_array($combined_manifest) && (int) $combined_manifest['volumes'] === 1);

  // Every OTHER manifest field must be untouched by the rewrite.
  $other_fields_intact = true;
  foreach (['plugin', 'version', 'home_url', 'site_url', 'table_prefix'] as $field) {
    if (!isset($combined_manifest[$field]) || $combined_manifest[$field] !== $src_manifest[$field]) {
      $other_fields_intact = false;
      echo "  Field '$field' changed: expected " . var_export($src_manifest[$field] ?? null, true) . ' got ' . var_export($combined_manifest[$field] ?? null, true) . "\n";
    }
  }
  check('Every other manifest field is untouched by the rewrite', $other_fields_intact);

  // And the rewrite must not have disturbed any OTHER entry's data.
  $missing = [];
  $mismatched = [];
  foreach ($expected_hashes as $name => $expected_sha1) {
    $data = $restore_archive->get_from_name($name);
    if ($data === false) { $missing[] = $name; continue; }
    if (sha1($data) !== $expected_sha1) { $mismatched[] = $name; }
  }
  check('Every tagged file is still present after the manifest rewrite', count($missing) === 0);
  check('Every tagged file is still byte-identical after the manifest rewrite', count($mismatched) === 0);

  $restore_archive->close();
}

// The combined file must still pass strict zip validation and be openable
// by an independent implementation, exactly like before this fix.
$za = new ZipArchive();
check('Combined file still opens cleanly under ZipArchive::CHECKCONS', $za->open($combined_path, ZipArchive::CHECKCONS) === true);
$za->close();
exec('unzip -tq ' . escapeshellarg($combined_path) . ' 2>&1', $unzip_out, $unzip_rc);
check('system unzip -t still reports the combined zip as OK', $unzip_rc === 0);

// --- Cleanup -----------------------------------------------------------------
foreach (array_keys($targets) as $rel_dir) {
  system('rm -rf ' . escapeshellarg($content_dir . '/' . $rel_dir));
}
foreach ($discovered['paths'] as $p) { @unlink($p); }
@unlink($combined_path);

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
