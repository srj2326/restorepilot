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

// The safety net: can a user whose restore failed actually get their site
// back from the pre-restore rollback point?
//
// This was broken outright until 2026-08-24, and nothing tested it. Every
// restore creates a rollback point first; when a restore fails the plugin
// tells the user "A pre-restore rollback point was saved. Scroll down to
// 'Pre-Restore Rollback Points' to recover your database", and renders a
// "Restore from this point" button beside each one. That button feeds the
// file into the ordinary restore form — which called validate_backup_zip()
// with require_full_restore=true and refused it, because a rollback point is
// database-only BY DESIGN (backup_type 'rollback', restorable false) and the
// readiness check demanded backup_type === 'full'. The user was told to
// "upload the full backup zip", of a file the plugin itself had just created
// as the recovery mechanism. There was no other in-product route: downloading
// and re-uploading hits the identical validation.
//
// Every other bug in this plugin is survivable BECAUSE the rollback exists,
// so this file guards the thing the rest of the safety story rests on.

$site_root = '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public';
$plugin_file = '/Users/surajitroy/Local Sites/morecalculators-dev/app/public/wp-content/plugins/restorepilot-backup-migration/restorepilot-backup-migration.php';

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

$failures = [];
function check($label, $cond) {
  global $failures;
  echo ($cond ? 'PASS' : 'FAIL') . "  $label\n";
  if (!$cond) $failures[] = $label;
}

// Reads over a BRAND-NEW connection every time. This parent is long-lived and
// drives real restores that swap wp_options underneath it, so neither its
// object cache nor its own $wpdb handle can be trusted to report what is
// actually committed — both produced confidently wrong readings while the
// deferred-plugins work was being verified.
function db_option_raw($name) {
  $conn = @mysqli_connect(null, DB_USER, DB_PASSWORD, DB_NAME, null, RP_TEST_SOCKET);
  if (!$conn) {
    throw new RuntimeException('Could not open sampler connection: ' . mysqli_connect_error());
  }
  $stmt = mysqli_prepare($conn, 'SELECT option_value FROM wp_options WHERE option_name = ?');
  mysqli_stmt_bind_param($stmt, 's', $name);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  $row = $res ? mysqli_fetch_row($res) : null;
  mysqli_stmt_close($stmt);
  mysqli_close($conn);
  return $row === null ? null : $row[0];
}

function db_set_option_raw($name, $value) {
  $conn = @mysqli_connect(null, DB_USER, DB_PASSWORD, DB_NAME, null, RP_TEST_SOCKET);
  $serialized = maybe_serialize($value);
  $stmt = mysqli_prepare($conn, 'UPDATE wp_options SET option_value = ? WHERE option_name = ?');
  mysqli_stmt_bind_param($stmt, 'ss', $serialized, $name);
  mysqli_stmt_execute($stmt);
  mysqli_stmt_close($stmt);
  mysqli_close($conn);
}

$MARKER = 'rp_rollback_recovery_marker';
$DEFERRED = constant('RestorePilot_Backup_Migration::DEFERRED_PLUGINS_OPTION');
$rollback_dir = call_private('rollback_dir');

// Captured up front and force-restored at the end no matter how this exits.
$original_active = maybe_unserialize(db_option_raw('active_plugins'));
$rollbacks_at_start = glob($rollback_dir . '/*.zip') ?: [];
$created_backups = [];

register_shutdown_function(function () use ($original_active) {
  db_set_option_raw('active_plugins', $original_active);
});

// === Test 1: a rollback point is accepted by the restore validator ========
// The core regression. Uses a REAL rollback point produced by the plugin's
// own create_restore_rollback_point(), not a hand-built archive, so the
// manifest under test is exactly the one a failed restore leaves behind.
call_private('create_restore_rollback_point');
$rollbacks_after = glob($rollback_dir . '/*.zip') ?: [];
$new_rollbacks = array_values(array_diff($rollbacks_after, $rollbacks_at_start));
check('A rollback point was created', count($new_rollbacks) === 1);
$rollback_path = $new_rollbacks[0] ?? '';

