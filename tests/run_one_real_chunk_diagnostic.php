<?php
define('WP_CLI', true);
$site_root = '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public';
require $site_root . '/wp-load.php';

add_filter('restorepilot_restore_chunk_seconds', function () { return 240.0; });

$job_id = $argv[1];
$token = $argv[2];
$start = microtime(true);
RestorePilot_Backup_Migration::run_restore_job($job_id, $token);
fwrite(STDERR, "Elapsed: " . round(microtime(true) - $start, 2) . "s\n");
