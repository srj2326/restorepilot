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

// Kicks off a REAL restore job on sunhsine-bkp, mirroring handle_ajax_restore()'s
// internals exactly (same private helpers, same order) rather than going
// through the public AJAX wrapper — that wrapper ends in wp_send_json_success(),
// which calls wp_die() and would terminate this script before it could report
// anything back. No auth/nonce faking needed either, since these are the
// plugin's own private implementation methods, not the public-facing handler.
// Unlike every other script this session, this is NOT disposable test data —
// it's the actual production backup, restored for real. Dispatch is the REAL
// mechanism (loopback POST + cron fallback), not blocked the way every test
// this session deliberately blocked it to drive resumptions manually instead.

$site_root = rp_test_site();
require $site_root . '/wp-load.php';

function call_private($method, array $args = []) {
  $ref = new ReflectionMethod('RestorePilot_Backup_Migration', $method);
  $ref->setAccessible(true);
  return $ref->invokeArgs(null, $args);
}

// Hand-run helper: name the archive to restore.
$backup_path = $argv[1] ?? '';
if ($backup_path === '' || !is_file($backup_path)) {
  fwrite(STDERR, "usage: start_real_restore.php <backup.zip>\n");
  exit(1);
}

$_POST['server_backup_path'] = $backup_path;
$restore_zip_path = call_private('prepare_restore_upload');
echo "Resolved restore zip path: $restore_zip_path\n";

$job_id = wp_generate_uuid4();
$token = wp_generate_password(32, false, false);
$poll_token = wp_generate_password(32, false, false);

call_private('set_restore_job', [$job_id, [
  'status' => 'queued',
  'phase' => 'queued',
  'phase_label' => call_private('restore_phase_label', ['queued']),
  'progress' => 5,
  'message' => __('Restore queued.', 'restorepilot-backup-migration'),
  'restore_zip_path' => $restore_zip_path,
  'auto_detect_urls' => true,
  'restore_files' => true,
  'source_url' => '',
  'target_url' => home_url(),
  'token' => $token,
  'poll_token' => $poll_token,
  'created' => time(),
  'updated' => time(),
]]);

call_private('write_poll_token_file', [$job_id, $poll_token]);

echo "Job created: $job_id\n";
echo "Token: $token\n";
echo "Poll token: $poll_token\n";

call_private('dispatch_restore_worker', [$job_id, $token]);

echo "Dispatch attempted (real loopback + cron fallback, not blocked).\n";
echo "JOB_ID=$job_id\n";
