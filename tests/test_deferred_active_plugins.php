<?php
// Verifies the fix for the mid-restore plugin-loading crash.
//
// The database phase swaps in the BACKUP's wp_options — including its
// active_plugins — while the file phase that would put those plugins' code
// on disk has not run yet. Every request in that window (including the
// restore's own next-chunk loopback and cron fallback) boots WordPress,
// which includes every active plugin from wp-settings.php with no error
// handling, before 'init' where the maintenance gate could intervene. A
// plugin whose files aren't there yet fatals that request outright, so the
// restore can never reach its next chunk. Confirmed live on a real 16 GB
// production restore (ACF Pro, then Yoast SEO).
//
// The fix holds active_plugins down to just RestorePilot from the swap
// until the file phase finishes, then reinstates the real list. The two
// properties that actually matter, and that this file exists to prove:
//   (1) the stash is written EXACTLY once, no matter how many resumptions
//       run — stashing twice would overwrite the real list with the
//       minimal one and lose the site's plugin set permanently;
//   (2) throughout the whole window the live option really is minimal, and
//       by completion the real list is genuinely back.

$site_root = '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public';
$plugin_file = '/Users/surajitroy/Local Sites/morecalculators-dev/app/public/wp-content/plugins/restorepilot-backup-migration/restorepilot-backup-migration.php';

// Local by Flywheel's MySQL socket. The default /tmp/mysql.sock belongs to an
// unrelated Homebrew MySQL on this machine, so it must be given explicitly.
// Used by db_option_raw()'s own fresh connections (see the note there).
const RP_TEST_SOCKET = '/Users/surajitroy/Library/Application Support/Local/run/gKsH4-EmV/mysql/mysqld.sock';

require $site_root . '/wp-load.php';
if (!class_exists('RestorePilot_Backup_Migration')) {
  require_once $plugin_file;
}

function call_private($method, array $args = []) {
  $ref = new ReflectionMethod('RestorePilot_Backup_Migration', $method);
  $ref->setAccessible(true);
  return $ref->invokeArgs(null, $args);
}
function const_of($name) {
  return constant('RestorePilot_Backup_Migration::' . $name);
}

$failures = [];
function check($label, $cond) {
  global $failures;
  echo ($cond ? 'PASS' : 'FAIL') . "  $label\n";
  if (!$cond) $failures[] = $label;
}

$DEFERRED = const_of('DEFERRED_PLUGINS_OPTION');
$self_basename = plugin_basename(constant('RESTOREPILOT_BACKUP_MIGRATION_FILE'));
$content_dir = call_private('content_dir');

// The real list is captured before anything touches it and force-restored in
// cleanup no matter how this test exits. Leaving a shared test site with its
// plugins switched off is exactly the failure mode this feature is about —
// it would be careless to introduce it here while testing the fix for it.
$original_active = get_option('active_plugins', []);
echo 'Captured original active_plugins: ' . count($original_active) . " entries.\n";

function restore_original_active_plugins() {
  global $original_active, $wpdb;
  // Written straight to the database, NOT via update_option(), for the same
  // reason the sampler reads directly — and this one matters more, because
  // failing silently leaves the shared test site broken. update_option()
  // early-returns without writing when the new value equals the OLD value it
  // reads, and it reads that through this parent's stale cache: the cache
  // still holds the original list from before the restore, so restoring the
  // original looks like a no-op and is skipped, leaving the site pointing at
  // the restored fixture plugins whose folders this test then deletes.
  // Observed exactly that: the site was left listing two plugins that no
  // longer existed on disk.
  $wpdb->query($wpdb->prepare(
    "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = 'active_plugins'",
    maybe_serialize($original_active)
  ));
  wp_cache_delete('alloptions', 'options');
  wp_cache_delete('active_plugins', 'options');
}
register_shutdown_function('restore_original_active_plugins');

// --- Fixture: two real plugin folders with valid headers -------------------
$fake_plugins = [
  'rp-defer-test-alpha' => 'rp-defer-test-alpha/rp-defer-test-alpha.php',
  'rp-defer-test-beta'  => 'rp-defer-test-beta/rp-defer-test-beta.php',
];
foreach ($fake_plugins as $dir => $rel) {
  $full_dir = $content_dir . '/plugins/' . $dir;
  if (is_dir($full_dir)) system('rm -rf ' . escapeshellarg($full_dir));
  mkdir($full_dir, 0777, true);
  file_put_contents(
    $content_dir . '/plugins/' . $rel,
    "<?php\n/**\n * Plugin Name: " . $dir . "\n */\n"
  );
}