if ($rollback_path !== '') {
  $rb_zip = call_private('open_backup_archive', [$rollback_path]);
  $rb_manifest = json_decode($rb_zip->get_from_name('manifest.json'), true);

  // Confirms the shape that used to be rejected — if these ever change, the
  // fix below is guarding something that no longer exists.
  check("Rollback manifest is database-only (backup_type 'rollback', includes_files false)",
    ($rb_manifest['backup_type'] ?? '') === 'rollback' && empty($rb_manifest['includes_files']));
  check('Rollback manifest is correctly NOT marked full-restorable', empty($rb_manifest['restorable']));

  $readiness = call_private('backup_restore_readiness', [$rb_manifest, 0]);
  check('Readiness still reports it as not full-restorable (guard intact)', empty($readiness['restorable']));
  check('Readiness reports it as restorable database-only (the fix)', !empty($readiness['database_only_restorable']));

  // The actual gate that failed before: perform_restore() calls this exact
  // form, with require_full_restore = true.
  $threw = null;
  try {
    call_private('validate_backup_zip', [$rb_zip, true, true]);
  } catch (Throwable $e) {
    $threw = $e->getMessage();
  }
  check('validate_backup_zip() ACCEPTS a rollback point with require_full_restore=true', $threw === null);
  if ($threw !== null) { echo "  Refused with: $threw\n"; }
  $rb_zip->close();
}

// === Test 2: the guard was narrowed, not removed ==========================
// An ordinary database-only backup (purpose 'backup') must STILL be refused —
// that check exists so nobody restores a plugins-only or database-only
// archive over their site expecting a whole-site restore.
$db_only = call_private('create_backup_package', [false, '', [], false, false, ['triggered_by' => 'rollback-recovery-test-dbonly']]);
$db_only_path = call_private('backup_dir') . '/' . $db_only['file'];
$created_backups[] = $db_only_path;
$db_zip = call_private('open_backup_archive', [$db_only_path]);
$db_manifest = json_decode($db_zip->get_from_name('manifest.json'), true);
check("Control archive really is a plain database-only backup", ($db_manifest['backup_type'] ?? '') === 'database');
$threw2 = null;
try {
  call_private('validate_backup_zip', [$db_zip, true, true]);
} catch (Throwable $e) {
  $threw2 = $e->getMessage();
}
check('A plain database-only backup is STILL refused (guard not widened)', $threw2 !== null);
$db_zip->close();

// === Test 3: retention never evicts the archive being restored ============
// Restoring the OLDEST rollback point is exactly what someone reaching for
// the furthest-back recovery does — and it is the first one retention would
// remove when the restore creates its own new rollback point.
while (count(glob($rollback_dir . '/*.zip') ?: []) < RestorePilot_Backup_Migration::MAX_RESTORE_ROLLBACKS) {
  usleep(1100000); // distinct mtimes, so "oldest" is unambiguous
  call_private('create_restore_rollback_point');
}
$points_before = call_private('list_restore_rollback_points');
check('At the retention cap before the next rollback is created', count($points_before) === RestorePilot_Backup_Migration::MAX_RESTORE_ROLLBACKS);
$oldest = end($points_before); // newest-first, so last is oldest
$oldest_path = $oldest['path'];

usleep(1100000);
call_private('create_restore_rollback_point', [$oldest_path]);
check('The rollback point being restored FROM survives retention', is_file($oldest_path));
check('One extra point is kept rather than destroying the restore source', count(call_private('list_restore_rollback_points')) === RestorePilot_Backup_Migration::MAX_RESTORE_ROLLBACKS + 1);

