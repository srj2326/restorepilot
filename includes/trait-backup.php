<?php
/**
 * Creating a backup: the chunked job, the archive, and what goes in it.
 *
 * @package RestorePilot_Backup_Migration
 */

if (!defined('ABSPATH')) {
  exit;
}

trait RestorePilot_Backup {
  /**
   * Kicks off (or continues) a backup worker: an immediate, best-effort
   * loopback request plus a short-delay WP-Cron fallback, exactly like the
   * very first dispatch when the job was created — a resumption is not
   * special, it is just another worker for the same job_id/token, and
   * run_backup_job() already treats it that way (the worker lock, not job
   * status, is what prevents two of them running at once).
   */
  private static function dispatch_backup_worker(string $job_id, string $token): void {
    $loopback = wp_remote_post(admin_url('admin-ajax.php'), [
      'timeout' => 1,
      'blocking' => false,
      // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter used by WordPress loopback requests.
      'sslverify' => apply_filters('https_local_ssl_verify', false),
      'body' => [
        'action' => 'restorepilot_run_backup_job',
        'job_id' => $job_id,
        'token' => $token,
      ],
    ]);
    if (is_wp_error($loopback)) {
      self::write_log('Loopback backup runner could not be dispatched: ' . $loopback->get_error_message());
    } else {
      self::write_log('Loopback backup runner dispatched: ' . $job_id);
    }

    if (!wp_next_scheduled('restorepilot_cron_backup_job', [$job_id, $token])) {
      $scheduled = wp_schedule_single_event(time() + 5, 'restorepilot_cron_backup_job', [$job_id, $token], true);
      if (is_wp_error($scheduled)) {
        self::write_log('Cron backup fallback could not be scheduled: ' . $scheduled->get_error_message());
      } else {
        self::write_log('Cron backup fallback scheduled: ' . $job_id);
      }
    }
  }

  public static function run_backup_job(string $job_id, string $token): void {
    // Register the shutdown/error handler so that a fatal under WP-Cron still
    // releases locks and disables maintenance mode (not just in AJAX requests).
    self::enable_error_logging();
    self::$active_backup_job_id = $job_id;
    $job = self::get_backup_job($job_id);
    if (!$job || empty($job['token']) || !hash_equals((string) $job['token'], (string) $token)) {
      self::$active_backup_job_id = '';
      return;
    }

    // 'running' is not blocked here: a job that yielded between chunks is
    // left in 'running' status on purpose (see the yield catch below), and
    // this same handler is exactly what its next resumption calls. The
    // worker lock immediately below is what actually prevents two workers
    // from touching the same job at once, whether it is its first chunk or
    // its fifth.
    //
    // 'canceled' is not blocked here either, and for a related reason: a
    // cancellation requested while the job is sitting between chunks (no
    // process currently executing it at all) has nothing to interrupt yet.
    // Letting this call through to create_backup_package() gives it one
    // more resumption whose sole purpose is to hit its own, very first
    // throw_if_backup_cancelled() check and unwind through the existing
    // cancellation cleanup — the volumes, database export directory, and
    // site-wide lock a yielded job is still holding. Skipping that resumption
    // would leave all of it orphaned, reachable only after the 15-minute
    // staleness window.
    if (in_array(($job['status'] ?? ''), ['complete', 'error', 'stale'], true)) {
      self::$active_backup_job_id = '';
      return;
    }

    if (!self::acquire_backup_worker_lock($job_id)) {
      self::$active_backup_job_id = '';
      return;
    }

    // Re-read under the lock, for the same reason as the restore side: the
    // copy above predates holding it, and its checkpoint decides what work is
    // still outstanding.
    // Fresh, not merely repeated: the read before the lock cached this
    // record in this process, so an ordinary re-read hands back the same
    // stale copy and this worker resumes from a position another worker
    // has already finished -- which is precisely how two workers ended up
    // inserting the same rows and colliding on a duplicate key.
    $job = self::get_backup_job($job_id, true) ?: $job;
    if (in_array(($job['status'] ?? ''), ['complete', 'error', 'stale', 'canceled'], true)) {
      self::release_backup_worker_lock($job_id);
      self::$active_backup_job_id = '';
      return;
    }

    // Set when this chunk yields, and acted on only after the worker lock has
    // been released below — see the dispatch at the end of this method.
    $dispatch_next_chunk = false;

    $resumption = (int) ($job['checkpoint']['resumption'] ?? 0);
    // A job let through the guard above specifically because it was already
    // 'canceled' must stay 'canceled' here — stamping it back to 'running'
    // would erase the only signal throw_if_backup_cancelled() has to detect
    // it, and this resumption's entire purpose in that case is to reach that
    // check and unwind through cleanup, not to keep working.
    $was_canceled = ($job['status'] ?? '') === 'canceled';

    try {
      self::prepare_for_long_operation();
      self::write_log('Backup runner started: ' . $job_id . ($resumption > 1 ? (' (resumption ' . $resumption . ')') : ''));
      if (!$was_canceled) {
        self::update_backup_job($job_id, [
          'status' => 'running',
          'phase' => $resumption > 1 ? ($job['phase'] ?? 'files') : 'starting',
          'phase_label' => self::backup_phase_label($resumption > 1 ? ($job['phase'] ?? 'files') : 'starting'),
          'progress' => $resumption > 1 ? ($job['progress'] ?? 55) : 10,
          'message' => $resumption > 1
            ? __('Backup is continuing in the background.', 'restorepilot-backup-migration')
            : __('Backup is running in the background.', 'restorepilot-backup-migration'),
        ]);
      }

      $result = self::create_backup_package(
        !empty($job['include_files']),
        $job_id,
        isset($job['selected_paths']) && is_array($job['selected_paths']) ? $job['selected_paths'] : [],
        !empty($job['file_selection_enabled'])
      );

      self::update_backup_job($job_id, [
        'status' => 'complete',
        'phase' => 'complete',
        'phase_label' => self::backup_phase_label('complete'),
        'progress' => 100,
        'message' => $result['message'],
        'file' => $result['file'] ?? '',
        'size' => $result['size'] ?? '',
      ]);
      self::maybe_send_backup_email('success', $result['message'], $result['file'] ?? '');
      self::write_operation_notice('success', 'backup', $result['message']);
    } catch (RestorePilot_Backup_Chunk_Yield_Exception $e) {
      // Not a failure: create_backup_package() already left the job option's
      // 'checkpoint' pointing at everything this chunk finished, and the zip
      // volume(s)/database export on disk exactly as they should be. Bump the
      // resumption counter for logging, leave 'status' at 'running' (this is
      // not a terminal state), and schedule the next chunk the same way the
      // very first one was dispatched.
      $job_now = self::get_backup_job($job_id);
      $checkpoint = is_array($job_now['checkpoint'] ?? null) ? $job_now['checkpoint'] : [];
      $checkpoint['resumption'] = (int) ($checkpoint['resumption'] ?? 1) + 1;
      self::update_backup_job($job_id, [
        'checkpoint' => $checkpoint,
        'message' => __('Backup is continuing in the background.', 'restorepilot-backup-migration'),
      ]);
      self::write_log('Backup chunk finished, continuing as resumption ' . $checkpoint['resumption'] . ': ' . $job_id);
      $dispatch_next_chunk = true;
    } catch (RestorePilot_Backup_Cancelled_Exception $e) {
      self::write_log('Backup job canceled: ' . $job_id);
      self::update_backup_job($job_id, [
        'status' => 'canceled',
        'phase' => 'canceled',
        'phase_label' => self::backup_phase_label('canceled'),
        'progress' => 100,
        'message' => __('Backup canceled.', 'restorepilot-backup-migration'),
      ]);
    } catch (Throwable $e) {
      self::write_log('Backup job failed: ' . $job_id . '; ' . $e->getMessage());
      self::update_backup_job($job_id, [
        'status' => 'error',
        'phase' => 'error',
        'phase_label' => self::backup_phase_label('error'),
        'progress' => 100,
        'message' => $e->getMessage(),
      ]);
      self::maybe_send_backup_email('failed', $e->getMessage());
      self::write_operation_notice('error', 'backup', $e->getMessage());
    } finally {
      self::release_backup_worker_lock($job_id);
      self::$active_backup_job_id = '';
    }

    // After the finally, for the same reason as the restore side: the worker
    // lock has to be gone before the next chunk can take it. Dispatched from
    // inside the try, the loopback arrived while this request still held the
    // lock, failed to acquire it, and returned silently — leaving the +5s
    // cron fallback to start every chunk.
    if ($dispatch_next_chunk) {
      self::dispatch_backup_worker($job_id, $token);
    }
  }