// === Test 1: the deferral itself ==========================================
delete_option($DEFERRED);
$pretend_restored_list = array_values(array_merge(array_values($fake_plugins), [$self_basename]));
update_option('active_plugins', $pretend_restored_list);

call_private('defer_active_plugins_during_restore');

check('Live active_plugins is reduced to only RestorePilot', get_option('active_plugins') === [$self_basename]);
check('The real list was stashed intact', get_option($DEFERRED) === $pretend_restored_list);

// === Test 2: idempotency across resumptions — the critical property =======
// This runs on the unconditional post-database path, so it executes again on
// EVERY later chunk, at which point active_plugins is already the minimal
// list. A second stash would capture that minimal list and destroy the real
// one for good.
call_private('defer_active_plugins_during_restore');
call_private('defer_active_plugins_during_restore');
check('Stash still holds the REAL list after repeat calls (not overwritten with the minimal one)', get_option($DEFERRED) === $pretend_restored_list);
check('Live list is still minimal after repeat calls', get_option('active_plugins') === [$self_basename]);

// === Test 3: self-healing if something puts a foreign list back ===========
update_option('active_plugins', $pretend_restored_list);
call_private('defer_active_plugins_during_restore');
check('A foreign list written mid-window is pulled back down to minimal', get_option('active_plugins') === [$self_basename]);
check('Self-healing did not disturb the stash', get_option($DEFERRED) === $pretend_restored_list);

// === Test 4: has_orphaned_deferred_plugins() ==============================
call_private('force_release_restore_locks', ['none']);
check('Orphan detected when a stash exists and no restore is running', call_private('has_orphaned_deferred_plugins') === true);

$probe_token = call_private('acquire_restore_lock', ['rp-defer-probe']);
check('NOT flagged as orphaned while a restore is genuinely running', call_private('has_orphaned_deferred_plugins') === false);
call_private('force_release_restore_locks', ['rp-defer-probe']);

// === Test 5: reinstatement drops plugins whose files are gone =============
// beta's folder is removed first, standing in for a plugin the backup did
// not actually contain.
system('rm -rf ' . escapeshellarg($content_dir . '/plugins/rp-defer-test-beta'));
call_private('restore_deferred_active_plugins');
$after = get_option('active_plugins');
check('Plugin whose files exist was reactivated', in_array($fake_plugins['rp-defer-test-alpha'], $after, true));
check('Plugin whose files are missing was left deactivated', !in_array($fake_plugins['rp-defer-test-beta'], $after, true));
check('RestorePilot itself is still active', in_array($self_basename, $after, true));
check('Stash is cleared once reinstated', get_option($DEFERRED, null) === null);
check('No longer reports an orphan after reinstatement', call_private('has_orphaned_deferred_plugins') === false);

// === Test 6: RestorePilot is re-added even if the stashed list omitted it ==
// A backup taken on a site where RestorePilot was inactive would otherwise
// deactivate the very plugin the browser is still polling for status.
update_option($DEFERRED, [$fake_plugins['rp-defer-test-alpha']]);
update_option('active_plugins', [$self_basename]);
call_private('restore_deferred_active_plugins');
check('RestorePilot is re-added even when the stashed list did not include it', in_array($self_basename, get_option('active_plugins'), true));

// === Test 7: end-to-end through a REAL chunked restore ====================
// The only check that proves the window is actually covered in practice
// rather than in isolation: assert on every single chunk between the swap
// and files-done that the live option really is minimal.
mkdir($content_dir . '/plugins/rp-defer-test-beta', 0777, true);
file_put_contents(
  $content_dir . '/plugins/' . $fake_plugins['rp-defer-test-beta'],
  "<?php\n/**\n * Plugin Name: rp-defer-test-beta\n */\n"
);
delete_option($DEFERRED);

$e2e_list = array_values(array_merge(array_values($fake_plugins), [$self_basename]));
update_option('active_plugins', $e2e_list);