// Control: without protection the oldest IS evicted, proving the guard is
// what saved it above rather than retention simply not running.
$points_now = call_private('list_restore_rollback_points');
$oldest_now = end($points_now);
usleep(1100000);
call_private('create_restore_rollback_point');
check('Control: an UNprotected oldest point is still evicted normally', !is_file($oldest_now['path']));

// === Test 4: end-to-end recovery from a restore aborted after the swap ====
// The real scenario, and the only one that proves the pieces work together.
// Two real plugin folders, so the backup and the rollback can carry
// DIFFERENT active_plugins lists. That difference is what proves recovery
// reinstates the ROLLBACK's list rather than the failed restore's — the two
// are easy to confuse, because the abandoned restore leaves its own stash
// behind and a new restore that reused it would silently reinstate the wrong
// site's plugin set.
$self_basename = plugin_basename(constant('RESTOREPILOT_BACKUP_MIGRATION_FILE'));
$plugins_dir = call_private('content_dir') . '/plugins';
$fake_plugins = [
  'alpha' => 'rp-rbk-alpha/rp-rbk-alpha.php',
  'beta'  => 'rp-rbk-beta/rp-rbk-beta.php',
];
foreach ($fake_plugins as $rel) {
  $dir = $plugins_dir . '/' . dirname($rel);
  if (is_dir($dir)) system('rm -rf ' . escapeshellarg($dir));
  mkdir($dir, 0777, true);
  file_put_contents($plugins_dir . '/' . $rel, "<?php\n/**\n * Plugin Name: " . dirname($rel) . "\n */\n");
}
$list_in_backup   = [$fake_plugins['alpha'], $fake_plugins['beta'], $self_basename];
$list_in_rollback = [$fake_plugins['alpha'], $self_basename];

db_set_option_raw('active_plugins', $list_in_backup);
$conn = mysqli_connect(null, DB_USER, DB_PASSWORD, DB_NAME, null, RP_TEST_SOCKET);
mysqli_query($conn, "DELETE FROM wp_options WHERE option_name = '$MARKER'");
mysqli_query($conn, "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('$MARKER', 'STATE-INSIDE-BACKUP', 'no')");
mysqli_close($conn);

$full = call_private('create_backup_package', [true, '', [], false, false, ['triggered_by' => 'rollback-recovery-test-full']]);
check('Full backup created (the archive the doomed restore will use)', !empty($full['file']));
$full_path = call_private('backup_dir') . '/' . $full['file'];
$created_backups[] = $full_path;

// The live state we expect to get BACK. It exists only after the backup, so
// it is absent from the archive — which is what makes it a real proof: if it
// returns, the recovery genuinely came from the rollback point.
db_set_option_raw($MARKER, 'STATE-TO-RECOVER');
check('Live marker set to the value recovery must bring back', db_option_raw($MARKER) === 'STATE-TO-RECOVER');
// Live plugin list now differs from the one inside the backup, so the
// rollback taken at the start of the doomed restore captures THIS one.
db_set_option_raw('active_plugins', $list_in_rollback);
check('Live plugin list differs from the backup\'s (so recovery can be told apart)', maybe_unserialize(db_option_raw('active_plugins')) === $list_in_rollback);

// Many small files, so the FILE phase genuinely spans chunk boundaries and
// the doomed restore can be abandoned partway THROUGH it rather than after
// it. Byte count barely matters here; per-file overhead is what lengthens
// the phase. Must exist before the backup is taken to be inside the archive.
$file_fixture = $content_dir_for_fixture = call_private('content_dir') . '/rp-rollback-recovery-fixture';
if (is_dir($file_fixture)) system('rm -rf ' . escapeshellarg($file_fixture));
mkdir($file_fixture, 0777, true);
for ($i = 0; $i < 12000; $i++) {
  file_put_contents($file_fixture . '/f' . $i . '.txt', 'rp-rollback-recovery-' . $i);
}
echo "Fixture: 12000 files added so the file phase spans chunks.\n";

