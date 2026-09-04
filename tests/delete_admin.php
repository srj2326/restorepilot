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

define('WP_USE_THEMES', false);
require_once '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$user = get_user_by('login', 'admin_nhdtvg');
if (!$user) {
    echo "Account not found (already gone).\n";
    exit(0);
}

// Reassign anything owned to the remaining admin rather than deleting content.
$keep = get_user_by('login', 'surajit');
$result = wp_delete_user($user->ID, $keep ? $keep->ID : null);

echo $result ? "Deleted: admin_nhdtvg (ID {$user->ID})\n" : "Deletion FAILED\n";