// The property under test is that the live option stays minimal across every
// chunk boundary of the FILE phase — so the file phase has to actually span
// several chunks, or there are no boundaries and the test is vacuous. This
// fixture must exist BEFORE the backup is taken, or it is not in the archive
// and the file phase has nothing extra to restore.
//
// Lowering the chunk budget alone does NOT lengthen that phase here, which is
// worth recording: this site's whole file phase completes in roughly one
// chunk no matter how small the budget gets, because RestorePilot stores zip
// entries uncompressed, so extraction is a straight byte copy at SSD speed.
// Dropping 5.0s to 2.0s took the DATABASE phase from 56 chunks to 238 and
// left the file phase at one — eight times slower while testing exactly as
// little. What lengthens the file phase is FILE COUNT, not byte count:
// per-file overhead (create, write, rename) dominates, so this adds many
// tiny files rather than a few large ones.
$file_phase_fixture = $content_dir . '/rp-defer-file-phase-fixture';
if (is_dir($file_phase_fixture)) system('rm -rf ' . escapeshellarg($file_phase_fixture));
mkdir($file_phase_fixture, 0777, true);
for ($i = 0; $i < 12000; $i++) {
  file_put_contents($file_phase_fixture . '/f' . $i . '.txt', 'rp-defer-file-phase-fixture-' . $i);
}
echo "Fixture: 12000 small files added before the backup, to lengthen the file phase.\n";

$backup_result = call_private('create_backup_package', [true, '', [], false, false, ['triggered_by' => 'deferred-plugins-test']]);
check('Fixture backup created', !empty($backup_result['file']));
$backup_path = call_private('backup_dir') . '/' . $backup_result['file'];

// Wipe both plugin folders so the file phase genuinely has to restore them —
// which is the whole point: during that phase their code is NOT on disk, and
// activating them would be exactly the fatal this fix prevents.
foreach (array_keys($fake_plugins) as $dir) {
  system('rm -rf ' . escapeshellarg($content_dir . '/plugins/' . $dir));
}
check('Fixture plugin folders are gone before the restore', !is_dir($content_dir . '/plugins/rp-defer-test-alpha'));

add_filter('pre_http_request', function () {
  return new WP_Error('blocked_for_test', 'Loopback dispatch blocked for test.');
}, 10, 3);
// The property under test is that the live option stays minimal across every
// chunk boundary of the FILE phase — so the file phase has to actually span
// several chunks, or there are no boundaries and the test is vacuous.
//
// Lowering the chunk budget alone does NOT achieve that here, which is worth
// recording: this site's whole file phase completes in roughly one chunk no
// matter how small the budget gets, because RestorePilot stores zip entries
// uncompressed, so extraction is a straight byte copy running at SSD speed.
// Dropping 5.0s to 2.0s changed the DB phase from 56 chunks to 238 and left
// the file phase at one, i.e. it made the test eight times slower while
// testing exactly as little. What actually lengthens the file phase is FILE
// COUNT, not byte count: per-file overhead (create, write, fsync, rename)
// is what dominates, so the fixture below adds many tiny files rather than a
// few large ones. Budget kept moderate so the database phase still converges
// in a sane number of chunks.
add_filter('restorepilot_restore_chunk_seconds', function () { return 3.0; });

call_private('ensure_storage');
$restore_zip_path = call_private('storage_dir') . '/restore-upload-' . wp_generate_uuid4() . '.zip';
foreach (call_private('volume_paths_for', [$backup_path]) as $src) {
  if ($src === $backup_path) { copy($src, $restore_zip_path); continue; }
  if (preg_match('/-v([0-9]{3,})\.zip$/', $src, $m)) {
    copy($src, preg_replace('/\.zip$/', '-v' . $m[1] . '.zip', $restore_zip_path));
  }
}

$job_id = 'rp-defer-e2e-' . wp_generate_uuid4();
$token = wp_generate_password(32, false, false);
call_private('set_restore_job', [$job_id, [
  'status' => 'queued', 'phase' => 'queued', 'phase_label' => 'Queued', 'progress' => 5, 'message' => 'queued',
  'restore_zip_path' => $restore_zip_path,
  'auto_detect_urls' => true,
  'restore_files' => true,
  'source_url' => '', 'target_url' => '',
  'token' => $token, 'poll_token' => wp_generate_password(32, false, false),
  'created' => time(), 'updated' => time(),
]]);

