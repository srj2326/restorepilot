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

/**
 * Step 1 of the browser-flow test: run a real restore that asks for a new
 * admin at a chosen address, exactly as the confirmation modal would.
 *
 * Writes the job id and poll token to a file so the next step can call the
 * password endpoint over real HTTP, the way the page does.
 */

require_once __DIR__ . '/env.php';
rp_test_boot();

$SCRATCH = '/private/tmp/claude-501/-Users-surajitroy-Local-Sites-morecalculators-dev-app-public-wp-content-plugins-restorepilot-backup-migration/7f9a6ea2-0e0d-47e3-9219-e411bdf20a00/scratchpad';
$EMAIL = 'restore-test@example.test';

function call_private($name, $args = []) {
    $m = new ReflectionMethod('RestorePilot_Backup_Migration', $name);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
}

// Start from a clean slate for this address.
$existing = email_exists($EMAIL);
if ($existing) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($existing); }

$backups = glob(call_private('storage_dir') . '/backups/*.zip');
usort($backups, function ($a, $b) { return filemtime($b) - filemtime($a); });
if (!$backups) { fwrite(STDERR, "No backup available\n"); exit(1); }
$backup = $backups[0];
echo "Backup: " . basename($backup) . " (" . round(filesize($backup) / 1048576) . " MB)\n";

// Copy it to a restore-upload path, as an upload would produce.
$restore_zip = call_private('storage_dir') . '/restore-upload-' . wp_generate_uuid4() . '.zip';
copy($backup, $restore_zip);

$job_id     = 'rp-e2e-admin-' . wp_generate_uuid4();
$token      = wp_generate_password(32, false, false);
$poll_token = wp_generate_password(32, false, false);

// Exactly the record handle_ajax_restore() builds from the modal's fields.
call_private('set_restore_job', [$job_id, [
    'status' => 'queued',
    'phase' => 'queued',
    'progress' => 0,
    'message' => 'Queued',
    'restore_zip_path' => $restore_zip,
    'auto_detect_urls' => true,
    'restore_files' => true,
    'create_new_admin' => true,
    'new_admin_email' => $EMAIL,
    'token' => $token,
    'poll_token' => $poll_token,
    'created' => time(),
]]);
call_private('write_poll_token_file', [$job_id, $poll_token]);

echo "Job: $job_id\nRunning restore";
$chunks = 0;
$deadline = time() + 1500;
do {
    call_private('run_restore_job', [$job_id, $token]);
    $chunks++;
    $job = call_private('get_restore_job', [$job_id]);
    $status = $job['status'] ?? '';
    if ($chunks % 5 === 0) { echo "."; }
} while (!in_array($status, ['complete', 'error', 'stale'], true) && time() < $deadline);

echo "\nChunks: $chunks\nStatus: $status\n";
if (!empty($job['message'])) { echo "Message: {$job['message']}\n"; }

if ($status !== 'complete') {
    fwrite(STDERR, "Restore did not complete\n");
    exit(1);
}

$user_id = (int) ($job['new_admin_user_id'] ?? 0);
$final_email = (string) ($job['new_admin_email_final'] ?? '');
echo "new_admin_user_id: $user_id\n";
echo "new_admin_email_final: $final_email\n";

// The password the endpoint has NOT been given yet -- prove it does not work.
$before = get_user_by('id', $user_id);
echo "account_exists_before_password_step: " . ($before ? 'yes' : 'no') . "\n";

file_put_contents($SCRATCH . '/e2e_admin_state.json', wp_json_encode([
    'job_id' => $job_id,
    'poll_token' => $poll_token,
    'user_id' => $user_id,
    'email' => $final_email,
    'restore_zip' => $restore_zip,
]));

echo "STEP1_OK\n";
