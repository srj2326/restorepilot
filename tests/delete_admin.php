<?php
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