// Each chunk runs in its OWN process, not as a repeated in-process call.
// This is mandatory here, not stylistic: restore_files() persists its
// files_index checkpoint through maybe_touch_restore_job(), which throttles
// real writes to once per 5 seconds via a FUNCTION-LOCAL STATIC. That static
// resets on every genuine chunk (each is its own PHP process) but survives a
// same-process loop, which then suppresses nearly every checkpoint save — so
// the file phase keeps restarting from a stale index and never converges.
// Learned twice already on this plugin; hit again here the moment the file
// phase grew big enough to span chunks (400 chunks, never completing, while
// the identical code completes fine via subprocesses).
//
// It also makes the window sampling strictly more faithful: the parent now
// reads active_plugins from a genuinely separate process between chunks,
// which is exactly what a real loopback or cron bootstrap would see.
$php_bin = '/Users/surajitroy/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php';
$sock = '/Users/surajitroy/Library/Application Support/Local/run/gKsH4-EmV/mysql/mysqld.sock';
$runner = __DIR__ . '/run_one_restore_chunk.php';

// Reads an option over a BRAND-NEW database connection every time, bypassing
// both WordPress's object cache and this parent's long-lived $wpdb handle.
//
// Two earlier versions of this sampler each reported violations that were not
// real, and both were wrong in a different way, so this one removes the whole
// class of problem rather than patching another symptom:
//   - get_option() lied because this parent is one long-lived process whose
//     object cache (including the 'notoptions' list of names already seen as
//     absent, which nothing here invalidates) keeps serving a pre-restore
//     view.
//   - A direct $wpdb query still lied: the parent's own connection is closed
//     and reopened around every shell_exec() and carries its own InnoDB
//     REPEATABLE READ snapshot across the RENAME TABLE swap, so it can read
//     a table version that no longer reflects what is committed.
// Both reported active_plugins as absent at moments when a separate query
// proved the row present and correct — and the plugin's own log confirmed
// the deferral and reinstatement had run exactly as intended.
//
// A fresh connection is also the faithful model of what actually mattered:
// a real chunk is a NEW process, with a new connection and an empty cache,
// reading this row at bootstrap. That is the read whose result decided
// whether the site fataled.
function db_option_raw($name) {
  $conn = @mysqli_connect(null, DB_USER, DB_PASSWORD, DB_NAME, null, RP_TEST_SOCKET);
  if (!$conn) {
    throw new RuntimeException('Sampler could not open its own database connection: ' . mysqli_connect_error());
  }
  $stmt = mysqli_prepare($conn, 'SELECT option_value FROM wp_options WHERE option_name = ?');
  mysqli_stmt_bind_param($stmt, 's', $name);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  $row = $result ? mysqli_fetch_row($result) : null;
  mysqli_stmt_close($stmt);
  mysqli_close($conn);
  return $row === null ? null : $row[0];
}

function sample_live_active_plugins() {
  $raw = db_option_raw('active_plugins');
  if ($raw === null) {
    return null;
  }
  $value = maybe_unserialize($raw);
  return is_array($value) ? array_values($value) : $value;
}

