<?php
// Regression test for the restore row-skip / table-skip cooperative
// time-check fix: catching up on already-restored rows (or coasting past
// already-completed tables) used to have NO time-budget check at all,
// because it never counted as "progress" and the existing check is gated
// behind $restore_chunk_progress_made. Verifies that with an already-
// expired chunk deadline, restore_database() now correctly throws a
// RestorePilot_Restore_Chunk_Yield_Exception (a clean, cooperative yield)
// while skipping already-inserted rows, rather than ignoring the deadline
// entirely and running the whole table to completion regardless.

$site_root = '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public';
require $site_root . '/wp-load.php';

function call_private($method, array $args = []) {
  $ref = new ReflectionMethod('RestorePilot_Backup_Migration', $method);
  $ref->setAccessible(true);
  return $ref->invokeArgs(null, $args);
}

function set_static_prop($name, $value) {
  $ref = new ReflectionProperty('RestorePilot_Backup_Migration', $name);
  $ref->setAccessible(true);
  $ref->setValue(null, $value);
}

function get_static_prop($name) {
  $ref = new ReflectionProperty('RestorePilot_Backup_Migration', $name);
  $ref->setAccessible(true);
  return $ref->getValue(null);
}

$failures = [];
function check($label, $cond) {
  global $failures;
  echo ($cond ? 'PASS' : 'FAIL') . "  $label\n";
  if (!$cond) $failures[] = $label;
}

global $wpdb;
$test_table = $wpdb->prefix . 'rp_skip_yield_test';
$wpdb->query("DROP TABLE IF EXISTS `$test_table`");
$wpdb->query("CREATE TABLE `$test_table` (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, val VARCHAR(50) NOT NULL) ENGINE=InnoDB");
for ($i = 1; $i <= 500; $i++) {
  $wpdb->insert($test_table, ['id' => $i, 'val' => "row-$i"]);
}
check('Fixture: 500-row source table created', (int) $wpdb->get_var("SELECT COUNT(*) FROM `$test_table`") === 500);

// A REAL backup + restore plan, so restore_database() gets everything it
// actually expects (a valid archive, manifest, and plan built the normal way).
$backup_result = call_private('create_backup_package', [false, '', [], false, false, ['triggered_by' => 'skip-yield-test']]);
check('Fixture backup created', !empty($backup_result['file']));
$base_path = call_private('backup_dir') . '/' . $backup_result['file'];

$zip = call_private('open_backup_archive', [$base_path]);
$manifest_raw = $zip->get_from_name('manifest.json');
$manifest = json_decode($manifest_raw, true);
$backup_prefix = $manifest['table_prefix'];
$plan_set = call_private('build_restore_plan', [$zip, $manifest, $backup_prefix]);

$plan_index = $plan_set['plan_by_table'][$test_table] ?? null;
check('Restore plan includes our fixture table', $plan_index !== null);
$tmp_table = $plan_set['plans'][$plan_index]['tmp_table'];

// Isolate the plan to ONLY our fixture table before calling
// restore_database(). Without this, a real backup also contains every
// other real WordPress table (wp_options, wp_posts, ...), and
// stream_database_records() would reach several of THOSE first — the
// first inserted row of the first real table sets
// restore_chunk_progress_made = true, which then lets the OLD, pre-
// existing, generic throw_if_restore_chunk_time_exceeded() check (fired
// at every table boundary, line ~5545) throw immediately on the very next
// table boundary, long before the stream ever reaches our fixture table.
// That check is a false pass: it proves the OLD check still works, not
// that the NEW row-skip check does. restore_database() already treats
// any table absent from plan_by_table as "deliberately excluded, skip its
// rows" (the same mechanism used for real exclusion lists) — so handing
// it a plan_set containing only our table safely makes every other real
// table a no-op pass-through: their rows never reach $wpdb->insert(), so
// progress_made stays false until OUR table's skip loop is reached.
$isolated_plan_set = [
  'plans' => [0 => $plan_set['plans'][$plan_index]],
  'plan_by_table' => [$test_table => 0],
];

