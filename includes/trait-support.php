<?php
/**
 * Settings, request input, capability checks, and path safety.
 *
 * @package RestorePilot_Backup_Migration
 */

if (!defined('ABSPATH')) {
  exit;
}

trait RestorePilot_Support {
  /**
   * The plugin's main file, and the directory holding it.
   *
   * __FILE__ and __DIR__ answer for the file they are written in, so once this
   * code was split across includes/ every use of them silently began pointing
   * one level too deep. That is not a theoretical problem: Master Reset used
   * dirname(__FILE__) to know which plugin directory was its own and skip it,
   * and after the split it matched nothing and deleted RestorePilot along with
   * everything else.
   *
   * Anything that needs to know where the plugin lives must ask here instead.
   * The constants are defined by the main file itself; the fallback climbs out
   * of includes/ so this still answers correctly if it is ever called before
   * they are set.
   */
  private static function plugin_root_file(): string {
    if (defined('RESTOREPILOT_BACKUP_MIGRATION_FILE')) {
      return (string) RESTOREPILOT_BACKUP_MIGRATION_FILE;
    }
    return dirname(__DIR__) . '/restorepilot-backup-migration.php';
  }

  private static function plugin_root_dir(): string {
    if (defined('RESTOREPILOT_BACKUP_MIGRATION_DIR')) {
      return rtrim((string) RESTOREPILOT_BACKUP_MIGRATION_DIR, '/\\');
    }
    return dirname(__DIR__);
  }

  /** This plugin's own "folder/file.php" identifier, as active_plugins stores it. */
  private static function plugin_basename_self(): string {
    return plugin_basename(self::plugin_root_file());
  }

  private static function get_settings(): array {
    $settings = get_option(self::SETTINGS_OPTION, []);
    if (!is_array($settings)) {
      $settings = [];
    }

    $email = isset($settings['notify_email']) ? sanitize_email((string) $settings['notify_email']) : '';
    if ($email === '' || !is_email($email)) {
      $email = (string) get_option('admin_email');
    }

    return [
      'scheduled_enabled' => !empty($settings['scheduled_enabled']),
      'scheduled_hour'    => isset($settings['scheduled_hour'])   ? max(0, min(23, (int) $settings['scheduled_hour']))  : 2,
      'scheduled_minute'  => isset($settings['scheduled_minute']) ? max(0, min(59, (int) $settings['scheduled_minute'])) : 0,
      'email_notifications' => !empty($settings['email_notifications']),
      'notify_email' => $email,
      'retention_count' => self::MAX_BACKUPS,
    ];
  }

  private static function retention_count(): int {
    return self::MAX_BACKUPS;
  }

  private static function prepare_for_long_operation(): void {
    if (function_exists('ignore_user_abort')) {
      ignore_user_abort(true);
    }
    if (function_exists('set_time_limit')) {
      @set_time_limit(0);
    }
  }

