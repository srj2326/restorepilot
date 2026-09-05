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

// Runs exactly ONE chunk of the REAL restore job, in its own fresh process
// — same reasoning as run_one_restore_chunk.php earlier this session, but
// for the actual production restore, with the plugin's own real ~20s
// chunk budget (no override) and without blocking pre_http_request, so the
// genuine loopback dispatch can still fire too if it ever starts working —
// harmless either way since the per-chunk worker lock makes double-driving
// safe (whichever gets there first wins; the other finds the lock held and
// returns immediately).

$site_root = rp_test_site();

// Same gotcha documented in project memory from earlier this session: a raw
// CLI invocation is not exempt from should_block_for_maintenance() the way
// real AJAX/cron dispatch is, so once THIS restore's own earlier chunk
// enabled maintenance mode, every subsequent chunk driven this way would
// just get served the maintenance page instead of ever reaching
// run_restore_job() at all.
define('WP_CLI', true);

require $site_root . '/wp-load.php';

$job_id = $argv[1];
$token = $argv[2];
RestorePilot_Backup_Migration::run_restore_job($job_id, $token);
