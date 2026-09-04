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
 * One racer. Started many times at once by test_worker_lock.php, each prints
 * WON or LOST for a shared lock name. Exactly one may print WON.
 */
define('WP_USE_THEMES', false);
require_once '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public/wp-load.php';

$job_id = $argv[1] ?? 'race';
$start  = (float) ($argv[2] ?? 0);

// Line every racer up on the same starting instant, so they genuinely collide
// rather than arriving in a queue.
if ($start > 0) {
    $wait = $start - microtime(true);
    if ($wait > 0) { usleep((int) ($wait * 1000000)); }
}

$m = new ReflectionMethod('RestorePilot_Backup_Migration', 'acquire_restore_worker_lock');
$m->setAccessible(true);
echo ($m->invoke(null, $job_id) ? 'WON' : 'LOST') . "\n";
