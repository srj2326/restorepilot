<?php
/**
 * Maintenance mode during a restore, and the notices left afterwards.
 *
 * @package RestorePilot_Backup_Migration
 */

if (!defined('ABSPATH')) {
  exit;
}

trait RestorePilot_Maintenance {
  /**
   * Hooked on init — after every plugin (including this one) has loaded, and
   * for every request type (front-end, wp-admin, admin-ajax.php, wp-cron.php,
   * WP-CLI). This deliberately does NOT use WordPress's own ABSPATH/.maintenance
   * flag or a wp-content/maintenance.php drop-in: wp_maintenance() runs those
   * from wp-settings.php before any plugin has loaded, so a normal plugin hook
   * can never register in time to exempt anything, and a custom maintenance.php
   * drop-in gets require()'d and then WordPress core calls die() right after —
   * unconditionally, regardless of what the drop-in itself does — leaving no
   * way for a request to fall through to normal routing once one exists. That
   * combination previously meant the loopback dispatch and cron fallback that
   * are supposed to carry a restore from one chunk to the next were themselves
   * blocked by the maintenance mode the restore had just turned on, deadlocking
   * the whole resumable-restore mechanism the moment it activated.
   */
  public static function maybe_block_for_maintenance(): void {
    if (!self::should_block_for_maintenance()) {
      return;
    }

    // An administrator gets a page that tells them what is happening and
    // offers a way out, instead of the same opaque 503 a visitor sees.
    //
    // They are deliberately still BLOCKED from the site itself: during a
    // restore the database is mid-replacement, and letting an admin browse
    // wp-admin would invite writes that the swap is about to discard. The
    // problem this solves is not access, it is being stranded — a restore
    // that dies leaves maintenance on for up to an hour and its site-wide
    // lock held for two (see restore_lock_can_be_released()), during which
    // the owner previously had no way to see what was wrong and no way to
    // release it. Any chunk that starts and dies partway also refreshes both
    // clocks, so a doomed restore could strand them repeatedly.
    if (is_user_logged_in() && current_user_can('manage_options')) {
      self::render_maintenance_admin_page();
    }

    self::render_maintenance_page();
  }

  /**
   * Pure decision, deliberately kept apart from render_maintenance_page()'s
   * exit — the exemptions here are exactly what a resumable restore's own
   * dispatch needs to survive the maintenance window it just turned on, and
   * getting them wrong is a silent deadlock, not a visible error, so this is
   * worth being able to unit-test directly rather than only by inspecting
   * the rendered response of a real request.
   */
  public static function should_block_for_maintenance(): bool {
    $until = (int) get_option(self::MAINTENANCE_OPTION, 0);
    if ($until <= 0 || time() > $until) {
      return false;
    }

    // WordPress's own cron runner processes every due event, including this
    // restore's own continuation — blocking it would mean maintenance mode
    // can never end on its own. WP-CLI is exempted for the same reason the
    // old mechanism exempted it. Both are trustworthy: neither can be aimed
    // at arbitrary site content the way a blocked visitor request could be.
    if (wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
      return false;
    }

    // The administrator's own "abandon this restore" action has to be
    // reachable while maintenance is on — being unreachable is the entire
    // problem it exists to solve. Letting the request through here only
    // reaches normal routing; handle_abandon_restore() verifies the nonce
    // and the manage_options capability itself before doing anything.
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- only selects a route; the handler verifies nonce and capability.
    $requested_action = isset($_POST['action']) ? sanitize_key(wp_unslash($_POST['action'])) : '';
    if ($requested_action === '') {
      // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects a route; the handler verifies nonce and capability.
      $requested_action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
    }
    if ($requested_action === 'restorepilot_abandon_restore') {
      return false;
    }

    // These two AJAX actions carry their own token-based authentication
    // (see handle_restore_status() and run_restore_job()) specifically so
    // they keep working without a valid admin session — including through a
    // maintenance window. Letting the request through here only reaches
    // WordPress's normal, already-authenticated routing for them; it does
    // not itself grant access to anything.
    if (wp_doing_ajax()) {
      // phpcs:ignore WordPress.Security.NonceVerification.Missing -- these actions carry their own token auth, checked by their own handler
      $action = isset($_POST['action']) ? sanitize_key(wp_unslash($_POST['action'])) : '';
      if ($action === 'restorepilot_restore_status' || $action === 'restorepilot_run_restore_job') {
        return false;
      }
    }

    return true;
  }

  private static function enable_maintenance_mode(): void {
    update_option(self::MAINTENANCE_OPTION, time() + HOUR_IN_SECONDS, true);
    self::write_log('Maintenance mode enabled for restore.');
  }

  private static function disable_maintenance_mode(): void {
    delete_option(self::MAINTENANCE_OPTION);
    self::write_log('Maintenance mode disabled.');
  }

  private static function operation_notice_file(): string {
    return self::storage_dir() . '/operation-notice.json';
  }

  private static function write_operation_notice(string $type, string $operation, string $message): void {
    $data = wp_json_encode([
      'type'      => $type,
      'operation' => $operation,
      'message'   => $message,
      'created'   => time(),
    ]);
    if ($data !== false) {
      @file_put_contents(self::operation_notice_file(), $data);
    }
  }

  private static function set_restore_success_notice(string $source_url, string $target_url): void {
    update_option(self::RESTORE_SUCCESS_OPTION, [
      'created' => time(),
      'source_url' => esc_url_raw($source_url),
      'target_url' => esc_url_raw($target_url),
    ], false);
  }

  private static function get_restore_success_notice(): array {
    if (self::$restore_success_notice !== null) {
      return is_array(self::$restore_success_notice) ? self::$restore_success_notice : [];
    }

    $notice = get_option(self::RESTORE_SUCCESS_OPTION, []);
    if (!is_array($notice) || empty($notice['created'])) {
      self::$restore_success_notice = [];
      return [];
    }

    self::$restore_success_notice = [
      'created' => (int) $notice['created'],
      'source_url' => isset($notice['source_url']) ? esc_url_raw((string) $notice['source_url']) : '',
      'target_url' => isset($notice['target_url']) ? esc_url_raw((string) $notice['target_url']) : '',
    ];

    return self::$restore_success_notice;
  }
}
