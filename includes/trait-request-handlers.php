<?php
/**
 * Entry points for admin-post, AJAX, cron, and WP-CLI requests.
 *
 * @package RestorePilot_Backup_Migration
 */

if (!defined('ABSPATH')) {
  exit;
}

trait RestorePilot_RequestHandlers {
  public static function handle_backup(): void {
    self::enable_error_logging();
    self::verify_admin_request();

    try {
      $selection_enabled = self::post_bool('file_selection_enabled');
      $selected_paths = self::selected_backup_paths_from_request();
      $result = self::create_backup_package(self::post_bool('include_files'), '', $selected_paths, $selection_enabled);
      self::redirect_notice($result['message']);
    } catch (Throwable $e) {
      self::write_log('Backup failed: ' . $e->getMessage());
      self::redirect_error($e->getMessage());
    }
  }

  public static function handle_ajax_backup(): void {
    self::enable_error_logging();

    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => __('Permission denied.', 'restorepilot-backup-migration')], 403);
    }

    check_ajax_referer(self::NONCE);

    if (is_multisite()) {
      wp_send_json_error(['message' => self::multisite_unsupported_message()], 403);
    }

    if (self::backup_lock_is_active()) {
      wp_send_json_error(['message' => __('A backup is already running. Please wait for it to finish.', 'restorepilot-backup-migration')], 409);
    }

    self::prune_finished_job_records();

    $job_id = wp_generate_uuid4();
    $token = wp_generate_password(32, false, false);
    $include_files = self::post_bool('include_files');
    $selection_enabled = self::post_bool('file_selection_enabled');
    $selected_paths = self::selected_backup_paths_from_request();

    self::set_backup_job($job_id, [
      'status' => 'queued',
      'phase' => 'queued',
      'phase_label' => self::backup_phase_label('queued'),
      'progress' => 5,
      'message' => __('Backup queued.', 'restorepilot-backup-migration'),
      'include_files' => $include_files,
      'file_selection_enabled' => $selection_enabled,
      'selected_paths' => $selected_paths,
      'files_scanned' => 0,
      'bytes_scanned' => 0,
      'token' => $token,
      'created' => time(),
      'updated' => time(),
    ]);

    self::write_log('Background backup job queued: ' . $job_id);
    self::dispatch_backup_worker($job_id, $token);

    wp_send_json_success([
      'job_id' => $job_id,
      'message' => __('Backup started in the background.', 'restorepilot-backup-migration'),
    ]);
  }

  public static function handle_backup_status(): void {
    self::enable_error_logging();

    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => __('Permission denied.', 'restorepilot-backup-migration')], 403);
    }

    check_ajax_referer(self::NONCE);

    $job_id = self::post_value('job_id');
    $job = self::get_backup_job($job_id);
    if (!$job) {
      wp_send_json_error(['message' => __('Backup job not found.', 'restorepilot-backup-migration')], 404);
    }

    $job = self::mark_unstarted_backup_job_if_needed($job_id, $job);
    $job = self::mark_stale_backup_job_if_needed($job_id, $job);

    wp_send_json_success([
      'status' => $job['status'] ?? 'unknown',
      'phase' => $job['phase'] ?? '',
      'phase_label' => !empty($job['phase_label']) ? $job['phase_label'] : self::backup_phase_label((string) ($job['phase'] ?? '')),
      'progress' => $job['progress'] ?? 0,
      'message' => $job['message'] ?? '',
      'file' => $job['file'] ?? '',
      'size' => $job['size'] ?? '',
      'files_scanned' => (int) ($job['files_scanned'] ?? 0),
      'bytes_scanned' => (int) ($job['bytes_scanned'] ?? 0),
      'created' => (int) ($job['created'] ?? 0),
      'updated' => $job['updated'] ?? 0,
      'elapsed_seconds' => !empty($job['created']) ? max(0, time() - (int) $job['created']) : 0,
      'server_time' => time(),
    ]);
  }

  public static function handle_read_log(): void {
    self::enable_error_logging();

    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => __('Permission denied.', 'restorepilot-backup-migration')], 403);
    }

    check_ajax_referer(self::NONCE);

    $log = self::read_log_for_display();
    wp_send_json_success([
      'log' => $log !== '' ? $log : __('No log entries yet.', 'restorepilot-backup-migration'),
    ]);
  }

  public static function handle_clear_log(): void {
    self::enable_error_logging();

    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => __('Permission denied.', 'restorepilot-backup-migration')], 403);
    }

    check_ajax_referer(self::NONCE);
    self::clear_log();
    self::write_log('Logs cleared.');

    wp_send_json_success([
      'message' => __('Logs cleared.', 'restorepilot-backup-migration'),
    ]);
  }

  public static function handle_chunk_restore_upload(): void {
    self::enable_error_logging();

    if (!current_user_can('manage_options')) {
      self::write_log('Chunk restore upload rejected: session expired or insufficient permissions.');
      wp_send_json_error(['message' => __('Your session has expired. Please refresh the page and log in again before uploading.', 'restorepilot-backup-migration'), 'session_expired' => true], 403);
    }

    check_ajax_referer(self::NONCE);

    if (is_multisite()) {
      wp_send_json_error(['message' => self::multisite_unsupported_message()], 403);
    }

    self::prepare_for_long_operation();

    $upload_id = sanitize_key(self::post_value('upload_id'));
    $file_name = sanitize_file_name(self::post_value('file_name'));
    $chunk_index = self::post_int('chunk_index', -1);
    $total_chunks = self::post_int('total_chunks', 0);

    if ($upload_id === '' || strlen($upload_id) > 80 || !preg_match('/\.zip$/i', $file_name) || $chunk_index < 0 || $total_chunks < 1 || $total_chunks > self::MAX_RESTORE_UPLOAD_CHUNKS || $chunk_index >= $total_chunks) {
      wp_send_json_error(['message' => __('Invalid restore upload request.', 'restorepilot-backup-migration')], 400);
    }

    $chunk_upload = self::uploaded_file_array('chunk');
    if (!$chunk_upload) {
      wp_send_json_error(['message' => __('Restore upload chunk is missing.', 'restorepilot-backup-migration')], 400);
    }

    $chunk_error = (int) ($chunk_upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($chunk_error !== UPLOAD_ERR_OK) {
      wp_send_json_error(['message' => self::upload_error_message($chunk_error, $file_name)], 400);
    }

    $tmp_name = isset($chunk_upload['tmp_name']) ? sanitize_text_field(wp_unslash($chunk_upload['tmp_name'])) : '';
    if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
      wp_send_json_error(['message' => __('Restore upload chunk is invalid.', 'restorepilot-backup-migration')], 400);
    }
    // Each chunk is a raw PART_SIZE-byte slice produced by this plugin's own
    // client-side chunking; combined with the total_chunks ceiling above,
    // this keeps the maximum possible assembled upload bounded instead of
    // accepting parts of unbounded size indefinitely.
    $chunk_size = (int) ($chunk_upload['size'] ?? 0);
    if ($chunk_size <= 0 || $chunk_size > self::PART_SIZE) {
      wp_send_json_error(['message' => __('Restore upload chunk is an unexpected size.', 'restorepilot-backup-migration')], 400);
    }

    $chunk_dir = null;
    try {
      self::ensure_storage();
      self::cleanup_restore_chunk_uploads();
      $chunk_dir = self::restore_chunk_dir($upload_id);
      if (!wp_mkdir_p($chunk_dir) && !is_dir($chunk_dir)) {
        throw new RuntimeException(__('Could not prepare restore upload storage.', 'restorepilot-backup-migration'));
      }

      $part_path = $chunk_dir . '/part-' . str_pad((string) $chunk_index, 6, '0', STR_PAD_LEFT);
      if (!function_exists('wp_handle_upload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
      }
      // Route the chunk through WordPress' upload handler for its is_uploaded_file
      // verification and safe move. test_type is disabled because each chunk is a
      // raw byte fragment of a zip, not a standalone typed file; the reassembled
      // archive is validated by validate_backup_zip() before any restore runs.
      $handled_chunk = wp_handle_upload($chunk_upload, [
        'test_form' => false,
        'test_type' => false,
      ]);
      if (!is_array($handled_chunk) || isset($handled_chunk['error']) || empty($handled_chunk['file'])) {
        throw new RuntimeException(__('Could not save restore upload chunk.', 'restorepilot-backup-migration'));
      }
      if (!@rename($handled_chunk['file'], $part_path)) {
        @unlink($handled_chunk['file']);
        throw new RuntimeException(__('Could not save restore upload chunk.', 'restorepilot-backup-migration'));
      }

      self::write_file($chunk_dir . '/meta.json', (string) wp_json_encode([
        'file_name' => $file_name,
        'total_chunks' => $total_chunks,
        'updated' => time(),
      ]), 'restore chunk metadata');

      if ($chunk_index + 1 < $total_chunks) {
        wp_send_json_success([
          'complete' => false,
          'uploaded_chunks' => $chunk_index + 1,
          'total_chunks' => $total_chunks,
        ]);
      }

      $restore_path = self::assemble_restore_chunks($upload_id, $file_name, $total_chunks);
      self::delete_directory($chunk_dir, self::storage_dir());
      self::write_log('Chunked restore upload assembled: ' . basename($restore_path));
      wp_send_json_success([
        'complete' => true,
        'path' => $restore_path,
      ]);
    } catch (Throwable $e) {
      // The client always starts a fresh upload_id for every restore attempt
      // (see admin.js) — nothing ever resumes a failed one — so chunks left
      // behind here serve no future purpose, only occupy disk space for up
      // to the 6-hour sweep in cleanup_restore_chunk_uploads(). Free it
      // immediately instead: this is exactly what let one failed assembly of
      // a 16GB restore silently strand 16GB of already-uploaded chunks.
      if ($chunk_dir !== null && is_dir($chunk_dir)) {
        self::delete_directory($chunk_dir, self::storage_dir());
      }
      self::write_log('Chunked restore upload failed: ' . $e->getMessage());
      wp_send_json_error(['message' => $e->getMessage()], 500);
    }
  }

  public static function handle_cancel_backup(): void {
    self::enable_error_logging();

    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => __('Permission denied.', 'restorepilot-backup-migration')], 403);
    }

    check_ajax_referer(self::NONCE);

    $job_id = self::post_value('job_id');
    $job = self::get_backup_job($job_id);
    if (!$job) {
      wp_send_json_error(['message' => __('Backup job not found.', 'restorepilot-backup-migration')], 404);
    }

    if (($job['status'] ?? '') === 'complete') {
      wp_send_json_error(['message' => __('This backup already completed.', 'restorepilot-backup-migration')], 409);
    }

    $message = __('Backup cancel requested. RestorePilot will clean incomplete backup files as soon as the running backup process stops.', 'restorepilot-backup-migration');
    self::update_backup_job($job_id, [
      'status' => 'canceled',
      'phase' => 'canceled',
      'phase_label' => self::backup_phase_label('canceled'),
      'progress' => 100,
      'message' => $message,
      'canceled' => time(),
    ]);

    // Do NOT force-release the backup locks here. The worker (running in a
    // separate loopback/cron request) only stops at its next
    // throw_if_backup_cancelled() checkpoint and releases both locks itself in
    // its own finally block at that point — it may still be mid-export right
    // now. Releasing the locks immediately would let a second backup start and
    // run concurrently with the still-executing canceled worker. If the worker
    // never checks in (crashed, killed), backup_lock_can_be_released() reclaims
    // the lock automatically once the job is stale (no progress for
    // BACKUP_HEARTBEAT_STALE_SECONDS), the same as any other stuck job.
    self::write_log('Backup cancel requested: ' . $job_id);
    wp_send_json_success(['message' => $message]);
  }

  public static function handle_run_backup_job_admin(): void {
    self::enable_error_logging();

    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => __('Permission denied.', 'restorepilot-backup-migration')], 403);
    }

    check_ajax_referer(self::NONCE);

    $job_id = self::post_value('job_id');
    $job = self::get_backup_job($job_id);
    if (!$job || empty($job['token'])) {
      wp_send_json_error(['message' => __('Backup job not found.', 'restorepilot-backup-migration')], 404);
    }

    self::write_log('Authenticated backup runner requested: ' . $job_id);
    self::run_backup_job($job_id, (string) $job['token']);
    wp_send_json_success(['message' => __('Backup runner finished.', 'restorepilot-backup-migration')]);
  }

  public static function handle_run_backup_job(): void {
    self::enable_error_logging();

    $job_id = self::post_value('job_id');
    $token = self::post_value('token');
    self::run_backup_job($job_id, $token);
    wp_die();
  }

  // nopriv loopback handler — same token-auth pattern as handle_run_backup_job.
  public static function handle_run_restore_job(): void {
    self::enable_error_logging();

    $job_id = self::post_value('job_id');
    $token = self::post_value('token');
    self::run_restore_job($job_id, $token);
    wp_die();
  }

  public static function handle_restore(): void {
    self::enable_error_logging();
    self::verify_admin_request();

    if (is_multisite()) {
      self::redirect_error(self::multisite_unsupported_message(), 'restore');
    }

    if (!self::post_bool('confirm_restore')) {
      self::redirect_error(__('Restore confirmation is required.', 'restorepilot-backup-migration'));
    }

    if (!class_exists('ZipArchive')) {
      self::redirect_error(__('ZipArchive is not available on this server.', 'restorepilot-backup-migration'));
    }

    self::ensure_storage();
    $auto_detect_urls = self::post_bool('auto_detect_urls');
    $restore_files = self::post_bool('restore_files');
    $restore_zip_path = '';

    try {
      $restore_zip_path = self::prepare_restore_upload();
      $result = self::perform_restore($restore_zip_path, $auto_detect_urls, $restore_files, '', '', '', self::post_bool('create_new_admin'));
      $notice_message = $result['message'];
      // The synchronous path is the fallback for when JS is unavailable, so
      // there is no page here to apply a chosen password afterwards. The
      // account's password is therefore the throwaway one nobody knows, and
      // the way in is a reset sent to the address on it. The password is
      // deliberately not put in this notice: notices travel as redirect
      // parameters, which would place it in a URL, in history, and in any
      // access log along the way.
      if (!empty($result['new_admin_email'])) {
        $notice_message .= ' ' . sprintf(
          /* translators: %s: email address of the newly created admin account */
          __('An administrator account was created for %s. Use "Lost your password?" on the login page to set its password.', 'restorepilot-backup-migration'),
          $result['new_admin_email']
        );
      }
      self::redirect_notice($notice_message, 'restore');
    } catch (Throwable $e) {
      if ($restore_zip_path !== '' && strpos($restore_zip_path, self::storage_dir() . '/restore-upload-') === 0) {
        @unlink($restore_zip_path);
      }
      self::write_log('Restore failed: ' . $e->getMessage());
      self::redirect_error($e->getMessage(), 'restore');
    }
  }

  public static function handle_restore_check(): void {
    self::enable_error_logging();
    self::verify_admin_request();

    if (!class_exists('ZipArchive')) {
      self::redirect_error(__('ZipArchive is not available on this server.', 'restorepilot-backup-migration'), 'restore');
    }

    self::ensure_storage();
    $restore_zip_path = '';

    try {
      $restore_zip_path = self::prepare_restore_upload();
      $message = self::backup_check_message($restore_zip_path, true);
      self::redirect_notice($message, 'restore');
    } catch (Throwable $e) {
      self::write_log('Restore backup check failed: ' . $e->getMessage());
      /* translators: %s: error message */
      self::redirect_error(sprintf(__('Backup check failed: %s', 'restorepilot-backup-migration'), $e->getMessage()), 'restore');
    } finally {
      if ($restore_zip_path !== '' && strpos($restore_zip_path, self::storage_dir() . '/restore-upload-') === 0) {
        @unlink($restore_zip_path);
      }
    }
  }

  public static function handle_ajax_restore(): void {
    self::enable_error_logging();

    if (!current_user_can('manage_options')) {
      self::write_log('Restore start rejected: session expired or insufficient permissions.');
      wp_send_json_error(['message' => __('Your session has expired. Please refresh the page and log in again before starting a restore.', 'restorepilot-backup-migration'), 'session_expired' => true], 403);
    }

    check_ajax_referer(self::NONCE);

    if (is_multisite()) {
      wp_send_json_error(['message' => self::multisite_unsupported_message()], 403);
    }

    if (!self::post_bool('confirm_restore')) {
      wp_send_json_error(['message' => __('Restore confirmation is required.', 'restorepilot-backup-migration')], 400);
    }

    if (!class_exists('ZipArchive')) {
      wp_send_json_error(['message' => __('ZipArchive is not available on this server.', 'restorepilot-backup-migration')], 500);
    }

    self::ensure_storage();
    $restore_zip_path = '';

    try {
      $restore_zip_path = self::prepare_restore_upload();
      $job_id = wp_generate_uuid4();
      $token = wp_generate_password(32, false, false);
      // poll_token is safe to expose to the browser — it only grants read access
      // to job status and is validated server-side on every status request.
      $poll_token = wp_generate_password(32, false, false);

      $set_ok = self::set_restore_job($job_id, [
        'status' => 'queued',
        'phase' => 'queued',
        'phase_label' => self::restore_phase_label('queued'),
        'progress' => 5,
        'message' => __('Restore queued.', 'restorepilot-backup-migration'),
        'restore_zip_path' => $restore_zip_path,
        'auto_detect_urls' => self::post_bool('auto_detect_urls'),
        'restore_files' => self::post_bool('restore_files'),
        'create_new_admin' => self::post_bool('create_new_admin'),
        // Safe in a job record that is mirrored to disk: an address is not
        // a secret. The chosen password is deliberately not here -- see
        // handle_set_restore_admin_password().
        'new_admin_email' => sanitize_email(self::post_value('new_admin_email')),
        'source_url' => self::post_value('source_url'),
        'target_url' => self::post_value('target_url', home_url()),
        'token' => $token,
        'poll_token' => $poll_token,
        'created' => time(),
        'updated' => time(),
      ]);
      // RP-038. These two files are the restore's only durable state once the
      // database swap replaces wp_options with the backup's own: the mirror
      // carries the checkpoint and the worker token, the poll-token file
      // authenticates every later status request and the post-restore password
      // step. Both writes used to be silent, so a restore could begin with
      // neither in place and only discover it after the swap, with no
      // checkpoint to resume from and no way to authenticate a poll.
      //
      // Refusing here costs an error message. Refusing later costs an outage,
      // so this is checked while nothing has been touched yet.
      $mirrored = $set_ok && self::write_poll_token_file($job_id, $poll_token);
      if (!$mirrored) {
        throw new RuntimeException(__('Could not write the restore status files that let a restore be resumed and monitored. Check that the backup storage directory is writable and has free space, then try again.', 'restorepilot-backup-migration'));
      }

      self::write_log('Background restore job queued: ' . $job_id);
      self::dispatch_restore_worker($job_id, $token);

      wp_send_json_success([
        'job_id'     => $job_id,
        'poll_token' => $poll_token,
        'message'    => __('Restore started in the background.', 'restorepilot-backup-migration'),
      ]);
    } catch (Throwable $e) {
      if ($restore_zip_path !== '' && strpos($restore_zip_path, self::storage_dir() . '/restore-upload-') === 0) {
        @unlink($restore_zip_path);
      }
      self::write_log('Background restore could not be queued: ' . $e->getMessage());
      wp_send_json_error(['message' => $e->getMessage()], 500);
    }
  }

  public static function handle_restore_status(): void {
    self::enable_error_logging();

    $job_id    = self::post_value('job_id');
    $poll_token = self::post_value('poll_token');

    // Accept either a valid admin session (nonce + capability) or a poll_token
    // issued at job-queue time. The poll_token path is used during maintenance mode
    // and after a DB restore when the admin session cookie is no longer valid.
    $token_auth = false;
    if ($poll_token !== '') {
      // Try DB first; fall back to file which survives a DB restore.
      $job_check    = self::get_restore_job($job_id);
      $stored_token = (!empty($job_check['poll_token'])) ? $job_check['poll_token'] : self::read_poll_token_file($job_id);
      if ($stored_token !== '' && hash_equals($stored_token, $poll_token)) {
        $token_auth = true;
      }
    }

    if (!$token_auth) {
      if (!current_user_can('manage_options')) {
        self::write_log('Restore status poll rejected: no valid poll token and no admin session (job ' . ($job_id !== '' ? $job_id : '(none)') . '). The session was likely invalidated by the database restore.');
        wp_send_json_error([
          'message' => __('Your session has expired. The page will refresh to show the restore result.', 'restorepilot-backup-migration'),
          'session_expired' => true,
        ], 403);
      }
      check_ajax_referer(self::NONCE);
    }

    $job = self::get_restore_job($job_id);
    if (!$job) {
      wp_send_json_error(['message' => __('Restore job not found.', 'restorepilot-backup-migration')], 404);
    }

    $job = self::mark_unstarted_restore_job_if_needed($job_id, $job);
    $job = self::mark_stale_restore_job_if_needed($job_id, $job);

    $response = [
      'status' => $job['status'] ?? 'unknown',
      'phase' => $job['phase'] ?? '',
      'phase_label' => !empty($job['phase_label']) ? $job['phase_label'] : self::restore_phase_label((string) ($job['phase'] ?? '')),
      'progress' => $job['progress'] ?? 0,
      'message' => $job['message'] ?? '',
      'created' => (int) ($job['created'] ?? 0),
      'updated' => $job['updated'] ?? 0,
      'elapsed_seconds' => !empty($job['created']) ? max(0, time() - (int) $job['created']) : 0,
      'server_time' => time(),
    ];

    // Tells the page the account is waiting for the password it is holding.
    // Only the address travels here: it is not a secret, and the page needs it
    // to say which login was just set up. No password is ever sent to the
    // browser — there is no longer one worth sending, since the account's
    // interim password is a throwaway nobody knows.
    if (!empty($job['new_admin_user_id'])) {
      $response['new_admin_awaiting_password'] = true;
      $response['new_admin_email'] = (string) ($job['new_admin_email_final'] ?? '');
    }

    wp_send_json_success($response);
  }

  /**
   * Applies an operator-chosen password to the account the restore created.
   *
   * This exists so that password never has to be stored. The restore job is
   * mirrored to a file under uploads (it has to be — the database swap wipes
   * the option it would otherwise live in), so anything carried through the
   * job sits in plaintext on disk for the whole restore. The username and
   * email go that route because neither is a secret; the password comes here
   * instead, straight from the page, once.
   *
   * Authorised by the same poll_token the status endpoint uses, because this
   * runs in the same window: the database swap has already invalidated the
   * admin session, so a capability check alone would reject the very request
   * that finishes the job. The token is no wider a privilege than it looks —
   * the restore it belongs to was started by an administrator who asked for
   * this account, and the account has already been created by the time this
   * can run. What is added here is which password it ends up with.
   *
   * Deliberately single-use and tightly scoped: the job must have asked for a
   * new admin, must have finished, must still name the account, and the
   * pointer is consumed before the password is applied, so a replayed request
   * cannot reset the account a second time.
   */
  public static function handle_set_restore_admin_password(): void {
    self::enable_error_logging();

    $job_id     = self::post_value('job_id');
    $poll_token = self::post_value('poll_token');

    $job = self::get_restore_job($job_id);
    if (!$job) {
      wp_send_json_error(['message' => __('Restore job not found.', 'restorepilot-backup-migration')], 404);
    }

    $stored_token = !empty($job['poll_token']) ? (string) $job['poll_token'] : self::read_poll_token_file($job_id);
    $token_auth = $poll_token !== '' && $stored_token !== '' && hash_equals($stored_token, $poll_token);

    if (!$token_auth) {
      if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied.', 'restorepilot-backup-migration')], 403);
      }
      check_ajax_referer(self::NONCE);
    }

    if (($job['status'] ?? '') !== 'complete') {
      wp_send_json_error(['message' => __('The restore has not finished yet.', 'restorepilot-backup-migration')], 409);
    }

    $user_id = (int) ($job['new_admin_user_id'] ?? 0);
    if ($user_id < 1) {
      wp_send_json_error(['message' => __('This restore did not create an admin account to set a password on.', 'restorepilot-backup-migration')], 409);
    }

    $user = get_user_by('id', $user_id);
    if (!$user) {
      wp_send_json_error(['message' => __('The account this restore created could no longer be found.', 'restorepilot-backup-migration')], 410);
    }

    // Read raw: a password is not text to be sanitized, and running it
    // through a sanitizer would silently change what the operator typed.
    // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- authorized above via poll_token or nonce+capability; a password must not be sanitized, only unslashed, or the sanitizer silently changes what was typed. Length is validated immediately below and the value is passed straight to wp_set_password().
    $password = isset($_POST['new_password']) ? (string) wp_unslash($_POST['new_password']) : '';
    if ($password === '' || strlen($password) < 8) {
      wp_send_json_error(['message' => __('Choose a password of at least 8 characters.', 'restorepilot-backup-migration')], 400);
    }

    // Consumed here, and only here: every check above has passed and the
    // password is about to be applied, so a second request finds nothing to
    // act on. Doing this any earlier spends the pointer on a request that
    // then fails validation — one mistyped password would permanently take
    // away the ability to set it, leaving a password reset as the only route
    // into an account the operator had just been told they were creating.
    self::update_restore_job($job_id, ['new_admin_user_id' => 0]);

    wp_set_password($password, $user_id);
    self::write_log('Applied the chosen password to the restore admin account (username only, never the password): ' . $user->user_login);

    wp_send_json_success([
      'email'   => $user->user_email,
      'message' => __('Your admin password has been set.', 'restorepilot-backup-migration'),
    ]);
  }

  public static function handle_run_restore_job_admin(): void {
    self::enable_error_logging();

    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => __('Permission denied.', 'restorepilot-backup-migration')], 403);
    }

    check_ajax_referer(self::NONCE);

    $job_id = self::post_value('job_id');
    $job = self::get_restore_job($job_id);
    if (!$job || empty($job['token'])) {
      wp_send_json_error(['message' => __('Restore job not found.', 'restorepilot-backup-migration')], 404);
    }

    self::write_log('Authenticated restore runner requested: ' . $job_id);
    self::run_restore_job($job_id, (string) $job['token']);
    wp_send_json_success(['message' => __('Restore runner finished.', 'restorepilot-backup-migration')]);
  }

  public static function handle_health_check(): void {
    self::enable_error_logging();
    self::verify_admin_request();
    $file = self::safe_backup_file_from_request();

    try {
      if (!is_file($file) || !is_readable($file)) {
        throw new RuntimeException(__('Backup file not found.', 'restorepilot-backup-migration'));
      }

      if (!class_exists('ZipArchive')) {
        throw new RuntimeException(__('ZipArchive is not available on this server.', 'restorepilot-backup-migration'));
      }

      $message = self::backup_check_message($file, false);
      self::write_log('Health check passed: ' . basename($file));
      self::redirect_notice($message, 'backup');
    } catch (Throwable $e) {
      if (isset($zip) && $zip instanceof ZipArchive) {
        $zip->close();
      }
      self::write_log('Health check failed for ' . basename($file) . ': ' . $e->getMessage());
      /* translators: %s: error message */
      self::redirect_error(sprintf(__('Backup health check failed: %s', 'restorepilot-backup-migration'), $e->getMessage()), 'backup');
    }
  }

  public static function handle_save_settings(): void {
    self::enable_error_logging();
    self::verify_admin_request();

    $settings = [
      // Daily scheduling can never be enabled on multisite, regardless of what
      // was submitted — the Settings UI already hides this control there, but
      // the option itself is force-disabled too as a second line of defense.
      'scheduled_enabled' => !is_multisite() && self::post_bool('scheduled_enabled'),
      'scheduled_hour'    => max(0, min(23, self::post_int('scheduled_hour', 2))),
      'scheduled_minute'  => max(0, min(59, self::post_int('scheduled_minute', 0))),
      'email_notifications' => self::post_bool('email_notifications'),
      'notify_email' => sanitize_email(self::post_value('notify_email', (string) get_option('admin_email'))),
      'retention_count' => self::MAX_BACKUPS,
    ];
    $redirect_tab = sanitize_key(self::post_value('redirect_tab', 'daily'));
    if (!in_array($redirect_tab, ['daily', 'settings'], true)) {
      $redirect_tab = 'daily';
    }

    if ($settings['notify_email'] === '' || !is_email($settings['notify_email'])) {
      $settings['notify_email'] = (string) get_option('admin_email');
    }

    update_option(self::SETTINGS_OPTION, $settings, false);
    self::sync_scheduled_backup();
    self::enforce_backup_retention();
    self::write_log('Settings saved.');
    self::redirect_notice(__('Settings saved.', 'restorepilot-backup-migration'), $redirect_tab);
  }

  public static function handle_cleanup_temp(): void {
    self::enable_error_logging();
    self::verify_admin_request();

    try {
      $result = self::cleanup_stale_temp_files();
      self::write_log(sprintf(
        'Maintenance cleanup removed %d stale temporary item(s), freeing %s.',
        (int) $result['count'],
        size_format((int) $result['bytes'])
      ));
      self::redirect_notice(sprintf(
        /* translators: 1: number of stale temporary items removed, 2: amount of disk space freed */
        __('Cleaned %1$d stale temporary item(s), freeing %2$s. Completed backups were not deleted.', 'restorepilot-backup-migration'),
        (int) $result['count'],
        size_format((int) $result['bytes'])
      ), 'settings');
    } catch (Throwable $e) {
      self::write_log('Maintenance cleanup failed: ' . $e->getMessage());
      /* translators: %s: error message */
      self::redirect_error(sprintf(__('Maintenance cleanup failed: %s', 'restorepilot-backup-migration'), $e->getMessage()), 'settings');
    }
  }

  public static function handle_reset_runtime(): void {
    self::enable_error_logging();
    self::verify_admin_request();

    delete_option(self::BACKUP_LOCK_OPTION);
    delete_option(self::RESTORE_LOCK_OPTION);
    global $wpdb;
    if (isset($wpdb) && method_exists($wpdb, 'prepare')) {
      // Per-job worker locks (released every chunk in the normal case) are
      // included here too — a user reaching for this button believes
      // something is stuck, and a worker lock left behind by whatever went
      // wrong would otherwise silently block every future resumption's
      // acquire_*_worker_lock() call even after the locks above are cleared.
      // See like_prefix_literal()'s docblock: prepare()'s %s binding cannot
      // be used for a LIKE-wildcard value on this WordPress version, or this
      // "I believe something is stuck" recovery button silently fails to
      // clear the very locks it claims to.
      $table = $wpdb->options;
      foreach ([self::BACKUP_WORKER_LOCK_PREFIX, self::RESTORE_WORKER_LOCK_PREFIX] as $prefix) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $table is $wpdb->options; $prefix is one of this plugin's own *_LOCK_PREFIX constants, quoted and escaped by like_prefix_literal().
        $wpdb->query("DELETE FROM `$table` WHERE option_name LIKE " . self::like_prefix_literal($prefix));
      }
    }
    self::disable_maintenance_mode();
    self::write_log('Runtime locks reset manually from Settings.');
    self::redirect_notice(__('Stuck RestorePilot runtime locks were reset and maintenance mode was removed. Start a new backup or restore only after confirming nothing is currently running.', 'restorepilot-backup-migration'), 'settings');
  }

  public static function handle_master_reset(): void {
    self::enable_error_logging();

    check_ajax_referer(self::NONCE);
    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => __('Permission denied.', 'restorepilot-backup-migration')], 403);
    }

    // Master Reset deletes plugin and theme directories, which are shared by
    // every site on a multisite network — a single site administrator must never
    // be able to remove resources other sites depend on. The feature is designed
    // for a single-site "back to a fresh install" reset, so it is unavailable on
    // multisite rather than partially applied.
    if (is_multisite()) {
      wp_send_json_error([
        'message' => __('Master Reset is not available on WordPress multisite networks, because plugins and themes are shared across all sites.', 'restorepilot-backup-migration'),
      ], 403);
    }

    // Master Reset deletes directly from the physical tables behind
    // $wpdb->users/$wpdb->usermeta. WordPress's CUSTOM_USER_TABLE and
    // CUSTOM_USER_META_TABLE constants let independent installs point those
    // properties at tables shared outside this site's own prefix — deleting
    // from them here could destroy accounts and metadata belonging to a
    // different installation. Refuse rather than risk cross-install data loss.
    if (self::uses_custom_user_tables()) {
      wp_send_json_error([
        'message' => __('Master Reset is not available because this site is configured with a custom shared user table (CUSTOM_USER_TABLE/CUSTOM_USER_META_TABLE). Resetting would risk deleting user accounts belonging to other installations that share that table.', 'restorepilot-backup-migration'),
      ], 403);
    }

    // switch_theme() below calls validate_theme_requirements() internally and
    // wp_die()s on an incompatible theme — a hard stop mid-AJAX-request, with
    // no chance to report $reset_problems, if it were reached after step 1
    // had already started deleting data. Picking and validating the theme
    // here, before any destructive step, means switch_theme()'s own internal
    // check can never fail: it re-validates the exact same theme this
    // already confirmed is installed and compatible.
    $reset_theme = self::pick_master_reset_theme();
    if ($reset_theme === '') {
      wp_send_json_error([
        'message' => __('Master Reset is not available because no compatible default WordPress theme is installed. Install a default theme (e.g. Twenty Twenty-Five), then try again.', 'restorepilot-backup-migration'),
      ], 403);
    }

    $confirm = isset($_POST['confirm_word']) ? sanitize_text_field(wp_unslash($_POST['confirm_word'])) : '';
    if ($confirm !== 'RESET') {
      wp_send_json_error(['message' => __('Confirmation word did not match. Type RESET in uppercase.', 'restorepilot-backup-migration')]);
    }

    $wpdb           = self::wpdb();
    $current_user_id = get_current_user_id();

    self::write_log('Master Reset started by user ID ' . $current_user_id . '.');

    // Every destructive step below records into this array instead of trusting
    // that the operation succeeded, so a partial failure is reported as an
    // error rather than a false success. This is checked at the end alongside
    // the post-reset usability invariants that already existed.
    $reset_problems = [];
    $failed_storage = [];
    $dropped_foreign = 0;
    // Explicitly asked for, and off unless it was: these backups are the only
    // route back from this action.
    $purge_backups = self::post_bool('purge_backups');
    $purge_mu = self::post_bool('purge_mu_plugins');
    $removed_mu = 0;

    // 1a. Drop tables other plugins created.
    //
    // Their files are removed further down, so leaving these behind produces a
    // site that is not the "clean WordPress installation" this action promises:
    // the data is unreadable with the plugin gone, yet still takes up space and
    // is still copied into every backup afterwards. Dropped rather than
    // emptied, because an empty table nothing will ever create again is still
    // not a clean install.
    foreach (self::foreign_plugin_tables() as $foreign_table) {
      $wpdb->last_error = '';
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL; the identifier is bound with %i and the table was matched against this site's own prefix.
      $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $foreign_table));
      if ($wpdb->last_error !== '') {
        $reset_problems[] = 'could not drop table ' . $foreign_table . ': ' . $wpdb->last_error;
      } else {
        $dropped_foreign++;
      }
    }

    // 1. Truncate all content tables
    foreach (['posts', 'postmeta', 'terms', 'termmeta', 'term_taxonomy', 'term_relationships', 'comments', 'commentmeta', 'links'] as $t) {
      if (!empty($wpdb->$t) && is_string($wpdb->$t)) {
        $wpdb->last_error = '';
        $wpdb->query($wpdb->prepare('TRUNCATE TABLE %i', $wpdb->$t));
        if ($wpdb->last_error !== '') {
          $reset_problems[] = 'could not clear table ' . $wpdb->$t . ': ' . $wpdb->last_error;
        }
      }
    }

    // 2. Delete all users except the current admin; restore full admin capabilities
    if ($current_user_id > 0) {
      $wpdb->last_error = '';
      $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE ID != %d', $wpdb->users, $current_user_id));
      if ($wpdb->last_error !== '') {
        $reset_problems[] = 'could not remove other user accounts: ' . $wpdb->last_error;
      }
      $wpdb->last_error = '';
      $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE user_id != %d', $wpdb->usermeta, $current_user_id));
      if ($wpdb->last_error !== '') {
        $reset_problems[] = 'could not remove other users\' metadata: ' . $wpdb->last_error;
      }
      // update_user_meta() returns false both on failure AND when the value
      // already matches what is in the database — the common case here,
      // since Master Reset is normally run by an admin who already has
      // these values. A false return is therefore not a reliable failure
      // signal; read the resulting state back instead.
      update_user_meta($current_user_id, $wpdb->get_blog_prefix() . 'capabilities', ['administrator' => true]);
      update_user_meta($current_user_id, $wpdb->get_blog_prefix() . 'user_level', 10);
      $capabilities_after = get_user_meta($current_user_id, $wpdb->get_blog_prefix() . 'capabilities', true);
      if (!is_array($capabilities_after) || empty($capabilities_after['administrator'])) {
        $reset_problems[] = 'could not restore administrator capabilities for the current user';
      }
      if ((int) get_user_meta($current_user_id, $wpdb->get_blog_prefix() . 'user_level', true) !== 10) {
        $reset_problems[] = 'could not restore user level for the current user';
      }

      $remaining_users = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $wpdb->users));
      if ($remaining_users !== 1) {
        $reset_problems[] = 'expected exactly 1 user account after reset, found ' . $remaining_users;
      }
    }

    // Resolved BEFORE step 3, which deletes every option that is not on its
    // keep-list -- and restorepilot_storage_path is one of the casualties. Once
    // it is gone nothing knows where storage was moved to, so the purge in step
    // 4 found only the uploads directory and left every migrated backup on
    // disk, while the reset reported them deleted. That is the same ordering
    // mistake as the one fixed in uninstall.php, in the other handler, and it
    // shipped in 0.5.8 because the fix was only ever tested by calling the
    // purge directly rather than by running Master Reset.
    $storage_targets = $purge_backups ? self::plugin_owned_storage_dirs() : [];

    // 3. Reset wp_options — wipe everything except core WordPress identity/keys.
    // 'cron' is deliberately NOT kept: every other plugin is being deleted in
    // step 5 below, so any of their scheduled events still in 'cron' would be
    // orphaned callbacks pointing at code that no longer exists. Wiping it
    // matches the "back to a fresh install" semantics of the rest of this
    // reset; WordPress repopulates the option automatically as new events are
    // scheduled (starting with this plugin's own, if daily backups are
    // re-enabled afterward).
    $keep_options = [
      'siteurl', 'home', 'blogname', 'blogdescription', 'admin_email', 'blogpublic',
      'gmt_offset', 'timezone_string', 'date_format', 'time_format', 'start_of_week',
      'blog_charset', 'upload_path', 'upload_url_path', 'uploads_use_yearmonth_folders',
      'db_version', 'wp_user_roles',
      'auth_key', 'secure_auth_key', 'logged_in_key', 'nonce_key',
      'auth_salt', 'secure_auth_salt', 'logged_in_salt', 'nonce_salt',
    ];
    // Build one %s placeholder per keep-list entry and bind the values through
    // prepare() rather than interpolating an escaped string. The options table
    // name is bound too, via the %i identifier placeholder.
    $keep_placeholders = implode(', ', array_fill(0, count($keep_options), '%s'));
    $wpdb->last_error = '';
    // $keep_placeholders is a generated list of %s placeholders; the table name
    // and every value are bound. Spans lines, so disable/enable.
    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query($wpdb->prepare(
      "DELETE FROM %i WHERE option_name NOT IN ({$keep_placeholders})",
      array_merge([$wpdb->options], $keep_options)
    ));
    // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    if ($wpdb->last_error !== '') {
      $reset_problems[] = 'could not clear site options: ' . $wpdb->last_error;
    }
    // Flush the ENTIRE object cache IMMEDIATELY after the raw bulk DELETE above,
    // before any options-API call. The DELETE bypasses WordPress's cache, so
    // "alloptions" and every individual option cache still hold pre-delete
    // values. Without flushing first, the very next update_option() call reads
    // a stale cached value, believes the row still exists, and performs an
    // UPDATE against a row that no longer exists, which affects zero rows and
    // silently no-ops — the option is never actually written. (This previously
    // broke the *following* switch_theme() call for the same reason; flushing
    // here, before every subsequent options-API call, covers both.)
    wp_cache_flush();
    // Restore essential WordPress defaults
    update_option('active_plugins', [self::plugin_basename_self()]);
    // Re-activate the default theme through the proper WordPress API: switch_theme()
    // validates the theme on disk, rebuilds the theme-roots cache, and writes the
    // template/stylesheet/current_theme options correctly.
    switch_theme($reset_theme);
    update_option('permalink_structure', '/%postname%/');
    update_option('posts_per_page', 10);
    update_option('default_comment_status', 'open');
    update_option('default_ping_status', 'open');
    delete_option('show_on_front');
    delete_option('page_on_front');
    delete_option('page_for_posts');

    // 4. Wipe all uploads (keep the uploads root directory itself)
    $upload = wp_upload_dir(null, false);
    if (empty($upload['error']) && !empty($upload['basedir'])) {
      if ($purge_mu) {
        $mu_result = self::master_reset_wipe_mu_plugins();
        $removed_mu = (int) $mu_result['removed'];
        if (!empty($mu_result['failed'])) {
          // Named, not counted: the operator needs to know which ones are still
          // loading on every request, and a bare number does not tell them.
          $reset_problems[] = 'could not remove must-use plugin(s): ' . implode(', ', $mu_result['failed']);
        }
      }

      if (!self::master_reset_wipe_dir($upload['basedir'], self::content_dir(), $purge_backups)) {
        $reset_problems[] = 'one or more files in the uploads directory could not be removed';
      }

    } else {
      $reset_problems[] = 'could not determine the uploads directory, so it was not cleared';
    }

    // RP-036. The uploads wipe above is not a proxy for "delete the stored
    // backups": once storage has been migrated out of the web root it is not a
    // descendant of the uploads directory at all, so the operator's choice
    // silently did nothing while the log said backups had been deleted. Purge
    // the plugin's own storage locations explicitly, and name any that survive
    // rather than reporting a success that did not happen.
    //
    // Outside the uploads branch on purpose: the private store does not depend
    // on the uploads directory being resolvable, and a site where it is not is
    // exactly where quietly skipping this would be worst.
    if ($purge_backups) {
      $failed_storage = self::purge_plugin_storage($storage_targets);
      foreach ($failed_storage as $failed_dir) {
        $reset_problems[] = 'stored backups could not be deleted from ' . $failed_dir;
      }
    }

    // 5. Delete all plugins except RestorePilot
    $own_dir = realpath(self::plugin_root_dir());
    $failed_plugins = [];
    if (is_dir(self::plugins_dir()) && $own_dir !== false) {
      foreach (new DirectoryIterator(self::plugins_dir()) as $item) {
        if ($item->isDot()) { continue; }
        if ($item->isDir()) {
          $real = realpath($item->getPathname());
          if ($real !== false && $real !== $own_dir) {
            if (!self::delete_directory($item->getPathname(), self::plugins_dir())) {
              $failed_plugins[] = $item->getFilename();
            }
          }
        } elseif ($item->isFile() && $item->getExtension() === 'php') {
          if (!@unlink($item->getPathname()) && file_exists($item->getPathname())) { // single-file plugin
            $failed_plugins[] = $item->getFilename();
          }
        }
      }
    } else {
      $reset_problems[] = 'could not locate the plugins directory, so other plugins were not removed';
    }
    if ($failed_plugins) {
      $reset_problems[] = 'could not remove plugin(s): ' . implode(', ', $failed_plugins);
    }

    // 6. Delete all themes except the one just activated
    $theme_root = get_theme_root();
    $failed_themes = [];
    if (is_dir($theme_root)) {
      foreach (new DirectoryIterator($theme_root) as $item) {
        if ($item->isDot() || !$item->isDir()) { continue; }
        if ($item->getFilename() === $reset_theme) { continue; }
        if (!self::delete_directory($item->getPathname(), $theme_root)) {
          $failed_themes[] = $item->getFilename();
        }
      }
    } else {
      $reset_problems[] = 'could not locate the themes directory, so other themes were not removed';
    }
    if ($failed_themes) {
      $reset_problems[] = 'could not remove theme(s): ' . implode(', ', $failed_themes);
    }

    // 7. Flush all object-cache and opcode cache
    wp_cache_flush();
    if (function_exists('opcache_reset')) { opcache_reset(); }

    // 8. Verify the invariants the site needs in order to remain usable, reading
    // them back from the database rather than trusting that the writes above
    // succeeded. A raw bulk DELETE followed by the options API has previously
    // been able to fail silently (update_option() returns false and writes
    // nothing when it compares against a stale cached value), which would leave
    // the site with no active plugins or no resolvable theme while this endpoint
    // still reported success. Report a failure instead of a false success so the
    // administrator knows the site needs attention.
    $active_after = get_option('active_plugins');
    if (!is_array($active_after) || !in_array(self::plugin_basename_self(), $active_after, true)) {
      $reset_problems[] = 'active_plugins was not written';
    }

    $template_after = (string) get_option('template');
    if ($template_after === '' || !is_dir(get_theme_root() . '/' . $template_after)) {
      $reset_problems[] = 'no usable active theme (template: ' . ($template_after !== '' ? $template_after : 'empty') . ')';
    }

    if (get_option('siteurl') === false || get_option('home') === false) {
      $reset_problems[] = 'siteurl/home missing';
    }

    if ($reset_problems) {
      $detail = implode('; ', $reset_problems);
      self::write_log('Master Reset finished with problems: ' . $detail);
      wp_send_json_error([
        'message' => sprintf(
          /* translators: %s: semicolon-separated list of problems detected after the reset */
          __('Master Reset ran but the site was left in an incomplete state (%s). Check the RestorePilot log, then repair the site before continuing.', 'restorepilot-backup-migration'),
          $detail
        ),
      ], 500);
    }

    self::write_log('Master Reset complete. Site reset to clean WordPress state. Dropped ' . $dropped_foreign
      . ' table(s) belonging to other plugins. Stored backups were '
      . ($purge_backups
          ? (empty($failed_storage) ? 'deleted at the operator\'s request.' : 'only partly deleted; see the problems above.')
          : 'kept.')
      . ' Must-use plugins: ' . ($purge_mu ? ($removed_mu . ' removed at the operator\'s request.') : 'kept.'));

    wp_send_json_success([
      'message'  => $dropped_foreign > 0
        ? sprintf(
          /* translators: %d: number of database tables removed that were created by other plugins */
          _n(
            'Master Reset complete. Your site has been reset to a clean WordPress installation, including %d database table left behind by another plugin.',
            'Master Reset complete. Your site has been reset to a clean WordPress installation, including %d database tables left behind by other plugins.',
            $dropped_foreign,
            'restorepilot-backup-migration'
          ),
          $dropped_foreign
        )
        : __('Master Reset complete. Your site has been reset to a clean WordPress installation.', 'restorepilot-backup-migration'),
      'redirect' => admin_url(),
    ]);
  }

  public static function handle_clear_log_post(): void {
    self::enable_error_logging();
    self::verify_admin_request();

    self::clear_log();
    self::write_log('Logs cleared from Settings.');
    self::redirect_notice(__('Logs cleared.', 'restorepilot-backup-migration'), 'settings');
  }

  public static function handle_scheduled_backup(): void {
    self::enable_error_logging();
    self::$active_scheduled_backup = true;

    if (is_multisite()) {
      // Multisite is not supported. Proactively clear the recurring event so
      // this callback cannot keep firing (and failing) daily — this also
      // self-heals a site that had a daily backup already scheduled before
      // it joined a network.
      wp_clear_scheduled_hook('restorepilot_scheduled_backup');
      self::write_log('Scheduled backup skipped and unscheduled: multisite is not supported.');
      return;
    }

    $settings = self::get_settings();
    if (empty($settings['scheduled_enabled'])) {
      self::sync_scheduled_backup();
      return;
    }

    if (self::backup_lock_is_active()) {
      self::write_log('Scheduled backup skipped because another backup is running.');
      self::maybe_send_backup_email('skipped', __('Scheduled backup skipped because another backup is running.', 'restorepilot-backup-migration'));
      return;
    }

    try {
      self::write_log('Scheduled backup started.');
      $result = self::create_backup_package(true, '', [], false, true, ['triggered_by' => 'scheduled']);
      self::write_log('Scheduled backup completed.');
      self::maybe_send_backup_email('success', $result['message'], $result['file'] ?? '');
    } catch (Throwable $e) {
      self::write_log('Scheduled backup failed: ' . $e->getMessage());
      self::maybe_send_backup_email('failed', $e->getMessage());
    } finally {
      self::sync_scheduled_backup();
    }
  }

  public static function cli_backup(array $args, array $assoc_args): void {
    self::enable_error_logging();
    if (is_multisite()) {
      if (class_exists('WP_CLI')) {
        WP_CLI::error(self::multisite_unsupported_message());
      }
      return;
    }
    $include_files = empty($assoc_args['db-only']);
    $result = self::create_backup_package($include_files, '', [], false);
    if (class_exists('WP_CLI')) {
      WP_CLI::success(($result['file'] ?? '') . ' ' . ($result['size'] ?? ''));
    }
  }

  public static function cli_health(array $args, array $assoc_args): void {
    self::enable_error_logging();
    $file = isset($args[0]) ? (string) $args[0] : '';
    if ($file === '') {
      $backups = self::list_backups();
      if (!$backups) {
        throw new RuntimeException(__('No RestorePilot backups were found.', 'restorepilot-backup-migration'));
      }
      $file = self::backup_dir() . '/' . $backups[0]['name'];
    } elseif (!file_exists($file)) {
      $file = self::backup_dir() . '/' . sanitize_file_name($file);
    }

    if (!is_file($file) || !is_readable($file)) {
      throw new RuntimeException(__('Backup file not found.', 'restorepilot-backup-migration'));
    }

    $zip = self::open_backup_archive($file);
    try {
      $validated = self::validate_backup_zip($zip, false);
    } finally {
      $zip->close();
    }

    if (class_exists('WP_CLI')) {
      WP_CLI::success(sprintf(
        'Backup OK: %s; tables=%d; files=%d',
        basename($file),
        (int) $validated['table_count'],
        (int) $validated['file_count']
      ));
    }
  }

  public static function handle_download(): void {
    self::enable_error_logging();
    try {
      self::serve_download();
    } catch (Throwable $e) {
      self::write_log('Download failed: ' . $e->getMessage());
      if (!headers_sent()) {
        /* translators: %s: error message */
        self::redirect_error(sprintf(__('Download failed: %s', 'restorepilot-backup-migration'), $e->getMessage()));
      }
      /* translators: %s: error message */
      wp_die(esc_html(sprintf(__('Download failed: %s', 'restorepilot-backup-migration'), $e->getMessage())));
    }
  }

  /**
   * Streams a partial zip containing only one content type from a backup.
   * Allowed parts: database | plugins | themes | uploads
   */
  public static function handle_download_partial(): void {
    self::enable_error_logging();
    self::verify_admin_request();

    $file = self::safe_backup_file_from_request();
    $part = sanitize_key(self::query_value('part'));

    if (!in_array($part, ['database', 'plugins', 'themes', 'uploads', 'mu-plugins', 'others'], true)) {
      self::redirect_error(__('Invalid partial download type.', 'restorepilot-backup-migration'));
    }

    if (!is_file($file) || !is_readable($file)) {
      self::redirect_error(__('Backup file not found.', 'restorepilot-backup-migration'));
    }

    if (!class_exists('ZipArchive')) {
      self::redirect_error(__('ZipArchive is required for partial downloads.', 'restorepilot-backup-migration'));
    }

    try {
      self::prepare_for_long_operation();
      $tmp_path = self::build_partial_zip($file, $part);

      $size = filesize($tmp_path);
      if ($size === false || $size < 1) {
        @unlink($tmp_path);
        self::redirect_error(sprintf(
          /* translators: %s: backup content part name (e.g. database or files) */
          __('The backup does not contain any "%s" content.', 'restorepilot-backup-migration'),
          $part
        ));
      }

      $base_name = preg_replace('/\.zip$/i', '', basename($file));
      $out_name  = $base_name . '-' . $part . '.zip';

      while (ob_get_level() > 0) {
        @ob_end_clean();
      }
      nocache_headers();
      header('Content-Type: application/zip');
      header('Content-Disposition: attachment; filename="' . self::download_header_filename($out_name) . '"');
      header('Content-Length: ' . $size);
      header('X-Content-Type-Options: nosniff');
      header('X-Accel-Buffering: no');

      $fh = fopen($tmp_path, 'rb');
      if ($fh !== false) {
        while (!feof($fh)) {
          $chunk = fread($fh, 1024 * 1024);
          if ($chunk !== false && $chunk !== '') {
            echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw binary file stream, not HTML
            flush();
          }
        }
        fclose($fh);
      }
    } catch (Throwable $e) {
      self::write_log('Partial download failed: ' . $e->getMessage());
      if (!headers_sent()) {
        /* translators: %s: error message */
        self::redirect_error(sprintf(__('Partial download failed: %s', 'restorepilot-backup-migration'), $e->getMessage()));
      }
      wp_die(esc_html($e->getMessage()));
    } finally {
      if (!empty($tmp_path) && is_file($tmp_path)) {
        @unlink($tmp_path);
      }
    }

    exit;
  }

  public static function handle_download_log(): void {
    self::enable_error_logging();
    self::verify_admin_request();

    $log = self::read_log();
    if (trim($log) === '') {
      $log = __('No log entries yet.', 'restorepilot-backup-migration') . "\n";
    }

    while (ob_get_level() > 0) {
      @ob_end_clean();
    }

    nocache_headers();
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="restorepilot-log-' . gmdate('Ymd-His') . '.txt"');
    header('X-Content-Type-Options: nosniff');
    echo $log; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text log file download (text/plain), not HTML
    exit;
  }

  public static function handle_split(): void {
    self::enable_error_logging();
    self::verify_admin_request();
    $file = self::safe_backup_file_from_request();

    if (!is_file($file) || !is_readable($file)) {
      self::redirect_error(__('Backup file not found.', 'restorepilot-backup-migration'));
    }

    $size = filesize($file);
    if ($size === false || $size < 1) {
      self::redirect_error(__('Backup file is empty or unreadable.', 'restorepilot-backup-migration'));
    }

    if (function_exists('set_time_limit')) {
      @set_time_limit(0);
    }

    try {
      self::create_backup_parts($file);
      self::write_log('Safe download files prepared manually for: ' . basename($file));
      self::redirect_notice(__('Safe download files are ready. Download every part file; RestorePilot can combine them automatically during restore.', 'restorepilot-backup-migration'));
    } catch (Throwable $e) {
      self::write_log('Safe download preparation failed: ' . $e->getMessage());
      self::redirect_error($e->getMessage());
    }
  }

  public static function handle_delete(): void {
    self::enable_error_logging();
    self::verify_admin_request();
    $file = self::safe_backup_file_from_request();

    if (is_file($file)) {
      self::delete_backup_parts(basename($file));
      // Deleting a backup deletes the whole volume set; leaving later volumes
      // behind would strand files the user believes they have removed.
      $volumes = self::volume_paths_for($file);
      foreach ($volumes as $volume_path) {
        @unlink($volume_path);
      }
      self::write_log('Backup deleted: ' . basename($file) . (count($volumes) > 1 ? ' (' . count($volumes) . ' volumes)' : ''));
    }

    self::redirect_notice(__('Backup deleted.', 'restorepilot-backup-migration'), 'backup');
  }

  /**
   * Lets an administrator declare a stopped restore dead, rather than waiting
   * out the lock's own two-hour staleness window with the site unavailable.
   */
  public static function handle_abandon_restore(): void {
    self::enable_error_logging();
    self::verify_admin_request();

    $snapshot = self::active_restore_snapshot();
    $job_id = $snapshot['job_id'];

    if ($job_id === '') {
      // Nothing holds the lock, but maintenance may still be on from a run
      // that never cleaned up after itself — clear that regardless, since
      // this action exists precisely to get an unavailable site back.
      self::disable_maintenance_mode();
      self::redirect_notice(__('No restore was running. Maintenance mode has been turned off.', 'restorepilot-backup-migration'), 'restore');
    }

    self::update_restore_job($job_id, [
      'status' => 'error',
      'phase' => 'error',
      'message' => __('Restore ended by an administrator because it had stopped responding.', 'restorepilot-backup-migration'),
    ]);
    self::force_release_restore_locks($job_id);
    self::write_log('Restore ended by administrator (was stuck): ' . $job_id);
    self::write_operation_notice(
      'error',
      'restore',
      __('The restore was ended because it had stopped. Your site may be partly restored — recover your database from a pre-restore rollback point below.', 'restorepilot-backup-migration')
    );

    self::redirect_notice(
      __('The restore was ended and the site unlocked. If it had already started replacing your database, recover from a pre-restore rollback point below.', 'restorepilot-backup-migration'),
      'restore'
    );
  }

  public static function handle_reactivate_deferred_plugins(): void {
    self::enable_error_logging();
    self::verify_admin_request();

    if (!is_array(get_option(self::DEFERRED_PLUGINS_OPTION, null))) {
      self::redirect_error(__('There are no held-back plugins to reactivate.', 'restorepilot-backup-migration'));
    }

    // Refuses while a restore is genuinely running: that restore is going to
    // reinstate the list itself when its file phase finishes, and putting
    // plugins back mid-restore would recreate the exact fatal-on-bootstrap
    // failure the deferral exists to prevent.
    if (self::restore_lock_is_active()) {
      self::redirect_error(__('A restore is currently running. Your plugins will be reactivated automatically when it finishes.', 'restorepilot-backup-migration'));
    }

    self::restore_deferred_active_plugins();
    // Rules from any newly reactivated post types are not registered in this
    // request (those plugins were not loaded when it booted), so let
    // WP_Rewrite rebuild them on the next one, same as after a restore.
    delete_option('rewrite_rules');
    self::redirect_notice(__('Your plugins have been reactivated.', 'restorepilot-backup-migration'));
  }
}
