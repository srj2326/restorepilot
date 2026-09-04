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

$keep = get_user_by('login', 'surajit');

// Sweep every generated restore admin, not just the one just seen -- earlier
// restores may have left others behind.
$generated = get_users(['role' => 'administrator', 'search' => 'admin_*', 'search_columns' => ['user_login']]);
$removed = [];
foreach ($generated as $user) {
    if ($keep && $user->ID === $keep->ID) { continue; }
    if (wp_delete_user($user->ID, $keep ? $keep->ID : null)) {
        $removed[] = $user->user_login;
    }
}

echo $removed ? 'Deleted: ' . implode(', ', $removed) . "\n" : "No generated admin accounts found.\n";

$admins = get_users(['role' => 'administrator']);
echo 'Admins remaining: ' . implode(', ', wp_list_pluck($admins, 'user_login')) . "\n";