  private static function create_backup_package(bool $include_files, string $job_id = '', array $selected_paths = [], bool $selection_enabled = false, bool $enforce_retention = true, array $options = []): array {
    self::assert_multisite_unsupported();
    $selected_paths = self::sanitize_selected_backup_paths($selected_paths);
    $files_included = $include_files && (!$selection_enabled || !empty($selected_paths));
    $skip_lock = !empty($options['skip_lock']);
    $purpose = isset($options['purpose']) ? sanitize_key((string) $options['purpose']) : 'backup';
    $log_label = $purpose === 'rollback' ? __('Restore rollback point', 'restorepilot-backup-migration') : __('Backup', 'restorepilot-backup-migration');
    $zip_path = '';
    $final_zip_path = '';
    $tmp_db = '';
    $zip = null;
    $yielding = false;

    // A job option carrying a 'checkpoint' means an earlier chunk for this
    // same job already got as far as creating its paths (and possibly
    // finishing the database export) before its time budget ran out. Only
    // ever set for a real async job — the synchronous rollback-point path
    // below always passes $job_id === '', so get_backup_job('') is always
    // empty and that call is untouched by any of this.
    $job = $job_id !== '' ? self::get_backup_job($job_id) : [];
    $checkpoint = is_array($job['checkpoint'] ?? null) ? $job['checkpoint'] : null;
    $resuming = $checkpoint !== null;

    // The site-wide backup lock (distinct from run_backup_job()'s per-job
    // worker lock) is held for the whole job, chunk boundaries included —
    // its entire purpose is stopping a second, unrelated backup from
    // starting while this one is still in progress, which a release-then-
    // reacquire between chunks would defeat during exactly the gap it exists
    // to close. A resumption reuses the token the first chunk acquired
    // rather than acquiring its own.
    if ($skip_lock) {
      $lock_token = '';
    } elseif ($resuming) {
      $lock_token = (string) ($checkpoint['lock_token'] ?? '');
      if ($lock_token === '') {
        throw new RuntimeException(__('Backup checkpoint is missing its lock token; the backup cannot be safely resumed.', 'restorepilot-backup-migration'));
      }
    } else {
      $lock_token = self::acquire_backup_lock($job_id);
    }

    // Chunking only applies to a real async job: run_backup_job() is the
    // only caller that can ever catch a yield and reschedule one. Without a
    // job_id — the daily scheduled backup and the synchronous rollback-point
    // snapshot both call this directly — a yield would throw, correctly skip
    // all cleanup (that is what a yield means), and then propagate to a
    // caller with no way to resume it: the site-wide lock and every volume
    // written so far would be orphaned instead of merely paused. Leaving the
    // deadline at 0.0 keeps throw_if_chunk_time_exceeded() a permanent no-op
    // for these callers, exactly matching their pre-resumability behavior of
    // running to completion in one uninterrupted call.
    self::$chunk_deadline = $job_id !== ''
      ? microtime(true) + (float) apply_filters('restorepilot_backup_chunk_seconds', self::BACKUP_CHUNK_SECONDS)
      : 0.0;
    self::$chunk_progress_made = false;

    try {
      self::reset_backup_exclusion_tracking();
      self::ensure_storage();
      self::prepare_for_long_operation();

      if ($resuming) {
        $final_zip_path = (string) $checkpoint['final_zip_path'];
        $zip_path = (string) $checkpoint['zip_path'];
        $tmp_db = (string) $checkpoint['tmp_db'];
        $created_gmt = (string) $checkpoint['created_gmt'];
        $destination_dir = dirname($final_zip_path);
      } else {
        $timestamp = gmdate('Ymd-His');
        $created_gmt = gmdate('c');
        $filename = isset($options['filename']) ? sanitize_file_name((string) $options['filename']) : self::friendly_backup_filename();
        $destination_dir = isset($options['destination_dir']) ? (string) $options['destination_dir'] : self::backup_dir();
        $final_zip_path = rtrim($destination_dir, '/\\') . '/' . $filename;
        $zip_path = self::storage_dir() . '/' . $filename . '.restorepilot-tmp';
        // A directory, not a file: the export is written as numbered
        // newline-delimited parts inside it (see write_database_export()).
        $tmp_db = self::storage_dir() . '/database-' . $timestamp . '-' . wp_generate_uuid4();
      }

      if (!wp_mkdir_p($destination_dir) && !is_dir($destination_dir)) {
        throw new RuntimeException(__('Could not create backup storage folder.', 'restorepilot-backup-migration'));
      }
      if (!wp_mkdir_p($tmp_db) && !is_dir($tmp_db)) {
        throw new RuntimeException(__('Could not create database export folder.', 'restorepilot-backup-migration'));
      }

      if ($job_id !== '' && !$resuming) {
        // Recorded before any real work starts so that even a process killed
        // during the database export — which cannot itself be resumed, see
        // write_database_export() — leaves behind a resumption that at least
        // knows where everything belongs, instead of starting completely over.
        self::update_backup_job($job_id, [
          'checkpoint' => [
            'final_zip_path' => $final_zip_path,
            'zip_path' => $zip_path,
            'tmp_db' => $tmp_db,
            'created_gmt' => $created_gmt,
            'lock_token' => $lock_token,
            'database_done' => false,
            'resumption' => 1,
          ],
        ]);
      }

      self::write_log($log_label . ' started.');
      $backup_estimate = self::assert_backup_disk_space($include_files, $selected_paths, $selection_enabled);
      self::throw_if_backup_cancelled($job_id);
      if ($job_id) {
        self::update_backup_job($job_id, [
          'phase' => 'preparing',
          'phase_label' => self::backup_phase_label('preparing'),
          'progress' => 18,
          'message' => __('Preparing backup...', 'restorepilot-backup-migration'),
          'estimated_database_bytes' => (int) ($backup_estimate['database'] ?? 0),
          'estimated_content_bytes' => (int) ($backup_estimate['content'] ?? 0),
        ]);
      }
      $backup_type = 'full';
      $restorable = true;
      if ($purpose !== 'backup') {
        $backup_type = $purpose;
        $restorable = false;
      } elseif (!$files_included) {
        $backup_type = 'database';
        $restorable = false;
      } elseif ($selection_enabled) {
        // Only mark as partial when the user actually excluded some folders.
        // If every available top-level path is included it is still a full backup.
        $all_available = self::sanitize_selected_backup_paths(
          array_column(self::list_backup_file_items(), 'path')
        );
        if (!empty($all_available) && count($selected_paths) < count($all_available)) {
          $backup_type = 'selected-content';
          $restorable = false;
        }
        // else: all folders selected — stays 'full' / restorable.
      }

      // The database export only ever captures tables starting with
      // $wpdb->prefix (see write_database_json()). A site configured with
      // CUSTOM_USER_TABLE/CUSTOM_USER_META_TABLE points its actual users and
      // usermeta at a differently-named table outside that scope, so this
      // backup — regardless of what the checks above concluded — does not
      // contain user accounts. Never call that "full" or "restorable"; a
      // restore already refuses it too (build_restore_plan() requires every
      // core table, including users/usermeta, to be present), but the
      // manifest should say so up front rather than let the admin discover
      // it only when a restore fails.
      $custom_user_tables = self::uses_custom_user_tables();
      if ($custom_user_tables) {
        $backup_type = 'unsupported-configuration';
        $restorable = false;
        self::write_log('Backup does not include user accounts: this site is configured with a custom shared user table (CUSTOM_USER_TABLE/CUSTOM_USER_META_TABLE), which is outside this export\'s scope.');
      }

      $triggered_by = isset($options['triggered_by']) ? sanitize_key((string) $options['triggered_by']) : 'manual';
      $manifest = [
        'plugin' => self::SLUG,
        'version' => self::VERSION,
        'backup_type' => $backup_type,
        'triggered_by' => $triggered_by,
        'restorable' => $restorable,
        'created_gmt' => $created_gmt,
        'home_url' => home_url(),
        'site_url' => site_url(),
        'table_prefix' => self::wpdb()->prefix,
        'wp_content_basename' => basename(self::content_dir()),
        'includes_database' => true,
        'includes_files' => $files_included,
        'file_selection_enabled' => $selection_enabled,
        'selected_content_paths' => $selection_enabled ? array_values($selected_paths) : [],
        'purpose' => $purpose,
        'custom_user_tables' => $custom_user_tables,
      ];

      if ($job_id) {
        self::update_backup_job($job_id, [
          'phase' => 'database',
          'phase_label' => self::backup_phase_label('database'),
          'progress' => 30,
          'message' => __('Exporting database...', 'restorepilot-backup-migration'),
        ]);
      }
      // The database export is never resumed mid-flight: it is one InnoDB
      // consistent-snapshot transaction (see write_database_export()), and a
      // transaction cannot survive past the PHP process that opened it, so
      // splitting it across chunks would mean giving up the guarantee that
      // the whole database was exported as of one single moment. It either
      // finishes inside the resumption that starts it, or that resumption
      // dies and the whole export restarts from scratch next time — cheap
      // relative to file collection, which is where real sites spend most of
      // a backup's time and where resumability actually matters.
      if ($resuming && !empty($checkpoint['database_done'])) {
        $database_parts = (array) $checkpoint['database_parts'];
        foreach ($database_parts as $part_path) {
          if (!is_file($part_path)) {
            throw new RuntimeException(__('A previously exported database part is missing; the backup cannot be resumed and must be restarted.', 'restorepilot-backup-migration'));
          }
        }
        $manifest['table_count'] = (int) $checkpoint['table_count'];
        self::write_log('Database export already completed in an earlier chunk: ' . count($database_parts) . ' part(s).');
      } else {
        if ($job_id) {
          self::update_backup_job($job_id, [
            'phase' => 'database',
            'phase_label' => self::backup_phase_label('database'),
            'progress' => 30,
            'message' => __('Exporting database...', 'restorepilot-backup-migration'),
          ]);
        }
        self::write_log('Database export started.');
        $database_export = self::write_database_export($tmp_db, $job_id);
        $database_parts = $database_export['parts'];
        $manifest['table_count'] = $database_export['table_count'];
        self::write_log('Database export completed: ' . count($database_parts) . ' part(s).');

        if ($job_id !== '') {
          self::update_backup_job($job_id, [
            'checkpoint' => [
              'final_zip_path' => $final_zip_path,
              'zip_path' => $zip_path,
              'tmp_db' => $tmp_db,
              'created_gmt' => $created_gmt,
              'lock_token' => $lock_token,
              'database_done' => true,
              'database_parts' => $database_parts,
              'table_count' => $manifest['table_count'],
              'resumption' => (int) ($checkpoint['resumption'] ?? 1),
            ],
          ]);
        }
      }
      // Recorded in the manifest so a later metadata-only check (e.g. Backup
      // Check) can report the table count without reading the export at all,
      // and so restore knows how many parts to stream — see
      // validate_backup_zip() and stream_database_records().
      $manifest['database_format'] = 'ndjson';
      $manifest['database_parts'] = count($database_parts);
      self::throw_if_backup_cancelled($job_id);

      /**
       * Filters the maximum size of a single backup volume, in bytes.
       *
       * Lower this on hosts that refuse to create files above a given size
       * (the write fails with EFBIG); the backup is split into more, smaller
       * volumes instead.
       *
       * @param int $bytes Default volume size.
       */
      $volume_bytes = (int) apply_filters('restorepilot_backup_volume_bytes', self::BACKUP_VOLUME_BYTES);
      $existing_volumes = self::discover_volumes($zip_path)['paths'];
      $zip = $existing_volumes
        ? RestorePilot_Backup_Volume_Writer::resume($zip_path, $volume_bytes, $existing_volumes)
        : new RestorePilot_Backup_Volume_Writer($zip_path, $volume_bytes);

      foreach ($database_parts as $part_path) {
        $part_name = self::DATABASE_PART_DIR . '/' . basename($part_path);
        if ($zip->has_entry($part_name)) {
          continue;
        }
        if ($zip->addFile($part_path, $part_name) === false) {
          throw new RuntimeException(__('Could not add database export to backup.', 'restorepilot-backup-migration'));
        }
      }

      if ($include_files) {
        if (!$files_included) {
          self::write_log('File collection skipped because no wp-content paths were selected.');
        } else {
          self::write_log('File collection started.');
          self::reset_file_scan_progress($job_id);
          if ($job_id) {
            self::update_backup_job($job_id, [
              'phase' => 'files',
              'phase_label' => self::backup_phase_label('files'),
              'progress' => 55,
              'message' => __('Collecting files...', 'restorepilot-backup-migration'),
              'files_scanned' => 0,
              'bytes_scanned' => 0,
            ]);
          }
          if ($selection_enabled) {
            self::add_selected_paths_to_zip($zip, $selected_paths, $job_id);
          } else {
            self::add_directory_to_zip($zip, self::content_dir(), 'files/wp-content', $job_id);
          }
          self::flush_file_scan_progress($job_id);
          self::write_log('File collection completed.');
        }
      }
      self::throw_if_backup_cancelled($job_id);

      // Written only now, after the file walk, so it can report exactly what
      // that walk actually excluded — a "full" backup with unreported skips
      // is exactly what this field exists to prevent.
      $manifest['excluded_paths'] = self::backup_exclusion_labels();
      // Recorded so a restore can tell a complete volume set from a set that
      // is missing its last volume — a count it cannot otherwise infer from
      // the filenames alone. The manifest is written into whichever volume is
      // currently open, without triggering a rollover, so the number below is
      // still correct once the archive is closed (asserted right after).
      $manifest['volumes'] = count($zip->volumes());
      $manifest_json = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
      if (!is_string($manifest_json) || $manifest_json === '') {
        throw new RuntimeException(__('Could not prepare backup manifest.', 'restorepilot-backup-migration'));
      }
      if ($zip->addFromString('manifest.json', $manifest_json, false) === false) {
        throw new RuntimeException(__('Could not add backup manifest.', 'restorepilot-backup-migration'));
      }
      if (count($zip->volumes()) !== (int) $manifest['volumes']) {
        throw new RuntimeException(__('Backup volume count changed while finalizing; the archive was not written correctly.', 'restorepilot-backup-migration'));
      }
      if ($manifest['excluded_paths']) {
        self::write_log('Backup excluded (see manifest): ' . implode('; ', $manifest['excluded_paths']));
      }
      if ($zip->oversize_entries()) {
        self::write_log('Backup contains file(s) larger than one volume, so their volume exceeds the split size: ' . implode(', ', array_slice($zip->oversize_entries(), 0, 5)));
      }

      self::write_log('Zip finalization started.');
      if ($job_id) {
        self::update_backup_job($job_id, [
          'progress' => 95,
          'phase' => 'finalizing',
          'phase_label' => self::backup_phase_label('finalizing'),
          'message' => __('Finalizing backup...', 'restorepilot-backup-migration'),
        ]);
      }
      $volume_paths = $zip->volumes();
      if ($zip->close() === false) {
        throw new RuntimeException(__('Could not finalize backup zip.', 'restorepilot-backup-migration'));
      }
      self::write_log('Zip finalization completed (' . count($volume_paths) . ' volume(s)).');
      $zip = null;
      self::delete_directory($tmp_db, self::storage_dir());

      foreach ($volume_paths as $volume_path) {
        if (!is_file($volume_path) || filesize($volume_path) < 1) {
          throw new RuntimeException(__('Backup zip was not created correctly.', 'restorepilot-backup-migration'));
        }
      }

      // Move every volume into storage together. Volume 1 keeps the backup's
      // plain name; the rest take the matching -vNNN suffix, which is how the
      // set is discovered again at restore time.
      $backup_size = 0;
      $final_volumes = [];
      foreach ($volume_paths as $i => $volume_path) {
        $destination = RestorePilot_Backup_Volume_Writer::volume_path($final_zip_path, $i + 1);
        if (!@rename($volume_path, $destination)) {
          foreach ($final_volumes as $written) {
            @unlink($written);
          }
          throw new RuntimeException(__('Could not move completed backup into storage.', 'restorepilot-backup-migration'));
        }
        $final_volumes[] = $destination;
        $backup_size += (int) filesize($destination);
      }
      $zip_path = $final_zip_path;

      $notice = $purpose === 'rollback' ? __('Restore rollback point created.', 'restorepilot-backup-migration') : __('Backup created successfully.', 'restorepilot-backup-migration');
      self::write_log($log_label . ' created: ' . basename($zip_path) . ' (' . size_format((int) $backup_size) . ' across ' . count($final_volumes) . ' volume(s)).');
      if ($enforce_retention) {
        self::enforce_backup_retention();
      }

      return [
        'message' => $notice,
        'file' => basename($zip_path),
        'size' => $backup_size ? size_format((int) $backup_size) : '',
      ];
    } catch (RestorePilot_Backup_Chunk_Yield_Exception $e) {
      // Deliberately does none of the cleanup below: a yield is not a
      // failure, so the volume(s) currently on disk, the writer's open
      // handle (simply abandoned here — the file itself is exactly as valid
      // for a future resume() as it was the instant the time budget tripped,
      // and nothing else in this process still needs the handle), the
      // database export directory, and the job's checkpoint must all survive
      // untouched for the next chunk to pick up. run_backup_job() is what
      // actually reschedules; this only needs to let the exception through.
      //
      // $yielding also tells the finally block below to leave the site-wide
      // backup lock held — it spans the whole job, not one chunk, so it must
      // not be released and re-acquired on every yield.
      $yielding = true;
      throw $e;
    } catch (Throwable $e) {
      if ($zip instanceof RestorePilot_Backup_Volume_Writer) {
        $zip->abort();
      }
      if ($tmp_db !== '') {
        self::delete_directory($tmp_db, self::storage_dir());
      }
      // Remove every volume of the half-written backup, under both the
      // temporary and the final name — a failure can land either side of the
      // rename loop, and leaving a partial volume set behind would look like
      // a usable backup.
      foreach ([$zip_path, $final_zip_path] as $base) {
        if ($base === '') {
          continue;
        }
        foreach (self::volume_paths_for($base) as $stale_volume) {
          @unlink($stale_volume);
          @unlink($stale_volume . '.journal');
        }
      }
      if ($final_zip_path !== '') {
        self::delete_backup_parts(basename($final_zip_path));
        self::write_log('Incomplete backup cleaned up: ' . basename($final_zip_path));
      } else {
        self::write_log('Incomplete backup cleaned up before final filename was created.');
      }
      throw $e;
    } finally {
      if ($lock_token !== '' && !$yielding) {
        self::release_backup_lock($lock_token);
      }
    }
  }

