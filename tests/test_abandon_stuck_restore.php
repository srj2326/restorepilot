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

// Verifies the escape hatch for a restore that has stopped responding.
//
// Why this exists: when a restore dies, its job stays status "running", so
// restore_lock_can_be_released() only frees the site-wide lock once the job
// has been silent for BACKUP_STALE_SECONDS — two hours. Maintenance mode is
// separately on for up to an hour, and should_block_for_maintenance() has no
// exemption for administrators, so the owner could not even reach wp-admin
// during it. Worse, any chunk that starts and dies partway still touches the
// job record and re-arms both clocks, so a doomed restore could strand them
// again and again. The safety net (recovering from a rollback point) is
// useless if the owner cannot reach it for two hours while the site is down.
//
// The fix: an administrator sees a real status page instead of the plain 503,
// with an explicit "end this restore and unlock" action. Everyone else still
// gets the maintenance page, and the site stays blocked either way — the
// point is to stop the owner being stranded, not to let them browse a site
// whose database is mid-replacement.

$site_root = '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public';
$plugin_file = '/Users/surajitroy/Local Sites/morecalculators-dev/app/public/wp-content/plugins/restorepilot-backup-migration/restorepilot-backup-migration.php';

require $site_root . '/wp-load.php';
if (!class_exists('RestorePilot_Backup_Migration')) {
  require_once $plugin_file;
}
require_once ABSPATH . 'wp-admin/includes/user.php';

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

$MAINT = RestorePilot_Backup_Migration::MAINTENANCE_OPTION;
$LOCK = RestorePilot_Backup_Migration::RESTORE_LOCK_OPTION;

// A botched run must never leave the shared site in maintenance mode — that
// blocks every later test (and this script's own next attempt), which has
// happened here before.
register_shutdown_function(function () use ($MAINT, $LOCK) {
  delete_option($MAINT);
  delete_option($LOCK);
  $_POST = [];
  $_GET = [];
});

$_POST = [];
$_GET = [];

// === The gate must let the abandon action through =========================
// Without this the action is unreachable exactly when it is needed, since
// admin-post.php runs through the same 'init' gate everything else does.
update_option($MAINT, time() + 3600, true);
check('Maintenance active, plain request: still blocked (visitors unaffected)', call_private('should_block_for_maintenance') === true);

$_POST['action'] = 'restorepilot_abandon_restore';
check('The abandon action is allowed through maintenance (POST)', call_private('should_block_for_maintenance') === false);
$_POST = [];

$_GET['action'] = 'restorepilot_abandon_restore';
check('The abandon action is allowed through maintenance (GET)', call_private('should_block_for_maintenance') === false);
$_GET = [];

// Regression: widening the gate must not have let anything else through.
$_POST['action'] = 'restorepilot_delete';
check('An unrelated admin action is STILL blocked (gate not widened)', call_private('should_block_for_maintenance') === true);
$_POST = [];
$_GET['action'] = 'edit';
check('An unrelated GET action is STILL blocked', call_private('should_block_for_maintenance') === true);
$_GET = [];

// === active_restore_snapshot() ============================================
delete_option($LOCK);
$snap = call_private('active_restore_snapshot');
check('With no restore running, no job is reported', $snap['job_id'] === '');
check('With no restore running, it is reported as stuck (nothing is progressing)', $snap['looks_stuck'] === true);

$job_id = 'rp-abandon-test-' . wp_generate_uuid4();
$token = wp_generate_password(32, false, false);
call_private('set_restore_job', [$job_id, [
  'status' => 'running', 'phase' => 'database', 'phase_label' => 'Restoring database',
  'progress' => 48, 'message' => 'Restoring database tables...',
  'token' => $token, 'poll_token' => wp_generate_password(32, false, false),
  'created' => time(), 'updated' => time(),
]]);
update_option($LOCK, ['started' => time(), 'job_id' => sanitize_key($job_id), 'token' => $token], false);

