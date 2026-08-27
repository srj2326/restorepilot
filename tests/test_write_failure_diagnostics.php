<?php
// Verifies throw_write_failure() (the new shared diagnostic behind
// write_stream()/write_file()) actually produces a useful message under a
// REAL ENOSPC condition, not just one that looks right on paper — this is
// exactly the failure the plugin author's own Mac hit (disk nearly full)
// during a chunked restore-upload assembly, reported as a bare, useless
// "Could not write backup data during assemble restore upload." with no
// detail in the log either.

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

$ramdisk = '/Volumes/RPTestRamDisk';
check('Ramdisk mount point exists', is_dir($ramdisk));

$target = $ramdisk . '/write-failure-test.bin';
$handle = fopen($target, 'wb');
check('Could open file on ramdisk', $handle !== false);

// Write past the ramdisk's real capacity (~8MB) with a single write_stream()
// call carrying more data than fits, exactly like assemble_restore_chunks()
// would for one oversized part.
$payload = str_repeat('X', 20 * 1024 * 1024); // 20MB into an 8MB volume

$threw = false;
$message = '';
try {
  call_private('write_stream', [$handle, $target, $payload, 'assemble restore upload']);
} catch (RuntimeException $e) {
  $threw = true;
  $message = $e->getMessage();
}
@fclose($handle);

check('write_stream() throws when the destination genuinely runs out of space', $threw);
echo "\n--- Actual exception message ---\n$message\n---------------------------------\n\n";

check('Message is NOT the old bare/generic text', strpos($message, 'Could not write backup data during') === false || strlen($message) > 80);
check('Message names the operation context', strpos($message, 'assemble restore upload') !== false);
check('Message reports how much was already written', (bool) preg_match('/\d[\d,]*\s*(bytes|KB|MB|GB)/i', $message));
check('Message gives free-space or errno-based guidance, not just "no further detail"', strpos($message, 'reported no further detail') === false);

@unlink($target);

// Regression: a normal, healthy write still works and is silent.
$normal_target = sys_get_temp_dir() . '/rp-write-ok-test.bin';
$normal_handle = fopen($normal_target, 'wb');
$ok = true;
try {
  call_private('write_stream', [$normal_handle, $normal_target, 'hello world', 'unit test']);
} catch (Throwable $e) {
  $ok = false;
}
fclose($normal_handle);
check('A normal write_stream() call on a healthy destination still succeeds', $ok && file_get_contents($normal_target) === 'hello world');
@unlink($normal_target);

// write_file() regression + failure-path sanity (permission-denied instead
// of ENOSPC, since file_put_contents() takes a path rather than a handle).
$ro_dir = sys_get_temp_dir() . '/rp-write-file-ro-test';
@mkdir($ro_dir, 0755, true);
chmod($ro_dir, 0500); // read+execute only, no write
$ro_target = $ro_dir . '/blocked.txt';
$threw_wf = false;
$message_wf = '';
try {
  call_private('write_file', [$ro_target, 'content', 'unit test file']);
} catch (RuntimeException $e) {
  $threw_wf = true;
  $message_wf = $e->getMessage();
}
chmod($ro_dir, 0755);
@rmdir($ro_dir);
check('write_file() throws on a permission-denied destination', $threw_wf);
echo "--- write_file() failure message ---\n$message_wf\n-------------------------------------\n";

$normal_file_target = sys_get_temp_dir() . '/rp-write-file-ok-test.txt';
$ok_wf = true;
try {
  call_private('write_file', [$normal_file_target, 'hello', 'unit test file']);
} catch (Throwable $e) {
  $ok_wf = false;
}
check('A normal write_file() call still succeeds', $ok_wf && file_get_contents($normal_file_target) === 'hello');
@unlink($normal_file_target);

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
