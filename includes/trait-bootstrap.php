<?php
/**
 * Plugin lifecycle: activation, menus, and asset loading.
 *
 * @package RestorePilot_Backup_Migration
 */

if (!defined('ABSPATH')) {
  exit;
}

trait RestorePilot_Bootstrap {
  public static function init(): void {
    if (self::$initialized) {
      return;
    }

    self::$initialized = true;
    restorepilot_backup_migration_bootstrap();
  }

  public static function activate(): void {
    self::ensure_storage();
    self::enforce_backup_retention();
    self::sync_scheduled_backup();
  }

  public static function admin_menu(): void {
    add_menu_page(
      __('RestorePilot Backup', 'restorepilot-backup-migration'),
      __('RestorePilot', 'restorepilot-backup-migration'),
      'manage_options',
      self::SLUG,
      [__CLASS__, 'render_admin_page'],
      'dashicons-backup',
      80
    );
  }

  public static function plugin_action_links(array $links): array {
    array_unshift($links, '<a href="' . esc_url(self::admin_url()) . '">' . esc_html__('Settings', 'restorepilot-backup-migration') . '</a>');
    return $links;
  }

  public static function plugin_row_meta(array $links, string $file): array {
    if (self::plugin_basename_self() !== $file) {
      return $links;
    }
    $links[] = '<a href="https://wordpress.org/support/plugin/restorepilot-backup-migration/" target="_blank" rel="noopener noreferrer">' . esc_html__('Support', 'restorepilot-backup-migration') . '</a>';
    return $links;
  }

  /**
   * Suggest privacy-policy text for the site admin's policy guide.
   *
   * RestorePilot stores backups locally and never transmits data externally,
   * but backups can contain personal data, so we surface that fact through the
   * core Privacy Policy Guide (Settings → Privacy) as recommended by the
   * WordPress Plugin Handbook.
   */
  public static function register_privacy_policy_content(): void {
    if (!function_exists('wp_add_privacy_policy_content')) {
      return;
    }
    $content = wp_kses_post(
      '<p>' . __('RestorePilot Backup &amp; Migration creates backup archives of this site. A backup can contain personal data stored in the WordPress database (for example, user accounts, comments, and post content) as well as files in the media library, themes, plugins, and uploads directory.', 'restorepilot-backup-migration') . '</p>'
      . '<p>' . __('Backup archives are stored locally on this server, in a directory beside the WordPress installation that the web server cannot serve; if that location is not writable they are kept in a protected folder inside the uploads directory instead. They remain there unless an administrator downloads or moves them. RestorePilot does not send backup data to the plugin author or to any third-party service.', 'restorepilot-backup-migration') . '</p>'
      . '<p>' . __('Deleting the plugin removes those stored backups. A storage directory an administrator has configured explicitly is left in place, because it belongs to the site rather than to the plugin.', 'restorepilot-backup-migration') . '</p>'
      . '<p>' . __('When you delete a backup — or uninstall the plugin — the corresponding archive files are removed from the server. If you download backups, you are responsible for storing and disposing of them securely.', 'restorepilot-backup-migration') . '</p>'
    );
    wp_add_privacy_policy_content('RestorePilot Backup & Migration', $content);
  }

  public static function enqueue_plugins_page_assets(string $hook): void {
    if ($hook !== 'plugins.php') {
      return;
    }
    $js_path = RESTOREPILOT_BACKUP_MIGRATION_DIR . 'assets/js/plugins-page.js';
    $js_ver  = (@filemtime($js_path) ?: false) ? self::VERSION . '.' . filemtime($js_path) : self::VERSION;
    wp_enqueue_script(
      'restorepilot-plugins-page',
      plugins_url('assets/js/plugins-page.js', RESTOREPILOT_BACKUP_MIGRATION_FILE),
      [],
      $js_ver,
      true
    );
    wp_localize_script('restorepilot-plugins-page', 'restorePilotPluginsPage', [
      'slug'           => self::SLUG,
      'confirmMessage' => __("Deleting RestorePilot will permanently remove ALL your backups stored on this server. Download any backups you want to keep before deleting.\n\nAre you sure you want to delete RestorePilot and all its backups?", 'restorepilot-backup-migration'),
    ]);
  }

