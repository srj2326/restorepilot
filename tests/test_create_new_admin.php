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

// Verifies the new "Create a new admin login" restore option: a brand-new
// administrator account is created exactly once (checkpoint-gated the same
// way rollback creation is, so a resumption doesn't create a second one),
// its generated password actually authenticates, credentials are served
// through the status-poll response exactly once and cleared immediately
// after, and turning the option off creates no account at all.

$site_root = rp_test_site();
$plugin_file = rp_test_plugin_file();

require $site_root . '/wp-load.php';
if (!class_exists('RestorePilot_Backup_Migration')) {
  require_once $plugin_file;
}
// wp_delete_user() (used in cleanup below) is only loaded by wp-admin's own
// bootstrap, not by wp-load.php on its own — confirmed the hard way: an
// earlier run of this exact test fatally crashed at cleanup on this, twice,
// leaving real admin accounts behind both times because nothing after the
// crash point ever ran.
require_once ABSPATH . 'wp-admin/includes/user.php';

function call_private($method, array $args = []) {
  $ref = new ReflectionMethod('RestorePilot_Backup_Migration', $method);
  $ref->setAccessible(true);
  return $ref->invokeArgs(null, $args);
}

// A plain copy($backup_path, $dest) only copies the FIRST volume — fine for
// a database-only or tiny fixture that never spans more than one, but a
// real full (database + files) backup can grow past the volume size and
// silently produce an incomplete copy (open_backup_archive() on the
// destination then fails validation, e.g. "manifest is missing", because
// manifest.json isn't necessarily in volume 1). Copies every volume,
// preserving the same "-vNNN" suffix pattern relative to the NEW base name.
function copy_all_volumes($src_base_path, $dest_base_path) {
  $sources = call_private('volume_paths_for', [$src_base_path]);
  foreach ($sources as $src) {
    if ($src === $src_base_path) {
      copy($src, $dest_base_path);
      continue;
    }
    if (preg_match('/-v([0-9]{3,})\.zip$/', $src, $m)) {
      $dest = preg_replace('/\.zip$/', '-v' . $m[1] . '.zip', $dest_base_path);
      copy($src, $dest);
    }
  }
}

$failures = [];
function check($label, $cond) {
  global $failures;
  echo ($cond ? 'PASS' : 'FAIL') . "  $label\n";
  if (!$cond) $failures[] = $label;
}

// handle_restore_status() ends in wp_send_json_success(), which calls
// wp_die() — a real die()/exit that a plain try/catch cannot intercept and
// would otherwise kill this whole script mid-test. WordPress's own test
// suite handles this the same way: swap in a die handler that throws a
// catchable exception instead, for the duration of just that one call.
class RP_Test_WPDieException extends Exception {}
function rp_throwing_die_handler($message = '', $title = '', $args = []) {
  throw new RP_Test_WPDieException(is_string($message) ? $message : 'wp_die');
}
function call_json_handler($method) {
  add_filter('wp_die_ajax_handler', 'rp_throwing_die_handler');
  add_filter('wp_die_json_handler', 'rp_throwing_die_handler');
  add_filter('wp_die_handler', 'rp_throwing_die_handler');
  ob_start();
  try {
    call_private($method);
  } catch (RP_Test_WPDieException $e) {
    // Expected — this is how a real success/error response ends.
  }
  $output = ob_get_clean();
  remove_filter('wp_die_ajax_handler', 'rp_throwing_die_handler');
  remove_filter('wp_die_json_handler', 'rp_throwing_die_handler');
  remove_filter('wp_die_handler', 'rp_throwing_die_handler');
  return json_decode($output, true);
}

global $wpdb;

// === Test 1: create_new_admin_login() itself ===============================
$users_before = count_users()['total_users'];
$created = call_private('create_new_admin_login', ['rp-e2e-one@example.test']);
check('Returns a username, address and interim password', !empty($created['username']) && !empty($created['email']) && !empty($created['password']));
check('Honours the chosen address', ($created['email'] ?? '') === 'rp-e2e-one@example.test');
$users_after = count_users()['total_users'];
check('Exactly one new user exists', $users_after === $users_before + 1);

if (!empty($created['username'])) {
  $user = get_user_by('login', $created['username']);
  check('New user is a real WP_User', $user instanceof WP_User);
  if ($user) {
    check('New user has the administrator role', in_array('administrator', $user->roles, true));
    $auth = wp_authenticate($created['email'], $created['password']);
    check('The account authenticates by EMAIL, which is how sign-in works now', !is_wp_error($auth) && $auth instanceof WP_User);
    check('Username is derived from the address, not a generated admin_* name', strpos($created['username'], 'rp-e2e-one') === 0);
  }
}

