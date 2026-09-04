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

// Directly tests assemble_restore_chunks()'s success path (not just the
// write_stream() diagnostics it calls into), since it's one of the 4 call
// sites whose write_stream() signature changed this phase. Simulates what
// handle_chunk_restore_upload() does before calling it: write N raw part
// files named part-000000, part-000001, ... into restore_chunk_dir(), then
// assemble them and verify the result is byte-identical to the concatenation.

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

$upload_id = 'test-' . uniqid();
$chunk_dir = call_private('restore_chunk_dir', [$upload_id]);
if (!is_dir($chunk_dir)) {
  mkdir($chunk_dir, 0777, true);
}

$total_chunks = 7;
$expected = '';
for ($i = 0; $i < $total_chunks; $i++) {
  $piece = random_bytes(50000 + $i * 777); // uneven sizes, like real chunks near EOF
  $expected .= $piece;
  $part_path = $chunk_dir . '/part-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT);
  file_put_contents($part_path, $piece);
}
echo "Fixture: $total_chunks part files, " . strlen($expected) . " bytes total.\n";

$restore_path = call_private('assemble_restore_chunks', [$upload_id, 'test-backup.zip', $total_chunks]);
check('assemble_restore_chunks() returned a path', is_string($restore_path) && $restore_path !== '');
check('Assembled file exists', is_file($restore_path));
check('Assembled file size matches the sum of all parts', filesize($restore_path) === strlen($expected));
check('Assembled file content is byte-identical to the parts in order', file_get_contents($restore_path) === $expected);

// Failure path: a missing chunk must still fail cleanly (regression for the
// existing "chunks are missing" check, unrelated to write_stream()).
$upload_id2 = 'test-missing-' . uniqid();
$chunk_dir2 = call_private('restore_chunk_dir', [$upload_id2]);
mkdir($chunk_dir2, 0777, true);
file_put_contents($chunk_dir2 . '/part-000000', 'only chunk 0');
$threw_missing = false;
try {
  call_private('assemble_restore_chunks', [$upload_id2, 'test-backup2.zip', 3]);
} catch (RuntimeException $e) {
  $threw_missing = true;
  echo "Missing-chunk message: " . $e->getMessage() . "\n";
}
check('assemble_restore_chunks() still throws cleanly when a chunk is missing', $threw_missing);

@unlink($restore_path);
call_private('delete_directory', [$chunk_dir, call_private('storage_dir')]);
call_private('delete_directory', [$chunk_dir2, call_private('storage_dir')]);

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