// Simulate "this table was already partway restored in an EARLIER chunk":
// create its scratch table for real and durably insert some (not all) rows
// into it directly — exactly what restore_database() itself would have
// left behind before yielding previously.
$wpdb->query("DROP TABLE IF EXISTS `$tmp_table`");
$wpdb->query($plan_set['plans'][$plan_index]['create']);
for ($i = 1; $i <= 300; $i++) {
  $wpdb->insert($tmp_table, ['id' => $i, 'val' => "row-$i"]);
}
$existing_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$tmp_table`");
check('Fixture: scratch table pre-populated with 300 of 500 rows (simulating an earlier chunk)', $existing_count === 300);

// Force the chunk deadline to already be in the past — this is the exact
// condition that used to be silently ignored during the skip pass.
set_static_prop('restore_chunk_deadline', microtime(true) - 5.0);
set_static_prop('restore_chunk_progress_made', false);

$checkpoint_base = [
  'restore_zip_path' => $base_path,
  'manifest' => $manifest,
  'backup_prefix' => $backup_prefix,
  'restore_plan' => $isolated_plan_set,
  'source_url' => home_url(),
  'target_url' => home_url(),
  'lock_token' => 'test-token',
  'files_needed' => false,
  'resumption' => 2,
];

$threw_yield = false;
$threw_something_else = false;
$message = '';
try {
  call_private('restore_database', [$zip, $manifest, $isolated_plan_set, home_url(), home_url(), '', $checkpoint_base, []]);
} catch (RestorePilot_Restore_Chunk_Yield_Exception $e) {
  $threw_yield = true;
  $message = $e->getMessage();
} catch (Throwable $e) {
  $threw_something_else = true;
  $message = get_class($e) . ': ' . $e->getMessage();
}

check('restore_database() throws a cooperative Yield exception when the deadline is already past, mid-skip', $threw_yield);
if ($message !== '') { echo "  Message: $message\n"; }
check('...and NOT some other, unexpected exception type', !$threw_something_else);
// The message must be one of the two NEW, specific coasting/skip messages,
// not the old generic "Restore chunk time budget exceeded." (no context) —
// otherwise this would just be re-proving the pre-existing, already-working
// check fired instead of one of the new ones this test targets. Accepting
// either — not requiring the row-skip-catch-up one specifically — because
// this test isolates the plan to just the target table, meaning every OTHER
// real table in the fixture is "excluded from plan": since the sibling fix
// added the same day, those rows are now ALSO correctly checked (they used
// to have no check at all either), and can legitimately catch the already-
// expired deadline before the stream ever reaches the target table's own
// row-skip logic. Both outcomes equally prove a real, cooperative yield
// fired instead of silently ignoring the deadline — which one depends only
// on how many excluded rows happen to precede the isolated table in the
// fixture's own stream order, not on anything this test should be pinning
// down.
check('...and it is one of the two new, specific messages (proves a NEW check fired, not the old generic one)',
  $message === 'Restore chunk time budget exceeded while catching up on already-restored rows.'
  || $message === 'Restore chunk time budget exceeded while coasting past rows not needing insertion.');

// The skip must not have silently inserted rows past where it should have
// stopped — the tmp table should still show progress bounded by roughly
// where the deadline hit, not a runaway completion.
$final_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$tmp_table`");
check('No NEW rows were inserted past the already-restored 300 (pure skip, no progress possible with an expired deadline)', $final_count === 300);

// === Control: with a deadline that has NOT expired, the same skip-past-300
// scenario must complete normally — no false-positive yield — and actually
// insert the remaining 200 rows once the skip count is exhausted, proving
// the fix only yields when it should, not on every resumption. ============
set_static_prop('restore_chunk_deadline', microtime(true) + 3600);
set_static_prop('restore_chunk_progress_made', false);

$threw_unexpectedly = false;
$control_message = '';
try {
  call_private('restore_database', [$zip, $manifest, $isolated_plan_set, home_url(), home_url(), '', $checkpoint_base, []]);
} catch (Throwable $e) {
  $threw_unexpectedly = true;
  $control_message = get_class($e) . ': ' . $e->getMessage();
}
check('Control: with a non-expired deadline, restore_database() completes without throwing', !$threw_unexpectedly);
if ($control_message !== '') { echo "  Unexpected: $control_message\n"; }

// A successful completion runs the RENAME TABLE swap, so tmp_table no
// longer exists by name — its data (300 skipped + 200 newly inserted) is
// now wherever the swap put it: either still tmp_table (swap didn't reach
// this table) or the live table itself (swap succeeded). Check whichever
// one actually exists rather than assuming.
function row_count_wherever_it_landed($wpdb, $candidate_a, $candidate_b) {
  foreach ([$candidate_a, $candidate_b] as $candidate) {
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $candidate));
    if ($exists === $candidate) {
      return (int) $wpdb->get_var("SELECT COUNT(*) FROM `$candidate`");
    }
  }
  return -1; // neither exists — a real problem, not a stale reference.
}
$control_final_count = row_count_wherever_it_landed($wpdb, $tmp_table, $test_table);
check('Control: the remaining 200 rows were actually inserted once the skip was exhausted (300 + 200 = 500)', $control_final_count === 500);

// --- Cleanup -----------------------------------------------------------------
$wpdb->query("DROP TABLE IF EXISTS `$test_table`");
$wpdb->query("DROP TABLE IF EXISTS `$tmp_table`");
$wpdb->query("DROP TABLE IF EXISTS `{$plan_set['plans'][$plan_index]['final_table']}`");
$wpdb->query("DROP TABLE IF EXISTS `{$plan_set['plans'][$plan_index]['old_table_candidate']}`");
$zip->close();
@unlink($base_path);
foreach (call_private('discover_volumes', [$base_path])['paths'] ?? [] as $p) { @unlink($p); }
set_static_prop('restore_chunk_deadline', 0.0);
set_static_prop('restore_chunk_progress_made', false);

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