  private static function purge_foreign_runtime_state(string $current_job_id = '', string $current_restore_lock_token = ''): void {
    delete_option(self::BACKUP_LOCK_OPTION);

    global $wpdb;
    if (isset($wpdb) && method_exists($wpdb, 'prepare')) {
      // Delete every backup/restore job record, worker lock, and poll-related
      // option that came from the backup. These prefixes only ever hold
      // short-lived runtime state, so wiping them wholesale is safe — except
      // the currently-running restore's own job record and worker lock,
      // excluded below. Missing the worker-lock exception here once let this
      // function delete the very lock the restore calling it was still
      // actively holding, reopening the double-execution race that lock
      // exists to prevent (dispatch_restore_worker()'s loopback+cron pair
      // means a second dispatch attempt for the same chunk is always
      // plausible, not just theoretical).
      $current_restore_option = $current_job_id !== '' ? self::restore_job_option($current_job_id) : '';
      $current_restore_worker_option = $current_job_id !== '' ? self::restore_worker_lock_option($current_job_id) : '';
      $patterns = [
        ['like' => self::like_prefix_literal('restorepilot_backup_job_'), 'except' => ''],
        ['like' => self::like_prefix_literal(self::RESTORE_JOB_PREFIX), 'except' => $current_restore_option],
        ['like' => self::like_prefix_literal(self::BACKUP_WORKER_LOCK_PREFIX), 'except' => ''],
        ['like' => self::like_prefix_literal(self::RESTORE_WORKER_LOCK_PREFIX), 'except' => $current_restore_worker_option],
      ];
      // like_prefix_literal() already returns a fully quoted SQL literal, not
      // a prepare()-style bound value (see its docblock for why) — the
      // 'except' option name has no wildcard character in it and is always
      // a trusted, internally-generated value, so a plain esc_sql() is
      // sufficient and consistent with the same non-prepare() approach here.
      $table = $wpdb->options;
      foreach ($patterns as $pattern) {
        if ($pattern['except'] !== '') {
          // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->options; 'like' is a quoted literal from like_prefix_literal() over a hardcoded prefix; 'except' is an internally generated option name run through esc_sql().
          $wpdb->query("DELETE FROM `$table` WHERE option_name LIKE {$pattern['like']} AND option_name != '" . esc_sql($pattern['except']) . "'");
        } else {
          // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- as above: options table, hardcoded prefix, no request input.
          $wpdb->query("DELETE FROM `$table` WHERE option_name LIKE {$pattern['like']}");
        }
      }
    }

    // Re-establish the current restore's own site-wide lock via a plain
    // overwrite rather than the delete-then-recreate this used to do —
    // WordPress's update_option() resolves to a single UPDATE (or a single
    // atomic add_option()-backed INSERT if the row is absent), so there is
    // never a moment where no restore lock exists at all. A delete first
    // opened exactly that moment: a window, however brief, during which a
    // second restore's own acquire_restore_lock() could succeed and start
    // running genuinely concurrently with this one. The RENAME TABLE swap
    // just above already replaced the live wp_options row this option lived
    // in with the restored site's own (foreign, or absent) value, so an
    // overwrite here is always correct — there is no "already mine" value
    // to preserve at this point regardless.
    if ($current_job_id !== '' && $current_restore_lock_token !== '') {
      update_option(self::RESTORE_LOCK_OPTION, [
        'started' => time(),
        'job_id' => sanitize_key($current_job_id),
        'token' => $current_restore_lock_token,
      ], false);
    } else {
      delete_option(self::RESTORE_LOCK_OPTION);
    }

    self::write_log('Cleared backup/restore locks and stale job records carried in from the restored database.');
  }

  private static function action_url(string $action, string $file): string {
    return self::admin_action_url($action, ['file' => $file]);
  }

  private static function admin_action_url(string $action, array $args = []): string {
    $args = array_merge(
      [
        'action' => $action,
        '_wpnonce' => wp_create_nonce(self::NONCE),
      ],
      $args
    );

    return add_query_arg(
      $args,
      admin_url('admin-post.php')
    );
  }

  private static function post_value(string $key, string $default = ''): string {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Action handlers verify nonces before reading POST data.
    return isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : $default;
  }

  /**
   * Reads a submitted flag.
   *
   * Presence alone is not enough. An unchecked checkbox is simply absent from
   * the POST, which is what the bare-presence test was written for, but a
   * hidden field mirroring a checkbox has to submit something either way and
   * the natural thing to submit is "0" — which presence alone reads as true.
   * That is not hypothetical: create_new_admin does exactly this, so every
   * restore was creating an administrator account whether or not one had been
   * asked for, the account's generated password appearing once and then being
   * gone. Anything a form can plausibly mean by "false" is treated as false.
   */
  private static function post_bool(string $key): bool {
    $value = strtolower(trim(self::post_value($key)));
    return !in_array($value, ['', '0', 'false', 'off', 'no'], true);
  }

  private static function post_int(string $key, int $default = 0): int {
    $value = self::post_value($key, (string) $default);
    return is_numeric($value) ? (int) $value : $default;
  }

  private static function post_array(string $key): array {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Action handlers verify nonces; array values are sanitized by sanitize_selected_backup_paths().
    $value = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : [];
    if (!is_array($value)) {
      $value = [$value];
    }
    return $value;
  }

  private static function query_value(string $key, string $default = ''): string {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin query args; state changes use nonce-protected handlers.
    return isset($_GET[$key]) ? sanitize_text_field(wp_unslash($_GET[$key])) : $default;
  }

  private static function query_file(string $key): string {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File query args are used only after capability and nonce checks, then sanitized as filenames.
    return isset($_GET[$key]) ? sanitize_file_name(rawurldecode(wp_unslash($_GET[$key]))) : '';
  }

  private static function uploaded_file_array(string $key): array {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Upload handlers verify nonces; file arrays are validated before use.
    return isset($_FILES[$key]) && is_array($_FILES[$key]) ? $_FILES[$key] : [];
  }