  /**
   * Exports every table of this site to newline-delimited JSON, split across
   * numbered part files in $dir.
   *
   * One JSON object per line — a {"t":"table"} header followed by its
   * {"t":"row"} records — means neither this writer nor the restore reader
   * ever needs more than a single line in memory, whatever the size of the
   * database. Parts are only ever rolled over between whole lines, so every
   * part is independently parseable and the concatenation of all parts in
   * order is the complete export.
   *
   * @return array{parts: string[], table_count: int}
   */
  private static function write_database_export(string $dir, string $job_id = ''): array {
    $wpdb = self::wpdb();
    $parts = [];
    $handle = null;
    $part_bytes = 0;

    $open_part = function () use (&$handle, &$parts, &$part_bytes, $dir) {
      $path = $dir . '/database-' . str_pad((string) (count($parts) + 1), 4, '0', STR_PAD_LEFT) . '.ndjson';
      $opened = fopen($path, 'wb');
      if ($opened === false) {
        throw new RuntimeException(__('Could not create database export file.', 'restorepilot-backup-migration'));
      }
      $handle = $opened;
      $parts[] = $path;
      $part_bytes = 0;
    };

    // Writes one complete record. Rollover is checked before the line is
    // written, never in the middle of one, so a part never ends mid-record.
    $emit = function (string $line) use (&$handle, &$part_bytes, &$parts, $open_part) {
      if ($handle !== null && $part_bytes >= self::DATABASE_PART_BYTES) {
        fclose($handle);
        $handle = null;
      }
      if ($handle === null) {
        $open_part();
      }
      $line .= "\n";
      self::write_stream($handle, (string) end($parts), $line, 'write database export');
      $part_bytes += strlen($line);
    };

    try {
      // A consistent InnoDB snapshot for the whole export, not just each
      // table's own read: every table and every batch within it sees the
      // database exactly as it stood when the transaction opened, regardless
      // of concurrent writes elsewhere while the export runs. This does not
      // extend to non-transactional storage engines (e.g. MyISAM) — those are
      // detected and reported below, not silently assumed consistent.
      $wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');

      $wpdb->last_error = '';
      // Direct query: no WordPress ORM equivalent for SHOW TABLES.
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
      $tables = (array) $wpdb->get_col('SHOW TABLES');
      self::throw_on_db_error('list database tables');

      $first_table = true;
      $table_count = 0;
      $non_transactional_tables = [];
      $table_prefix = (string) $wpdb->prefix;

      // Decide which tables are in scope BEFORE exporting any of them, so the
      // export knows how many it is going to write. That count is what lets
      // progress advance table by table instead of sitting at one number for
      // the whole phase: on a site with many tables the database phase runs
      // for minutes, and a frozen bar reads as a hung backup even though it
      // is working normally. Every check here is name-based and cheap — no
      // query — so running them in their own pass costs nothing.
      $exportable_tables = [];
      foreach ($tables as $table) {
        $table = (string) $table;
        if ($table === '') {
          continue;
        }
        if ($table_prefix !== '' && strpos($table, $table_prefix) !== 0) {
          continue;
        }
        // Never export another network site's tables (see
        // table_belongs_to_other_site()).
        if (self::table_belongs_to_other_site($table, $table_prefix)) {
          continue;
        }
        // Never export this plugin's own restore scratch tables. These only
        // exist at all if an earlier restore was interrupted before its
        // RENAME TABLE swap — sweep_stale_restore_tables() clears them at
        // the START of the NEXT restore, but nothing sweeps them before a
        // BACKUP, so without this check they get exported as if they were
        // real site content: a table named after this plugin's own restore
        // internals, backed up and offered back as something to restore.
        // Contains-check rather than strict prefix, since a very long site
        // prefix can be truncated ahead of the marker (see
        // restore_scratch_table_name()) — the marker string itself is
        // distinctive enough not to collide with real table names.
        if (strpos($table, self::RESTORE_TMP_TABLE_MARKER) !== false || strpos($table, self::RESTORE_OLD_TABLE_MARKER) !== false) {
          continue;
        }
        $exportable_tables[] = $table;
      }

      $total_tables = count($exportable_tables);
      $table_position = 0;

      foreach ($exportable_tables as $table) {
        self::throw_if_backup_cancelled($job_id);
        $table_position++;
        $database_progress = self::database_phase_progress($table_position - 1, $total_tables);
        $database_label = self::database_phase_label($table_position, $total_tables);

        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $create_row = $wpdb->get_row($wpdb->prepare('SHOW CREATE TABLE %i', $table), ARRAY_N);
        self::throw_on_db_error('read table schema');
        $create_sql = isset($create_row[1]) ? (string) $create_row[1] : '';
        $engine = self::table_engine($create_sql);
        if ($engine !== '' && strtolower($engine) !== 'innodb') {
          $non_transactional_tables[] = $table . ' (' . $engine . ')';
        }

        $first_table = false;
        $table_count++;

        $emit(
          '{"t":"table","name":' . self::json_fragment($table, 'encode table name') .
          ',"create":' . self::json_fragment($create_sql, 'encode table schema') . '}'
        );

        $limit = 500;
        $pk_columns = self::keyset_cursor_columns($create_sql);

        if ($pk_columns) {
          // Deterministic keyset pagination: always move strictly forward by
          // primary key value rather than by row position. A concurrent
          // INSERT/DELETE elsewhere in the table cannot shift already-read or
          // not-yet-read rows across a position-based boundary, so no row is
          // ever exported twice or skipped, and a large table does not
          // progressively slow down the way OFFSET does (no scan-and-discard
          // of rows already read). Works for both a single-column key and a
          // composite key (e.g. wp_term_relationships' (object_id,
          // term_taxonomy_id)) via MySQL's row-constructor comparison, which
          // is lexicographic and matches an index on the same column order.
          //
          // The table and primary-key column names are bound through
          // prepare()'s %i identifier placeholder, and the cursor values
          // through %s, so nothing is concatenated into the statement.
          $column_placeholders = implode(', ', array_fill(0, count($pk_columns), '%i'));
          $order_by = implode(', ', array_fill(0, count($pk_columns), '%i ASC'));
          $value_placeholders = implode(', ', array_fill(0, count($pk_columns), '%s'));
          $tuple = count($pk_columns) > 1 ? '(' . $column_placeholders . ')' : $column_placeholders;
          $value_tuple = count($pk_columns) > 1 ? '(' . $value_placeholders . ')' : $value_placeholders;

          $last_seen = null; // null, or one value per column in $pk_columns, same order.
          do {
            self::throw_if_backup_cancelled($job_id);
            self::maybe_touch_backup_job($job_id, __('Exporting database...', 'restorepilot-backup-migration'), $database_progress, [
              'phase' => 'database',
              'phase_label' => $database_label,
            ]);
            $wpdb->last_error = '';
            if ($last_seen === null) {
              // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $order_by is a generated list of literal "%i ASC" placeholders; all identifiers and values are bound below.
              $sql = $wpdb->prepare(
                "SELECT * FROM %i ORDER BY {$order_by} LIMIT %d",
                array_merge([$table], $pk_columns, [$limit])
              );
            } else {
              // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $tuple/$value_tuple/$order_by are generated lists of literal %i and %s placeholders; all identifiers and values are bound below.
              $sql = $wpdb->prepare(
                "SELECT * FROM %i WHERE {$tuple} > {$value_tuple} ORDER BY {$order_by} LIMIT %d",
                array_merge([$table], $pk_columns, $last_seen, $pk_columns, [$limit])
              );
            }
            $batch = $wpdb->get_results($sql, ARRAY_A);
            self::throw_on_db_error('export table rows');

            foreach ($batch as $row) {
              $emit('{"t":"row","d":' . self::json_fragment($row, 'encode table row') . '}');
              $last_seen = array_map(static function ($c) use ($row) {
                return $row[$c] ?? null;
              }, $pk_columns);
            }
          } while (is_array($batch) && count($batch) === $limit);
        } else {
          // Neither a primary key nor a UNIQUE NOT NULL key — keyset
          // pagination needs at least one strictly-ordered, unique cursor, so
          // this table falls back to OFFSET-based export. That re-scans every
          // preceding row on each batch, so it is markedly slower on a large
          // table, and it remains subject to the same concurrent-write caveat
          // that keyset pagination exists to avoid for every other table. A
          // real WordPress core table always has a primary key; this path only
          // exists for a third-party table sharing this site's prefix.
          self::write_log('No usable key for ' . $table . '; exporting with offset-based pagination (slower on large tables).');
          $offset = 0;
          do {
            self::throw_if_backup_cancelled($job_id);
            self::maybe_touch_backup_job($job_id, __('Exporting database...', 'restorepilot-backup-migration'), $database_progress, [
              'phase' => 'database',
              'phase_label' => $database_label,
            ]);
            $wpdb->last_error = '';
            $batch = $wpdb->get_results(
              $wpdb->prepare(
                'SELECT * FROM %i LIMIT %d OFFSET %d',
                $table,
                $limit,
                $offset
              ),
              ARRAY_A
            );
            self::throw_on_db_error('export table rows');

            foreach ($batch as $row) {
              $emit('{"t":"row","d":' . self::json_fragment($row, 'encode table row') . '}');
            }

            $offset += $limit;
          } while (is_array($batch) && count($batch) === $limit);
        }
      }

      if ($first_table) {
        throw new RuntimeException(__('No WordPress database tables were found for this site prefix.', 'restorepilot-backup-migration'));
      }

      if ($non_transactional_tables) {
        self::write_log('Export snapshot consistency does not cover non-InnoDB table(s): ' . implode(', ', $non_transactional_tables) . '. These were still exported, but without the same-moment guarantee InnoDB tables have.');
      }
    } catch (Throwable $e) {
      if ($handle !== null) {
        fclose($handle);
        $handle = null;
      }
      foreach ($parts as $part) {
        @unlink($part);
      }
      throw $e;
    } finally {
      // Read-only transaction: COMMIT and ROLLBACK are equivalent here.
      // Always close it, success or failure, so it never lingers past this
      // function even if an exception was thrown above.
      $wpdb->query('COMMIT');
    }

    if ($handle !== null) {
      fclose($handle);
    }

    return ['parts' => $parts, 'table_count' => $table_count];
  }

