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

// Regression test for two fixes to assemble_restore_chunks(): (1) each
// chunk is now unlinked immediately after its content is durably written
// into the combined file, instead of the whole chunk set staying on disk
// until the very end — this is what previously made peak disk usage during
// assembly close to double the backup's size; (2) an upfront disk-space
// check now fails immediately if there isn't room for the combined file,
// instead of discovering that partway through a multi-GB write. Verifies
// both against real chunk files and a real (if artificially inflated via
// a sparse file) size comparison — not just read through the code.

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

$storage_dir = call_private('storage_dir');
$upload_id = 'test-assembly-fix-' . uniqid();
$chunk_dir = $storage_dir . '/restore-chunks/' . $upload_id;
@mkdir($chunk_dir, 0777, true);

// === Test 1: normal, successful assembly still works correctly, AND each
// chunk is deleted incrementally (not all left until the very end). =======
$n_chunks = 6;
$chunk_content = [];
for ($i = 0; $i < $n_chunks; $i++) {
  $content = str_repeat(chr(65 + $i), 100000 + $i * 137); // distinct, verifiable content per chunk
  $chunk_content[] = $content;
  file_put_contents($chunk_dir . '/part-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT), $content);
}
$expected_combined = implode('', $chunk_content);

// Wrap fopen isn't practical here, so instead verify the END STATE: after a
// normal, successful assembly, EVERY chunk file must be gone (not just the
// directory as a whole after handle_chunk_restore_upload()'s own cleanup,
// which this test bypasses by calling assemble_restore_chunks() directly)
// — proving the per-chunk unlink() inside the function itself ran, not
// just the caller's own end-of-request directory sweep.
$restore_path = call_private('assemble_restore_chunks', [$upload_id, 'test-backup.zip', $n_chunks]);
check('Assembly succeeded and returned a real file', is_file($restore_path));
check('Combined file content is byte-for-byte correct', file_get_contents($restore_path) === $expected_combined);

$remaining_chunks = glob($chunk_dir . '/part-*') ?: [];
check('Every individual chunk was deleted incrementally by assemble_restore_chunks() itself (not left for the caller to clean up)', count($remaining_chunks) === 0);

@unlink($restore_path);

// === Test 2: an assembly that cannot possibly fit fails IMMEDIATELY, before
// creating (or writing meaningfully into) the output file — not partway
// through, the way the real production failure did. Uses a SPARSE file (a
// huge *reported* size via truncate(), consuming almost no real disk) so
// this is a genuine, deterministic over-the-limit condition without
// needing an actually-full disk to test it. ================================
@mkdir($chunk_dir, 0777, true);
$huge_chunk = $chunk_dir . '/part-000000';
$fh = fopen($huge_chunk, 'wb');
ftruncate($fh, 500 * 1024 * 1024 * 1024); // reports as 500GB; consumes ~0 real bytes (sparse)
fclose($fh);
check('Fixture: sparse chunk reports a huge size via filesize()', filesize($huge_chunk) === 500 * 1024 * 1024 * 1024);

$threw = false;
$message = '';
$restore_path_2 = null;
try {
  $restore_path_2 = call_private('assemble_restore_chunks', [$upload_id, 'test-backup.zip', 1]);
} catch (RuntimeException $e) {
  $threw = true;
  $message = $e->getMessage();
}
check('Assembly of an oversized set throws immediately instead of attempting the write', $threw);
if ($message !== '') { echo "  Message: $message\n"; }
check('...with a message naming free space and space needed, not a generic failure', strpos($message, 'free disk space') !== false || stripos($message, 'Available') !== false);

$leftover_output = glob($storage_dir . '/restore-upload-*test-backup.zip') ?: [];
check('No partial/empty output file was left behind by the failed attempt', count($leftover_output) === 0);

// === Test 3: the disk check must key off the LARGEST SINGLE chunk, not the
// total of all chunks combined — a real production failure this exact test
// would have missed: with the whole chunk set already uploaded (and so
// already occupying its own disk space before assembly even starts) and
// each chunk freed as soon as it's consumed, the only NEW headroom ever
// needed at once is about one chunk's worth. A check against the TOTAL
// (an earlier, shipped-then-reverted version of this fix) would demand a
// second full copy's worth of free space on top of the chunks already
// sitting there — exactly the double-disk requirement this whole fix
// exists to eliminate. Proven here by making total_size clearly exceed
// any single chunk's size, then reading back what the check itself logged
// it required, rather than depending on genuinely exhausting real disk
// space (which the earlier "500GB single chunk" case already exercises
// for the case where even one chunk can't fit at all). ====================
@mkdir($chunk_dir, 0777, true);
$n_chunks_3 = 10;
$each_size = 2 * 1024 * 1024; // 2 MB per chunk
for ($i = 0; $i < $n_chunks_3; $i++) {
  file_put_contents($chunk_dir . '/part-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT), random_bytes($each_size));
}
$expected_total = $n_chunks_3 * $each_size; // 20 MB
$expected_max_chunk = $each_size; // 2 MB
check('Fixture: total chunk size (20 MB) is clearly larger than any single chunk (2 MB)', $expected_total > $expected_max_chunk * 2);

$log_ref = new ReflectionMethod('RestorePilot_Backup_Migration', 'storage_dir');
$log_ref->setAccessible(true);
$log_path = $log_ref->invoke(null) . '/restorepilot.log';

$restore_path_3 = call_private('assemble_restore_chunks', [$upload_id, 'test-backup.zip', $n_chunks_3]);
check('Assembly of a normal, well-within-disk-space set still succeeds', is_file($restore_path_3));

// Read the log's own tail directly rather than a size-delta from before the
// call: write_log() caps the file at MAX_LOG_BYTES and trims older content
// to stay under it, so a file-size snapshot taken moments earlier can be
// larger than, equal to, or unrelated to the size after a new line is
// appended and old ones trimmed — a size-delta read is not reliable
// against a self-truncating log, regardless of how little time passes.
$tail = '';
if (is_file($log_path)) {
  $fh = fopen($log_path, 'rb');
  fseek($fh, -min(4000, filesize($log_path)), SEEK_END);
  $tail = fread($fh, 4000);
  fclose($fh);
}
if (preg_match('/estimated transient headroom needed ([0-9.]+ ?[A-Za-z]+) \(chunk set already on disk: ([0-9.]+ ?[A-Za-z]+)\)/', $tail, $m)) {
  echo "  Logged: needed {$m[1]}, chunk set on disk {$m[2]}\n";
  // The formula is max(largest single chunk, PART_SIZE=100MB) + 20MB. With
  // 2 MB chunks, PART_SIZE's floor dominates: expected ~120 MB. A buggy
  // total-based check (total_size + 20MB) would instead report ~40 MB for
  // this fixture's 20 MB total — well below 120 MB either way, so the real
  // discriminating check is that it's NOT anywhere near the 20 MB total,
  // landing at the PART_SIZE-floored value instead.
  $needed_mb = (float) $m[1];
  check('Logged "needed" is the PART_SIZE-floored per-chunk figure (~120 MB), not scaled to the 20 MB total', $needed_mb > 100 && $needed_mb < 150);
} else {
  check('Disk-check log line was found and parseable', false);
  echo "  Log tail was: " . substr($tail, -500) . "\n";
}

// --- Cleanup -----------------------------------------------------------------
if ($restore_path_2 !== null) { @unlink($restore_path_2); }
if (isset($restore_path_3) && is_file($restore_path_3)) { @unlink($restore_path_3); }
call_private('delete_directory', [$chunk_dir, $storage_dir]);

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