// Calling it again must produce a DIFFERENT user (no collision), proving the
// username_exists() retry loop and the underlying random source both work.
$created2 = call_private('create_new_admin_login', ['rp-e2e-one@example.test']);
check('A second call with the same address still yields a distinct account', ($created2['username'] ?? '') !== ($created['username'] ?? ''));
check('...and a distinct address, since the first is now taken', ($created2['email'] ?? '') !== ($created['email'] ?? ''));

// === Test 2: end-to-end through a real (small) restore, option ON =========
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'rp_admin_opt_test_%'");
update_option('rp_admin_opt_test_marker', 'present-before-restore');

// include_files MUST be true: validate_backup_zip()'s require_full_restore
// check rejects a database-only backup outright (restorable=false), so a
// false here fails the restore before the new-admin step is ever reached —
// same fixture requirement as test_resumable_restore_e2e.php.
$backup_result = call_private('create_backup_package', [true, '', [], false, false, ['triggered_by' => 'new-admin-test']]);
check('Fixture backup created', !empty($backup_result['file']));
$backup_path = call_private('backup_dir') . '/' . $backup_result['file'];

add_filter('pre_http_request', function () {
  return new WP_Error('blocked_for_test', 'Loopback dispatch blocked for test.');
}, 10, 3);
// Not the full ~20s default (needlessly slow for a fixture site over up to
// 100 in-process iterations) and deliberately not tiny either — see project
// memory on why an overly small budget here can make a resumption loop
// advance by only a handful of rows per iteration and never converge. 3.0s
// was enough standalone but not when run inside the full suite (this site's
// own table count fluctuates with whatever ran just before it) — 5.0s for
// real margin.
add_filter('restorepilot_restore_chunk_seconds', function () { return 5.0; });

call_private('ensure_storage');
$restore_zip_path = call_private('storage_dir') . '/restore-upload-' . wp_generate_uuid4() . '.zip';
copy_all_volumes($backup_path, $restore_zip_path);

$job_id = 'rp-new-admin-test-' . wp_generate_uuid4();
$token = wp_generate_password(32, false, false);
call_private('set_restore_job', [$job_id, [
  'status' => 'queued', 'phase' => 'queued', 'phase_label' => 'Queued', 'progress' => 5, 'message' => 'queued',
  'restore_zip_path' => $restore_zip_path,
  'auto_detect_urls' => true,
  'restore_files' => false,
  'create_new_admin' => true,
  'source_url' => '', 'target_url' => '',
  'token' => $token, 'poll_token' => wp_generate_password(32, false, false),
  'created' => time(), 'updated' => time(),
]]);

$users_before_restore = count_users()['total_users'];
$iterations = 0;
$status = 'running';
do {
  $iterations++;
  RestorePilot_Backup_Migration::run_restore_job($job_id, $token);
  $job = call_private('get_restore_job', [$job_id]);
  $status = $job['status'] ?? '(missing)';
} while ($status === 'running' && $iterations < 100);

check('Restore (option ON) reached complete status', $status === 'complete');
$users_after_restore = count_users()['total_users'];
check('Exactly one new admin user exists after the restore', $users_after_restore === $users_before_restore + 1);

$job_after = call_private('get_restore_job', [$job_id]);
// The job now records only a pointer and an address. A password never goes
// here: the record is mirrored to a file under uploads to survive the
// database swap, which would put a plaintext credential on disk.
check('Job record carries the new admin user id', !empty($job_after['new_admin_user_id']));
check('Job record carries the account address', !empty($job_after['new_admin_email_final']));
check('SECURITY: job record holds no password of any kind',
  strpos(wp_json_encode($job_after), 'new_admin_credentials') === false
  && empty($job_after['new_admin_password']));
$restore_created_id = (int) ($job_after['new_admin_user_id'] ?? 0);
if ($restore_created_id > 0) {
  $u = get_user_by('id', $restore_created_id);
  check('That user is really an administrator', $u instanceof WP_User && in_array('administrator', $u->roles, true));
}

// wp_send_json_success() only routes through wp_die() — which the filters in
// call_json_handler() can intercept — when wp_doing_ajax() is true; otherwise
// it calls a bare, uninterceptable die() right after echoing the JSON,
// silently killing the rest of this script (every check after the first
// poll, all of Test 3, and cleanup) despite exiting with a "successful" 0
// code. Confirmed the hard way: an earlier run of this exact test did
// exactly that, and left three real admin accounts on the site because
// cleanup below never got a chance to run.
if (!defined('DOING_AJAX')) {
  define('DOING_AJAX', true);
}

