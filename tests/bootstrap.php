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

