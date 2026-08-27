<?php
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
