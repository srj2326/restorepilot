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

$user = get_user_by('login', 'admin_aptmsu');
if (!$user) {
    echo "admin_aptmsu not found\n";
    exit(1);
}

wp_set_password('RestorePilot2026!', $user->ID);
echo "Password reset for: {$user->user_login} (ID {$user->ID})\n";
echo "Roles: " . implode(', ', $user->roles) . "\n";
