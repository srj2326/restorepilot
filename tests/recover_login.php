<?php
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