  private static function add_selected_paths_to_zip(RestorePilot_Backup_Volume_Writer $zip, array $selected_paths, string $job_id = ''): void {
    $base = rtrim(self::content_dir(), '/\\');
    foreach ($selected_paths as $relative) {
      self::throw_if_backup_cancelled($job_id);
      self::throw_if_chunk_time_exceeded();
      $relative = trim(str_replace('\\', '/', (string) $relative), '/');
      if ($relative === '' || self::path_is_unsafe($relative)) {
        continue;
      }

      $path = $base . '/' . $relative;
      if (!file_exists($path)) {
        /* translators: %s: selected file or directory path relative to wp-content */
        throw new RuntimeException(sprintf(__('Selected backup path no longer exists: %s', 'restorepilot-backup-migration'), $relative));
      }
      if (self::should_skip_file($relative, $path)) {
        continue;
      }

      $zip_name = 'files/wp-content/' . $relative;
      if (is_dir($path)) {
        // A directory's own empty-dir marker may already be in the zip from
        // an earlier chunk, but that says nothing about its contents — always
        // recurse regardless, so every child still gets its own has_entry()
        // check. The directory walk itself is cheap; only the file reads and
        // writes inside it are worth skipping.
        if (!$zip->has_entry($zip_name)) {
          if ($zip->addEmptyDir($zip_name) === false) {
            /* translators: %s: directory path relative to wp-content */
            throw new RuntimeException(sprintf(__('Could not add directory to backup: %s', 'restorepilot-backup-migration'), $relative));
          }
          self::$chunk_progress_made = true;
        }
        self::add_directory_to_zip($zip, $path, $zip_name, $job_id, $relative);
      } elseif (is_file($path)) {
        if ($zip->has_entry($zip_name)) {
          continue;
        }
        self::add_file_to_zip($zip, $path, $zip_name, $relative, $job_id);
      }
    }
  }