$snap = call_private('active_restore_snapshot');
check('A running restore is found via the site-wide lock', $snap['job_id'] === sanitize_key($job_id));
check('A restore that just reported progress is NOT called stuck', $snap['looks_stuck'] === false);
check('Its phase and progress are available to show the admin', ($snap['job']['phase_label'] ?? '') === 'Restoring database');

// Silent for longer than a live restore ever is between touches (which are
// throttled to one per 5s), but far short of the two hours the lock's own
// staleness check waits for — the whole window this feature covers.
//
// Written via set_restore_job(), NOT update_restore_job(): the latter forces
// 'updated' => time() on every call, so backdating through it silently does
// nothing and the job always looks freshly alive.
$backdated = call_private('get_restore_job', [$job_id]);
$backdated['updated'] = time() - 15 * MINUTE_IN_SECONDS;
call_private('set_restore_job', [$job_id, $backdated]);
$snap = call_private('active_restore_snapshot');
check('A restore silent for 15 minutes IS reported as stuck', $snap['looks_stuck'] === true);
check('...even though the lock itself would not release for two hours', call_private('restore_lock_can_be_released', [get_option($LOCK, [])]) === false);

// === handle_abandon_restore() =============================================
// It ends in wp_safe_redirect()+exit, so it is driven in its own process and
// its EFFECTS are inspected here afterwards.
$php_bin = '/Users/surajitroy/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php';
$sock = '/Users/surajitroy/Library/Application Support/Local/run/gKsH4-EmV/mysql/mysqld.sock';

$admin_id = wp_insert_user([
  'user_login' => 'rp_abandon_admin_' . wp_generate_password(6, false, false),
  'user_pass' => wp_generate_password(20, true, true),
  'user_email' => 'rp_abandon_' . wp_generate_password(8, false, false) . '@example.test',
  'role' => 'administrator',
]);
check('Test administrator created', !is_wp_error($admin_id));

$runner = sys_get_temp_dir() . '/rp_abandon_runner_' . wp_generate_uuid4() . '.php';
file_put_contents($runner, <<<'PHP'
<?php
// Set BEFORE wp-load.php on purpose. wp-load fires 'init', which is where
// maybe_block_for_maintenance() decides whether to render the maintenance
// page and exit — and it reads the action out of $_POST. A real browser
// request has $_POST populated by PHP long before WordPress boots, so
// populating it after wp-load (as an earlier version of this runner did)
// models the wrong thing entirely: the gate saw an empty action, blocked,
// and the handler under test never ran at all.
$_POST['action'] = 'restorepilot_abandon_restore';

require '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public/wp-load.php';
// Guarded: the plugin is ACTIVE on this site, so WordPress has already loaded
// it from the site's own copy. require_once keys on the resolved path, and
// the dev copy is a different path, so an unguarded require redeclares
// everything and fatals ("Cannot redeclare restorepilot_backup_migration_
// bootstrap()"). The two copies are kept byte-identical anyway.
if (!class_exists('RestorePilot_Backup_Migration')) {
  require_once '/Users/surajitroy/Local Sites/morecalculators-dev/app/public/wp-content/plugins/restorepilot-backup-migration/restorepilot-backup-migration.php';
}
wp_set_current_user((int) $argv[1]);
// The nonce can only be minted once WordPress is loaded; the gate above does
// not look at it, only the handler does.
$_POST['_wpnonce'] = wp_create_nonce(RestorePilot_Backup_Migration::NONCE);
$_REQUEST = $_POST;
// verify_admin_request() ends in wp_die() on refusal, and the handler ends in
// a redirect on success; either way it is the effects on the database that
// this test inspects afterwards.
add_filter('wp_redirect', function ($location) { echo "REDIRECTED\n"; return false; }, 10, 1);
try {
  RestorePilot_Backup_Migration::handle_abandon_restore();
} catch (Throwable $e) {
  echo 'EXCEPTION: ' . $e->getMessage() . "\n";
}
PHP);

