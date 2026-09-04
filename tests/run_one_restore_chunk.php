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

// Runs exactly ONE restore chunk in its OWN fresh PHP process, invoked by
// test_resumable_restore_files.php via proc_open() for every simulated
// chunk. This is deliberate, not an optimization shortcut: maybe_touch_
// restore_job() throttles its checkpoint DB write to once per 5 real
// seconds via a function-local `static $last_touch` cache — which is
// correct and safe in real usage because every real chunk (loopback HTTP
// dispatch or the WP-Cron fallback) is its own separate PHP process with a
// fresh, empty static. Driving run_restore_job() many times in a single
// shared PHP process (a tight loop) instead keeps that static alive
// across every simulated "chunk," so the throttle can end up suppressing
// literally every checkpoint write after the first for as long as the
// whole loop runs faster than 5 real seconds — which a tiny local fixture
// easily does. That produced a livelock that was specific to this test's
// old driving method, not to the plugin: an artifact of not modeling a
// real per-chunk process boundary, not a bug in the resumability logic
// itself. Shelling out to a fresh process each time is what actually
// matches real dispatch.

$site_root = '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public';
$plugin_file = '/Users/surajitroy/Local Sites/morecalculators-dev/app/public/wp-content/plugins/restorepilot-backup-migration/restorepilot-backup-migration.php';

// Real chunk dispatch is exempt from the plugin's own maintenance-mode gate
// via wp_doing_cron() or wp_doing_ajax() (see should_block_for_maintenance())
// — without one of those, a chunk that itself just enabled maintenance mode
// would immediately block every chunk after it, including its own
// continuation, since a raw CLI script is neither. DOING_CRON, not WP_CLI:
// on this exact site, WP_CLI fatally crashes once the database phase swaps
// in a real production wp_options — Advanced Custom Fields Pro's own CLI
// bootstrap checks WP_CLI and assumes the real framework is loaded whenever
// it's true. DOING_CRON is the other documented exemption and carries no
// such risk from any plugin's own assumptions.
define('DOING_CRON', true);

require $site_root . '/wp-load.php';
if (!class_exists('RestorePilot_Backup_Migration')) {
  require_once $plugin_file;
}

add_filter('pre_http_request', function () {
  return new WP_Error('blocked_for_test', 'Loopback dispatch blocked for test.');
}, 10, 3);
$job_id = $argv[1];
$token = $argv[2];
$chunk_seconds = (float) $argv[3];
add_filter('restorepilot_restore_chunk_seconds', function () use ($chunk_seconds) { return $chunk_seconds; });
RestorePilot_Backup_Migration::run_restore_job($job_id, $token);

