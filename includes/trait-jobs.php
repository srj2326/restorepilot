<?php
/**
 * The job record a chunked backup or restore resumes from.
 *
 * @package RestorePilot_Backup_Migration
 */

if (!defined('ABSPATH')) {
  exit;
}

trait RestorePilot_Jobs {
  private static function backup_job_option(string $job_id): string {
    return 'restorepilot_backup_job_' . sanitize_key($job_id);
  }

  private static function restore_job_option(string $job_id): string {
    return self::RESTORE_JOB_PREFIX . sanitize_key($job_id);
  }

  private static function poll_token_file(string $job_id): string {
    return self::storage_dir() . '/poll-token-' . sanitize_file_name($job_id) . '.txt';
  }

  private static function write_poll_token_file(string $job_id, string $poll_token): void {
    @file_put_contents(self::poll_token_file($job_id), $poll_token);
  }

  private static function read_poll_token_file(string $job_id): string {
    $file = self::poll_token_file($job_id);
    if (!is_file($file)) {
      return '';
    }
    return trim((string) @file_get_contents($file));
  }

  private static function delete_poll_token_file(string $job_id): void {
    $file = self::poll_token_file($job_id);
    if (is_file($file)) {
      @unlink($file);
    }
  }

  private static function restore_status_file(string $job_id): string {
    return self::storage_dir() . '/restore-status-' . sanitize_file_name($job_id) . '.json';
  }

  // Mirror the restore job's status to a file. The job record normally lives in
  // wp_options, but the database restore replaces wp_options with the backup's
  // contents mid-run, wiping the job. This file lives in our storage dir, which
  // is excluded from backups and never written by restore_files(), so it survives
  // the swap and lets status polls keep reporting progress.
  /**
   * Mirrors the WHOLE job record, not just the display fields a status poll
   * needs. A resumable restore's checkpoint — and the token run_restore_job()
   * authenticates every resumption against — must survive the exact same
   * wp_options wipe this file already exists to survive; a partial mirror
   * would leave get_restore_job()'s fallback (below) unable to recover
   * either one, silently restarting the whole restore from scratch on top
   * of an already-restored database, or failing token auth outright so no
   * further resumption could ever run at all.
   */
  private static function write_restore_status_file(string $job_id, array $job): void {
    if ($job_id === '') {
      return;
    }
    try {
      self::ensure_storage();
      @file_put_contents(self::restore_status_file($job_id), wp_json_encode($job));
    } catch (Throwable $e) {
      return;
    }
  }

  private static function read_restore_status_file(string $job_id): array {
    $file = self::restore_status_file($job_id);
    if (!is_file($file)) {
      return [];
    }
    $data = json_decode((string) @file_get_contents($file), true);
    return is_array($data) ? $data : [];
  }

  /**
   * @param bool $fresh Re-read from the database, ignoring this process's
   *   option cache. Required after taking the worker lock: the read that
   *   happens before the lock caches the record, so a plain re-read hands
   *   back the same stale copy and the caller carries on from a position
   *   another worker has already moved past.
   */
  private static function get_backup_job(string $job_id, bool $fresh = false): array {
    if ($job_id === '') {
      return [];
    }

    if ($fresh) {
      wp_cache_delete(self::backup_job_option($job_id), 'options');
    }

    $job = get_option(self::backup_job_option($job_id), []);
    return is_array($job) ? $job : [];
  }

  /**
   * @param bool $fresh Re-read from the database, ignoring this process's
   *   option cache. Required after taking the worker lock: the read that
   *   happens before the lock caches the record, so a plain re-read hands
   *   back the same stale copy and the caller carries on from a position
   *   another worker has already moved past.
   */
  private static function get_restore_job(string $job_id, bool $fresh = false): array {
    if ($job_id === '') {
      return [];
    }

    if ($fresh) {
      wp_cache_delete(self::restore_job_option($job_id), 'options');
    }

    $job = get_option(self::restore_job_option($job_id), []);
    if (is_array($job) && !empty($job)) {
      return $job;
    }

    // The DB record is gone (most likely the restore replaced wp_options).
    // Fall back to the status file so polls still see the live progress.
    $status = self::read_restore_status_file($job_id);
    return $status;
  }