$iterations = 0;
$status = 'running';
$in_window = false;
$window_samples = 0;
$window_violations = 0;
global $wpdb;
do {
  $iterations++;

  // Sampled BEFORE the chunk runs, not only after it. This is the faithful
  // model of the real failure: the crash happened when a fresh loopback or
  // cron request BOOTED between chunks and wp-settings.php included whatever
  // active_plugins named at that instant. Checking only after each chunk
  // would miss exactly that gap.
  if ($in_window) {
    $window_samples++;
    $live_at_boot = sample_live_active_plugins();
    if ($live_at_boot !== [$self_basename]) {
      $window_violations++;
      if ($window_violations <= 3) {
        echo '  Violation at chunk-start ' . $iterations . ': ' . wp_json_encode($live_at_boot) . "\n";
      }
    }
  }

  // The parent's own open connection stops the forked child's writes from
  // ever durably committing — close it first; wpdb reconnects lazily.
  $wpdb->close();
  shell_exec(sprintf(
    '%s -d %s -d %s %s %s %s %s 2>&1',
    escapeshellarg($php_bin),
    escapeshellarg('mysqli.default_socket=' . $sock),
    escapeshellarg('pdo_mysql.default_socket=' . $sock),
    escapeshellarg($runner),
    escapeshellarg($job_id),
    escapeshellarg($token),
    escapeshellarg('3.0')
  ));

  wp_cache_delete(call_private('restore_job_option', [$job_id]), 'options');
  $job = call_private('get_restore_job', [$job_id]);
  $status = $job['status'] ?? '(missing)';
  $cp = $job['checkpoint'] ?? [];

  // The danger window: database swapped in, file phase not finished yet.
  $in_window = !empty($cp['database_done']) && empty($cp['files_done']) && $status === 'running';
  if ($in_window) {
    $window_samples++;
    $live = sample_live_active_plugins();
    if ($live !== [$self_basename]) {
      $window_violations++;
      if ($window_violations <= 3) {
        echo '  Violation at chunk-end ' . $iterations . ': ' . wp_json_encode($live) . "\n";
      }
    }
  }
  if ($iterations % 25 === 0) {
    echo '  [iter ' . $iterations . '] status=' . $status . ' phase=' . ($job['phase'] ?? '?')
      . ' db_done=' . var_export($cp['database_done'] ?? null, true)
      . ' files_index=' . ($cp['files_index'] ?? '?')
      . ' window_samples=' . $window_samples . "\n";
  }
} while ($status === 'running' && $iterations < 400);

echo "Resumptions: $iterations, final status: $status, samples taken inside the danger window: $window_samples\n";
check('Restore reached complete status', $status === 'complete');
// Guards against the test silently degrading into a no-op: if the file phase
// ever finishes in a single chunk again, there are no boundaries to test and
// this must fail loudly rather than pass on one trivial sample.
check('The danger window was sampled repeatedly across real chunk boundaries (test is meaningful)', $window_samples >= 4);
check('active_plugins stayed minimal at EVERY sample inside the danger window', $window_violations === 0);

// Every one of these reads a value the CHILD process wrote, so the parent's
// own object cache has to be busted first or they all report this parent's
// stale pre-restore view.
$final_active = sample_live_active_plugins();
check('Both fixture plugins are active again after completion', is_array($final_active) && in_array($fake_plugins['rp-defer-test-alpha'], $final_active, true) && in_array($fake_plugins['rp-defer-test-beta'], $final_active, true));
check('RestorePilot is active after completion', is_array($final_active) && in_array($self_basename, $final_active, true));
check('Stash option is cleared after completion', db_option_raw($DEFERRED) === null);
check('Restored plugin files are genuinely back on disk', is_file($content_dir . '/plugins/' . $fake_plugins['rp-defer-test-alpha']));
$final_rules = db_option_raw('rewrite_rules');
check('rewrite_rules was dropped so it rebuilds with all plugins loaded', $final_rules === null || $final_rules === '');

// --- Cleanup ---------------------------------------------------------------
foreach (array_keys($fake_plugins) as $dir) {
  system('rm -rf ' . escapeshellarg($content_dir . '/plugins/' . $dir));
}
system('rm -rf ' . escapeshellarg($file_phase_fixture));
delete_option($DEFERRED);
foreach (call_private('discover_volumes', [$backup_path])['paths'] as $p) { @unlink($p); }
foreach (call_private('volume_paths_for', [$restore_zip_path]) as $p) { @unlink($p); }
$status_file_ref = new ReflectionMethod('RestorePilot_Backup_Migration', 'restore_status_file');
$status_file_ref->setAccessible(true);
@unlink($status_file_ref->invoke(null, $job_id));
$poll_ref = new ReflectionMethod('RestorePilot_Backup_Migration', 'poll_token_file');
$poll_ref->setAccessible(true);
@unlink($poll_ref->invoke(null, $job_id));
delete_option('restorepilot_restore_job_' . sanitize_key($job_id));
wp_clear_scheduled_hook('restorepilot_cron_restore_job', [$job_id, $token]);
call_private('force_release_restore_locks', [$job_id]);
restore_original_active_plugins();

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
