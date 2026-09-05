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

// Sets up the fixture + queued job, then exits — a separate driver script
// calls run_restore_job() once per real PHP process invocation, matching how
// production actually dispatches resumptions (each one a fresh process, so
// maybe_touch_restore_job()'s function-local throttle starts fresh too,
// unlike a tight in-process loop that shares it across "simulated" calls).

$site_root = rp_test_site();
$plugin_file = rp_test_plugin_file();
require $site_root . '/wp-load.php';
require_once $plugin_file;

function call_private($method, array $args = []) {
  $ref = new ReflectionMethod('RestorePilot_Backup_Migration', $method);
  $ref->setAccessible(true);
  return $ref->invokeArgs(null, $args);
}

$content_dir = call_private('content_dir');
$root = $content_dir . '/rp-restore-files-test';
if (is_dir($root)) system('rm -rf ' . escapeshellarg($root));
mkdir($root, 0777, true);

$expected_hashes = [];
for ($i = 1; $i <= 150; $i++) {
  $name = sprintf('file-%03d.bin', $i);
  $bytes = random_bytes(500 + $i * 20);
  file_put_contents($root . '/' . $name, $bytes);
  $expected_hashes[$name] = sha1($bytes);
}
file_put_contents(sys_get_temp_dir() . '/rp_expected_hashes.json', json_encode($expected_hashes));

$backup_result = call_private('create_backup_package', [true, '', [], false, false, ['triggered_by' => 'restore-files-test-separate']]);
$backup_path = call_private('backup_dir') . '/' . $backup_result['file'];

system('rm -rf ' . escapeshellarg($root));

call_private('ensure_storage');
$restore_zip_path = call_private('storage_dir') . '/restore-upload-' . wp_generate_uuid4() . '.zip';
copy($backup_path, $restore_zip_path);

$job_id = 'rp-restore-files-sep-' . wp_generate_uuid4();
$token = wp_generate_password(32, false, false);
call_private('set_restore_job', [$job_id, [
  'status' => 'queued', 'phase' => 'queued', 'phase_label' => 'Queued', 'progress' => 5, 'message' => 'queued',
  'restore_zip_path' => $restore_zip_path,
  'auto_detect_urls' => true, 'restore_files' => true,
  'source_url' => '', 'target_url' => '',
  'token' => $token, 'poll_token' => wp_generate_password(32, false, false),
  'created' => time(), 'updated' => time(),
]]);

file_put_contents(sys_get_temp_dir() . '/rp_job_id.txt', $job_id);
file_put_contents(sys_get_temp_dir() . '/rp_token.txt', $token);
file_put_contents(sys_get_temp_dir() . '/rp_backup_path.txt', $backup_path);
file_put_contents(sys_get_temp_dir() . '/rp_restore_zip_path.txt', $restore_zip_path);
echo "Setup complete. job_id=$job_id\n";
