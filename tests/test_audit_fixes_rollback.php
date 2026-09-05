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

// Regression test for the rollback-point multi-volume grouping fix.
// Verifies list_restore_rollback_points() treats a multi-volume rollback
// set as ONE logical point (not one per physical volume), and that
// enforce_restore_rollback_retention() deletes every volume of an evicted
// set together rather than leaving orphaned siblings behind.

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

$rollback_dir = call_private('rollback_dir');
// This directory is shared with the real site, not exclusive to this test —
// confirmed the hard way: it already held a real rollback point during
// today's testing, which made "starts clean" false and, worse, meant this
// test's OWN fixture-creation calls below (create_restore_rollback_point()
// enforces the real MAX_RESTORE_ROLLBACKS=3 cap against this same real
// directory as an ordinary side effect) could silently evict real content
// mid-test, not just leave a wrong assertion — a before/after diff on the
// assertions alone cannot fix that, since the unwanted eviction already
// happened by the time anything is diffed. Move anything pre-existing out
// of the way for the duration of the test (same filesystem as storage_dir,
// so rename() is instant, not a slow copy) and back afterward, so the rest
// of this test's logic can safely keep assuming a clean, isolated slate.
$holding_dir = call_private('storage_dir') . '/restore-rollbacks-test-holding';
if (is_dir($holding_dir)) { system('rm -rf ' . escapeshellarg($holding_dir)); }
mkdir($holding_dir, 0777, true);
$moved_aside = [];
foreach (glob($rollback_dir . '/*.zip') ?: [] as $f) {
  $dest = $holding_dir . '/' . basename($f);
  rename($f, $dest);
  $moved_aside[] = $dest;
}
check('Rollback dir starts clean for this test', count(glob($rollback_dir . '/*.zip') ?: []) === 0);

// --- Fixture 1: a MULTI-VOLUME rollback point. A real rollback is
// database-only (no files), and this test site's whole DB export is one
// small entry — volume rollover only happens BETWEEN entries, so no
// filter on restorepilot_backup_volume_bytes can force a split when there
// are only 2 entries total (one DB part + manifest.json) regardless of how
// small the budget is. list_restore_rollback_points()/
// enforce_restore_rollback_retention() only ever read filenames and
// filesizes via discover_volumes()/volume_paths_for() — never zip content
// — so hand-crafted files with the real naming pattern exercise the exact
// same grouping logic a real multi-volume set would, without needing a
// database large enough to naturally produce one.
$base_name = 'sunhsine-bkp-local-restore-rollback-test-fixture-multivol.zip';
$base_path = $rollback_dir . '/' . $base_name;
file_put_contents($base_path, str_repeat('A', 5000));
file_put_contents($rollback_dir . '/sunhsine-bkp-local-restore-rollback-test-fixture-multivol-v002.zip', str_repeat('B', 7000));
file_put_contents($rollback_dir . '/sunhsine-bkp-local-restore-rollback-test-fixture-multivol-v003.zip', str_repeat('C', 3000));

$after_fixture1 = glob($rollback_dir . '/*.zip') ?: [];
check('The multi-volume rollback fixture has 3 physical files on disk', count($after_fixture1) === 3);
usleep(1100000);

// --- Fixtures 2 and 3: normal single-volume rollback points ---
call_private('create_restore_rollback_point');
usleep(1100000);
call_private('create_restore_rollback_point');
usleep(1100000);

// === Test: list_restore_rollback_points() groups correctly ================
$points = call_private('list_restore_rollback_points');
check('Exactly 3 LOGICAL rollback points reported (not 1 per physical volume)', count($points) === 3);

$multi_volume_point = null;
foreach ($points as $p) {
  $volumes = call_private('volume_paths_for', [$p['path']]);
  if (count($volumes) > 1) {
    $multi_volume_point = $p;
    break;
  }
}
check('The multi-volume point is identifiable and reports size across ALL its volumes', $multi_volume_point !== null);
if ($multi_volume_point !== null) {
  $volumes = call_private('volume_paths_for', [$multi_volume_point['path']]);
  $real_total = 0;
  foreach ($volumes as $v) { $real_total += filesize($v); }
  check('Reported size matches the true sum of all volumes (not just the first)', $multi_volume_point['size'] === $real_total);
  check('Reported size is bigger than any single volume alone (proves it summed, not took one)', $multi_volume_point['size'] > filesize($volumes[0]));
}

// === Test: retention deletes an evicted multi-volume set completely, not
// partially. create_restore_rollback_point() already calls
// enforce_restore_rollback_retention() internally on every call (same
// pattern as backup creation) — so eviction happens as a side effect of
// the 4th creation call itself, not as a separate step afterward. =========
$points_before = call_private('list_restore_rollback_points');
check('3 logical points exist right before the 4th rollback is created', count($points_before) === 3);
$oldest = end($points_before); // list is newest-first; last is oldest
check('The multi-volume fixture is correctly identified as the oldest (about to be evicted)', count(call_private('volume_paths_for', [$oldest['path']])) === 3);
$oldest_volumes = call_private('volume_paths_for', [$oldest['path']]);

call_private('create_restore_rollback_point'); // its own internal retention call evicts $oldest

$points_after = call_private('list_restore_rollback_points');
check('Still exactly 3 logical points after the 4th creation (oldest evicted, not just appended)', count($points_after) === 3);

$orphans = 0;
foreach ($oldest_volumes as $v) {
  if (is_file($v)) { $orphans++; }
}
check('EVERY volume of the evicted (oldest) point was removed — none left orphaned', $orphans === 0);

// No stray physical files belonging to a NON-evicted point should have been
// touched either.
$remaining_physical = glob($rollback_dir . '/*.zip') ?: [];
$expected_remaining = 0;
foreach ($points_after as $p) {
  $expected_remaining += count(call_private('volume_paths_for', [$p['path']]));
}
check('Physical file count on disk matches exactly what the 3 surviving logical points account for', count($remaining_physical) === $expected_remaining);

// --- Cleanup -----------------------------------------------------------------
// Safe to unconditionally clear here: the directory held only this test's
// own fixtures for its whole duration, since anything pre-existing was
// moved aside above before a single fixture was created.
foreach (glob($rollback_dir . '/*.zip') ?: [] as $f) { @unlink($f); }
foreach ($moved_aside as $f) {
  rename($f, $rollback_dir . '/' . basename($f));
}
@rmdir($holding_dir);

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