  private static function safe_backup_file_from_request(): string {
    $name = self::query_file('file');
    if (!preg_match('/\.zip(\.part[0-9]{3})?$/', $name)) {
      return self::backup_dir() . '/';
    }
    return self::backup_dir() . '/' . $name;
  }

  private static function path_is_unsafe(string $path): bool {
    return strpos($path, '..') !== false || strpos($path, "\0") !== false || preg_match('#^[/\\\\]#', $path);
  }

  private static function zip_entry_is_unsafe(string $name): bool {
    $name = str_replace('\\', '/', $name);
    if ($name === '' || strpos($name, "\0") !== false || preg_match('#^([a-z]:)?/#i', $name)) {
      return true;
    }

    foreach (explode('/', $name) as $part) {
      if ($part === '..') {
        return true;
      }
    }

    return false;
  }

  private static function safe_content_path(string $relative): string {
    $relative = trim(str_replace('\\', '/', $relative), '/');
    if ($relative === '' || self::path_is_unsafe($relative)) {
      throw new RuntimeException(__('Restore path is unsafe.', 'restorepilot-backup-migration'));
    }

    $base = realpath(self::content_dir());
    if ($base === false) {
      throw new RuntimeException(__('wp-content folder could not be located.', 'restorepilot-backup-migration'));
    }

    $target = trailingslashit(self::content_dir()) . $relative;
    $parent = dirname($target);
    if (!wp_mkdir_p($parent)) {
      /* translators: %s: folder path being created */
      throw new RuntimeException(sprintf(__('Could not create restore folder: %s', 'restorepilot-backup-migration'), $relative));
    }

    $real_parent = realpath($parent);
    $base = rtrim(str_replace('\\', '/', $base), '/');
    $real_parent = $real_parent ? rtrim(str_replace('\\', '/', $real_parent), '/') : '';
    if ($real_parent === '' || ($real_parent !== $base && strpos($real_parent, $base . '/') !== 0)) {
      throw new RuntimeException(__('Restore target is outside wp-content.', 'restorepilot-backup-migration'));
    }

    return $target;
  }

  private static function verify_admin_request(): void {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Permission denied.', 'restorepilot-backup-migration'));
    }
    check_admin_referer(self::NONCE);
  }

  /**
   * Refuse backup/restore operations on a multisite network.
   *
   * RestorePilot selects tables by $wpdb->prefix. On a multisite MAIN site that
   * prefix ("wp_") also owns the network-global tables — users, usermeta, blogs,
   * site, sitemeta, signups — so a single site administrator holding only
   * manage_options could otherwise export shared accounts and network
   * configuration, or overwrite them during a restore. Excluding subsite tables
   * alone does not fix that, and a backup that contains the global tables but
   * not each subsite's tables cannot be restored coherently anyway.
   *
   * Rather than ship a partial implementation with that data-integrity trap,
   * backup and restore are unavailable on multisite. This is enforced at the two
   * chokepoints every entry path funnels through (create_backup_package() and
   * perform_restore()), so admin-post, AJAX, WP-CLI and cron are all covered.
   * Uninstall cleanup remains multisite-aware.
   */
  private static function multisite_unsupported_message(): string {
    return __('RestorePilot does not support backups or restores on WordPress multisite networks, because database tables and the plugin and theme directories are shared across the network.', 'restorepilot-backup-migration');
  }

  // Throws inside worker code (create_backup_package()/perform_restore()) that
  // is already wrapped in a try/catch. Every user-facing entry point ALSO checks
  // is_multisite() directly, before any upload/queue/loopback/cron side effect —
  // this assertion is the last-resort backstop, not the primary gate.
  private static function assert_multisite_unsupported(): void {
    if (is_multisite()) {
      throw new RuntimeException(self::multisite_unsupported_message());
    }
  }

  private static function redirect_notice(string $message, string $tab = ''): void {
    $args = ['rp_notice' => rawurlencode($message)];
    if ($tab !== '') {
      $args['tab'] = sanitize_key($tab);
    }
    wp_safe_redirect(add_query_arg($args, self::admin_url()));
    exit;
  }

  private static function redirect_error(string $message, string $tab = ''): void {
    $args = ['rp_error' => rawurlencode($message)];
    if ($tab !== '') {
      $args['tab'] = sanitize_key($tab);
    }
    wp_safe_redirect(add_query_arg($args, self::admin_url()));
    exit;
  }

  private static function admin_url(): string {
    return admin_url('admin.php?page=' . self::SLUG);
  }
}