  private static function set_backup_job(string $job_id, array $job): void {
    update_option(self::backup_job_option($job_id), $job, false);
  }

  private static function set_restore_job(string $job_id, array $job): void {
    update_option(self::restore_job_option($job_id), $job, false);
    self::write_restore_status_file($job_id, $job);
  }

  private static function update_backup_job(string $job_id, array $updates): void {
    $job = self::get_backup_job($job_id);
    if (!$job) {
      return;
    }

    $job = array_merge($job, $updates, ['updated' => time()]);
    self::set_backup_job($job_id, $job);
  }

  private static function update_restore_job(string $job_id, array $updates): void {
    $job = self::get_restore_job($job_id);
    if (!$job) {
      if ($job_id === '' || $job_id !== self::$active_restore_job_id) {
        return;
      }
      $job = [
        'status' => 'running',
        'phase' => 'database',
        'phase_label' => self::restore_phase_label('database'),
        'progress' => 60,
        'message' => __('Restore is continuing after the database swap.', 'restorepilot-backup-migration'),
        'created' => time(),
      ];
    }

    // Carry the poll_token forward if the bootstrap job doesn't have it yet.
    if (empty($job['poll_token'])) {
      $token_from_file = self::read_poll_token_file($job_id);
      if ($token_from_file !== '') {
        $job['poll_token'] = $token_from_file;
      }
    }
    $job = array_merge($job, $updates, ['updated' => time()]);
    self::set_restore_job($job_id, $job);
  }

  private static function maybe_touch_backup_job(string $job_id, string $message, int $progress, array $extra = [], bool $force = false): void {
    static $last_touch = [];
    if ($job_id === '') {
      return;
    }

    $now = time();
    if (!$force && isset($last_touch[$job_id]) && ($now - $last_touch[$job_id]) < 5) {
      return;
    }

    $last_touch[$job_id] = $now;
    self::update_backup_job($job_id, array_merge([
      'progress' => $progress,
      'message' => $message,
    ], $extra));
  }

  private static function maybe_touch_restore_job(string $job_id, string $message, int $progress, array $extra = [], bool $force = false): void {
    static $last_touch = [];
    if ($job_id === '') {
      return;
    }

    $now = time();
    if (!$force && isset($last_touch[$job_id]) && ($now - $last_touch[$job_id]) < 5) {
      return;
    }

    $last_touch[$job_id] = $now;
    self::update_restore_job($job_id, array_merge([
      'status' => 'running',
      'progress' => $progress,
      'message' => $message,
    ], $extra));
  }

  /**
   * Stops a running backup as soon as the operator cancels it.
   *
   * Called at every table and every row, which reads as though it stops
   * promptly -- but get_option() caches per process, so a worker that read the
   * job at the start of its chunk kept seeing "running" no matter what the
   * Cancel button wrote. Cancelling only took effect when the next chunk began
   * in a fresh process, up to a whole chunk later.
   *
   * The read is therefore uncached, and throttled to once a second so that
   * calling it per row does not mean a query per row. A cancel is noticed
   * within a second instead of within twenty.
   */
  private static function throw_if_backup_cancelled(string $job_id): void {
    if ($job_id === '') {
      return;
    }

    static $last_checked = 0.0;
    static $last_job_id = '';

    $now = microtime(true);
    // Always check immediately when the job being watched changes, so a new
    // chunk never inherits the previous one's throttle.
    if ($job_id === $last_job_id && ($now - $last_checked) < 1.0) {
      return;
    }
    $last_checked = $now;
    $last_job_id = $job_id;

    $job = self::get_backup_job($job_id, true);
    if (($job['status'] ?? '') === 'canceled') {
      throw new RestorePilot_Backup_Cancelled_Exception(__('Backup canceled.', 'restorepilot-backup-migration'));
    }
  }

