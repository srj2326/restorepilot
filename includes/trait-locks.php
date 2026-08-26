<?php
/**
 * Keeping two backups, two restores, or two workers off the same job.
 *
 * @package RestorePilot_Backup_Migration
 */

if (!defined('ABSPATH')) {
  exit;
}

trait RestorePilot_Locks {
  private static function acquire_backup_lock(string $job_id = ''): string {
    $token = wp_generate_password(24, false, false);
    $lock = [
      'started' => time(),
      'job_id' => sanitize_key($job_id),
      'token' => $token,
    ];

    $existing = get_option(self::BACKUP_LOCK_OPTION, []);
    if (is_array($existing) && !empty($existing['started']) && self::backup_lock_can_be_released($existing)) {
      delete_option(self::BACKUP_LOCK_OPTION);
      $existing = [];
      self::write_log('Released inactive backup lock before acquiring a new lock.');
    }

    if (is_array($existing) && !empty($existing['started']) && (time() - (int) $existing['started']) < 6 * HOUR_IN_SECONDS) {
      throw new RuntimeException(__('A backup is already running. Please wait for it to finish.', 'restorepilot-backup-migration'));
    }

    if (is_array($existing) && !empty($existing)) {
      delete_option(self::BACKUP_LOCK_OPTION);
    }

    if (!add_option(self::BACKUP_LOCK_OPTION, $lock, '', false)) {
      $existing = get_option(self::BACKUP_LOCK_OPTION, []);
      if (is_array($existing) && !empty($existing['started']) && self::backup_lock_can_be_released($existing)) {
        delete_option(self::BACKUP_LOCK_OPTION);
        if (add_option(self::BACKUP_LOCK_OPTION, $lock, '', false)) {
          return $token;
        }
      }

      throw new RuntimeException(__('A backup is already running. Please wait for it to finish.', 'restorepilot-backup-migration'));
    }

    return $token;
  }

  private static function backup_lock_is_active(): bool {
    $lock = get_option(self::BACKUP_LOCK_OPTION, []);
    if (!is_array($lock) || empty($lock['started'])) {
      return false;
    }

    $job_id = isset($lock['job_id']) ? (string) $lock['job_id'] : '';
    if ($job_id !== '') {
      $job = self::get_backup_job($job_id);
      if (self::backup_lock_can_be_released($lock)) {
        self::force_release_backup_locks($job_id);
        self::write_log('Released inactive backup lock before starting a new backup: ' . $job_id);
        return false;
      }
    }

    return (time() - (int) $lock['started']) < 6 * HOUR_IN_SECONDS;
  }

  private static function release_backup_lock(string $token): void {
    $lock = get_option(self::BACKUP_LOCK_OPTION, []);
    if (is_array($lock) && hash_equals((string) ($lock['token'] ?? ''), $token)) {
      delete_option(self::BACKUP_LOCK_OPTION);
    }
  }

  /**
   * Mirrors backup_lock_is_active() — used by enforce_backup_retention() so
   * a resumable restore's source file (re-read from disk on every one of
   * its many chunks) can never be deleted out from under it by ordinary
   * retention cleanup running on some other request in the meantime.
   */
  private static function restore_lock_is_active(): bool {
    $lock = get_option(self::RESTORE_LOCK_OPTION, []);
    if (!is_array($lock) || empty($lock['started'])) {
      return false;
    }

    $job_id = isset($lock['job_id']) ? (string) $lock['job_id'] : '';
    if ($job_id !== '' && self::restore_lock_can_be_released($lock)) {
      self::force_release_restore_locks($job_id);
      self::write_log('Released inactive restore lock before enforcing backup retention.');
      return false;
    }

    return (time() - (int) $lock['started']) < 6 * HOUR_IN_SECONDS;
  }

