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

function restorepilot_backup_migration_uninstall_site_cleanup(): void {
  restorepilot_backup_migration_uninstall_clear_cron();
  restorepilot_backup_migration_uninstall_delete_options();
  restorepilot_backup_migration_uninstall_delete_uploads();
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
