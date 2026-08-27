<?php
define('WP_USE_THEMES', false);
require_once '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public/wp-load.php';

$user = get_user_by('login', 'surajit');
if (!$user) {
    fwrite(STDERR, "User not found\n");
    exit(1);
}

wp_set_password('RestorePilot2026!', $user->ID);
echo "Password updated for: " . $user->user_login . "\n";