$cmd = sprintf(
  '%s -d %s -d %s %s %d 2>&1',
  escapeshellarg($php_bin),
  escapeshellarg('mysqli.default_socket=' . $sock),
  escapeshellarg('pdo_mysql.default_socket=' . $sock),
  escapeshellarg($runner),
  (int) $admin_id
);
$out = shell_exec($cmd);
echo '  [handler output] ' . trim((string) $out) . "\n";

// The parent's caches cannot be trusted after a child process wrote.
wp_cache_delete($MAINT, 'options');
wp_cache_delete($LOCK, 'options');
wp_cache_delete('alloptions', 'options');
wp_cache_delete(call_private('restore_job_option', [$job_id]), 'options');

check('Abandoning released the site-wide restore lock', empty(get_option($LOCK, [])));
check('Abandoning turned maintenance mode off', (int) get_option($MAINT, 0) === 0);
$job_after = call_private('get_restore_job', [$job_id]);
check('The job is marked errored, not left "running" forever', ($job_after['status'] ?? '') === 'error');
check('The site is no longer blocked, so the owner can reach the rollback points', call_private('should_block_for_maintenance') === false);

// A second restore must now be startable immediately — the entire point.
$second_token = null;
$second_failed = null;
try {
  $second_token = call_private('acquire_restore_lock', ['rp-abandon-followup']);
} catch (Throwable $e) {
  $second_failed = $e->getMessage();
}
check('A recovery restore can be started straight away (no two-hour wait)', $second_token !== null);
if ($second_failed !== null) { echo "  Refused with: $second_failed\n"; }
call_private('force_release_restore_locks', ['rp-abandon-followup']);

// === A non-administrator must not be able to do this ======================
$sub_id = wp_insert_user([
  'user_login' => 'rp_abandon_sub_' . wp_generate_password(6, false, false),
  'user_pass' => wp_generate_password(20, true, true),
  'user_email' => 'rp_abandon_sub_' . wp_generate_password(8, false, false) . '@example.test',
  'role' => 'subscriber',
]);
update_option($MAINT, time() + 3600, true);
update_option($LOCK, ['started' => time(), 'job_id' => 'rp-abandon-guard', 'token' => 'x'], false);
$out2 = shell_exec(sprintf(
  '%s -d %s -d %s %s %d 2>&1',
  escapeshellarg($php_bin),
  escapeshellarg('mysqli.default_socket=' . $sock),
  escapeshellarg('pdo_mysql.default_socket=' . $sock),
  escapeshellarg($runner),
  (int) $sub_id
));
wp_cache_delete($LOCK, 'options');
wp_cache_delete($MAINT, 'options');
wp_cache_delete('alloptions', 'options');
check('A subscriber cannot abandon a restore (lock survives)', !empty(get_option($LOCK, [])));
check('A subscriber cannot turn maintenance mode off', (int) get_option($MAINT, 0) > 0);

// --- Cleanup ---------------------------------------------------------------
@unlink($runner);
delete_option($MAINT);
delete_option($LOCK);
delete_option('restorepilot_restore_job_' . sanitize_key($job_id));
$status_file_ref = new ReflectionMethod('RestorePilot_Backup_Migration', 'restore_status_file');
$status_file_ref->setAccessible(true);
@unlink($status_file_ref->invoke(null, $job_id));
$poll_ref = new ReflectionMethod('RestorePilot_Backup_Migration', 'poll_token_file');
$poll_ref->setAccessible(true);
@unlink($poll_ref->invoke(null, $job_id));
@unlink(call_private('operation_notice_file'));
foreach ([$admin_id, $sub_id] as $uid) {
  if (!is_wp_error($uid) && $uid) { wp_delete_user((int) $uid); }
}
call_private('force_release_restore_locks', [$job_id]);

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