add_filter('pre_http_request', function () {
  return new WP_Error('blocked_for_test', 'Loopback dispatch blocked for test.');
}, 10, 3);
// Mutable by reference: the doomed restore needs a SMALL budget so its file
// phase yields and can be caught mid-way, while the recovery restore after
// it just needs to finish, and a small budget there would drag its database
// phase out for no benefit. A single fixed value cannot do both — an earlier
// version used 60s throughout and the doomed restore simply ran to
// completion in one chunk, quietly testing the easy case instead of the one
// that matters.
$chunk_budget = 3.0;
add_filter('restorepilot_restore_chunk_seconds', function () use (&$chunk_budget) { return $chunk_budget; });

call_private('ensure_storage');
$doomed_zip_path = call_private('storage_dir') . '/restore-upload-' . wp_generate_uuid4() . '.zip';
foreach (call_private('volume_paths_for', [$full_path]) as $src) {
  if ($src === $full_path) { copy($src, $doomed_zip_path); continue; }
  if (preg_match('/-v([0-9]{3,})\.zip$/', $src, $m)) {
    copy($src, preg_replace('/\.zip$/', '-v' . $m[1] . '.zip', $doomed_zip_path));
  }
}

$rollbacks_before_doomed = glob($rollback_dir . '/*.zip') ?: [];
$doomed_job = 'rp-rollback-doomed-' . wp_generate_uuid4();
$doomed_token = wp_generate_password(32, false, false);
call_private('set_restore_job', [$doomed_job, [
  'status' => 'queued', 'phase' => 'queued', 'phase_label' => 'Queued', 'progress' => 5, 'message' => 'queued',
  'restore_zip_path' => $doomed_zip_path,
  'auto_detect_urls' => true,
  'restore_files' => true,   // there must be a file phase left to die during
  'source_url' => '', 'target_url' => '',
  'token' => $doomed_token, 'poll_token' => wp_generate_password(32, false, false),
  'created' => time(), 'updated' => time(),
]]);

// Drive chunks only until the database swap has landed, then simply stop —
// the faithful model of a restore whose process died mid-file-phase, which
// is the worst realistic moment and the one the rollback exists for.
// Stops the moment the restore is INSIDE the file phase — database swapped,
// files still going. Waiting only for database_done is not enough: the chunk
// that sets it can also finish the whole file phase, which is exactly how an
// earlier version of this test ended up verifying the easy case.
$iterations = 0;
$mid_files = false;
$status = 'running';
do {
  $iterations++;
  RestorePilot_Backup_Migration::run_restore_job($doomed_job, $doomed_token);
  $job = call_private('get_restore_job', [$doomed_job]);
  $status = $job['status'] ?? '(missing)';
  $cp = $job['checkpoint'] ?? [];
  $mid_files = !empty($cp['database_done']) && empty($cp['files_done']) && $status === 'running';
  if ($iterations % 25 === 0) {
    echo '  [doomed iter ' . $iterations . '] status=' . $status . ' phase=' . ($job['phase'] ?? '?')
      . ' db_done=' . var_export($cp['database_done'] ?? null, true)
      . ' files_index=' . ($cp['files_index'] ?? '?') . "\n";
  }
} while (!$mid_files && $status === 'running' && $iterations < 400);

$cp = $job['checkpoint'] ?? [];
echo "Doomed restore abandoned after $iterations chunk(s): status=$status"
  . ' db_done=' . var_export($cp['database_done'] ?? null, true)
  . ' files_done=' . var_export($cp['files_done'] ?? null, true)
  . ' files_index=' . ($cp['files_index'] ?? '?') . "\n";