  public static function enqueue_admin_assets(string $hook): void {
    if (strpos($hook, 'restorepilot') === false) {
      return;
    }
    // Version assets by file modification time so a plugin update always busts
    // the browser cache. Falls back to the plugin version if mtime is unreadable.
    $css_path = RESTOREPILOT_BACKUP_MIGRATION_DIR . 'assets/css/admin.css';
    $js_path  = RESTOREPILOT_BACKUP_MIGRATION_DIR . 'assets/js/admin.js';
    $css_ver  = (@filemtime($css_path) ?: false) ? self::VERSION . '.' . filemtime($css_path) : self::VERSION;
    $js_ver   = (@filemtime($js_path) ?: false) ? self::VERSION . '.' . filemtime($js_path) : self::VERSION;
    wp_enqueue_style(
      'restorepilot-admin',
      RESTOREPILOT_BACKUP_MIGRATION_URL . 'assets/css/admin.css',
      [],
      $css_ver
    );
    wp_enqueue_script(
      'restorepilot-admin',
      RESTOREPILOT_BACKUP_MIGRATION_URL . 'assets/js/admin.js',
      [],
      $js_ver,
      true
    );
    wp_localize_script('restorepilot-admin', 'restorePilotData', [
      'nonce'        => wp_create_nonce(self::NONCE),
      'restoreTabUrl' => esc_url(add_query_arg('tab', 'restore', self::admin_url())),
      // Where a finished restore sends the browser. Always the login form,
      // never straight back into wp-admin: the restore has replaced the users
      // table and the session tokens that live in usermeta alongside it, so
      // whatever session this page still thinks it has is gone regardless of
      // whether the restore was from this domain or another one. Landing on
      // an admin screen first would just produce a bounce through login with
      // a broken page on the way. Signing in returns here, where the
      // completion dialog is waiting.
      'loginUrl' => esc_url(wp_login_url(add_query_arg('tab', 'restore', self::admin_url()))),
      'i18n'         => [
        'showPassword'   => __('Show password', 'restorepilot-backup-migration'),
        'hidePassword'   => __('Hide password', 'restorepilot-backup-migration'),
        'noLogEntriesYet'          => __('No log entries yet.', 'restorepilot-backup-migration'),
        'noMatchingLogEntries'     => __('No matching log entries.', 'restorepilot-backup-migration'),
        'confirmClearLogs'         => __('Clear RestorePilot logs?', 'restorepilot-backup-migration'),
        'seconds'                  => __('seconds', 'restorepilot-backup-migration'),
        'done'                     => __('done', 'restorepilot-backup-migration'),
        'finalizingBackup'         => __('finalizing backup', 'restorepilot-backup-migration'),
        'estimatingSecondsLeft'    => __('estimating seconds left', 'restorepilot-backup-migration'),
        'left'                     => __('left', 'restorepilot-backup-migration'),
        'complete'                 => __('Complete', 'restorepilot-backup-migration'),
        'stopped'                  => __('Stopped', 'restorepilot-backup-migration'),
        // Headline shown above the progress bar. It has to change with the
        // job's real state — left as a fixed "Backup in progress" it kept
        // claiming a backup was running after one had been canceled or had
        // already finished.
        'backupInProgress'         => __('Backup in progress', 'restorepilot-backup-migration'),
        /* translators: shown after an elapsed duration, e.g. "2m 14s elapsed" */
        'elapsed'                  => __('elapsed', 'restorepilot-backup-migration'),
        'canceled'                 => __('Canceled', 'restorepilot-backup-migration'),
        'failed'                   => __('Failed', 'restorepilot-backup-migration'),
        'backupJobNotFound'        => __('Backup job could not be found.', 'restorepilot-backup-migration'),
        'backupRunning'            => __('Backup is running...', 'restorepilot-backup-migration'),
        'backupComplete'           => __('Backup complete.', 'restorepilot-backup-migration'),
        'backupFailed'             => __('Backup failed.', 'restorepilot-backup-migration'),
        'backupCanceled'           => __('Backup canceled.', 'restorepilot-backup-migration'),
        'backupStatusError'        => __('Backup status could not be checked.', 'restorepilot-backup-migration'),
        'startingBackup'           => __('Starting backup...', 'restorepilot-backup-migration'),
        'startingBackupLabel'      => __('Starting backup', 'restorepilot-backup-migration'),
        'backupJobNotStarted'      => __('Backup job could not be started.', 'restorepilot-backup-migration'),
        'backupRunningBackground'  => __('Backup is running in the background...', 'restorepilot-backup-migration'),
        'queued'                   => __('Queued', 'restorepilot-backup-migration'),
        'confirmCancelBackup'      => __('Cancel the running backup?', 'restorepilot-backup-migration'),
        'cancelingBackup'          => __('Canceling backup...', 'restorepilot-backup-migration'),
        'canceling'                => __('Canceling', 'restorepilot-backup-migration'),
        'backupCancelError'        => __('Backup could not be canceled.', 'restorepilot-backup-migration'),
        // Headline above the restore progress bar. Same reason as
        // 'backupInProgress': hardcoded in the markup it read "Uploading" for
        // the whole restore, including after one had finished or failed.
        'adminEmailInvalid'        => __('Enter the email address you want to sign in with.', 'restorepilot-backup-migration'),
        'adminPasswordTooShort'    => __('Choose a password of at least 8 characters.', 'restorepilot-backup-migration'),
        'settingAdminPassword'     => __('Restore complete. Setting your admin password...', 'restorepilot-backup-migration'),
        'adminPasswordSet'         => __('Restore complete. Sign in with the email and password you chose.', 'restorepilot-backup-migration'),
        /* translators: %s: the email address of the administrator account that was created */
        'adminPasswordSetFor'      => __('Restore complete. Sign in with %s and the password you chose.', 'restorepilot-backup-migration'),
        'adminPasswordFailed'      => __('The restore finished, but your chosen password could not be applied. Use "Lost your password?" on the login page with the email you entered to set one.', 'restorepilot-backup-migration'),
        'uploading'                => __('Uploading', 'restorepilot-backup-migration'),
        'restoreInProgress'        => __('Restore in progress', 'restorepilot-backup-migration'),
        'restoreStatusError'       => __('Restore status could not be read. If the site asks you to log in again, check the Logs tab after login.', 'restorepilot-backup-migration'),
        'restoreRunning'           => __('Restore is running...', 'restorepilot-backup-migration'),
        'restoreInProgressMaintenance' => __('Restore in progress — the site is briefly in maintenance mode. Please wait...', 'restorepilot-backup-migration'),
        'restoreComplete'          => __('Restore complete. You may need to log in again.', 'restorepilot-backup-migration'),
        'restoreNeedsAttention'    => __('Restore needs attention. Check Logs.', 'restorepilot-backup-migration'),
        'restoreStatusErrorAfterLogin' => __('Restore status could not be read. Check Logs after logging in again.', 'restorepilot-backup-migration'),
        'queuingRestore'           => __('Queueing restore...', 'restorepilot-backup-migration'),
        'restoreNotStarted'        => __('Restore could not be started.', 'restorepilot-backup-migration'),
        'restoreQueued'            => __('Restore queued.', 'restorepilot-backup-migration'),
        'chooseBackupToContinue'   => __('Choose a backup zip or enter a server backup path to continue.', 'restorepilot-backup-migration'),
        'uploadingBackup'          => __('Uploading backup...', 'restorepilot-backup-migration'),
        'backupUploadFailed'       => __('Backup upload failed.', 'restorepilot-backup-migration'),
        'uploadCompleteChecking'   => __('Upload complete. Checking backup...', 'restorepilot-backup-migration'),
        'uploadCompleteRestoring'  => __('Upload complete. Starting restore...', 'restorepilot-backup-migration'),
        'masterResetting'          => __('Resetting…', 'restorepilot-backup-migration'),
        'masterResetFailed'        => __('Reset failed. Please try again.', 'restorepilot-backup-migration'),
      ],
    ]);
  }
}
