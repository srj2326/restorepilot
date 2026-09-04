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

define('WP_CLI', true);
$site_root = '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public';
require $site_root . '/wp-load.php';

add_filter('restorepilot_restore_chunk_seconds', function () { return 240.0; });

$job_id = $argv[1];
$token = $argv[2];
$start = microtime(true);
RestorePilot_Backup_Migration::run_restore_job($job_id, $token);
fwrite(STDERR, "Elapsed: " . round(microtime(true) - $start, 2) . "s\n");