check('The doomed restore got past the database swap', !empty($cp['database_done']));
check('It was abandoned genuinely mid-FILE-phase, not after completing', $mid_files === true);
check('Site now holds the backup\'s data, not the live data', db_option_raw($MARKER) === 'STATE-INSIDE-BACKUP');
// The abandoned restore should have left the site in the deferred-plugins
// state built earlier today — plugins held back, stash present. Recovery has
// to work FROM that state, which is the realistic starting point.
$stash_during = db_option_raw($DEFERRED);
check('Abandoned restore left the deferred-plugins state active (realistic recovery starting point)', $stash_during !== null);
$active_during = maybe_unserialize(db_option_raw('active_plugins'));
check('Only RestorePilot is active while abandoned (site still bootable)', $active_during === [plugin_basename(constant('RESTOREPILOT_BACKUP_MIGRATION_FILE'))]);

$rollbacks_after_doomed = glob($rollback_dir . '/*.zip') ?: [];
$doomed_rollbacks = array_values(array_diff($rollbacks_after_doomed, $rollbacks_before_doomed));
check('The doomed restore left a rollback point behind to recover from', count($doomed_rollbacks) >= 1);
$recovery_source = $doomed_rollbacks[0] ?? '';

// A real user cannot start the recovery while the dead restore still holds
// the site-wide lock. Recorded rather than asserted: whether that clears on
// its own, and how fast, is a separate question from whether recovery WORKS.
$lock_blocking = call_private('restore_lock_is_active');
echo 'Restore lock still active right after abandonment: ' . var_export($lock_blocking, true)
  . ($lock_blocking ? " (user must wait for staleness detection before recovering)\n" : "\n");
call_private('force_release_restore_locks', [$doomed_job]);

// --- The recovery itself, through the ordinary restore path the UI uses ---
// Guarded on a real source path: if the doomed restore errored before it got
// far enough to create a rollback point at all (its own log names the reason
// — e.g. a genuine "not enough free disk space" from the environment, not a
// plugin bug), $recovery_source is '' and every check below is meaningless
// against nothing. Fail the whole block with ONE clear reason instead of
// crashing several lines later on copy('', ...) — PHP 8's copy() throws a
// bare ValueError on an empty path, which reads like an unrelated bug and
// stops the script before its own cleanup runs. Hit exactly this the hard
// way: a host low on disk space failed the doomed restore's OWN rollback
// step, and the resulting crash briefly looked like a new regression until
// the log line above was actually read.
if ($recovery_source === '') {
  check('Recovery restore from the rollback point COMPLETED (skipped — no rollback point to recover from)', false);
} else {
  $recovery_zip_path = call_private('storage_dir') . '/restore-upload-' . wp_generate_uuid4() . '.zip';
  copy($recovery_source, $recovery_zip_path);
  $recovery_job = 'rp-rollback-recovery-' . wp_generate_uuid4();
  $recovery_token = wp_generate_password(32, false, false);
  call_private('set_restore_job', [$recovery_job, [
    'status' => 'queued', 'phase' => 'queued', 'phase_label' => 'Queued', 'progress' => 5, 'message' => 'queued',
    'restore_zip_path' => $recovery_zip_path,
    'auto_detect_urls' => true,
    'restore_files' => true,   // as the UI would submit it; a rollback has no
                               // files, so the file phase is skipped anyway
    'source_url' => '', 'target_url' => '',
    'token' => $recovery_token, 'poll_token' => wp_generate_password(32, false, false),
    'created' => time(), 'updated' => time(),
  ]]);

  // The recovery restore only needs to finish; a small budget would drag its
  // database phase out for no benefit.
  $chunk_budget = 60.0;
  $r_iterations = 0;
  $r_status = 'running';
  do {
    $r_iterations++;
    RestorePilot_Backup_Migration::run_restore_job($recovery_job, $recovery_token);
    $rjob = call_private('get_restore_job', [$recovery_job]);
    $r_status = $rjob['status'] ?? '(missing)';
  } while ($r_status === 'running' && $r_iterations < 40);

  echo "Recovery restore: $r_iterations chunk(s), status=$r_status\n";
  if ($r_status === 'error') { echo '  Error: ' . ($rjob['message'] ?? '(none)') . "\n"; }
  check('Recovery restore from the rollback point COMPLETED', $r_status === 'complete');
  check('The live pre-restore state is genuinely back', db_option_raw($MARKER) === 'STATE-TO-RECOVER');

  $active_after = maybe_unserialize(db_option_raw('active_plugins'));
  check('Plugins are active again after recovery (not left deferred)', is_array($active_after) && count($active_after) > 0);
  // The decisive one. The abandoned restore left a stash holding the BACKUP's
  // plugin list; if recovery reused it (rather than establishing its own after
  // its own swap), the site would silently come back with the failed restore's
  // plugin set instead of the one it was rolled back to.
  sort($active_after);
  $expected_after = $list_in_rollback;
  sort($expected_after);
  check('Recovery reinstated the ROLLBACK\'s plugin list, not the failed restore\'s', $active_after === $expected_after);
  if ($active_after !== $expected_after) {
    echo '  got:      ' . wp_json_encode($active_after) . "\n";
    echo '  expected: ' . wp_json_encode($expected_after) . "\n";
  }
  check('No deferred-plugins stash left dangling after recovery', db_option_raw($DEFERRED) === null);
  check('Maintenance mode is off after recovery', !is_file(ABSPATH . '.maintenance'));

  $conn = mysqli_connect(null, DB_USER, DB_PASSWORD, DB_NAME, null, RP_TEST_SOCKET);
  $scratch = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND (table_name LIKE '%restorepilot\\_rtmp\\_%' OR table_name LIKE '%restorepilot\\_rold\\_%')"))[0];
  mysqli_close($conn);
  check('No scratch tables left behind by either restore', (int) $scratch === 0);
}

