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

// Runs exactly ONE resumption, as a genuinely fresh PHP process — this is
// what a real loopback/cron dispatch actually does. Prints the resulting
// job status/checkpoint progress so the driving bash loop can decide
// whether to invoke another one.

$site_root = '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public';
$plugin_file = '/Users/surajitroy/Local Sites/morecalculators-dev/app/public/wp-content/plugins/restorepilot-backup-migration/restorepilot-backup-migration.php';
require $site_root . '/wp-load.php';
require_once $plugin_file;

function call_private($method, array $args = []) {
  $ref = new ReflectionMethod('RestorePilot_Backup_Migration', $method);
  $ref->setAccessible(true);
  return $ref->invokeArgs(null, $args);
}

add_filter('pre_http_request', function () {
  return new WP_Error('blocked_for_test', 'Loopback dispatch blocked for test.');
}, 10, 3);
add_filter('restorepilot_restore_chunk_seconds', function () { return (float) ($argv[1] ?? 2.0); });

$job_id = trim(file_get_contents(sys_get_temp_dir() . '/rp_job_id.txt'));
$token = trim(file_get_contents(sys_get_temp_dir() . '/rp_token.txt'));

RestorePilot_Backup_Migration::run_restore_job($job_id, $token);

$job = call_private('get_restore_job', [$job_id]);
$cp = $job['checkpoint'] ?? [];
echo 'status=' . ($job['status'] ?? '?')
  . ' phase=' . ($job['phase'] ?? '?')
  . ' database_done=' . var_export($cp['database_done'] ?? null, true)
  . ' completed_tables=' . count($cp['completed_tables'] ?? []) . '/' . count($cp['restore_plan']['plans'] ?? [])
  . ' files_done=' . var_export($cp['files_done'] ?? null, true)
  . ' files_index=' . ($cp['files_index'] ?? '?')
  . ' resumption=' . ($cp['resumption'] ?? '?')
  . "\n";