// The poll tells the page an account is waiting for the password it holds,
// and names the address to sign in with -- but never sends a credential.
$_POST['job_id'] = $job_id;
$_POST['poll_token'] = $job_after['poll_token'] ?? '';
$poll_json = call_json_handler('handle_restore_status');
check('Poll response flags that the account awaits its password', !empty($poll_json['data']['new_admin_awaiting_password']));
check('Poll response names the address to sign in with', !empty($poll_json['data']['new_admin_email']));
check('SECURITY: poll response contains no password',
  strpos(wp_json_encode($poll_json), 'new_admin_credentials') === false
  && empty($poll_json['data']['new_admin_password']));
unset($_POST['job_id'], $_POST['poll_token']);

// === Test 3: option OFF creates no account =================================
$restore_zip_path_2 = call_private('storage_dir') . '/restore-upload-' . wp_generate_uuid4() . '.zip';
copy_all_volumes($backup_path, $restore_zip_path_2);
$job_id_2 = 'rp-new-admin-test-off-' . wp_generate_uuid4();
$token_2 = wp_generate_password(32, false, false);
call_private('set_restore_job', [$job_id_2, [
  'status' => 'queued', 'phase' => 'queued', 'phase_label' => 'Queued', 'progress' => 5, 'message' => 'queued',
  'restore_zip_path' => $restore_zip_path_2,
  'auto_detect_urls' => true,
  'restore_files' => false,
  'create_new_admin' => false,
  'source_url' => '', 'target_url' => '',
  'token' => $token_2, 'poll_token' => wp_generate_password(32, false, false),
  'created' => time(), 'updated' => time(),
]]);
$iterations2 = 0;
$status2 = 'running';
do {
  $iterations2++;
  RestorePilot_Backup_Migration::run_restore_job($job_id_2, $token_2);
  $job2 = call_private('get_restore_job', [$job_id_2]);
  $status2 = $job2['status'] ?? '(missing)';
} while ($status2 === 'running' && $iterations2 < 100);
check('Restore (option OFF) reached complete status', $status2 === 'complete');
// NOT a total-user-count comparison: this restore uses the SAME fixture
// backup as Test 2 (captured once, before either restore ran), so this
// restore's own database swap reverts wp_users to that same fixture
// snapshot — which does not include Test 2's admin (created after the
// snapshot was taken). That alone drops the total count by one, with
// nothing to do with whether THIS restore created a new admin. The
// job's own new_admin_user_id directly reflects only what
// create_new_admin_login() did during this specific run.
check('No admin account is pointed to on the job record when the option is off', empty($job2['new_admin_user_id']));

// --- Cleanup -----------------------------------------------------------------
foreach ([$created['username'] ?? '', $created2['username'] ?? ''] as $uname) {
  if ($uname !== '') {
    $u = get_user_by('login', $uname);
    if ($u) { wp_delete_user($u->ID); }
  }
}
// The admin the restore itself made is known by id, not by name: the job
// record deliberately no longer carries a username. This used to read a
// $restore_created_username that the email-only rewrite had already removed,
// so get_user_by('login', null) quietly returned false and the account was
// never deleted -- every run left another administrator on the site.
if (!empty($restore_created_id)) {
  $u = get_user_by('id', (int) $restore_created_id);
  if ($u) { wp_delete_user($u->ID); }
}
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'rp_admin_opt_test_%'");
foreach (call_private('discover_volumes', [$backup_path])['paths'] as $p) { @unlink($p); }
foreach (call_private('volume_paths_for', [$restore_zip_path]) as $p) { @unlink($p); }
foreach (call_private('volume_paths_for', [$restore_zip_path_2]) as $p) { @unlink($p); }
foreach ([$job_id, $job_id_2] as $jid) {
  delete_option('restorepilot_restore_job_' . sanitize_key($jid));
  $status_file_ref = new ReflectionMethod('RestorePilot_Backup_Migration', 'restore_status_file');
  $status_file_ref->setAccessible(true);
  @unlink($status_file_ref->invoke(null, $jid));
  wp_clear_scheduled_hook('restorepilot_cron_restore_job', [$jid, $jid === $job_id ? $token : $token_2]);
}
call_private('force_release_restore_locks', [$job_id]);
call_private('force_release_restore_locks', [$job_id_2]);

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
