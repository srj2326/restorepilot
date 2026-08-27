<?php
define("WP_USE_THEMES", false);
define("WP_ADMIN", true);
$_SERVER["HTTP_HOST"] = "morecalculators-dev.local";
$_SERVER["REQUEST_URI"] = "/wp-admin/";
chdir("/Users/surajitroy/Local Sites/morecalculators-dev/app/public");
require "wp-load.php";
require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_set_current_user(1);
if (!class_exists('RestorePilot_Backup_Migration')) {
  require_once WP_CONTENT_DIR . '/plugins/restorepilot-backup-migration/restorepilot-backup-migration.php';
}