  private static function add_directory_to_zip(RestorePilot_Backup_Volume_Writer $zip, string $dir, string $zip_prefix, string $job_id = '', string $content_relative_prefix = ''): void {
    $dir = rtrim($dir, '/\\');
    $content_relative_prefix = trim(str_replace('\\', '/', $content_relative_prefix), '/');
    $iterator = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::SELF_FIRST
    );

    $count = 0;
    foreach ($iterator as $file) {
      $count++;
      if ($count % 25 === 0) {
        self::throw_if_backup_cancelled($job_id);
        self::throw_if_chunk_time_exceeded();
      }

      $path = $file->getPathname();
      $relative = ltrim(str_replace('\\', '/', substr($path, strlen($dir))), '/');
      $skip_relative = self::join_relative_path($content_relative_prefix, $relative);

      if (self::should_skip_file($skip_relative, $path)) {
        continue;
      }

      $zip_name = $zip_prefix . '/' . str_replace('\\', '/', $relative);
      // The iterator itself keeps descending into a directory regardless of
      // whether its own entry is skipped here, so this one check correctly
      // covers both an already-added directory (its children still get
      // visited next) and an already-added file (nothing left to do).
      if ($zip->has_entry($zip_name)) {
        continue;
      }
      if ($file->isDir()) {
        if ($zip->addEmptyDir($zip_name) === false) {
          /* translators: %s: directory path relative to wp-content */
          throw new RuntimeException(sprintf(__('Could not add directory to backup: %s', 'restorepilot-backup-migration'), $relative));
        }
        self::$chunk_progress_made = true;
      } elseif ($file->isFile()) {
        self::add_file_to_zip($zip, $path, $zip_name, $skip_relative, $job_id);
      }
    }
  }

  private static function add_file_to_zip(RestorePilot_Backup_Volume_Writer $zip, string $path, string $zip_name, string $relative, string $job_id = ''): void {
    if (!is_readable($path)) {
      /* translators: %s: file path relative to wp-content */
      throw new RuntimeException(sprintf(__('Could not read file for backup: %s', 'restorepilot-backup-migration'), $relative));
    }
    $size = filesize($path);
    $recorded_bytes = 0;
    $progress = null;
    if ($job_id !== '') {
      $progress = function (int $chunk_bytes) use ($job_id, &$recorded_bytes): void {
        self::throw_if_backup_cancelled($job_id);
        $recorded_bytes += max(0, $chunk_bytes);
        self::record_file_scan_progress($job_id, max(0, $chunk_bytes), false);
      };
    }

    if ($zip->addFile($path, $zip_name, $progress) === false) {
      /* translators: %s: file path relative to wp-content */
      throw new RuntimeException(sprintf(__('Could not add file to backup: %s', 'restorepilot-backup-migration'), $relative));
    }
    self::$chunk_progress_made = true;
    $remaining_bytes = $size === false ? 0 : max(0, (int) $size - $recorded_bytes);
    self::record_file_scan_progress($job_id, $remaining_bytes, true);
  }

  private static function reset_file_scan_progress(string $job_id): void {
    if ($job_id === '') {
      return;
    }

    // A fresh PHP process always starts this static array empty, including
    // one that is actually a resumption continuing a file collection an
    // earlier chunk left partway done — so the counters pick up from the
    // job's own last persisted values instead of visibly resetting to zero.
    $job = self::get_backup_job($job_id);
    self::$file_scan_progress[$job_id] = [
      'files' => (int) ($job['files_scanned'] ?? 0),
      'bytes' => (int) ($job['bytes_scanned'] ?? 0),
    ];
  }

  private static function record_file_scan_progress(string $job_id, int $bytes, bool $count_file = true): void {
    if ($job_id === '') {
      return;
    }

    if (!isset(self::$file_scan_progress[$job_id])) {
      self::reset_file_scan_progress($job_id);
    }

    if ($count_file) {
      self::$file_scan_progress[$job_id]['files']++;
    }
    self::$file_scan_progress[$job_id]['bytes'] += max(0, $bytes);
    self::flush_file_scan_progress($job_id, false);
  }

  private static function flush_file_scan_progress(string $job_id, bool $force = true): void {
    if ($job_id === '' || !isset(self::$file_scan_progress[$job_id])) {
      return;
    }

    $files = (int) self::$file_scan_progress[$job_id]['files'];
    $bytes = (int) self::$file_scan_progress[$job_id]['bytes'];
    $progress = 55;
    $job = self::get_backup_job($job_id);
    $estimated_content = is_array($job) ? (int) ($job['estimated_content_bytes'] ?? 0) : 0;
    if ($estimated_content > 0) {
      $progress = min(80, 55 + (int) floor((min($bytes, $estimated_content) / $estimated_content) * 25));
    }

    self::maybe_touch_backup_job(
      $job_id,
      sprintf(
        /* translators: 1: number of files collected so far, 2: total size scanned so far */
        __('Collecting files... %1$s files, %2$s scanned.', 'restorepilot-backup-migration'),
        number_format_i18n($files),
        size_format($bytes)
      ),
      $progress,
      [
        'phase' => 'files',
        'phase_label' => self::backup_phase_label('files'),
        'files_scanned' => $files,
        'bytes_scanned' => $bytes,
      ],
      $force
    );
  }

  private static function assert_backup_disk_space(bool $include_files, array $selected_paths = [], bool $selection_enabled = false): array {
    $database_estimate = self::estimate_database_size();
    $content_estimate = 0;
    if ($include_files) {
      $content_estimate = $selection_enabled ? self::estimate_selected_paths_size($selected_paths) : self::estimate_directory_size(self::content_dir());
    }

    $estimate = [
      'database' => $database_estimate,
      'content' => $content_estimate,
    ];

    $free = @disk_free_space(self::storage_dir());
    if ($free === false) {
      return $estimate;
    }

    $needed = ($database_estimate * 2) + $content_estimate;
    $needed = (int) min(PHP_INT_MAX, max(100 * 1024 * 1024, $needed * 1.35));
    if ($free < $needed) {
      throw new RuntimeException(sprintf(
        /* translators: 1: free disk space, 2: estimated space needed */
        __('Not enough free disk space for a safe backup. Available: %1$s. Estimated needed: %2$s.', 'restorepilot-backup-migration'),
        size_format((int) $free),
        size_format($needed)
      ));
    }

    return $estimate;
  }

  private static function estimate_database_size(): int {
    $wpdb = self::wpdb();
    $db = DB_NAME;
    $wpdb->last_error = '';
    $size = (int) $wpdb->get_var($wpdb->prepare(
      'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE table_schema = %s',
      $db
    ));
    if (!empty($wpdb->last_error)) {
      $wpdb->last_error = '';
      return 20 * 1024 * 1024;
    }

    return max($size, 20 * 1024 * 1024);
  }

  private static function estimate_directory_size(string $dir, string $content_relative_prefix = ''): int {
    $total = 0;
    $dir = rtrim($dir, '/\\');
    $content_relative_prefix = trim(str_replace('\\', '/', $content_relative_prefix), '/');
    if (!is_dir($dir)) {
      return 0;
    }

    $iterator = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
      $path = $file->getPathname();
      $relative = ltrim(str_replace('\\', '/', substr($path, strlen($dir))), '/');
      $skip_relative = self::join_relative_path($content_relative_prefix, $relative);
      if ($file->isFile() && !self::should_skip_file($skip_relative, $path)) {
        $total += (int) $file->getSize();
      }
    }

    return $total;
  }

  private static function estimate_selected_paths_size(array $selected_paths): int {
    $total = 0;
    $base = rtrim(self::content_dir(), '/\\');
    foreach ($selected_paths as $relative) {
      $relative = trim(str_replace('\\', '/', (string) $relative), '/');
      if ($relative === '' || self::path_is_unsafe($relative)) {
        continue;
      }

      $path = $base . '/' . $relative;
      if (!file_exists($path) || self::should_skip_file($relative, $path)) {
        continue;
      }

      if (is_file($path)) {
        $total += (int) filesize($path);
      } elseif (is_dir($path)) {
        $total += self::estimate_directory_size($path, $relative);
      }
    }

    return $total;
  }

  private static function join_relative_path(string $prefix, string $relative): string {
    $prefix = trim(str_replace('\\', '/', $prefix), '/');
    $relative = trim(str_replace('\\', '/', $relative), '/');
    if ($prefix === '') {
      return $relative;
    }
    if ($relative === '') {
      return $prefix;
    }
    return $prefix . '/' . $relative;
  }

  private static function should_skip_file(string $relative, string $path): bool {
    // Build lookup tables once per PHP process and cache them statically.
    // The old approach rebuilt three arrays on every call and iterated all
    // patterns for every file — O(n×m) across a large backup.  Here:
    //   • exact basenames  → O(1) hash lookup
    //   • path-part rules  → bucketed by first path segment so most files only
    //                        check the globally-matchable subset (≈ half the list)
    //   • prefix rules     → tiny list, applied once
    //
    // Deliberately NOT included: skipping files by extension alone (e.g. any
    // .zip/.sql/.tar file anywhere in wp-content). That previously excluded
    // legitimate uploads, plugin/theme bundled archives, and customer content
    // from "full" backups with no record of the omission. Every rule below is
    // scoped to a specific known backup-tool storage location or filename, so
    // a match here means "this is that tool's own archive/cache", not "this
    // file happens to end in .zip".
    static $rules = null;
    if ($rules === null) {
      $rules = self::compile_skip_rules();
    }

    $relative = trim(str_replace('\\', '/', $relative), '/');
    if ($relative === '') {
      return false;
    }

    $lower_base = strtolower(basename($relative));

    // 1. Exact basename — O(1)
    if (isset($rules['basenames'][$lower_base])) {
      self::record_backup_exclusion('system file: ' . $lower_base);
      return true;
    }

    // 2. Temp-file marker (single substring check)
    if (strpos($relative, '.restorepilot-tmp-') !== false) {
      self::record_backup_exclusion('RestorePilot temporary file');
      return true;
    }

    // 3. Prefix rules.
    //    Segmented prefixes already contain 'uploads/' so an interior check is
    //    safe.  Bare prefixes (e.g. 'backwpup-') are matched at path-start ONLY
    //    — an interior match would incorrectly skip plugins whose folder names
    //    begin with the same string (e.g. plugins/backwpup-something/).
    foreach ($rules['prefixes_seg'] as $prefix) {
      if (strpos($relative, $prefix) === 0 || strpos($relative, '/' . $prefix) !== false) {
        self::record_backup_exclusion('backup storage: ' . $prefix . '*');
        return true;
      }
    }
    foreach ($rules['prefixes_bare'] as $prefix) {
      if (strpos($relative, $prefix) === 0) {
        self::record_backup_exclusion('backup storage: ' . $prefix . '*');
        return true;
      }
    }

    // 4. Path-part rules.
    //    Segmented parts (e.g. 'uploads/updraftplus') already carry a path
    //    prefix that makes interior matches safe.
    //    Global bare-name parts (e.g. 'updraftplus') are matched at the TOP
    //    LEVEL of wp-content ONLY — never via an interior segment check —
    //    because interior matching would incorrectly exclude installed plugins
    //    and themes whose directory names match a backup-storage folder name,
    //    e.g. plugins/updraftplus/, plugins/duplicator/, plugins/wp-staging/.
    $slash     = strpos($relative, '/');
    $first_seg = $slash !== false ? substr($relative, 0, $slash) : $relative;

    foreach ($rules['parts_by_seg'][$first_seg] ?? [] as $part) {
      if (self::path_matches_skip_part($relative, $part)) {
        self::record_backup_exclusion('backup storage: ' . $part);
        return true;
      }
    }
    foreach ($rules['parts_global'] as $part) {
      if (self::path_starts_with_part($relative, $part)) {
        self::record_backup_exclusion('backup storage: ' . $part);
        return true;
      }
    }

    if (is_link($path)) {
      self::record_backup_exclusion('symbolic link');
      return true;
    }

    return false;
  }

  private static function reset_backup_exclusion_tracking(): void {
    self::$backup_exclusion_labels = [];
  }

  private static function record_backup_exclusion(string $label): void {
    self::$backup_exclusion_labels[$label] = true;
  }

  private static function backup_exclusion_labels(): array {
    return array_keys(self::$backup_exclusion_labels);
  }

  /**
   * Returns true when $relative matches a skip-part rule using a full check:
   * exact path, starts-with, interior segment, or ends-with.
   * Only use this for parts that already carry a specific prefix (e.g.
   * 'uploads/updraftplus') where interior matches are safe.
   */
  private static function path_matches_skip_part(string $relative, string $part): bool {
    return $relative === $part
      || strpos($relative, $part . '/') === 0
      || strpos($relative, '/' . $part . '/') !== false
      || substr($relative, -strlen('/' . $part)) === '/' . $part;
  }

  /**
   * Returns true when $relative is exactly $part or starts with "$part/".
   * Use this for bare directory names (e.g. 'updraftplus') to avoid matching
   * installed plugins or themes that share the same name, such as
   * plugins/updraftplus/ or plugins/duplicator/.
   */
  private static function path_starts_with_part(string $relative, string $part): bool {
    return $relative === $part || strpos($relative, $part . '/') === 0;
  }

  /**
   * Pre-compile all skip rules into lookup structures used by should_skip_file().
   * Called exactly once and cached via a static variable.
   */
  private static function compile_skip_rules(): array {
    // Exact basenames (lowercased for case-insensitive match).
    $basenames = ['debug.log' => true, '.ds_store' => true, 'thumbs.db' => true];

    // Deliberately no extension-based rule here (e.g. skip every *.zip/*.sql
    // file). That would exclude legitimate uploads, plugin/theme bundled
    // archives, and customer content anywhere in wp-content just because of
    // their file extension. Other backup tools' archives are excluded only
    // by the path-specific prefix/part rules below, which target their known
    // storage locations, not their file type.

    // Prefix rules split into two groups:
    //   prefixes_seg  — already contain 'uploads/', so an interior check is safe.
    //   prefixes_bare — bare names; matched at path-start ONLY to avoid hitting
    //                   plugins/themes whose folder names share the same prefix.
    $prefixes_seg  = ['uploads/backwpup-', 'uploads/wpvivid-'];
    $prefixes_bare = ['backwpup-', 'wpvivid-'];

    // Path-part rules, split into two groups:
    //   'segmented' — carry 'uploads/' prefix; interior matching is safe.
    //   'global'    — bare directory names; matched at wp-content top-level ONLY
    //                 (see path_starts_with_part) to avoid skipping installed
    //                 plugins/themes that share a name with a backup folder.
    $segmented_parts = [
      'uploads' => [
        'uploads/restorepilot-backup-migration',
        'uploads/restorepilot-direct-downloads',
        'uploads/mc-site-vault',
        'uploads/updraft',
        'uploads/updraftplus',
        'uploads/backupbuddy_backups',
        'uploads/backupbuddy_temp',
        'uploads/ithemes-security/backups',
        'uploads/ai1wm-backups',
        'uploads/wpvividbackups',
        'uploads/wpvivid_backup',
        'uploads/duplicator',
        'uploads/duplicator-pro',
        'uploads/wp-staging',
        'uploads/boldgrid_backup',
        'uploads/wp-migration-backup',
        'uploads/xcloner-backup-and-restore',
      ],
    ];

    $global_parts = [
      'restorepilot-backup-migration',
      'restorepilot-direct-downloads',
      'mc-site-vault',
      'updraft',
      'updraftplus',
      'backupbuddy_backups',
      'backupbuddy_temp',
      'ithemes-security/backups',
      'ai1wm-backups',
      'wpvividbackups',
      'wpvivid_backup',
      'duplicator',
      'duplicator-pro',
      'wp-staging',
      'boldgrid_backup',
      'wp-migration-backup',
      'xcloner-backup-and-restore',
      'cache',
      'upgrade',
      'upgrade-temp-backup',
    ];

    return [
      'basenames'     => $basenames,
      'prefixes_seg'  => $prefixes_seg,
      'prefixes_bare' => $prefixes_bare,
      'parts_by_seg'  => $segmented_parts,
      'parts_global'  => $global_parts,
    ];
  }

  private static function list_backup_file_items(): array {
    if (!defined('WP_CONTENT_DIR')) {
      return [];
    }

    $base = rtrim(self::content_dir(), '/\\');
    if (!is_dir($base) || !is_readable($base)) {
      return [];
    }

    $entries = @scandir($base);
    if (!is_array($entries)) {
      return [];
    }

    $items = [];
    foreach ($entries as $entry) {
      if ($entry === '.' || $entry === '..') {
        continue;
      }

      $relative = trim(str_replace('\\', '/', (string) $entry), '/');
      if ($relative === '' || strpos($relative, '/') !== false || strpos($relative, ':') !== false || self::path_is_unsafe($relative)) {
        continue;
      }

      $path = $base . '/' . $relative;
      if (!file_exists($path) || is_link($path) || !is_readable($path) || self::should_skip_file($relative, $path)) {
        continue;
      }

      $is_dir = is_dir($path);
      $items[] = [
        'path' => $relative,
        'label' => $relative . ($is_dir ? '/' : ''),
        'is_dir' => $is_dir,
      ];
    }

    usort($items, function ($a, $b) {
      if ($a['is_dir'] !== $b['is_dir']) {
        return $a['is_dir'] ? -1 : 1;
      }
      return strnatcasecmp($a['path'], $b['path']);
    });

    return $items;
  }

  private static function selected_backup_paths_from_request(): array {
    $paths = self::post_array('backup_paths');
    return self::sanitize_selected_backup_paths($paths);
  }

  private static function sanitize_selected_backup_paths($paths): array {
    if (!is_array($paths)) {
      $paths = [$paths];
    }

    $allowed = [];
    foreach (self::list_backup_file_items() as $item) {
      $allowed[(string) $item['path']] = true;
    }

    $selected = [];
    foreach ($paths as $path) {
      $path = sanitize_text_field((string) $path);
      $path = trim(str_replace('\\', '/', $path), '/');
      $path = (string) preg_replace('#/+#', '/', $path);

      if ($path === '' || strpos($path, ':') !== false || self::path_is_unsafe($path) || !isset($allowed[$path])) {
        continue;
      }

      $selected[$path] = $path;
    }

    return array_values($selected);
  }

  private static function create_backup_parts(string $file): void {
    self::delete_backup_parts(basename($file));

    $input = fopen($file, 'rb');
    if ($input === false) {
      throw new RuntimeException(__('Could not open backup file for safe downloads.', 'restorepilot-backup-migration'));
    }

    try {
      $part_number = 1;
      while (!feof($input)) {
        $part_name = basename($file) . '.part' . str_pad((string) $part_number, 3, '0', STR_PAD_LEFT);
        $part_path = self::backup_dir() . '/' . $part_name;
        $output = fopen($part_path, 'wb');
        if ($output === false) {
          throw new RuntimeException(__('Could not create safe download file.', 'restorepilot-backup-migration'));
        }

        $written = 0;
        while (!feof($input) && $written < self::PART_SIZE) {
          $remaining = self::PART_SIZE - $written;
          $chunk = fread($input, (int) min(1024 * 1024, $remaining));
          if ($chunk === false) {
            throw new RuntimeException(__('Could not read backup while creating safe download files.', 'restorepilot-backup-migration'));
          }
          if ($chunk === '') {
            break;
          }
          $written += strlen($chunk);
          self::write_stream($output, $part_path, $chunk, 'write safe download file');
        }

        fclose($output);

        if ($written < 1) {
          @unlink($part_path);
        }

        $part_number++;
      }
    } catch (Throwable $e) {
      if (isset($output) && is_resource($output)) {
        fclose($output);
      }
      fclose($input);
      self::delete_backup_parts(basename($file));
      throw $e;
    }

    fclose($input);
  }

  private static function sync_scheduled_backup(): void {
    if (!function_exists('wp_next_scheduled')) {
      return;
    }

    // Multisite is never allowed to have a daily event scheduled, even if the
    // stored setting says otherwise — for example a site that had daily
    // backups enabled before it was added to a network. This check is
    // unconditional so a stale setting can never re-schedule it.
    if (is_multisite()) {
      if (wp_next_scheduled('restorepilot_scheduled_backup')) {
        wp_clear_scheduled_hook('restorepilot_scheduled_backup');
        self::write_log('Scheduled backup disabled: multisite is not supported.');
      }
      return;
    }

    $settings = self::get_settings();
    $next     = wp_next_scheduled('restorepilot_scheduled_backup');

    if (empty($settings['scheduled_enabled'])) {
      if ($next) {
        wp_clear_scheduled_hook('restorepilot_scheduled_backup');
        self::write_log('Scheduled backup disabled.');
      }
      return;
    }

    $target_hour   = (int) $settings['scheduled_hour'];
    $target_minute = (int) $settings['scheduled_minute'];

    // If already scheduled, check whether the saved time still matches.
    // If it does, leave the existing event alone so we don't reset the clock.
    if ($next) {
      $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
      $scheduled_dt = (new DateTime('@' . $next))->setTimezone($tz);
      if ((int) $scheduled_dt->format('G') === $target_hour
          && (int) $scheduled_dt->format('i') === $target_minute) {
        return; // already at the right time, nothing to do
      }
      // Time changed — reschedule
      wp_clear_scheduled_hook('restorepilot_scheduled_backup');
    }

    // Calculate the next UTC timestamp for target_hour:target_minute in the
    // site's local timezone (requires WordPress 5.3+; plugin requires 6.2+).
    $tz   = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    $now  = new DateTime('now', $tz);
    $fire = clone $now;
    $fire->setTime($target_hour, $target_minute, 0);

    if ($fire <= $now) {
      $fire->modify('+1 day');
    }

    // getTimestamp() always returns a UTC-based Unix timestamp
    wp_schedule_event($fire->getTimestamp(), 'daily', 'restorepilot_scheduled_backup');
    self::write_log(sprintf(
      'Scheduled daily backup enabled at %02d:%02d site time (%s).',
      $target_hour,
      $target_minute,
      $tz->getName()
    ));
  }

  private static function maybe_send_backup_email(string $status, string $message, string $file = ''): void {
    $settings = self::get_settings();
    if (empty($settings['email_notifications']) || empty($settings['notify_email']) || !function_exists('wp_mail')) {
      return;
    }

    $site_host  = wp_parse_url(home_url(), PHP_URL_HOST) ?: home_url();
    $site_url   = home_url();
    $admin_url  = admin_url('admin.php?page=' . self::SLUG);
    $date_str   = wp_date(get_option('date_format') . ' ' . get_option('time_format'));

    $subject = sprintf(
      /* translators: %1$s: backup status (success / failed / skipped), %2$s: site domain */
      __('RestorePilot backup %1$s for %2$s', 'restorepilot-backup-migration'),
      $status,
      $site_host
    );

    // Status-specific colours and labels.
    if ($status === 'success') {
      $accent     = '#1a7f37';
      $badge_bg   = '#d4edda';
      $badge_text = '#155724';
      $badge_label = __('Backup Successful', 'restorepilot-backup-migration');
      $icon       = '&#10003;'; // ✓
    } elseif ($status === 'failed') {
      $accent     = '#c0392b';
      $badge_bg   = '#f8d7da';
      $badge_text = '#721c24';
      $badge_label = __('Backup Failed', 'restorepilot-backup-migration');
      $icon       = '&#10007;'; // ✗
    } else {
      $accent     = '#856404';
      $badge_bg   = '#fff3cd';
      $badge_text = '#856404';
      $badge_label = __('Backup Skipped', 'restorepilot-backup-migration');
      $icon       = '&#8212;'; // —
    }

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen-Sans,Ubuntu,Cantarell,'Helvetica Neue',sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f0f1;padding:32px 16px;">
  <tr><td align="center">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">

      <!-- Header -->
      <tr><td style="background:#1d2327;border-radius:8px 8px 0 0;padding:24px 32px;">
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td style="vertical-align:middle;">
              <!-- Shield SVG logo -->
              <svg width="36" height="36" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="display:inline-block;vertical-align:middle;margin-right:10px;">
                <path d="M12 2L4 5.5V11C4 15.55 7.41 19.74 12 21C16.59 19.74 20 15.55 20 11V5.5L12 2Z" fill="#72aee6"/>
                <path d="M10.5 14.5L7.5 11.5L8.56 10.44L10.5 12.38L15.44 7.44L16.5 8.5L10.5 14.5Z" fill="#fff"/>
              </svg>
              <span style="color:#fff;font-size:20px;font-weight:700;vertical-align:middle;letter-spacing:-0.3px;">RestorePilot</span>
            </td>
            <td align="right" style="vertical-align:middle;">
              <span style="color:#a7aaad;font-size:12px;"><?php echo esc_html($site_host); ?></span>
            </td>
          </tr>
        </table>
      </td></tr>

      <!-- Status banner -->
      <tr><td style="background:<?php echo esc_attr($accent); ?>;padding:20px 32px;">
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td>
              <span style="display:inline-block;background:rgba(255,255,255,0.2);color:#fff;font-size:13px;font-weight:600;padding:4px 12px;border-radius:20px;margin-bottom:8px;"><?php echo esc_html($icon . ' ' . $badge_label); ?></span>
              <div style="color:#fff;font-size:22px;font-weight:700;line-height:1.3;"><?php echo esc_html($message); ?></div>
            </td>
          </tr>
        </table>
      </td></tr>

      <!-- Details card -->
      <tr><td style="background:#fff;padding:28px 32px;">
        <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #dcdcde;border-radius:6px;overflow:hidden;">
          <?php if ($file !== ''): ?>
          <tr>
            <td style="padding:12px 16px;border-bottom:1px solid #f0f0f1;background:#f6f7f7;font-size:11px;font-weight:600;color:#646970;text-transform:uppercase;letter-spacing:.05em;width:38%;"><?php echo esc_html__('Backup file', 'restorepilot-backup-migration'); ?></td>
            <td style="padding:12px 16px;border-bottom:1px solid #f0f0f1;font-size:13px;color:#1d2327;word-break:break-all;"><?php echo esc_html(basename($file)); ?></td>
          </tr>
          <?php endif; ?>
          <tr>
            <td style="padding:12px 16px;border-bottom:1px solid #f0f0f1;background:#f6f7f7;font-size:11px;font-weight:600;color:#646970;text-transform:uppercase;letter-spacing:.05em;"><?php echo esc_html__('Date', 'restorepilot-backup-migration'); ?></td>
            <td style="padding:12px 16px;border-bottom:1px solid #f0f0f1;font-size:13px;color:#1d2327;"><?php echo esc_html($date_str); ?></td>
          </tr>
          <tr>
            <td style="padding:12px 16px;background:#f6f7f7;font-size:11px;font-weight:600;color:#646970;text-transform:uppercase;letter-spacing:.05em;"><?php echo esc_html__('Site', 'restorepilot-backup-migration'); ?></td>
            <td style="padding:12px 16px;font-size:13px;"><a href="<?php echo esc_url($site_url); ?>" style="color:#2271b1;text-decoration:none;"><?php echo esc_html($site_url); ?></a></td>
          </tr>
        </table>

        <div style="margin-top:24px;text-align:center;">
          <a href="<?php echo esc_url($admin_url); ?>" style="display:inline-block;background:#2271b1;color:#fff;font-size:13px;font-weight:600;text-decoration:none;padding:10px 22px;border-radius:4px;"><?php echo esc_html__('View Backups', 'restorepilot-backup-migration'); ?></a>
        </div>
      </td></tr>

      <!-- Footer -->
      <tr><td style="background:#f6f7f7;border:1px solid #dcdcde;border-top:none;border-radius:0 0 8px 8px;padding:16px 32px;text-align:center;">
        <p style="margin:0;font-size:12px;color:#646970;"><?php echo esc_html__('You are receiving this email because backup notifications are enabled in RestorePilot.', 'restorepilot-backup-migration'); ?></p>
        <p style="margin:6px 0 0;font-size:12px;color:#a7aaad;"><?php echo esc_html__('RestorePilot Backup & Migration', 'restorepilot-backup-migration'); ?></p>
      </td></tr>

    </table>
  </td></tr>
</table>
</body>
</html>
    <?php
    $html = ob_get_clean();

    $headers = ['Content-Type: text/html; charset=UTF-8'];
    wp_mail($settings['notify_email'], $subject, $html, $headers);
  }

  /**
   * Where the bar should sit while the database phase is $done tables into
   * $total, interpolated across the span this phase owns.
   *
   * The surrounding phases report fixed figures — preparing 18, database 30,
   * files 55, finalizing 95 — so the database phase occupies 30 up to 55.
   * Without interpolation the bar stops dead at 30 for the phase's entire
   * duration, which on a table-heavy site is minutes, and a bar that does not
   * move is indistinguishable from a backup that has died.
   */
  private static function database_phase_progress(int $done, int $total): int {
    $floor = 30;
    $ceiling = 55;
    if ($total < 1) {
      return $floor;
    }
    $ratio = max(0.0, min(1.0, $done / $total));
    // Stops one short of the ceiling: 55 is the files phase's own figure, and
    // reaching it here would announce a phase that has not started.
    return min($ceiling - 1, $floor + (int) floor($ratio * ($ceiling - $floor)));
  }

  /**
   * "Exporting database (table 47 of 149)" — the count is the point. A phase
   * that names only itself still looks stuck when one table takes a minute;
   * a position that keeps climbing shows the work is real.
   */
  private static function database_phase_label(int $position, int $total): string {
    if ($total < 1) {
      return self::backup_phase_label('database');
    }

    return sprintf(
      /* translators: 1: number of the table being exported, 2: total tables to export */
      __('Exporting database (table %1$d of %2$d)', 'restorepilot-backup-migration'),
      $position,
      $total
    );
  }

  private static function backup_phase_label(string $phase): string {
    $labels = [
      'queued' => __('Queued', 'restorepilot-backup-migration'),
      'starting' => __('Starting backup', 'restorepilot-backup-migration'),
      'preparing' => __('Preparing backup', 'restorepilot-backup-migration'),
      'database' => __('Exporting database', 'restorepilot-backup-migration'),
      'files' => __('Collecting files', 'restorepilot-backup-migration'),
      'finalizing' => __('Finalizing zip', 'restorepilot-backup-migration'),
      'complete' => __('Complete', 'restorepilot-backup-migration'),
      'canceled' => __('Canceled', 'restorepilot-backup-migration'),
      'error' => __('Error', 'restorepilot-backup-migration'),
      'stale' => __('Needs attention', 'restorepilot-backup-migration'),
    ];

    return $labels[$phase] ?? __('Working', 'restorepilot-backup-migration');
  }
}
