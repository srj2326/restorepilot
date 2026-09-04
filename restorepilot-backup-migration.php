<?php
/**
 * Plugin Name: RestorePilot Backup & Migration
 * Description: Back up, restore, and migrate WordPress sites with serialized-safe URL replacement.
 * Version:     0.5.7
 * Author:      Surajit Roy
 * Author URI:  https://profiles.wordpress.org/srjdev/
 * Text Domain: restorepilot-backup-migration
 * Requires at least: 6.2
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
  exit;
}

// Behaviour lives in includes/: the helper classes, and the traits the
// main class is assembled from. Loaded before the hooks below, which name
// its methods.
require_once __DIR__ . '/includes/exceptions.php';
require_once __DIR__ . '/includes/class-backup-zip-writer.php';
require_once __DIR__ . '/includes/class-backup-volume-writer.php';
require_once __DIR__ . '/includes/class-backup-archive.php';
require_once __DIR__ . '/includes/trait-bootstrap.php';
require_once __DIR__ . '/includes/trait-admin-ui.php';
require_once __DIR__ . '/includes/trait-request-handlers.php';
require_once __DIR__ . '/includes/trait-backup.php';
require_once __DIR__ . '/includes/trait-restore.php';
require_once __DIR__ . '/includes/trait-database.php';
require_once __DIR__ . '/includes/trait-migration.php';
require_once __DIR__ . '/includes/trait-storage.php';
require_once __DIR__ . '/includes/trait-jobs.php';
require_once __DIR__ . '/includes/trait-locks.php';
require_once __DIR__ . '/includes/trait-logging.php';
require_once __DIR__ . '/includes/trait-maintenance.php';
require_once __DIR__ . '/includes/trait-support.php';
require_once __DIR__ . '/includes/class-restorepilot-backup-migration.php';


/*
 * Plugin location constants, resolved from __FILE__ so they stay correct
 * regardless of where WordPress is installed or where wp-content is moved to.
 */
if (!defined('RESTOREPILOT_BACKUP_MIGRATION_FILE')) {
  define('RESTOREPILOT_BACKUP_MIGRATION_FILE', __FILE__);
}
if (!defined('RESTOREPILOT_BACKUP_MIGRATION_DIR')) {
  define('RESTOREPILOT_BACKUP_MIGRATION_DIR', plugin_dir_path(__FILE__));
}
if (!defined('RESTOREPILOT_BACKUP_MIGRATION_URL')) {
  define('RESTOREPILOT_BACKUP_MIGRATION_URL', plugin_dir_url(__FILE__));
}

/*
 * RestorePilot is a backup/restore plugin, so it intentionally performs
 * low-level filesystem streaming, uploaded chunk assembly, direct database
 * export/restore SQL, and raw binary download output. The surrounding code
 * validates capabilities, nonces, file names, zip paths, table identifiers, and
 * output boundaries before these operations.
 *
 * Every table and column name reaching the database is bound through
 * $wpdb->prepare()'s %i identifier placeholder, and every value through its
 * value placeholders; nothing is concatenated into a statement. The single
 * exception is the CREATE TABLE statement replayed during a restore, which is
 * schema DDL rather than bound values — it carries its own local
 * phpcs:ignore and is whitelisted in full by assert_create_table_is_safe()
 * before execution.
 *
 * Each remaining file-wide disable below is for a category triggered at many
 * call sites throughout this file (backup/restore/export/import), not a
 * single reviewable spot; narrowing it to per-statement phpcs:ignore comments
 * needs an actual PHPCS/WPCS run to confirm complete coverage, which this
 * environment does not have available. Categories with few enough trigger
 * sites to audit and confirm by hand have been removed from this list rather
 * than left here unverified.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions -- fopen/fwrite/fclose-style streaming for backup zips, chunked uploads, and log files; used at dozens of sites, always on plugin-owned paths validated before use.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery -- direct $wpdb queries for operations with no WordPress ORM equivalent (SHOW TABLES/CREATE TABLE, TRUNCATE, RENAME TABLE, raw SELECT for streamed export); all identifiers and values are bound via prepare().
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught exception messages are plugin-generated (not raw user input) and are written to this plugin's own log, JSON API responses, or wp_die()/esc_html() output; not echoed as unescaped HTML.
 */