// --- Cleanup ---------------------------------------------------------------
$conn = mysqli_connect(null, DB_USER, DB_PASSWORD, DB_NAME, null, RP_TEST_SOCKET);
mysqli_query($conn, "DELETE FROM wp_options WHERE option_name = '$MARKER'");
mysqli_query($conn, "DELETE FROM wp_options WHERE option_name LIKE 'restorepilot_restore_job_rp-rollback-%' OR option_name = 'restorepilot_restore_lock' OR option_name LIKE 'restorepilot_restore_worker_%' OR option_name = '" . $DEFERRED . "'");
mysqli_close($conn);

system('rm -rf ' . escapeshellarg($file_fixture));
foreach ($fake_plugins as $rel) {
  system('rm -rf ' . escapeshellarg($plugins_dir . '/' . dirname($rel)));
}
foreach ($created_backups as $p) {
  foreach (call_private('discover_volumes', [$p])['paths'] as $vp) { @unlink($vp); }
}
foreach ([$doomed_zip_path, $recovery_zip_path] as $p) {
  foreach (call_private('volume_paths_for', [$p]) as $vp) { @unlink($vp); }
}
// Remove only rollback points THIS run created; anything that predated it is
// left exactly as it was found.
foreach (array_diff(glob($rollback_dir . '/*.zip') ?: [], $rollbacks_at_start) as $p) { @unlink($p); }

$status_file_ref = new ReflectionMethod('RestorePilot_Backup_Migration', 'restore_status_file');
$status_file_ref->setAccessible(true);
$poll_ref = new ReflectionMethod('RestorePilot_Backup_Migration', 'poll_token_file');
$poll_ref->setAccessible(true);
foreach ([$doomed_job, $recovery_job] as $jid) {
  @unlink($status_file_ref->invoke(null, $jid));
  @unlink($poll_ref->invoke(null, $jid));
}
wp_clear_scheduled_hook('restorepilot_cron_restore_job', [$doomed_job, $doomed_token]);
wp_clear_scheduled_hook('restorepilot_cron_restore_job', [$recovery_job, $recovery_token]);
call_private('force_release_restore_locks', [$doomed_job]);
call_private('force_release_restore_locks', [$recovery_job]);
db_set_option_raw('active_plugins', $original_active);

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