  /**
   * Site-wide lock, held for a resumable restore's entire multi-chunk
   * lifetime — mirrors acquire_backup_lock()'s shape (including its TOCTOU
   * handling) rather than the simpler flat-timeout version this used to be,
   * now that $job_id lets a stale lock be reclaimed based on the job's own
   * progress instead of a flat 6-hour wait. A resumption never calls this —
   * it reuses the token already recorded in the job's checkpoint.
   */
  private static function acquire_restore_lock(string $job_id = ''): string {
    $token = wp_generate_password(24, false, false);
    $lock = [
      'started' => time(),
      'job_id' => sanitize_key($job_id),
      'token' => $token,
    ];

    $existing = get_option(self::RESTORE_LOCK_OPTION, []);
    if (is_array($existing) && !empty($existing['started']) && self::restore_lock_can_be_released($existing)) {
      delete_option(self::RESTORE_LOCK_OPTION);
      $existing = [];
      self::write_log('Released inactive restore lock before acquiring a new lock.');
    }

    if (is_array($existing) && !empty($existing['started']) && (time() - (int) $existing['started']) < 6 * HOUR_IN_SECONDS) {
      throw new RuntimeException(__('A restore is already running. Please wait for it to finish before starting another restore.', 'restorepilot-backup-migration'));
    }

    if (is_array($existing) && !empty($existing)) {
      delete_option(self::RESTORE_LOCK_OPTION);
    }

    if (!add_option(self::RESTORE_LOCK_OPTION, $lock, '', false)) {
      $existing = get_option(self::RESTORE_LOCK_OPTION, []);
      if (is_array($existing) && !empty($existing['started']) && self::restore_lock_can_be_released($existing)) {
        delete_option(self::RESTORE_LOCK_OPTION);
        if (add_option(self::RESTORE_LOCK_OPTION, $lock, '', false)) {
          return $token;
        }
      }
      throw new RuntimeException(__('A restore is already running. Please wait for it to finish before starting another restore.', 'restorepilot-backup-migration'));
    }

    return $token;
  }

  private static function release_restore_lock(string $token): void {
    $lock = get_option(self::RESTORE_LOCK_OPTION, []);
    if (is_array($lock) && hash_equals((string) ($lock['token'] ?? ''), $token)) {
      delete_option(self::RESTORE_LOCK_OPTION);
    }
  }

  private static function backup_lock_can_be_released(array $lock): bool {
    $started = (int) ($lock['started'] ?? 0);
    $age = $started > 0 ? time() - $started : PHP_INT_MAX;
    $job_id = isset($lock['job_id']) ? (string) $lock['job_id'] : '';

    if ($job_id !== '') {
      $job = self::get_backup_job($job_id);
      if ($job) {
        $status = (string) ($job['status'] ?? '');
        // 'complete'/'error'/'stale' are terminal: by the time that status is
        // visible here, the worker has already unwound through its own
        // finally-block cleanup and released this lock itself, so if the lock
        // is still present it is safe to reclaim.
        // 'canceled' is deliberately NOT included here: cancellation only
        // requests that the worker stop — it may still be mid-export and
        // holding this lock legitimately. Wait for backup_job_is_stale() (no
        // progress update for a while) before treating a canceled job's lock
        // as abandoned, so a second backup cannot start while the first
        // worker is still actually running.
        if (in_array($status, ['complete', 'error', 'stale'], true)) {
          return true;
        }
        if (self::backup_job_is_stale($job)) {
          return true;
        }
      } elseif ($age > self::BACKUP_START_TIMEOUT_SECONDS) {
        // The lock names a job that has no record on this site. A genuine backup
        // writes its job option in the same request that acquires the lock, so a
        // missing job after the start-timeout means the lock is orphaned — most
        // commonly a foreign lock carried in from a restored database. Release it
        // rather than blocking new backups for the full stale window.
        return true;
      }
    }

    return $age >= 6 * HOUR_IN_SECONDS;
  }

  /** Restore-side counterpart to backup_lock_can_be_released() — see there for the reasoning. */
  private static function restore_lock_can_be_released(array $lock): bool {
    $started = (int) ($lock['started'] ?? 0);
    $age = $started > 0 ? time() - $started : PHP_INT_MAX;
    $job_id = isset($lock['job_id']) ? (string) $lock['job_id'] : '';

    if ($job_id !== '') {
      $job = self::get_restore_job($job_id);
      if ($job) {
        $status = (string) ($job['status'] ?? '');
        if (in_array($status, ['complete', 'error', 'stale'], true)) {
          return true;
        }
        if (self::restore_job_is_stale($job)) {
          return true;
        }
      } elseif ($age > self::BACKUP_START_TIMEOUT_SECONDS) {
        return true;
      }
    }

    return $age >= 6 * HOUR_IN_SECONDS;
  }