function restorepilot_backup_migration_bootstrap(): void {
  static $bootstrapped = false;
  if ($bootstrapped) {
    return;
  }

  $bootstrapped = true;
  add_action('admin_menu', ['RestorePilot_Backup_Migration', 'admin_menu']);
  add_action('admin_init', ['RestorePilot_Backup_Migration', 'register_privacy_policy_content']);
  // Moves backups out of the web-served uploads directory, once, from a request
  // where nothing is mid-flight. See maybe_migrate_storage().
  add_action('admin_init', ['RestorePilot_Backup_Migration', 'maybe_migrate_storage']);
  add_action('admin_enqueue_scripts', ['RestorePilot_Backup_Migration', 'enqueue_admin_assets']);
  add_action('admin_notices', ['RestorePilot_Backup_Migration', 'render_restore_success_notice']);
  add_action('admin_notices', ['RestorePilot_Backup_Migration', 'render_operation_notices']);
  add_action('admin_notices', ['RestorePilot_Backup_Migration', 'render_deferred_plugins_notice']);
  add_action('admin_footer', ['RestorePilot_Backup_Migration', 'render_restore_success_dialog']);
  add_action('admin_post_restorepilot_backup', ['RestorePilot_Backup_Migration', 'handle_backup']);
  add_action('wp_ajax_restorepilot_ajax_backup', ['RestorePilot_Backup_Migration', 'handle_ajax_backup']);
  add_action('wp_ajax_restorepilot_backup_status', ['RestorePilot_Backup_Migration', 'handle_backup_status']);
  add_action('wp_ajax_restorepilot_cancel_backup', ['RestorePilot_Backup_Migration', 'handle_cancel_backup']);
  add_action('wp_ajax_restorepilot_read_log', ['RestorePilot_Backup_Migration', 'handle_read_log']);
  add_action('wp_ajax_restorepilot_clear_log', ['RestorePilot_Backup_Migration', 'handle_clear_log']);
  add_action('wp_ajax_restorepilot_chunk_restore_upload', ['RestorePilot_Backup_Migration', 'handle_chunk_restore_upload']);
  add_action('wp_ajax_restorepilot_ajax_restore', ['RestorePilot_Backup_Migration', 'handle_ajax_restore']);
  add_action('wp_ajax_restorepilot_restore_status', ['RestorePilot_Backup_Migration', 'handle_restore_status']);
  add_action('wp_ajax_restorepilot_run_restore_job_admin', ['RestorePilot_Backup_Migration', 'handle_run_restore_job_admin']);
  add_action('wp_ajax_restorepilot_run_backup_job_admin', ['RestorePilot_Backup_Migration', 'handle_run_backup_job_admin']);
  add_action('wp_ajax_restorepilot_run_backup_job', ['RestorePilot_Backup_Migration', 'handle_run_backup_job']);
  // nopriv endpoints for the loopback background workers: each request carries
  // a per-job single-use token (32 random alphanumeric chars, compared with
  // hash_equals) that is generated at job-queue time and never exposed to the
  // browser.  Authentication here is intentionally token-based rather than
  // cookie-based so the loopback HTTP call works even if the session is absent.
  add_action('wp_ajax_nopriv_restorepilot_run_backup_job',    ['RestorePilot_Backup_Migration', 'handle_run_backup_job']);
  add_action('wp_ajax_nopriv_restorepilot_run_restore_job',   ['RestorePilot_Backup_Migration', 'handle_run_restore_job']);
  // Status polling must work even when maintenance mode is active and after a
  // DB restore that invalidates the admin session. Uses a poll_token instead
  // of cookie auth so it is safe to expose to the browser and works nopriv.
  add_action('wp_ajax_nopriv_restorepilot_restore_status',    ['RestorePilot_Backup_Migration', 'handle_restore_status']);
  // Same reasoning as the status endpoint: this runs after the database swap
  // has invalidated the admin session, so it authenticates on the job's own
  // poll_token. See handle_set_restore_admin_password() for why the chosen
  // password takes this route rather than travelling with the job.
  add_action('wp_ajax_restorepilot_set_restore_admin_password',        ['RestorePilot_Backup_Migration', 'handle_set_restore_admin_password']);
  add_action('wp_ajax_nopriv_restorepilot_set_restore_admin_password', ['RestorePilot_Backup_Migration', 'handle_set_restore_admin_password']);
  add_action('init', ['RestorePilot_Backup_Migration', 'maybe_block_for_maintenance']);
  add_action('restorepilot_cron_backup_job', ['RestorePilot_Backup_Migration', 'run_backup_job'], 10, 2);
  add_action('restorepilot_cron_restore_job', ['RestorePilot_Backup_Migration', 'run_restore_job'], 10, 2);
  add_action('restorepilot_cleanup_direct_download', ['RestorePilot_Backup_Migration', 'cleanup_direct_downloads_cron'], 10, 1);
  add_action('admin_post_restorepilot_restore', ['RestorePilot_Backup_Migration', 'handle_restore']);
  add_action('admin_post_restorepilot_check_restore', ['RestorePilot_Backup_Migration', 'handle_restore_check']);
  add_action('admin_post_restorepilot_download', ['RestorePilot_Backup_Migration', 'handle_download']);
  add_action('admin_post_restorepilot_download_stream', ['RestorePilot_Backup_Migration', 'handle_download']);
  add_action('admin_post_restorepilot_download_part', ['RestorePilot_Backup_Migration', 'handle_download_partial']);
  add_action('admin_post_restorepilot_split', ['RestorePilot_Backup_Migration', 'handle_split']);
  add_action('admin_post_restorepilot_delete', ['RestorePilot_Backup_Migration', 'handle_delete']);
  add_action('admin_post_restorepilot_health', ['RestorePilot_Backup_Migration', 'handle_health_check']);
  add_action('admin_post_restorepilot_download_log', ['RestorePilot_Backup_Migration', 'handle_download_log']);
  add_action('admin_post_restorepilot_clear_log_post', ['RestorePilot_Backup_Migration', 'handle_clear_log_post']);
  add_action('admin_post_restorepilot_reactivate_plugins', ['RestorePilot_Backup_Migration', 'handle_reactivate_deferred_plugins']);
  add_action('admin_post_restorepilot_abandon_restore', ['RestorePilot_Backup_Migration', 'handle_abandon_restore']);
  add_action('admin_post_restorepilot_cleanup_temp', ['RestorePilot_Backup_Migration', 'handle_cleanup_temp']);
  add_action('admin_post_restorepilot_reset_runtime', ['RestorePilot_Backup_Migration', 'handle_reset_runtime']);
  add_action('wp_ajax_restorepilot_master_reset',     ['RestorePilot_Backup_Migration', 'handle_master_reset']);
  add_action('admin_post_restorepilot_save_settings', ['RestorePilot_Backup_Migration', 'handle_save_settings']);
  add_action('restorepilot_scheduled_backup', ['RestorePilot_Backup_Migration', 'handle_scheduled_backup']);
  add_filter('plugin_action_links_' . plugin_basename(__FILE__), ['RestorePilot_Backup_Migration', 'plugin_action_links']);
  add_filter('plugin_row_meta', ['RestorePilot_Backup_Migration', 'plugin_row_meta'], 10, 2);
  add_action('admin_enqueue_scripts', ['RestorePilot_Backup_Migration', 'enqueue_plugins_page_assets']);
}

