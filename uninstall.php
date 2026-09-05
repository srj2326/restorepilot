<?php
/**
 * Remove RestorePilot data when WordPress deletes the plugin.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
  exit;
}

/*
 * Uninstall cleanup must remove backup archives, temporary restore/download
 * files, and plugin options created by RestorePilot. Paths are restricted to
 * this plugin's upload folders before deletion.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
 * phpcs:disable WordPress.DB.DirectDatabaseQuery
 */

function restorepilot_backup_migration_uninstall_cleanup(): void {
  if (is_multisite()) {
    $site_ids = get_sites([
      'fields' => 'ids',
      'number' => 0,
    ]);

    foreach ($site_ids as $site_id) {
      switch_to_blog((int) $site_id);
      restorepilot_backup_migration_uninstall_site_cleanup();
      restore_current_blog();
    }
    return;
  }

  restorepilot_backup_migration_uninstall_site_cleanup();
}

/*
 * The plugin's own class is not loaded during uninstall -- WordPress includes
 * this file on its own -- so the two values that identify a storage directory
 * we created are repeated here. test_uninstall_removes_storage asserts they
 * still match RestorePilot_Backup_Migration::PRIVATE_STORAGE_DIRNAME and
 * ::STORAGE_MARKER_FILE, so renaming either constant cannot quietly leave
 * uninstall unable to find what it is supposed to remove.
 */
const RESTOREPILOT_UNINSTALL_PRIVATE_DIRNAME = 'restorepilot-private-storage';
const RESTOREPILOT_UNINSTALL_STORAGE_MARKER  = '.restorepilot-storage';

function restorepilot_backup_migration_uninstall_site_cleanup(): void {
  restorepilot_backup_migration_uninstall_clear_cron();
  // Storage before options, deliberately. The option recording where backups
  // were moved to is itself a restorepilot_* option, so deleting options first
  // -- which is what this did -- threw away the only pointer to the private
  // directory and left every archive in it on disk, undiscoverable, while the
  // privacy policy promised uninstall had removed them.
  restorepilot_backup_migration_uninstall_delete_private_storage();
  restorepilot_backup_migration_uninstall_delete_uploads();
  restorepilot_backup_migration_uninstall_delete_options();
}

/**
 * Remove the storage directory the plugin created outside WordPress.
 *
 * This deletes a tree that is not under ABSPATH, so it demands proof rather
 * than inference: the administrator has not named this location themselves,
 * it is called what we call ours, and it carries the marker file the plugin
 * writes into directories it creates. Any one of those missing and the
 * directory is left exactly as it is.
 */
function restorepilot_backup_migration_uninstall_delete_private_storage(): void {
  $recorded = get_option('restorepilot_storage_path', '');
  if (!is_string($recorded) || $recorded === '') {
    return;
  }

  $dir = rtrim(str_replace('\\', '/', $recorded), '/');
  if ($dir === '' || !is_dir($dir)) {
    return;
  }

  // A location an administrator configured is theirs to manage. We have no
  // idea what else lives there and no licence to recurse through it.
  if (defined('RESTOREPILOT_STORAGE_DIR')) {
    $forced = realpath(rtrim((string) RESTOREPILOT_STORAGE_DIR, '/\\'));
    if ($forced !== false && $forced === realpath($dir)) {
      return;
    }
  }

  if (basename($dir) !== RESTOREPILOT_UNINSTALL_PRIVATE_DIRNAME) {
    return;
  }
  if (!is_file($dir . '/' . RESTOREPILOT_UNINSTALL_STORAGE_MARKER)) {
    return;
  }

  // Confined to its own parent: enough to remove this tree, never enough to
  // climb out of it.
  restorepilot_backup_migration_uninstall_delete_directory($dir, dirname($dir));
}

function restorepilot_backup_migration_uninstall_clear_cron(): void {
  // wp_clear_scheduled_hook() clears all instances of a hook (any args) since
  // WordPress 5.1.  The plugin requires WordPress 6.2+, so the private
  // _get_cron_array() call that was previously here is not needed.
  wp_clear_scheduled_hook('restorepilot_cron_backup_job');
  wp_clear_scheduled_hook('restorepilot_cron_restore_job');
  wp_clear_scheduled_hook('restorepilot_scheduled_backup');
  wp_clear_scheduled_hook('restorepilot_cleanup_direct_download');
}

function restorepilot_backup_migration_uninstall_delete_options(): void {
  delete_option('restorepilot_backup_lock');
  delete_option('restorepilot_recent_log');

  global $wpdb;
  if (!isset($wpdb) || !method_exists($wpdb, 'prepare')) {
    return;
  }

  $restorepilot_like = $wpdb->esc_like('restorepilot_') . '%';
  $legacy_like = $wpdb->esc_like('mc_site_vault_') . '%';
  $wpdb->query($wpdb->prepare(
    'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
    $wpdb->options,
    $restorepilot_like,
    $legacy_like
  ));
}

function restorepilot_backup_migration_uninstall_delete_uploads(): void {
  $upload = wp_upload_dir(null, false);
  if (!empty($upload['error']) || empty($upload['basedir'])) {
    return;
  }

  $uploads_base = realpath($upload['basedir']);
  if ($uploads_base === false || !is_dir($uploads_base)) {
    return;
  }

  $folders = [
    'restorepilot-backup-migration',
    'restorepilot-direct-downloads',
    'mc-site-vault',
    'mc-site-vault-direct-downloads',
  ];

  foreach ($folders as $folder) {
    restorepilot_backup_migration_uninstall_delete_directory(
      trailingslashit($upload['basedir']) . $folder,
      $uploads_base
    );
  }
}

function restorepilot_backup_migration_uninstall_delete_directory(string $path, string $allowed_base): void {
  $real_path = realpath($path);
  $real_base = realpath($allowed_base);
  if ($real_path === false || $real_base === false || !is_dir($real_path)) {
    return;
  }

  $real_path = str_replace('\\', '/', $real_path);
  $real_base = rtrim(str_replace('\\', '/', $real_base), '/');
  if ($real_path === $real_base || strpos($real_path, $real_base . '/') !== 0) {
    return;
  }

  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($real_path, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
  );

  foreach ($iterator as $item) {
    $item_path = $item->getPathname();
    if ($item->isDir() && !$item->isLink()) {
      @rmdir($item_path);
    } else {
      @unlink($item_path);
    }
  }

  @rmdir($real_path);
}

restorepilot_backup_migration_uninstall_cleanup();