  /**
   * Takes a named worker lock, or returns false if another worker holds it.
   *
   * add_option() cannot do this, despite the comment that used to claim it
   * could. It decides whether the option exists with a get_option() read and
   * then writes with INSERT ... ON DUPLICATE KEY UPDATE, which never fails on
   * a duplicate — so two workers arriving together both read "absent", both
   * write, and both are told they won. That is exactly what happened here:
   * two restore workers ran the same chunk one second apart and collided on a
   * duplicate primary key mid-restore.
   *
   * This is the pattern WordPress core uses for the same job in
   * WP_Upgrader::create_lock(): INSERT IGNORE, with no ON DUPLICATE clause, so
   * the unique index on option_name decides it inside the database and exactly
   * one caller can win. The value is a bare timestamp rather than a serialized
   * array, so the staleness check below can compare it without unserializing.
   */
  private static function claim_worker_lock(string $option): bool {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the point of this query is to bypass the option cache and let the database arbitrate.
    $claimed = $wpdb->query($wpdb->prepare(
      "INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'off')",
      $option,
      (string) time()
    ));

    if (!$claimed) {
      return false;
    }

    // The row went in behind the option cache's back, so anything it still
    // believes about this name is now wrong.
    wp_cache_delete($option, 'options');
    $notoptions = wp_cache_get('notoptions', 'options');
    if (is_array($notoptions) && isset($notoptions[$option])) {
      unset($notoptions[$option]);
      wp_cache_set('notoptions', $notoptions, 'options');
    }

    return true;
  }

  /**
   * Shared body of the two acquire_*_worker_lock() calls.
   */
  private static function acquire_worker_lock(string $option): bool {
    if (self::claim_worker_lock($option)) {
      return true;
    }

    // Someone holds it. Read straight past the cache: this process may never
    // have read this name before and would otherwise be told it is absent.
    wp_cache_delete($option, 'options');
    $started = (int) get_option($option, 0);
    if ($started <= 0) {
      return false;
    }
    if ((time() - $started) < self::WORKER_LOCK_STALE_SECONDS) {
      return false;
    }

    // Expired. Clear it and race for it again -- whoever wins the INSERT
    // IGNORE this time is the single holder, so two workers arriving at an
    // expired lock together still cannot both proceed.
    delete_option($option);
    return self::claim_worker_lock($option);
  }

  private static function acquire_backup_worker_lock(string $job_id): bool {
    return self::acquire_worker_lock(self::backup_worker_lock_option($job_id));
  }

  private static function release_backup_worker_lock(string $job_id): void {
    delete_option(self::backup_worker_lock_option($job_id));
  }

  private static function backup_worker_lock_option(string $job_id): string {
    return self::BACKUP_WORKER_LOCK_PREFIX . sanitize_key($job_id);
  }

  private static function acquire_restore_worker_lock(string $job_id): bool {
    return self::acquire_worker_lock(self::restore_worker_lock_option($job_id));
  }

  private static function release_restore_worker_lock(string $job_id): void {
    delete_option(self::restore_worker_lock_option($job_id));
  }

  private static function restore_worker_lock_option(string $job_id): string {
    return self::RESTORE_WORKER_LOCK_PREFIX . sanitize_key($job_id);
  }

  private static function force_release_backup_locks(string $job_id): void {
    $lock = get_option(self::BACKUP_LOCK_OPTION, []);
    if (is_array($lock) && !empty($lock['started'])) {
      // Release if this lock belongs to the job being cleaned up, OR if the
      // existing lock is independently releasable (its job finished/is stale).
      // backup_lock_can_be_released() reads the real job's 'updated' timestamp
      // from the DB, so it correctly handles long-running backups that are
      // still making progress (vs. the old code which used lock['started'] as
      // the stale proxy and would incorrectly release after BACKUP_STALE_SECONDS).
      if ((string) ($lock['job_id'] ?? '') === sanitize_key($job_id) || self::backup_lock_can_be_released($lock)) {
        delete_option(self::BACKUP_LOCK_OPTION);
      }
    }

    self::release_backup_worker_lock($job_id);
  }

  /**
   * Mirrors force_release_backup_locks(), plus disable_maintenance_mode()
   * since a restore's cleanup always needs both. Every restore crash/stale/
   * never-started recovery path must go through this rather than hand-
   * rolling delete_option(RESTORE_LOCK_OPTION) itself — three call sites
   * previously did that and every one of them forgot the worker lock (one
   * forgot the site lock too), leaving restorepilot_restore_worker_<job_id>
   * behind in wp_options indefinitely once the job reached a terminal
   * status, since nothing else ever revisits a finished job's worker lock.
   */
  private static function force_release_restore_locks(string $job_id): void {
    $lock = get_option(self::RESTORE_LOCK_OPTION, []);
    if (is_array($lock) && !empty($lock['started'])) {
      if ((string) ($lock['job_id'] ?? '') === sanitize_key($job_id) || self::restore_lock_can_be_released($lock)) {
        delete_option(self::RESTORE_LOCK_OPTION);
      }
    }

    self::release_restore_worker_lock($job_id);
    self::disable_maintenance_mode();
  }
}