// Uninstall cleanup is handled exclusively by uninstall.php, WordPress's
// dedicated mechanism for this — there is no separate uninstall function here.
function restorepilot_backup_migration_clear_scheduled_events(): void {
  // wp_clear_scheduled_hook() clears all instances of a hook (any args) since
  // WordPress 5.1.  The plugin requires WordPress 6.2+, so the private
  // _get_cron_array() call that was previously here is not needed.
  wp_clear_scheduled_hook('restorepilot_cron_backup_job');
  wp_clear_scheduled_hook('restorepilot_cron_restore_job');
  wp_clear_scheduled_hook('restorepilot_scheduled_backup');
  wp_clear_scheduled_hook('restorepilot_cleanup_direct_download');
  // The scheduled cleanup events just cleared above are the only thing that
  // would ever remove an outstanding direct-download hardlink — deactivation
  // must not leave one sitting on disk, publicly reachable, with nothing left
  // to clean it up.
  RestorePilot_Backup_Migration::purge_direct_downloads();
}

if (function_exists('register_activation_hook')) {
  register_activation_hook(__FILE__, ['RestorePilot_Backup_Migration', 'activate']);
}
if (function_exists('register_deactivation_hook')) {
  // Clear all scheduled RestorePilot cron events on deactivation so a
  // deactivated-but-not-deleted plugin never leaves orphaned daily backup or
  // background worker events firing with no registered callback. Full data
  // removal (options, stored backups, temp files) happens on uninstall via
  // uninstall.php, which is the single authoritative uninstall method.
  register_deactivation_hook(__FILE__, 'restorepilot_backup_migration_clear_scheduled_events');
}
restorepilot_backup_migration_bootstrap();

if (defined('RESTOREPILOT_BACKUP_MIGRATION_LOADED')) {
  return;
}
define('RESTOREPILOT_BACKUP_MIGRATION_LOADED', true);