  /**
   * Stops a restore that an administrator has ended from the maintenance page.
   *
   * Ending a restore marks the job terminal and releases the locks, but a
   * worker already inside a chunk knew nothing about it and carried on writing
   * for the rest of that chunk -- with the locks already gone, so a new restore
   * could start alongside it. Two restores writing at once is how one ends up
   * dropping the other's tables.
   *
   * Same shape as the backup check above, and the same reasons: uncached,
   * because a cached read is what hid this; throttled, because it is called
   * often enough that a query each time would be felt.
   */
  private static function throw_if_restore_abandoned(string $job_id): void {
    if ($job_id === '') {
      return;
    }

    static $last_checked = 0.0;
    static $last_job_id = '';

    $now = microtime(true);
    if ($job_id === $last_job_id && ($now - $last_checked) < 1.0) {
      return;
    }
    $last_checked = $now;
    $last_job_id = $job_id;

    $job = self::get_restore_job($job_id, true);
    $status = (string) ($job['status'] ?? '');
    if ($status !== '' && in_array($status, ['error', 'stale', 'complete'], true)) {
      throw new RuntimeException(__('This restore was ended before it finished. Recover your database from a pre-restore rollback point.', 'restorepilot-backup-migration'));
    }
  }

  /**
   * Checked at the same call sites as throw_if_backup_cancelled() during
   * file collection (never during the database export — see
   * create_backup_package()). Deliberately not checked more often than
   * that: this only needs to catch the budget being exceeded before the
   * host's own timeout does, not to the nearest millisecond.
   *
   * Never yields until $chunk_progress_made is true — see its declaration
   * for why a resumption is not allowed to end with zero forward progress
   * regardless of how far past its own deadline it already is.
   */
  private static function throw_if_chunk_time_exceeded(): void {
    if (self::$chunk_progress_made && self::$chunk_deadline > 0.0 && microtime(true) >= self::$chunk_deadline) {
      throw new RestorePilot_Backup_Chunk_Yield_Exception('Backup chunk time budget exceeded.');
    }
  }

  /**
   * Checked between tables and periodically within a table's row loop in
   * restore_database(), and between files in restore_files(). Never checked
   * during build_restore_plan() — that pass is read-only validation, cheap
   * enough to always redo in full on a resumption rather than checkpoint.
   *
   * Never yields until $restore_chunk_progress_made is true — see
   * $chunk_progress_made's declaration for the reasoning, which applies here
   * identically.
   */
  private static function throw_if_restore_chunk_time_exceeded(): void {
    if (self::$restore_chunk_progress_made && self::$restore_chunk_deadline > 0.0 && microtime(true) >= self::$restore_chunk_deadline) {
      throw new RestorePilot_Restore_Chunk_Yield_Exception('Restore chunk time budget exceeded.');
    }
  }

  private static function mark_stale_backup_job_if_needed(string $job_id, array $job): array {
    if (!self::backup_job_is_stale($job)) {
      return $job;
    }

    $message = __('Backup stopped responding. It may have been interrupted by the server. Check Logs, then start a fresh backup.', 'restorepilot-backup-migration');
    $updates = [
      'status' => 'stale',
      'phase' => 'stale',
      'phase_label' => self::backup_phase_label('stale'),
      'progress' => 100,
      'message' => $message,
    ];
    self::update_backup_job($job_id, $updates);
    self::force_release_backup_locks($job_id);
    self::write_log('Backup marked stale after no progress: ' . $job_id);

    return array_merge($job, $updates, ['updated' => time()]);
  }

  private static function mark_unstarted_backup_job_if_needed(string $job_id, array $job): array {
    if (($job['status'] ?? '') !== 'queued') {
      return $job;
    }

    $created = (int) ($job['created'] ?? $job['updated'] ?? 0);
    if ($created < 1 || (time() - $created) <= self::BACKUP_START_TIMEOUT_SECONDS) {
      return $job;
    }

    $message = __('Backup could not start. The server did not run the background worker. Check Logs, then try again.', 'restorepilot-backup-migration');
    $updates = [
      'status' => 'error',
      'phase' => 'error',
      'phase_label' => self::backup_phase_label('error'),
      'progress' => 100,
      'message' => $message,
    ];
    self::update_backup_job($job_id, $updates);
    self::force_release_backup_locks($job_id);
    self::write_log('Backup worker did not start within ' . self::BACKUP_START_TIMEOUT_SECONDS . ' seconds: ' . $job_id);

    return array_merge($job, $updates, ['updated' => time()]);
  }

