<?php
// Regression test for the volume-filename-cap fix: is_follow_on_volume()
// and discover_volumes() used to require EXACTLY 3 digits (-vNNN.zip),
// while the writer's str_pad() naturally overflows to more digits past
// volume 999 (-v1000.zip) without truncating — meaning a set that grew
// past 999 volumes was invisible to discovery. Widened to {3,}. Uses
// hand-crafted files (no real 1000-volume backup needed) since
// discover_volumes() only ever reads filenames, never zip content.

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

$backup_dir = call_private('backup_dir');
$pre_existing = glob($backup_dir . '/*.zip') ?: [];
check('Backup dir starts clean for this test', count($pre_existing) === 0);

$base_name = 'sunhsine-bkp-local-backup-test-fixture-overflow.zip';
$base_path = $backup_dir . '/' . $base_name;
file_put_contents($base_path, 'base volume');
file_put_contents($backup_dir . '/sunhsine-bkp-local-backup-test-fixture-overflow-v002.zip', 'volume 2');
// The overflow case: a 4-digit follow-on, exactly what str_pad() produces
// past index 999 without truncating.
file_put_contents($backup_dir . '/sunhsine-bkp-local-backup-test-fixture-overflow-v1000.zip', 'volume 1000');

check('4-digit follow-on volume IS recognized as a follow-on (not a backup of its own)',
  call_private('is_follow_on_volume', ['sunhsine-bkp-local-backup-test-fixture-overflow-v1000.zip']) === true);
check('3-digit follow-on volume is STILL recognized (backward compatibility)',
  call_private('is_follow_on_volume', ['sunhsine-bkp-local-backup-test-fixture-overflow-v002.zip']) === true);
check('The base filename itself is NOT treated as a follow-on volume',
  call_private('is_follow_on_volume', [$base_name]) === false);

$discovered = call_private('discover_volumes', [$base_path]);
check('discover_volumes() finds all 3 volumes, including the 4-digit one', count($discovered['paths']) === 3);
check('discover_volumes() reports the correct highest index (1000)', $discovered['highest'] === 1000);
check('discover_volumes() reports indexes [1, 2, 1000]', $discovered['indexes'] === [1, 2, 1000]);

// list_backups() must show this as ONE backup entry (not 3), with the
// 4-digit volume correctly excluded from being its own listing.
$backups = call_private('list_backups');
check('list_backups() shows exactly 1 backup (not 3 — the 4-digit volume is correctly excluded)', count($backups) === 1);
if (count($backups) === 1) {
  check('list_backups() reports the correct volume count (3) for this backup', (int) $backups[0]['volumes'] === 3);
}

// --- Cleanup -----------------------------------------------------------------
foreach (glob($backup_dir . '/*.zip') ?: [] as $f) { @unlink($f); }

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