  private static function mark_unstarted_restore_job_if_needed(string $job_id, array $job): array {
    if (($job['status'] ?? '') !== 'queued') {
      return $job;
    }

    $created = (int) ($job['created'] ?? $job['updated'] ?? 0);
    if ($created < 1 || (time() - $created) <= self::BACKUP_START_TIMEOUT_SECONDS) {
      return $job;
    }

    $message = __('Restore could not start. The server did not run the background worker. Check Logs, then try again.', 'restorepilot-backup-migration');
    $updates = [
      'status' => 'error',
      'phase' => 'error',
      'phase_label' => self::restore_phase_label('error'),
      'progress' => 100,
      'message' => $message,
    ];
    self::update_restore_job($job_id, $updates);
    self::force_release_restore_locks($job_id);
    self::write_log('Restore worker did not start within ' . self::BACKUP_START_TIMEOUT_SECONDS . ' seconds: ' . $job_id);

    return array_merge($job, $updates, ['updated' => time()]);
  }

  // Backup/restore job records are short-lived status blobs in wp_options that
  // are never cleaned once the job ends, so they accumulate (one per backup
  // ever run). Drop any whose status is terminal or that have not been updated
  // recently. The currently-running job (if any) is always recent and 'running',
  // so it is never touched.
  private static function prune_finished_job_records(): void {
    global $wpdb;
    if (!isset($wpdb) || !method_exists($wpdb, 'get_results')) {
      return;
    }

    // See like_prefix_literal()'s docblock: prepare()'s %s binding cannot be
    // used for a LIKE-wildcard value on this WordPress version, or finished
    // job records accumulate in wp_options forever instead of being pruned.
    $like = self::like_prefix_literal('restorepilot_backup_job_');
    $like2 = self::like_prefix_literal(self::RESTORE_JOB_PREFIX);
    $table = $wpdb->options;
    $rows = $wpdb->get_results("SELECT option_name, option_value FROM `$table` WHERE option_name LIKE $like OR option_name LIKE $like2");
    if (!is_array($rows)) {
      return;
    }

    $now = time();
    foreach ($rows as $row) {
      $job = @maybe_unserialize($row->option_value);
      $remove = false;
      if (!is_array($job)) {
        $remove = true;
      } else {
        $status = (string) ($job['status'] ?? '');
        $updated = (int) ($job['updated'] ?? $job['created'] ?? 0);
        if (in_array($status, ['complete', 'canceled', 'error', 'stale'], true)) {
          $remove = true;
        } elseif ($updated <= 0 || ($now - $updated) > DAY_IN_SECONDS) {
          $remove = true;
        }
      }
      if ($remove) {
        delete_option($row->option_name);
      }
    }
  }

  private static function backup_job_is_stale(array $job): bool {
    // 'canceled' is included alongside the normal in-flight statuses: cancellation
    // is cooperative (the worker only stops at its next throw_if_backup_cancelled()
    // checkpoint), so a canceled job can still have a live worker process for a
    // little while. Staleness is what actually tells us the worker is gone.
    $status = (string) ($job['status'] ?? '');
    if (!in_array($status, ['queued', 'running', 'canceled'], true)) {
      return false;
    }

    $updated = (int) ($job['updated'] ?? $job['created'] ?? 0);
    return $updated > 0 && (time() - $updated) > self::BACKUP_HEARTBEAT_STALE_SECONDS;
  }

  private static function mark_stale_restore_job_if_needed(string $job_id, array $job): array {
    if (!self::restore_job_is_stale($job)) {
      return $job;
    }

    $message = __('Restore stopped responding. It may have been interrupted by the server. Check Logs before trying again.', 'restorepilot-backup-migration');
    $updates = [
      'status' => 'stale',
      'phase' => 'stale',
      'phase_label' => self::restore_phase_label('stale'),
      'progress' => 100,
      'message' => $message,
    ];
    self::update_restore_job($job_id, $updates);
    self::force_release_restore_locks($job_id);
    self::write_log('Restore marked stale after no progress: ' . $job_id);

    return array_merge($job, $updates, ['updated' => time()]);
  }

  private static function restore_job_is_stale(array $job): bool {
    $status = (string) ($job['status'] ?? '');
    if (!in_array($status, ['queued', 'running'], true)) {
      return false;
    }

    $updated = (int) ($job['updated'] ?? $job['created'] ?? 0);
    return $updated > 0 && (time() - $updated) > self::BACKUP_STALE_SECONDS;
  }
}
