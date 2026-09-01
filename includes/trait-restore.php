<?php
/**
 * Restoring a backup: the chunked job, the plan, and the safety net around it.
 *
 * @package RestorePilot_Backup_Migration
 */

if (!defined('ABSPATH')) {
  exit;
}

trait RestorePilot_Restore {
  private static function perform_restore(string $restore_zip_path, bool $auto_detect_urls, bool $restore_files, string $job_id = '', string $manual_source_url = '', string $manual_target_url = '', bool $create_new_admin = false, string $new_admin_email = ''): array {
    self::assert_multisite_unsupported();
    self::prepare_for_long_operation();

    // See create_backup_package() for the identical reasoning. Only a real
    // async job (job_id !== '') can ever have a checkpoint to resume from —
    // there is no other restore caller — but the guard is kept anyway for
    // the same defense-in-depth reason it exists on the backup side.
    $job = $job_id !== '' ? self::get_restore_job($job_id) : [];
    $checkpoint = is_array($job['checkpoint'] ?? null) ? $job['checkpoint'] : null;
    $resuming = $checkpoint !== null;

    self::$restore_chunk_deadline = $job_id !== ''
      ? microtime(true) + (float) apply_filters('restorepilot_restore_chunk_seconds', self::RESTORE_CHUNK_SECONDS)
      : 0.0;
    self::$restore_chunk_progress_made = false;

    self::write_log($resuming ? 'Restore resumed.' : 'Restore started.');

    $zip = null;
    $restore_lock_token = '';
    $maintenance_enabled = false;

    try {
      if ($resuming) {
        $restore_lock_token = (string) ($checkpoint['lock_token'] ?? '');
        if ($restore_lock_token === '') {
          throw new RuntimeException(__('Restore checkpoint is missing its lock token; the restore cannot be safely resumed.', 'restorepilot-backup-migration'));
        }
        $restore_zip_path = (string) $checkpoint['restore_zip_path'];
      } else {
        $restore_lock_token = self::acquire_restore_lock($job_id);
      }

      self::maybe_touch_restore_job($job_id, __('Validating backup...', 'restorepilot-backup-migration'), 12, [
        'phase' => 'validating',
        'phase_label' => self::restore_phase_label('validating'),
      ], true);

      // Opens every volume of the set and refuses up front if any are
      // missing, so a partial set can never produce a partial restore.
      // Redone on every resumption — read-only and cheap relative to the
      // database/file phases below, which is why only those two carry a
      // checkpoint at all.
      $zip = self::open_backup_archive($restore_zip_path);
      if ($zip->volume_count() > 1) {
        self::write_log('Restoring from a ' . $zip->volume_count() . '-volume backup set.');
      }

      // Reject an absurd entry count before any per-entry loop touches this
      // archive at all — including the disk-space estimate below, which
      // would otherwise iterate every entry first. validate_backup_zip()
      // repeats this same check for its other callers (e.g. Backup Check),
      // which do not go through assert_restore_disk_space().
      self::assert_restore_zip_entry_count($zip);

      // Pass how far the files phase already got, so a resumption is only
      // required to have room for what it still has left to write rather
      // than for the whole archive over again — see the method's docblock.
      self::assert_restore_disk_space(
        $restore_zip_path,
        $zip,
        $resuming ? (int) ($checkpoint['files_index'] ?? 0) : 0
      );

      $validated = self::validate_backup_zip($zip, true, true);

      if ($resuming) {
        $manifest = (array) $checkpoint['manifest'];
        $backup_prefix = (string) $checkpoint['backup_prefix'];
        $restore_plan = (array) $checkpoint['restore_plan'];
        $source_url = (string) $checkpoint['source_url'];
        $target_url = (string) $checkpoint['target_url'];
        $files_needed = !empty($checkpoint['files_needed']);
      } else {
        $manifest = $validated['manifest'];
        self::assert_restore_preflight($zip, $restore_files && !empty($manifest['includes_files']));

        // The table prefix is read from the (untrusted) backup manifest and is used
        // to derive target table names during the restore. Validate it at this trust
        // boundary — a valid MySQL/WordPress prefix is limited to [A-Za-z0-9_]. This
        // makes prefix safety local here rather than relying only on the downstream
        // per-table [A-Za-z0-9_] whitelist in restore_database().
        // An empty prefix is rejected too: every RestorePilot backup records the
        // source prefix, so a missing one means a corrupted or foreign manifest.
        // Continuing without it would restore tables under their archive names
        // with no prefix mapping or validation.
        $backup_prefix = isset($manifest['table_prefix']) ? (string) $manifest['table_prefix'] : '';
        if ($backup_prefix === '' || !preg_match('/^[A-Za-z0-9_]+$/', $backup_prefix)) {
          throw new RuntimeException(__('Backup manifest is missing a valid database table prefix, so this archive cannot be restored safely.', 'restorepilot-backup-migration'));
        }
        // Reject a truncated, corrupted, or malformed archive BEFORE the rollback
        // point and maintenance mode below — not after. restore_database() below
        // executes exactly this validated plan; it does not re-derive or re-check
        // any of it. Built exactly once for the whole restore and checkpointed —
        // never rebuilt by a later resumption — because its restore_id fixes
        // every scratch table name for the rest of this restore's lifetime; see
        // restore_database()'s docblock.
        $restore_plan = self::build_restore_plan($zip, $manifest, $backup_prefix);

        if ($auto_detect_urls) {
          $source_url = self::validate_restore_url(self::normalize_url($manifest['home_url'] ?? ''), __('Source URL', 'restorepilot-backup-migration'), true);
          $target_url = self::validate_restore_url(self::normalize_url(home_url()), __('Target URL', 'restorepilot-backup-migration'));
        } else {
          $source_input = $manual_source_url !== '' ? $manual_source_url : self::post_value('source_url');
          $target_input = $manual_target_url !== '' ? $manual_target_url : self::post_value('target_url', home_url());
          $source_url = self::validate_restore_url(self::normalize_url($source_input), __('Source URL', 'restorepilot-backup-migration'), true);
          $target_url = self::validate_restore_url(self::normalize_url($target_input), __('Target URL', 'restorepilot-backup-migration'));
        }

        // Scratch names for this restore are fixed right here, once, and
        // reused unchanged by every later resumption (restore_plan is
        // checkpointed below, never rebuilt) — so sweeping and journaling
        // only ever happens this once. Sweeping again on a later resumption
        // would drop this restore's OWN in-progress tmp tables, not just a
        // genuinely stale attempt's — see restore_database()'s docblock.
        // Both scoped to this job: the sweep leaves alone anything belonging
        // to a restore that is still running, and the journal records these
        // tables against this restore rather than over whatever was there.
        self::sweep_stale_restore_tables(self::wpdb()->prefix, $job_id);
        self::journal_restore_scratch_tables($job_id, array_merge(
          array_column($restore_plan['plans'], 'tmp_table'),
          array_column($restore_plan['plans'], 'old_table_candidate')
        ));

        $files_needed = $restore_files && !empty($manifest['includes_files']);
      }

      // Identity fields: fixed once the plan is built, unchanged by anything
      // that happens afterward. Every checkpoint write below is this base
      // plus whatever progress fields that specific phase owns — never a
      // merge against get_restore_job()'s current return, which right after
      // the table swap further down is briefly not this restore's own
      // record at all (see purge_foreign_runtime_state()).
      $checkpoint_base = [
        'restore_zip_path' => $restore_zip_path,
        'manifest' => $manifest,
        'backup_prefix' => $backup_prefix,
        'restore_plan' => $restore_plan,
        'source_url' => $source_url,
        'target_url' => $target_url,
        'lock_token' => $restore_lock_token,
        'files_needed' => $files_needed,
        'resumption' => (int) ($checkpoint['resumption'] ?? 1),
      ];
      $rollback_created = $resuming && !empty($checkpoint['rollback_created']);
      $new_admin_created = $resuming && !empty($checkpoint['new_admin_created']);
      $database_done = $resuming && !empty($checkpoint['database_done']);
      $completed_tables = $resuming && is_array($checkpoint['completed_tables'] ?? null) ? $checkpoint['completed_tables'] : [];
      $files_done = $resuming ? !empty($checkpoint['files_done']) : !$files_needed;
      $files_index = $resuming ? (int) ($checkpoint['files_index'] ?? 0) : 0;

      if ($job_id !== '' && !$resuming) {
        self::update_restore_job($job_id, ['checkpoint' => array_merge($checkpoint_base, [
          'rollback_created' => false,
          'database_done' => false,
          'completed_tables' => [],
          'files_done' => $files_done,
          'files_index' => 0,
        ])]);
      }

      if (!$rollback_created) {
        self::maybe_touch_restore_job($job_id, __('Creating rollback point...', 'restorepilot-backup-migration'), 24, [
          'phase' => 'rollback',
          'phase_label' => self::restore_phase_label('rollback'),
        ], true);
        // Pass the archive being restored FROM so retention cannot evict it
        // — it may itself be a rollback point (recovering from a failed
        // restore), and the oldest one is both the likeliest to be chosen
        // and the first retention would remove.
        self::create_restore_rollback_point($restore_zip_path);
        $rollback_created = true;
        if ($job_id !== '') {
          self::update_restore_job($job_id, ['checkpoint' => array_merge($checkpoint_base, [
            'rollback_created' => true,
            'database_done' => $database_done,
            'completed_tables' => $completed_tables,
            'files_done' => $files_done,
            'files_index' => $files_index,
          ])]);
        }
      }

      // Folded into the base so it survives EVERY later checkpoint write.
      // restore_database() and restore_files() both persist progress as
      // array_merge($checkpoint_base, [...their own phase's fields...]) —
      // so any field the base does not carry is silently dropped from the
      // checkpoint the moment either of them writes one. rollback_created
      // used to be exactly that: set true here, then erased by the first
      // checkpoint the database phase wrote, leaving the next resumption
      // to conclude no rollback point existed and build another one from
      // scratch. Harmless-looking on a small site, but the rollback is a
      // full export of the CURRENT database — which, once the database
      // phase has swapped the source site's data in, is the restored
      // site's own full size. On a large restore that meant re-exporting
      // gigabytes on every single resumption, churning rollback retention,
      // and — observed on a real 16 GB restore — exhausting PHP's memory
      // limit outright, turning a working restore into one that could
      // never get past its own redundant bookkeeping.
      $checkpoint_base['rollback_created'] = $rollback_created;
      $checkpoint_base['new_admin_created'] = $new_admin_created;

      self::maybe_touch_restore_job($job_id, __('Enabling maintenance mode...', 'restorepilot-backup-migration'), 36, [
        'phase' => 'maintenance',
        'phase_label' => self::restore_phase_label('maintenance'),
      ], true);
      self::enable_maintenance_mode();
      $maintenance_enabled = true;

      if (!$database_done) {
        self::maybe_touch_restore_job($job_id, __('Restoring database...', 'restorepilot-backup-migration'), 48, [
          'phase' => 'database',
          'phase_label' => self::restore_phase_label('database'),
        ], true);
        self::restore_database($zip, $manifest, $restore_plan, $source_url, $target_url, $job_id, $checkpoint_base, $completed_tables);
        $database_done = true;
        if ($job_id !== '') {
          self::update_restore_job($job_id, ['checkpoint' => array_merge($checkpoint_base, [
            'rollback_created' => true,
            'database_done' => true,
            'completed_tables' => array_column($restore_plan['plans'], 'old_table'),
            'files_done' => $files_done,
            'files_index' => $files_index,
          ])]);
        }
      }

      // restore_database() swapped the live tables — including wp_options — via
      // RENAME TABLE, which the object cache knows nothing about. Every cached
      // option (and the whole "alloptions" blob, loaded at the start of THIS
      // request, before the swap) now describes the pre-restore database.
      //
      // This must be flushed before any options API call below, or those calls
      // compare against stale values. The migration case is the dangerous one:
      // update_option('home', $target_url) further down would read the cached
      // pre-restore home — which already equals $target_url on this site — and
      // return early without writing, leaving the freshly restored options table
      // still pointing at the SOURCE site's URL. The result is a migrated site
      // that redirects to the domain it was migrated from. Unconditional and
      // idempotent, so it is fine to repeat on a resumption that reaches this
      // point without having just done the swap itself.
      wp_cache_flush();

      self::write_log('Database restored. Source URL: ' . ($source_url ?: '(none)') . '; target URL: ' . ($target_url ?: '(none)') . '.');

      // The restored wp_options now contains the SOURCE site's RestorePilot
      // runtime state — backup/restore locks, in-flight job records, worker locks.
      // These are meaningless (and actively harmful) on this site: a foreign
      // backup lock would make the next "Create backup" report "already running"
      // for up to 2 hours. Purge them — except this restore's own still-active
      // lock and job record, which are not foreign state, and which the rest of
      // this resumption (and every later one) still needs.
      self::purge_foreign_runtime_state($job_id, $restore_lock_token);

      // The swap also brought in the backup's own active_plugins, naming
      // plugins whose code the file phase below has not written yet. Hold
      // that list back until it has — otherwise the next chunk's own
      // bootstrap fatals on a plugin that isn't there, and the restore can
      // never continue. Runs on every resumption (idempotent by design);
      // reinstated after restore_files() completes.
      self::defer_active_plugins_during_restore();

      // Gated the same way rollback creation is: checkpointed so it happens
      // exactly once, right after the swap that just put a real wp_users
      // table in place (creating it earlier would have the very next
      // resumption's RENAME TABLE erase it again).
      if ($create_new_admin && !$new_admin_created) {
        $new_admin_created = true;
        $checkpoint_base['new_admin_created'] = true;
        $new_admin = self::create_new_admin_login($new_admin_email);
        if ($job_id !== '' && !empty($new_admin['username'])) {
          // Only the id and the address are recorded. The id is what
          // handle_set_restore_admin_password() needs to find this account
          // afterwards; the address is what the page tells the operator to
          // sign in with. The account's password at this moment is a
          // throwaway nobody knows, and nothing here ever puts a working
          // credential on screen.
          self::update_restore_job($job_id, [
            'new_admin_user_id' => (int) ($new_admin['user_id'] ?? 0),
            'new_admin_email_final' => (string) ($new_admin['email'] ?? ''),
          ]);
        }
      }

      if ($files_needed && !$files_done) {
        self::maybe_touch_restore_job($job_id, __('Restoring wp-content files...', 'restorepilot-backup-migration'), 70, [
          'phase' => 'files',
          'phase_label' => self::restore_phase_label('files'),
        ], true);
        self::restore_files($zip, $job_id, (int) $validated['file_count'], $files_index, $checkpoint_base);
        $files_done = true;
        self::write_log('wp-content files restored.');
        // File restore overwrites the storage dir, wiping the poll-token file.
        // Re-write it from the job record so subsequent status polls keep working.
        $job_after_files = self::get_restore_job($job_id);
        if (!empty($job_after_files['poll_token'])) {
          self::ensure_storage();
          self::write_poll_token_file($job_id, $job_after_files['poll_token']);
        }
        if ($job_id !== '') {
          self::update_restore_job($job_id, ['checkpoint' => array_merge($checkpoint_base, [
            'rollback_created' => true,
            'database_done' => true,
            'completed_tables' => array_column($restore_plan['plans'], 'old_table'),
            'files_done' => true,
            'files_index' => (int) $validated['file_count'],
          ])]);
        }
      }

      self::maybe_touch_restore_job($job_id, __('Finalizing restore...', 'restorepilot-backup-migration'), 92, [
        'phase' => 'finalizing',
        'phase_label' => self::restore_phase_label('finalizing'),
      ], true);
      // Every file is on disk now, so the plugins held back at the swap can
      // safely go back into active_plugins. Must run BEFORE
      // cleanup_missing_active_plugins(), which would otherwise only ever
      // see (and pointlessly re-validate) the minimal single-entry list.
      self::restore_deferred_active_plugins();
      self::cleanup_missing_active_plugins();

      $zip->close();
      $zip = null;
      if ($restore_zip_path !== '' && strpos($restore_zip_path, self::storage_dir() . '/restore-upload-') === 0) {
        @unlink($restore_zip_path);
      }

      update_option('home', $target_url);
      update_option('siteurl', $target_url);
      // Rebuilds .htaccess (its one job that has to happen in a real request
      // against the live filesystem) — but the rules it writes into the
      // rewrite_rules option are necessarily incomplete here: the plugins
      // just reactivated above are not LOADED in this process (this request
      // booted before they were in active_plugins), so any custom post type
      // or taxonomy they register contributed nothing to what got generated,
      // and their permalinks would 404 until something flushed again. That
      // was true before plugins were ever deferred — whichever subset of
      // them happened to be loadable mid-restore was equally arbitrary —
      // but deferring makes it consistent, so handle it properly rather
      // than leave it to chance: dropping the generated option makes
      // WP_Rewrite regenerate lazily on the next request, which is a normal
      // bootstrap with every restored plugin loaded and its rules
      // registered.
      flush_rewrite_rules();
      delete_option('rewrite_rules');

      self::disable_maintenance_mode();
      $maintenance_enabled = false;
      self::write_log('Restore completed.');
      self::set_restore_success_notice($source_url, $target_url);
      self::release_restore_lock($restore_lock_token);
      $restore_lock_token = '';
      self::clear_restore_table_journal($job_id);

      return [
        'message' => __('Restore completed. Please log in again if WordPress asks you to.', 'restorepilot-backup-migration'),
        'source_url' => $source_url,
        'target_url' => $target_url,
        // Only meaningful for the synchronous ($job_id === '') caller — the
        // async path stashes this on the job record instead, where the
        // status-poll response serves it. The password is not returned: the
        // account carries a throwaway one by design, and no caller has any
        // business putting it in front of anyone.
        'new_admin_email' => $new_admin['email'] ?? '',
      ];
    } catch (RestorePilot_Restore_Chunk_Yield_Exception $e) {
      // Not a failure: every checkpoint write above is a complete, durable
      // record of exactly how far this resumption got, and restore_database()
      // /restore_files() have already left the database and filesystem in a
      // consistent, resumable state on their own (see their docblocks) —
      // there is nothing to undo. Maintenance mode and the site-wide restore
      // lock must both stay exactly as they are; the uploaded backup file
      // must not be deleted, the next resumption still needs to open it.
      // run_restore_job() is what actually reschedules; this only needs to
      // let the exception through.
      if ($zip instanceof RestorePilot_Backup_Archive) {
        $zip->close();
      }
      throw $e;
    } catch (Throwable $e) {
      if ($zip instanceof RestorePilot_Backup_Archive) {
        $zip->close();
      }
      if ($restore_zip_path !== '' && strpos($restore_zip_path, self::storage_dir() . '/restore-upload-') === 0) {
        @unlink($restore_zip_path);
      }
      if ($maintenance_enabled) {
        self::disable_maintenance_mode();
      }
      if ($restore_lock_token !== '') {
        self::release_restore_lock($restore_lock_token);
      }
      self::write_log('Restore failed: ' . $e->getMessage());
      throw $e;
    }
  }

  /** Restore-side counterpart to dispatch_backup_worker() — see there for the reasoning. */
  private static function dispatch_restore_worker(string $job_id, string $token): void {
    $loopback = wp_remote_post(admin_url('admin-ajax.php'), [
      'timeout'  => 1,
      'blocking' => false,
      // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter used by WordPress loopback requests.
      'sslverify' => apply_filters('https_local_ssl_verify', false),
      'body' => [
        'action' => 'restorepilot_run_restore_job',
        'job_id' => $job_id,
        'token'  => $token,
      ],
    ]);
    if (is_wp_error($loopback)) {
      self::write_log('Loopback restore runner could not be dispatched: ' . $loopback->get_error_message());
    } else {
      self::write_log('Loopback restore runner dispatched: ' . $job_id);
    }

    if (!wp_next_scheduled('restorepilot_cron_restore_job', [$job_id, $token])) {
      $scheduled = wp_schedule_single_event(time() + 5, 'restorepilot_cron_restore_job', [$job_id, $token], true);
      if (is_wp_error($scheduled)) {
        self::write_log('Cron restore fallback could not be scheduled: ' . $scheduled->get_error_message());
      } else {
        self::write_log('Cron restore fallback scheduled: ' . $job_id);
      }
    }
  }

  public static function run_restore_job(string $job_id, string $token): void {
    // Register the shutdown/error handler so that a fatal under WP-Cron still
    // releases locks and disables maintenance mode (not just in AJAX requests).
    self::enable_error_logging();
    self::$active_restore_job_id = $job_id;
    $job = self::get_restore_job($job_id);
    if (!$job || empty($job['token']) || !hash_equals((string) $job['token'], (string) $token)) {
      self::$active_restore_job_id = '';
      return;
    }

    // 'running' is not blocked here: a job that yielded between chunks is
    // left in 'running' status on purpose (see the yield catch below), and
    // this same handler is exactly what its next resumption calls. There is
    // no 'canceled' status on the restore side (restore has no cancel
    // feature), so unlike run_backup_job() nothing else needs to change here.
    // The worker lock immediately below is what actually prevents two
    // workers from touching the same job at once.
    if (in_array(($job['status'] ?? ''), ['complete', 'error', 'stale'], true)) {
      self::$active_restore_job_id = '';
      return;
    }

    if (!self::acquire_restore_worker_lock($job_id)) {
      self::$active_restore_job_id = '';
      return;
    }

    // Re-read now that the lock is held. The copy above was taken before it
    // was, so it may describe the job as it stood before whoever held the
    // lock finished with it -- and its checkpoint is what says which tables
    // are already restored. Acting on a stale one repeats work that was
    // already durably done, which surfaces as a duplicate-key insert partway
    // through a restore.
    // Fresh, not merely repeated: the read before the lock cached this
    // record in this process, so an ordinary re-read hands back the same
    // stale copy and this worker resumes from a position another worker
    // has already finished -- which is precisely how two workers ended up
    // inserting the same rows and colliding on a duplicate key.
    $job = self::get_restore_job($job_id, true) ?: $job;
    if (in_array(($job['status'] ?? ''), ['complete', 'error', 'stale'], true)) {
      self::release_restore_worker_lock($job_id);
      self::$active_restore_job_id = '';
      return;
    }

    $resumption = (int) ($job['checkpoint']['resumption'] ?? 0);
    // Set when this chunk yields, and acted on only after the worker lock has
    // been released below — see the dispatch at the end of this method.
    $dispatch_next_chunk = false;

    try {
      self::write_log('Restore runner started: ' . $job_id . ($resumption > 1 ? (' (resumption ' . $resumption . ')') : ''));
      if ($resumption <= 1) {
        self::update_restore_job($job_id, [
          'status' => 'running',
          'phase' => 'starting',
          'phase_label' => self::restore_phase_label('starting'),
          'progress' => 10,
          'message' => __('Restore is running in the background.', 'restorepilot-backup-migration'),
        ]);
      } else {
        self::update_restore_job($job_id, [
          'status' => 'running',
          'message' => __('Restore is continuing in the background.', 'restorepilot-backup-migration'),
        ]);
      }

      $restore_zip_path = isset($job['restore_zip_path']) ? (string) $job['restore_zip_path'] : '';
      if ($restore_zip_path === '') {
        throw new RuntimeException(__('Restore job is missing its backup file.', 'restorepilot-backup-migration'));
      }

      $result = self::perform_restore(
        $restore_zip_path,
        !empty($job['auto_detect_urls']),
        !empty($job['restore_files']),
        $job_id,
        isset($job['source_url']) ? (string) $job['source_url'] : '',
        isset($job['target_url']) ? (string) $job['target_url'] : '',
        !empty($job['create_new_admin']),
        isset($job['new_admin_email']) ? (string) $job['new_admin_email'] : ''
      );

      self::update_restore_job($job_id, [
        'status' => 'complete',
        'phase' => 'complete',
        'phase_label' => self::restore_phase_label('complete'),
        'progress' => 100,
        'message' => $result['message'],
      ]);
    } catch (RestorePilot_Restore_Chunk_Yield_Exception $e) {
      // Not a failure: perform_restore() already left the job option's
      // 'checkpoint' pointing at everything this chunk finished, and the
      // database/filesystem exactly as they should be. Bump the resumption
      // counter for logging, leave 'status' at 'running' (not terminal), and
      // schedule the next chunk the same way the first one was dispatched.
      $job_now = self::get_restore_job($job_id);
      $checkpoint = is_array($job_now['checkpoint'] ?? null) ? $job_now['checkpoint'] : [];
      $checkpoint['resumption'] = (int) ($checkpoint['resumption'] ?? 1) + 1;
      self::update_restore_job($job_id, [
        'checkpoint' => $checkpoint,
        'message' => __('Restore is continuing in the background.', 'restorepilot-backup-migration'),
      ]);
      self::write_log('Restore chunk finished, continuing as resumption ' . $checkpoint['resumption'] . ': ' . $job_id);
      $dispatch_next_chunk = true;
    } catch (RestorePilot_Restore_Already_Finished_Exception $e) {
      // Another worker finished the job while this one was inside its chunk.
      // Deliberately placed ahead of the generic handler below and deliberately
      // writing nothing: the job is already 'complete' and correct, and the
      // handler below would overwrite that with 'error' plus an instruction to
      // recover from a rollback point -- for a restore that had just succeeded.
      // No dispatch either; there is nothing left to continue.
      self::write_log('Restore already finished by another worker, stopping quietly: ' . $job_id);
    } catch (Throwable $e) {
      self::write_log('Restore job failed: ' . $job_id . '; ' . $e->getMessage());
      $has_rollback = !empty(self::list_restore_rollback_points());
      $error_msg      = $e->getMessage();
      if ($has_rollback) {
        $error_msg .= ' ' . __('A pre-restore rollback point was saved. Scroll down to "Pre-Restore Rollback Points" to recover your database.', 'restorepilot-backup-migration');
      }
      self::update_restore_job($job_id, [
        'status'        => 'error',
        'phase'         => 'error',
        'phase_label'   => self::restore_phase_label('error'),
        'progress'      => 100,
        'message'       => $error_msg,
        'has_rollback'  => $has_rollback,
      ]);
      // Store in a file so the notice survives after a DB restore wipes wp_options.
      self::write_operation_notice('error', 'restore', $error_msg);
    } finally {
      self::release_restore_worker_lock($job_id);
      self::$active_restore_job_id = '';
      // Do NOT delete the poll-token / status files here: the browser is often
      // still polling for the final "complete"/"error" state and would otherwise
      // lose authentication mid-poll. They are short-lived and swept by
      // cleanup_stale_temp_files() (1-hour age) and at the next restore start.
    }

    // Deliberately after the finally, because the worker lock has to be gone
    // before the next chunk can take it. Dispatched from inside the try, the
    // loopback arrived while this request still held the lock, failed to
    // acquire it, and returned without a word — so every chunk was actually
    // started by the +5s cron fallback instead. On a restore of any size that
    // is five seconds of nothing per chunk: a fifth of the total run on this
    // site's own timings, spent waiting for a request that had already been
    // turned away.
    if ($dispatch_next_chunk) {
      self::dispatch_restore_worker($job_id, $token);
    }
  }

  /**
   * $protect_path is the archive the current restore is reading FROM, if any.
   * It matters when that archive is itself a rollback point: this function's
   * retention sweep would otherwise be free to evict it as "oldest" — and
   * restoring the OLDEST rollback point is exactly what someone reaching for
   * the furthest-back recovery does. Deleting it mid-restore does not fail
   * loudly either, which is what makes it dangerous: the already-open file
   * handle keeps this chunk working on Unix, and the restore only dies on a
   * LATER resumption, when open_backup_archive() reopens it by path and finds
   * nothing — with the database swap already applied and no source left to
   * finish from.
   */
  private static function create_restore_rollback_point(string $protect_path = ''): void {
    self::write_log('Creating pre-restore database rollback point.');
    $rollback = self::create_backup_package(false, '', [], true, false, [
      'skip_lock' => true,
      'purpose' => 'rollback',
      'destination_dir' => self::rollback_dir(),
      'filename' => self::friendly_rollback_filename(),
    ]);
    self::enforce_restore_rollback_retention($protect_path);
    self::write_log('Pre-restore rollback point created: ' . ($rollback['file'] ?? '(unknown)'));
  }

  /**
   * Executes a restore plan already fully validated by build_restore_plan().
   * Deliberately takes the validated $plans array itself rather than the raw
   * backup data, so there is no path for this method to re-derive a mapping
   * or skip a bad row that the preflight validation did not already accept.
   */
  /**
   * $checkpoint_base is everything the job's checkpoint needs OTHER than
   * this phase's own progress ('database_done'/'completed_tables') — the
   * caller (perform_restore()) already has it, and passing it in lets every
   * checkpoint write here be a complete, self-contained object built from
   * known-good local state, never a merge against whatever get_restore_job()
   * currently returns (which right after the table swap below, and until
   * this same call re-establishes it, is briefly not this restore's own
   * record at all — see purge_foreign_runtime_state()).
   *
   * $completed_tables lists old_table names (the export's own names, before
   * prefix mapping) already fully created and populated by an earlier
   * resumption. The plan itself — and so every tmp_table name — is built
   * once and reused unchanged across resumptions (see perform_restore()),
   * which is what makes SELECT COUNT(*) FROM tmp_table a reliable way to
   * find exactly how many of a partially-done table's rows survived the
   * last chunk boundary: they are this restore's own, not some unrelated
   * leftover, and row order is deterministic since the same export is read
   * in the same order every time.
   */
  private static function restore_database(RestorePilot_Backup_Archive $zip, array $manifest, array $plan_set, string $source_url, string $target_url, string $job_id, array $checkpoint_base, array $completed_tables): void {
    $wpdb = self::wpdb();
    $plans = $plan_set['plans'];
    $plan_by_table = $plan_set['plan_by_table'];
    $completed_set = array_fill_keys($completed_tables, true);

    $old_tables = [];
    $yielding = false;

    $persist = function () use ($job_id, $checkpoint_base, &$completed_set): void {
      if ($job_id === '') {
        return;
      }
      self::update_restore_job($job_id, ['checkpoint' => array_merge($checkpoint_base, [
        'database_done' => false,
        'completed_tables' => array_keys($completed_set),
      ])]);
    };

    try {
      // Second streaming pass over the same export. The first pass
      // (build_restore_plan()) already validated every table and row, so
      // nothing here re-derives or re-checks the plan — this pass only
      // executes it, creating each staging table as its header comes past
      // and inserting each row as it is read. Row data is never accumulated,
      // so a database of any size restores in constant memory.
      $active_plan = null;
      $active_old_table = '';
      $skip_remaining = 0;
      $row_counter = 0;

      self::stream_database_records($zip, $manifest, function (string $type, $payload) use (
        &$active_plan, &$active_old_table, &$skip_remaining, &$row_counter, &$completed_set,
        $plans, $plan_by_table, $wpdb, $source_url, $target_url, $job_id, $persist
      ): void {
        if ($type === 'table') {
          // Checked at every table boundary, including one this resumption
          // is only skipping past (already done) — cheap either way, and it
          // bounds a resumption that has to skip through many already-done
          // tables before reaching any new work, not just one that is
          // actively inserting.
          self::throw_if_restore_abandoned($job_id);
        self::throw_if_restore_chunk_time_exceeded();

          // Finalize whichever table this stream was just actively working
          // on (fresh insert or mid-table resume), if any.
          if ($active_old_table !== '') {
            if ($skip_remaining > 0) {
              /* translators: %s: database table name from the backup */
              throw new RuntimeException(sprintf(__('Table %s has fewer rows in this pass than were already restored in an earlier attempt; the restore state is inconsistent and cannot continue safely.', 'restorepilot-backup-migration'), $active_old_table));
            }
            $completed_set[$active_old_table] = true;
            $persist();
            self::$restore_chunk_progress_made = true;
          }

          $name = is_string($payload['name'] ?? null) ? $payload['name'] : '';
          $active_plan = null;
          $active_old_table = '';
          $skip_remaining = 0;
          $row_counter = 0;

          if ($name === '' || !isset($plan_by_table[$name])) {
            // A table the plan deliberately excluded; its rows are skipped too.
            return;
          }
          if (isset($completed_set[$name])) {
            // Already fully done in an earlier resumption. Nothing to finalize
            // for it this time (active_old_table stays ''), its rows are just
            // streamed past below without being touched. Same reasoning as
            // the row-skip time check below: coasting through many already-
            // completed tables to reach real work is itself unbounded (every
            // one of their rows is still read and JSON-decoded, just not
            // inserted), so this checks the deadline unconditionally rather
            // than through the progress-gated throw_if_restore_chunk_time_
            // exceeded() a few lines above, which would never fire here —
            // no table boundary in a coasting stretch ever counts as
            // progress. One check per table (not per row) is cheap enough
            // to need no throttling.
            if (self::$restore_chunk_deadline > 0.0 && microtime(true) >= self::$restore_chunk_deadline) {
              self::write_log('Restore chunk time budget exceeded while coasting past already-restored tables — yielding to continue.');
              throw new RestorePilot_Restore_Chunk_Yield_Exception('Restore chunk time budget exceeded while coasting past already-restored tables.');
            }
            return;
          }

          $active_plan = $plans[$plan_by_table[$name]];
          $active_old_table = $name;

          // Position of the table now starting: everything already finished,
          // plus this one. The checkpoint tracks these to make the restore
          // resumable — reporting them costs nothing and is the difference
          // between a bar that moves and one that looks hung.
          $table_total = count($plans);
          $table_position = min($table_total, count($completed_set) + 1);

          self::maybe_touch_restore_job(
            $job_id,
            __('Restoring database tables...', 'restorepilot-backup-migration'),
            self::restore_database_phase_progress($table_position - 1, $table_total),
            [
              'phase' => 'database',
              'phase_label' => self::restore_database_phase_label($table_position, $table_total),
            ]
          );

          if (self::table_exists($active_plan['tmp_table'])) {
            // Left behind by an earlier resumption of THIS restore — do not
            // drop and recreate it, that would discard rows already durably
            // inserted. Its row count is exactly how many of this table's
            // rows to skip below instead of re-inserting.
            $wpdb->last_error = '';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $existing_rows = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $active_plan['tmp_table']));
            self::throw_on_db_error('count already-restored rows');
            $skip_remaining = (int) $existing_rows;
            return;
          }

          $wpdb->last_error = '';
          $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $active_plan['tmp_table']));
          self::throw_on_db_error('drop temporary table');

          $wpdb->last_error = '';
          // A schema definition is SQL, not bound values, so it cannot be passed
          // through prepare(). It is instead whitelisted in full by
          // assert_create_table_is_safe() during build_restore_plan(), before
          // this method is ever reached: the statement must match exactly the
          // form SHOW CREATE TABLE produces, targeting this restore's own
          // generated temp table name, with only inert table options after the
          // column block — which is what rejects CREATE TABLE ... SELECT.
          // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- schema DDL cannot be parameterized; fully whitelisted by assert_create_table_is_safe() before reaching here.
          $wpdb->query($active_plan['create']);
          self::throw_on_db_error('create temporary restore table');
          return;
        }

        if ($active_plan === null) {
          // A row belonging to a table this chunk is not inserting into
          // right now — either excluded from the plan entirely, or already
          // fully completed in an earlier resumption (see the table-
          // boundary check above, which only fires ONCE per table, on
          // entry). Coasting through a single large table's worth of rows
          // this way is exactly as unbounded as the table-boundary case
          // it complements: every row is still read and JSON-decoded even
          // though nothing is inserted, and the boundary check cannot
          // fire again until this table's rows are entirely behind us —
          // which, for one sufficiently large already-completed table, can
          // be longer than the whole chunk budget. Checked every row, not
          // throttled to a fixed count: microtime() is cheap enough not to
          // need throttling, and a fixed row interval can still be far too
          // coarse — a production table with unusually large individual
          // rows (long serialized values needing decode) can take many
          // times longer to get through 200 of them than the whole chunk
          // budget, which was exactly confirmed happening on this site's
          // own restore before this fix. Ungated by design, same as the
          // row-skip check below: coasting never sets progress, so the
          // progress-gated check elsewhere would never fire here either.
          $row_counter++;
          if (self::$restore_chunk_deadline > 0.0 && microtime(true) >= self::$restore_chunk_deadline) {
            self::write_log('Restore chunk time budget exceeded while coasting past rows not needing insertion — yielding to continue.');
            throw new RestorePilot_Restore_Chunk_Yield_Exception('Restore chunk time budget exceeded while coasting past rows not needing insertion.');
          }
          return;
        }
        if (!is_array($payload)) {
          return;
        }

        if ($skip_remaining > 0) {
          $skip_remaining--;
          // Re-reading and discarding already-restored rows to catch up to
          // real work is itself unbounded: stream_database_records() cannot
          // seek, so every resumption re-derives $skip_remaining fresh from
          // SELECT COUNT(*) and re-reads the whole prefix from byte zero —
          // meaning a table whose already-inserted count has grown large
          // across several earlier chunks can take longer to merely skip
          // past than one full chunk budget allows. Deliberately NOT gated
          // behind $restore_chunk_progress_made the way the insert path's
          // check below is (a skip, by definition, writes nothing new, so
          // that gate would never let this fire at all) — without a time
          // check here, the only thing that could ever end an oversized
          // skip was the *host's* own external timeout (PHP-FPM, a reverse
          // proxy) hard-killing the process mid-skip, before a single new
          // row was written, silently, with the next resumption re-reading
          // and discarding the identical prefix and dying at the identical
          // point every time — a livelock that gets worse, not better, on
          // every retry, since skip cost only grows as a table nears
          // completion. A clean, logged, cooperative yield here is strictly
          // better than that regardless of host behavior, and converges as
          // soon as one chunk's budget covers the remaining skip distance —
          // same as every table that already restored successfully before
          // this one. Checked every row rather than every 200: a fixed row
          // interval can be far too coarse for a table with unusually
          // large individual rows, which is exactly what made this check
          // effectively never fire in practice before this change —
          // microtime() is cheap enough not to need throttling.
          $row_counter++;
          if (self::$restore_chunk_deadline > 0.0 && microtime(true) >= self::$restore_chunk_deadline) {
            self::write_log(sprintf(
              'Restore chunk time budget exceeded while catching up on already-restored rows in %s (%d of %d remaining) — yielding to continue.',
              $active_old_table,
              $skip_remaining,
              $active_plan['row_count'] ?? $skip_remaining
            ));
            throw new RestorePilot_Restore_Chunk_Yield_Exception('Restore chunk time budget exceeded while catching up on already-restored rows.');
          }
          return;
        }

        $clean = [];
        foreach ($payload as $key => $value) {
          // Unwrap any base64 sentinel written for non-UTF-8 binary columns
          // before applying URL replacement.
          $value = self::decode_b64_column_value($value);
          $clean[$key] = self::replace_urls_deep($value, $source_url, $target_url);
        }
        $wpdb->last_error = '';
        $inserted = $wpdb->insert($active_plan['tmp_table'], $clean);
        if ($inserted === false) {
          self::throw_on_db_error('insert restored row');
        }
        self::$restore_chunk_progress_made = true;
        // Touch the job record on every row so the stale detector does not
        // fire during a very large single-table import that takes > 2 h.
        // maybe_touch throttles actual DB writes to once per 5 s.
        $row_table_total = count($plans);
        $row_table_position = min($row_table_total, count($completed_set) + 1);
        self::maybe_touch_restore_job(
          $job_id,
          __('Restoring database tables...', 'restorepilot-backup-migration'),
          self::restore_database_phase_progress($row_table_position - 1, $row_table_total),
          [
            'phase' => 'database',
            'phase_label' => self::restore_database_phase_label($row_table_position, $row_table_total),
          ]
        );

        // Checked every row rather than every 200: throw_if_restore_chunk_
        // time_exceeded() is already gated behind $restore_chunk_progress_
        // made (set just above), so this is never the FIRST check to fire
        // in a chunk regardless of frequency — but a fixed row interval
        // still meant a table with unusually large individual rows could
        // run for many multiples of the chunk budget past the deadline
        // before the 200th row was even reached. microtime() is cheap
        // enough not to need throttling to a row count at all.
        $row_counter++;
        self::throw_if_restore_abandoned($job_id);
        self::throw_if_restore_chunk_time_exceeded();
      });

      // Finalize whatever table the stream ended on.
      if ($active_old_table !== '') {
        if ($skip_remaining > 0) {
          /* translators: %s: database table name from the backup */
          throw new RuntimeException(sprintf(__('Table %s has fewer rows in this pass than were already restored in an earlier attempt; the restore state is inconsistent and cannot continue safely.', 'restorepilot-backup-migration'), $active_old_table));
        }
        $completed_set[$active_old_table] = true;
      }

      self::maybe_touch_restore_job($job_id, __('Swapping restored database tables...', 'restorepilot-backup-migration'), 64, [
        'phase' => 'database',
        'phase_label' => self::restore_phase_label('database'),
      ], true);

      // RENAME TABLE is an atomic multi-table swap with no WordPress ORM
      // equivalent, so the statement is built here — but every table name in
      // it is bound through $wpdb->prepare()'s %i identifier placeholder
      // rather than concatenated in. One "%i TO %i" pair is emitted per
      // rename, and the matching names are collected in order and passed as
      // prepare()'s bound arguments.
      $rename_pairs = [];
      $rename_args = [];
      foreach ($plans as $plan) {
        if (self::table_exists($plan['final_table'])) {
          $old_table = $plan['old_table_candidate'];
          $old_tables[] = $old_table;
          $rename_pairs[] = '%i TO %i';
          $rename_args[] = $plan['final_table'];
          $rename_args[] = $old_table;
        }
        $rename_pairs[] = '%i TO %i';
        $rename_args[] = $plan['tmp_table'];
        $rename_args[] = $plan['final_table'];
      }

      if (!$rename_pairs) {
        throw new RuntimeException(__('No database tables were available to swap.', 'restorepilot-backup-migration'));
      }

      $wpdb->last_error = '';
      // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $rename_pairs is a generated list of literal "%i TO %i" placeholder pairs; every table name is bound via $rename_args below.
      $wpdb->query($wpdb->prepare('RENAME TABLE ' . implode(', ', $rename_pairs), $rename_args));
      self::throw_on_db_error('swap restored database tables');

      // From this instant the live wp_options is the BACKUP's, including its
      // active_plugins — naming plugins whose code the file phase has not
      // written yet. Held back here, in the same breath as the swap, rather
      // than a few statements later in perform_restore(): every request the
      // site serves in between (a visitor, a cron tick, this restore's own
      // next-chunk dispatch) boots WordPress and includes whatever
      // active_plugins names, and maintenance mode cannot help, because it
      // is enforced on 'init' — long after wp-settings.php has already
      // loaded every plugin. perform_restore() re-asserts this on every
      // later chunk; this call is what closes the window at the one moment
      // it opens.
      //
      // The cache flush is required for correctness here, not tidiness:
      // this process's object cache still holds the PRE-swap alloptions
      // blob, so reading active_plugins without flushing first would stash
      // the target site's own list instead of the restored one — and the
      // stash is written exactly once, so that value is the one reinstated
      // at the end.
      wp_cache_flush();
      self::defer_active_plugins_during_restore();

      foreach ($old_tables as $old_table) {
        $wpdb->last_error = '';
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $old_table));
      }
    } catch (RestorePilot_Restore_Chunk_Yield_Exception $e) {
      // Not a failure: every table in $completed_set is durably created and
      // fully populated (that is the only way a table ever gets added to it
      // — see $persist() above), and the ones that are not yet in it still
      // legitimately need their scratch tables to exist, journaled, for the
      // next resumption to find via table_exists() + SELECT COUNT(*). None
      // of that may be touched here.
      $yielding = true;
      throw $e;
    } catch (Throwable $e) {
      foreach ($plans as $plan) {
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $plan['tmp_table']));
      }
      throw $e;
    } finally {
      // Cleanup above ran to completion (success or a caught failure) without
      // the process being killed, so nothing this restore journaled needs a
      // future sweep. If the process IS killed before this point — or it
      // yielded cleanly, still mid-restore — this never runs and the journal
      // correctly survives for the next restore attempt.
      if (!$yielding) {
        self::clear_restore_table_journal($job_id);
      }
    }
  }

  private static function backup_restore_readiness(array $manifest, int $file_count): array {
    $backup_type = isset($manifest['backup_type']) ? sanitize_key((string) $manifest['backup_type']) : '';
    $includes_database = !empty($manifest['includes_database']);
    $includes_files = !empty($manifest['includes_files']);
    $selected_content = !empty($manifest['file_selection_enabled']);

    // A pre-restore rollback point is database-only BY DESIGN — this plugin
    // creates it that way (create_restore_rollback_point() passes
    // include_files=false), so it is correctly not "restorable" in the
    // full-site sense and never should be. But it is precisely what a failed
    // restore is supposed to be recovered from, and the failure message
    // itself sends the user to it ("A pre-restore rollback point was saved.
    // Scroll down to ... to recover your database."), with a "Restore from
    // this point" button beside every one.
    //
    // Reported separately from 'restorable' rather than folded into it: the
    // full-restore guard exists to stop someone restoring a plugins-only or
    // uploads-only archive over their site expecting a whole-site restore,
    // which is a real and useful protection. Widening that flag would remove
    // it for every archive type at once. This flag only ever says "this
    // specific archive is a valid DATABASE-ONLY restore", which is exactly
    // what a rollback is, and the files phase is skipped for it
    // automatically ($files_needed is gated on includes_files).
    $database_only_restorable = $includes_database
      && ($backup_type === 'rollback' || (isset($manifest['purpose']) && $manifest['purpose'] === 'rollback'));

    if ($backup_type === '') {
      if ($includes_database && $includes_files && !$selected_content && $file_count > 0) {
        return ['type' => 'full', 'restorable' => true, 'database_only_restorable' => $database_only_restorable];
      }
      if ($includes_database && !$includes_files) {
        return ['type' => 'database', 'restorable' => false, 'database_only_restorable' => $database_only_restorable];
      }
      if ($selected_content) {
        return ['type' => 'selected-content', 'restorable' => false, 'database_only_restorable' => $database_only_restorable];
      }
      return ['type' => 'partial', 'restorable' => false, 'database_only_restorable' => $database_only_restorable];
    }

    // Trust the manifest's own restorable flag — it is set by create_backup_package
    // which already knows whether all paths were selected (our Partial/Full fix).
    // Do not re-derive from file_selection_enabled here, as that flag can be true
    // even when all folders are included (making the backup fully restorable).
    $restorable = $backup_type === 'full'
      && !empty($manifest['restorable'])
      && $includes_database
      && $includes_files
      && $file_count > 0;

    return [
      'type' => $backup_type,
      'restorable' => $restorable,
      'database_only_restorable' => $database_only_restorable,
    ];
  }

  /**
   * Rejects an absurd entry count before iterating the archive at all. This
   * is deliberately far above what any real site produces — a WordPress
   * upload library rarely exceeds a few hundred thousand files — so it only
   * ever rejects a pathological archive (e.g. millions of near-empty entries
   * built to make every per-entry loop in the restore path expensive), never
   * a genuine large-site backup. Callers must run this before their own
   * first per-entry loop over the archive, not only before validate_backup_zip()'s.
   */
  private static function assert_restore_zip_entry_count(RestorePilot_Backup_Archive $zip): void {
    if ($zip->num_files() > self::MAX_RESTORE_ZIP_ENTRIES) {
      throw new RuntimeException(sprintf(
        /* translators: 1: number of entries found in the archive, 2: the maximum number of entries a backup archive may contain */
        __('Backup archive contains %1$d entries, which is more than the %2$d RestorePilot allows.', 'restorepilot-backup-migration'),
        $zip->num_files(),
        self::MAX_RESTORE_ZIP_ENTRIES
      ));
    }
  }

  private static function validate_backup_zip(RestorePilot_Backup_Archive $zip, bool $include_database, bool $require_full_restore = false): array {
    self::assert_restore_zip_entry_count($zip);

    // Check the manifest's declared (uncompressed) size via the zip's central
    // directory BEFORE decompressing it into memory with getFromName(); an
    // oversized manifest is itself a sign of a corrupted or crafted archive.
    $manifest_stat = $zip->stat_name('manifest.json');
    if (is_array($manifest_stat) && (int) ($manifest_stat['size'] ?? 0) > self::MAX_MANIFEST_JSON_BYTES) {
      throw new RuntimeException(sprintf(
        /* translators: %s: the maximum size a backup manifest file may be */
        __('Backup manifest is larger than the %s RestorePilot allows; this archive is not a valid RestorePilot backup.', 'restorepilot-backup-migration'),
        size_format(self::MAX_MANIFEST_JSON_BYTES)
      ));
    }

    $manifest_raw = $zip->get_from_name('manifest.json');
    if (!is_string($manifest_raw) || $manifest_raw === '') {
      throw new RuntimeException(__('Backup manifest is missing.', 'restorepilot-backup-migration'));
    }

    $manifest = json_decode($manifest_raw, true);
    if (!is_array($manifest) || ($manifest['plugin'] ?? '') !== self::SLUG) {
      throw new RuntimeException(__('This is not a valid RestorePilot backup.', 'restorepilot-backup-migration'));
    }

    $file_count = 0;
    for ($i = 0; $i < $zip->num_files(); $i++) {
      $name = $zip->get_name_index($i);
      if (!is_string($name) || $name === '') {
        continue;
      }

      if (self::zip_entry_is_unsafe($name)) {
        /* translators: %s: unsafe file path found inside the backup archive */
        throw new RuntimeException(sprintf(__('Backup contains an unsafe file path: %s', 'restorepilot-backup-migration'), $name));
      }

      if (strpos($name, 'files/wp-content/') === 0 && substr($name, -1) !== '/') {
        $file_count++;
      }
    }

    if ($require_full_restore) {
      $readiness = self::backup_restore_readiness($manifest, $file_count);
      // A pre-restore rollback point is allowed through as a database-only
      // restore. Without this, the one recovery path a failed restore points
      // the user at was refused by the plugin that had just created the file
      // — and refused with a message telling them to pick a different, "full"
      // backup, which does not exist. See backup_restore_readiness().
      if (empty($readiness['restorable']) && empty($readiness['database_only_restorable'])) {
        throw new RuntimeException(__('This does not look like a complete RestorePilot backup. Please upload the full backup zip, not an individual database, plugins, themes, uploads, or wp-content archive.', 'restorepilot-backup-migration'));
      }
    }

    // The table count is read from the manifest, never by decoding the export.
    // For newline-delimited backups the export is streamed record by record
    // during the restore itself (see stream_database_records()), so it is
    // never loaded as a whole here — that is what allows a database of any
    // size to be restored within a fixed memory budget.
    $table_count = 0;
    $database_parts = self::database_part_names($manifest);

    if (isset($manifest['table_count']) && is_numeric($manifest['table_count'])) {
      $table_count = (int) $manifest['table_count'];
    }

    if ($database_parts) {
      // Confirm every declared part is actually present before any
      // destructive step, so a truncated archive fails here rather than
      // part-way through the restore.
      foreach ($database_parts as $part) {
        if ($zip->stat_name($part) === false) {
          throw new RuntimeException(sprintf(
            /* translators: %s: name of the missing database export part inside the backup archive */
            __('Backup database export part %s is missing; this archive is incomplete.', 'restorepilot-backup-migration'),
            $part
          ));
        }
      }
    } else {
      // Legacy single-file export. Its size is still checked up front,
      // because this format has to be decoded whole and is therefore bounded
      // by memory rather than disk.
      $database_stat = $zip->stat_name('database.json');
      if (is_array($database_stat) && (int) ($database_stat['size'] ?? 0) > self::MAX_DATABASE_JSON_BYTES) {
        throw new RuntimeException(sprintf(
          /* translators: %s: the maximum size a backup's database export may be */
          __('Backup database export is larger than the %s RestorePilot allows.', 'restorepilot-backup-migration'),
          size_format(self::MAX_DATABASE_JSON_BYTES)
        ));
      }
      if ($include_database && $database_stat === false) {
        throw new RuntimeException(__('Backup database export is missing.', 'restorepilot-backup-migration'));
      }
      if ($table_count === 0 && is_array($database_stat)) {
        // Created before table_count was recorded in the manifest: fall back
        // to decoding purely to report the count.
        $database_raw = $zip->get_from_name('database.json');
        if (is_string($database_raw) && $database_raw !== '') {
          $decoded = json_decode($database_raw, true);
          unset($database_raw);
          if (is_array($decoded) && !empty($decoded['tables']) && is_array($decoded['tables'])) {
            $table_count = count($decoded['tables']);
          }
        }
      }
    }

    if ($table_count > self::MAX_RESTORE_TABLE_COUNT) {
      throw new RuntimeException(sprintf(
        /* translators: 1: number of tables found in the backup's database export, 2: the maximum number of tables RestorePilot allows */
        __('Backup database export declares %1$d tables, which is more than the %2$d RestorePilot allows.', 'restorepilot-backup-migration'),
        $table_count,
        self::MAX_RESTORE_TABLE_COUNT
      ));
    }

    return [
      'manifest' => $manifest,
      'table_count' => $table_count,
      'file_count' => $file_count,
    ];
  }

  /**
   * Streams the whole database export once, validating every table header and
   * every row, and returns the plan restore_database() will execute.
   *
   * Nothing but the per-table plan is retained — row data is validated as it
   * goes past and then discarded — so this costs the same memory for a 10 GB
   * export as for a 10 MB one. The plan is complete and fully validated
   * before the caller creates a rollback point or enables maintenance mode,
   * which is what keeps "reject a bad archive before touching the live site"
   * true even though the rows are no longer all held at once.
   */
  private static function build_restore_plan(RestorePilot_Backup_Archive $zip, array $manifest, string $backup_prefix): array {
    $target_prefix = self::wpdb()->prefix;
    $restore_id = substr(md5(wp_generate_uuid4()), 0, 12);
    $seen_logical_names = [];
    $plans = [];
    $plan_by_table = [];
    $position = -1;
    $seen_any_table = false;
    $current_plan_index = null;

    self::stream_database_records($zip, $manifest, function (string $type, $payload) use (
      &$plans, &$plan_by_table, &$seen_logical_names, &$position, &$seen_any_table, &$current_plan_index,
      $backup_prefix, $target_prefix, $restore_id
    ): void {
      if ($type === 'table') {
        $position++;
        $seen_any_table = true;
        // Until proven otherwise this table is not part of the restore, so a
        // row arriving before the checks below finish is never attributed to
        // the previous table.
        $current_plan_index = null;

        $old_table = $payload['name'] ?? null;
        $create_raw = $payload['create'] ?? null;
        if (!is_string($old_table) || $old_table === '' || !is_string($create_raw) || $create_raw === '') {
          /* translators: %d: zero-based position of the malformed table record inside the backup's database export */
          throw new RuntimeException(sprintf(__('Backup database export contains a malformed table record at position %d.', 'restorepilot-backup-migration'), (int) $position));
        }

        if (strpos($old_table, $backup_prefix) !== 0) {
          // Not one of this backup's own WordPress-prefixed tables — never part
          // of the restore plan or the required-table check below.
          self::write_log('Skipped non-WordPress-prefix table during restore: ' . $old_table);
          return;
        }
        // Refuse to write another network site's tables, even if an older backup
        // archive still contains them (see table_belongs_to_other_site()).
        if (self::table_belongs_to_other_site($old_table, $backup_prefix)) {
          self::write_log('Skipped table belonging to another network site during restore: ' . $old_table);
          return;
        }

        $logical_name = substr($old_table, strlen($backup_prefix));
        if ($logical_name === '') {
          return;
        }
        if (isset($seen_logical_names[$logical_name])) {
          /* translators: %s: fully-prefixed database table name that more than one backup entry maps to */
          throw new RuntimeException(sprintf(__('Backup database export contains more than one table that maps to %s.', 'restorepilot-backup-migration'), $target_prefix . $logical_name));
        }
        $seen_logical_names[$logical_name] = true;

        $new_table = self::map_table_name($old_table, $backup_prefix, $target_prefix);
        if (!preg_match('/^[A-Za-z0-9_]+$/', $new_table)) {
          /* translators: %s: database table name from the backup */
          throw new RuntimeException(sprintf(__('Backup table name %s does not map to a valid database table name.', 'restorepilot-backup-migration'), $old_table));
        }

        $tmp_table = self::temporary_table_name($target_prefix, $restore_id, count($plans));
        $create = preg_replace('/CREATE TABLE `?' . preg_quote($old_table, '/') . '`?/i', 'CREATE TABLE `' . $tmp_table . '`', $create_raw, 1);
        if (!$create || $create === $create_raw) {
          /* translators: %s: database table name */
          throw new RuntimeException(sprintf(__('Could not prepare table restore for %s.', 'restorepilot-backup-migration'), $old_table));
        }

        // The CREATE statement comes from the untrusted backup archive and is the
        // one piece of restore input that cannot be expressed as bound values —
        // it is a schema definition, so it is executed as SQL. Everything it is
        // allowed to contain is therefore whitelisted explicitly here, before it
        // is ever handed to the database. See assert_create_table_is_safe().
        self::assert_create_table_is_safe($create, $tmp_table, $old_table);

        $plans[] = [
          'old_table' => $old_table,
          'final_table' => $new_table,
          'tmp_table' => $tmp_table,
          // Precomputed now, alongside tmp_table, so the full set of scratch
          // names this restore can ever create is known and journaled before
          // any of them exist. Only used in the swap loop below when the final
          // table already exists; otherwise it is simply never created.
          'old_table_candidate' => self::old_table_name($target_prefix, $restore_id, count($plans)),
          'create' => $create,
          'row_count' => 0,
        ];
        $current_plan_index = count($plans) - 1;
        $plan_by_table[$old_table] = $current_plan_index;
        return;
      }

      // A row record.
      if (!$seen_any_table) {
        throw new RuntimeException(__('Backup database export contains a row before any table definition.', 'restorepilot-backup-migration'));
      }
      if ($current_plan_index === null) {
        // Belongs to a table this restore is deliberately skipping.
        return;
      }

      $table_name = $plans[$current_plan_index]['old_table'];
      if (!is_array($payload)) {
        /* translators: %s: database table name from the backup */
        throw new RuntimeException(sprintf(__('Backup table %s contains a row that is not a valid record.', 'restorepilot-backup-migration'), $table_name));
      }
      // Every column key must be a plausible MySQL identifier. A real
      // RestorePilot export can never produce anything else (column names come
      // straight from MySQL); this guards against a corrupted or crafted
      // archive smuggling a key restore_database() would otherwise pass
      // straight into $wpdb->insert().
      foreach (array_keys($payload) as $column) {
        if (!is_string($column) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
          /* translators: %s: database table name from the backup */
          throw new RuntimeException(sprintf(__('Backup table %s contains a row with an invalid column name.', 'restorepilot-backup-migration'), $table_name));
        }
      }
      $plans[$current_plan_index]['row_count']++;
    });

    // Derived from WordPress's own table registry rather than a hardcoded list,
    // so it stays correct if core ever adds/renames a table. Multisite network
    // tables are never included here — restores are already refused entirely
    // on multisite before this method is reached.
    $required = self::wpdb()->tables('all', false);
    $missing = array_diff($required, array_keys($seen_logical_names));
    if ($missing) {
      throw new RuntimeException(sprintf(
        /* translators: %s: comma-separated list of missing WordPress core table names */
        __('Backup database export is missing required WordPress tables: %s. This archive cannot be restored safely.', 'restorepilot-backup-migration'),
        implode(', ', array_map(fn($t) => $target_prefix . $t, $missing))
      ));
    }

    if (!$plans) {
      throw new RuntimeException(__('Backup database does not contain any restorable tables.', 'restorepilot-backup-migration'));
    }

    return ['restore_id' => $restore_id, 'plans' => $plans, 'plan_by_table' => $plan_by_table];
  }

  private static function assert_restore_preflight(RestorePilot_Backup_Archive $zip, bool $restore_files): void {
    $wpdb = self::wpdb();
    $wpdb->last_error = '';
    // Direct query: lightweight connectivity ping; no caching needed or appropriate.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->get_var('SELECT 1');
    self::throw_on_db_error('restore preflight database check');

    if (!$restore_files) {
      return;
    }

    if (!is_dir(self::content_dir()) || !is_writable(self::content_dir())) {
      throw new RuntimeException(__('wp-content is not writable for file restore.', 'restorepilot-backup-migration'));
    }

    $needed = self::estimate_restore_file_bytes($zip);
    $free = @disk_free_space(self::content_dir());
    if ($free !== false && $needed > 0 && $free < (int) ($needed * 1.15)) {
      throw new RuntimeException(sprintf(
        /* translators: 1: free disk space, 2: estimated space needed */
        __('Not enough free disk space for file restore. Available: %1$s. Estimated needed: %2$s.', 'restorepilot-backup-migration'),
        size_format((int) $free),
        size_format((int) ($needed * 1.15))
      ));
    }
  }

  private static function estimate_restore_file_bytes(RestorePilot_Backup_Archive $zip): int {
    $total = 0;
    for ($i = 0; $i < $zip->num_files(); $i++) {
      $name = $zip->get_name_index($i);
      if (!is_string($name) || strpos($name, 'files/wp-content/') !== 0 || substr($name, -1) === '/') {
        continue;
      }

      $stat = $zip->stat_index($i);
      if (is_array($stat) && isset($stat['size'])) {
        $total += max(0, (int) $stat['size']);
      }
    }

    return $total;
  }

  /**
   * $start_index resumes at exactly the zip entry index a previous chunk
   * left off at — entries are addressed by a plain integer position in
   * RestorePilot_Backup_Archive's stable, deterministic order, so unlike
   * restore_database() this needs no content-based "is this already done"
   * check at all. Idempotent by construction regardless: each file is
   * written to a per-attempt temp name and only atomically renamed into
   * place once fully written, so even redoing the one file a kill landed
   * mid-write of (this resumes exactly at its index, not past it) is safe.
   * $checkpoint_base is the rest of the job's checkpoint — see
   * restore_database() for why it is passed in rather than re-derived.
   */
  private static function restore_files(RestorePilot_Backup_Archive $zip, string $job_id, int $total_files, int $start_index, array $checkpoint_base): void {
    $restored_files = $start_index;
    // Never overwrite our own plugin files — the backup contains the version from
    // the SOURCE site, which may be older than the one running this restore.
    $own_plugin_rel = 'plugins/' . basename(self::plugin_root_dir()) . '/';
    for ($i = $start_index; $i < $zip->num_files(); $i++) {
      try {
        self::throw_if_restore_abandoned($job_id);
        self::throw_if_restore_chunk_time_exceeded();
      } catch (RestorePilot_Restore_Chunk_Yield_Exception $e) {
        // Persist how far this chunk actually got BEFORE yielding, forcing
        // the write past maybe_touch_restore_job()'s throttle.
        //
        // Without this, files_index is only ever persisted by the throttled
        // touch below, which refuses to write more than once per 5 seconds
        // per job — and the entry into this phase already spends that
        // allowance on its own force=true touch. Any chunk budget shorter
        // than the throttle interval therefore ends without ever recording a
        // single index, so the next chunk restarts from exactly where this
        // one did and the file phase can never advance: a silent, permanent
        // livelock, with the job still reporting status "running" and
        // progress ticking, on a site stuck in maintenance mode. The default
        // 20s budget hides it (the throttle expires ~3 times per chunk), but
        // restorepilot_restore_chunk_seconds is a public filter and a host
        // needing a short budget is exactly the case chunking exists to
        // serve. Observed directly: at a 3s budget the file phase reported
        // files_index=0 for 175 consecutive chunks.
        //
        // $i, not $i + 1: this entry has not been restored in this chunk.
        if ($job_id !== '') {
          self::maybe_touch_restore_job($job_id, sprintf(
            /* translators: 1: number of files restored so far, 2: total number of files to restore */
            __('Restoring files... %1$d of %2$d', 'restorepilot-backup-migration'),
            $restored_files,
            max($restored_files, $total_files)
          ), $total_files > 0 ? 70 + (int) floor(min(18, ($restored_files / max(1, $total_files)) * 18)) : 78, [
            'phase' => 'files',
            'phase_label' => self::restore_phase_label('files'),
            'files_restored' => $restored_files,
            'files_total' => $total_files,
            'checkpoint' => array_merge($checkpoint_base, [
              'database_done' => true,
              'files_done' => false,
              'files_index' => $i,
            ]),
          ], true);
        }
        throw $e;
      }

      $name = $zip->get_name_index($i);
      if (!is_string($name) || strpos($name, 'files/wp-content/') !== 0 || substr($name, -1) === '/') {
        continue;
      }

      $relative = substr($name, strlen('files/wp-content/'));
      if ($relative === '' || self::path_is_unsafe($relative)) {
        continue;
      }
      if (strpos($relative, $own_plugin_rel) === 0) {
        continue;
      }

      $target = self::safe_content_path($relative);
      wp_mkdir_p(dirname($target));

      $input = $zip->get_stream($name);
      if ($input === false) {
        /* translators: %s: file path relative to wp-content */
        throw new RuntimeException(sprintf(__('Could not read file from backup: %s', 'restorepilot-backup-migration'), $relative));
      }

      $tmp_target = $target . '.restorepilot-tmp-' . wp_generate_password(8, false, false);
      $output = fopen($tmp_target, 'wb');
      if ($output === false) {
        fclose($input);
        /* translators: %s: file path relative to wp-content */
        throw new RuntimeException(sprintf(__('Could not prepare restored file: %s', 'restorepilot-backup-migration'), $relative));
      }

      try {
        while (!feof($input)) {
          $chunk = fread($input, 1024 * 1024);
          if ($chunk === false) {
            /* translators: %s: file path relative to wp-content */
            throw new RuntimeException(sprintf(__('Could not restore file %s.', 'restorepilot-backup-migration'), $relative));
          }
          if ($chunk !== '') {
            self::write_stream($output, $tmp_target, $chunk, 'restore file');
          }
        }
      } catch (Throwable $e) {
        fclose($input);
        fclose($output);
        @unlink($tmp_target);
        throw $e;
      }

      fclose($input);
      fclose($output);

      if (!@rename($tmp_target, $target)) {
        @unlink($tmp_target);
        /* translators: %s: file path relative to wp-content */
        throw new RuntimeException(sprintf(__('Could not move restored file into place: %s.', 'restorepilot-backup-migration'), $relative));
      }

      $restored_files++;
      self::$restore_chunk_progress_made = true;
      if ($job_id !== '') {
        $progress = $total_files > 0 ? 70 + (int) floor(min(18, ($restored_files / max(1, $total_files)) * 18)) : 78;
        self::maybe_touch_restore_job($job_id, sprintf(
          /* translators: 1: number of files restored so far, 2: total number of files to restore */
          __('Restoring files... %1$d of %2$d', 'restorepilot-backup-migration'),
          $restored_files,
          max($restored_files, $total_files)
        ), $progress, [
          'phase' => 'files',
          'phase_label' => self::restore_phase_label('files'),
          'files_restored' => $restored_files,
          'files_total' => $total_files,
          // Folded into this same throttled call rather than persisted on
          // every file: a kill can therefore redo up to ~5s of already-
          // extracted files, always safe (see the docblock above) and far
          // cheaper than a database write per file on a site with thousands
          // of small ones.
          'checkpoint' => array_merge($checkpoint_base, [
            'database_done' => true,
            'files_done' => false,
            'files_index' => $i + 1,
          ]),
        ]);
      }
    }
  }

  /**
   * Adds a brand-new administrator account rather than touching any restored
   * one — deliberately: this runs right after the database swap, when the
   * user table is whatever the backup's source site had, on a target this
   * restore may not share credentials with (the common case this exists
   * for: restoring a different domain's backup where the source site's own
   * admin password isn't known or isn't one this site should carry). Never
   * logged and never written anywhere except the one job-record field the
   * completion UI reads to show it exactly once.
   */
  /**
   * Creates the post-restore administrator account.
   *
   * Runs after the database swap, so every uniqueness check here is made
   * against the RESTORED site's users — a name that was free before the
   * restore may well be taken in the backup, and the reverse.
   *
   * A requested username or email is honoured when it is valid and free, and
   * silently replaced with a generated one when it is not. Refusing outright
   * is not an option this late: the database is already replaced, and the
   * whole reason for this account is that the operator may have no other way
   * in. An account under a different name is recoverable; no account is not.
   * The name actually used is returned so the caller can report it.
   *
   * The password is always generated here. A password the operator chose
   * reaches the account through set_restore_admin_password() instead, after
   * the restore has finished, so that it never has to sit in the job record.
   */
  private static function create_new_admin_login(string $requested_email = ''): array {
    $email = trim($requested_email);
    if ($email === '' || !is_email($email)) {
      self::write_log('No usable email was given for the restore admin account; deriving one from this site instead.');
      $host = wp_parse_url(home_url(), PHP_URL_HOST);
      $host = is_string($host) && $host !== '' ? $host : 'example.com';
      $email = 'admin_' . strtolower(wp_generate_password(6, false, false)) . '@' . $host;
    }

    // An address already in use in the RESTORED site cannot be reused —
    // wp_insert_user() requires it to be unique, and quietly attaching to
    // someone else's account would be worse than failing. A tagged variant
    // keeps the operator's own mailbox as the recovery route, since the
    // plus-address still delivers to it.
    if (email_exists($email)) {
      self::write_log('The chosen admin email already exists in the restored site; tagging a variant so the account can still be created.');
      $parts = explode('@', $email, 2);
      $candidate = $parts[0] . '+rp' . substr(md5((string) wp_rand()), 0, 5) . '@' . $parts[1];
      $email = email_exists($candidate) ? '' : $candidate;

      if ($email === '') {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        $host = is_string($host) && $host !== '' ? $host : 'example.com';
        $email = 'admin_' . strtolower(wp_generate_password(6, false, false)) . '@' . $host;
      }
    }

    // WordPress needs a user_login even though sign-in here is by email, so
    // it is derived rather than asked for. It is never shown: the operator
    // signs in with the address they chose.
    $base = sanitize_user(strstr($email, '@', true), true);
    $base = $base !== '' ? strtolower($base) : 'admin';
    $username = $base;
    while ($username === '' || username_exists($username)) {
      $username = $base . '_' . strtolower(wp_generate_password(5, false, false));
    }

    // Always a throwaway. The operator's own password is applied afterwards by
    // handle_set_restore_admin_password(), so that it never has to be stored;
    // if that never happens, this value is unknown to everyone and the way in
    // is WordPress's own password reset, sent to the address above.
    $password = wp_generate_password(20, true, true);

    $user_id = wp_insert_user([
      'user_login' => $username,
      'user_pass' => $password,
      'user_email' => $email,
      'role' => 'administrator',
    ]);

    if (is_wp_error($user_id)) {
      self::write_log('Could not create new admin login: ' . $user_id->get_error_message());
      return [];
    }

    self::write_log('Created a new admin login for this restore (username only, never the password): ' . $username);

    return ['username' => $username, 'email' => $email, 'password' => $password, 'user_id' => (int) $user_id];
  }

  /**
   * Keeps only this plugin active for the rest of a restore, stashing the
   * restored site's real active_plugins list to be reinstated once every
   * file from the backup is on disk.
   *
   * The database phase's RENAME TABLE swap brings in the BACKUP's own
   * wp_options — including its active_plugins — while the file phase that
   * would put those plugins' code on disk has not run yet, and on a large
   * site will not finish for many more chunks. Every request the site serves
   * in that window boots WordPress, and wp-settings.php includes every
   * plugin named in active_plugins with no error handling around it, long
   * before the 'init' hook where maybe_block_for_maintenance() could
   * intervene. A plugin whose files are not there yet fatals the request
   * outright — including the restore's OWN next-chunk loopback request and
   * its WP-Cron fallback, which is what makes this unrecoverable rather than
   * merely ugly: the restore can never reach its next chunk, and the site
   * stays down, with no error surfaced anywhere.
   *
   * Confirmed live on a real 16 GB production restore: the moment the swap
   * landed, Advanced Custom Fields Pro fataled the next bootstrap. Removing
   * it from active_plugins by hand let the restore continue — and Yoast SEO
   * fataled the same way moments later. Yoast is the case that rules out the
   * obvious cheaper fix of "only skip plugins whose main file is missing":
   * wp-seo.php was present and it fataled anyway, on its own require_once of
   * wp-seo-main.php, which the file phase had not written yet. Nothing can
   * verify from the outside that a half-restored plugin will load, so the
   * only reliable answer is not to load any of them until the file phase is
   * genuinely done.
   *
   * This plugin's own files are never overwritten by a restore (restore_files()
   * skips its own directory precisely so the code driving the restore cannot
   * be swapped out from under itself mid-run), so leaving just this one
   * active is always safe.
   */
  private static function defer_active_plugins_during_restore(): void {
    $self = plugin_basename(RESTOREPILOT_BACKUP_MIGRATION_FILE);

    // The stash is written exactly once, on the first chunk to get past the
    // swap. This function runs again on EVERY later resumption (it sits on
    // the unconditional path after the database phase), where active_plugins
    // is already the minimal list written below — stashing again there would
    // overwrite the real list with that minimal one and lose the site's
    // plugin set permanently. get_option()'s null default distinguishes
    // "never stashed" from a legitimately stashed empty array.
    if (get_option(self::DEFERRED_PLUGINS_OPTION, null) === null) {
      $active = get_option('active_plugins', []);
      if (!is_array($active)) {
        $active = [];
      }
      update_option(self::DEFERRED_PLUGINS_OPTION, array_values($active), false);
      self::write_log(sprintf(
        'Restore: holding back %d plugin(s) from the restored database until the file phase finishes; only RestorePilot stays active until then.',
        count($active)
      ));
    }

    // Re-asserted every resumption rather than only alongside the stash:
    // cheap, and it self-heals if anything (a stray write, a partially
    // applied swap) puts a foreign list back while files are still landing.
    $current = get_option('active_plugins', []);
    if (!is_array($current) || array_values($current) !== [$self]) {
      update_option('active_plugins', [$self]);
    }
  }

  /**
   * Reinstates the list held back by defer_active_plugins_during_restore(),
   * now that every file from the backup is on disk.
   *
   * A plugin whose main file did not survive the restore is dropped rather
   * than reactivated — the same check cleanup_missing_active_plugins()
   * applies, for the same reason: a name in active_plugins whose file is
   * absent makes WordPress emit a "plugin file does not exist" error on the
   * next admin page load.
   */
  private static function restore_deferred_active_plugins(): void {
    $deferred = get_option(self::DEFERRED_PLUGINS_OPTION, null);
    if (!is_array($deferred)) {
      return;
    }

    $kept = [];
    $dropped = [];
    foreach ($deferred as $plugin) {
      $plugin = trim(str_replace('\\', '/', (string) $plugin), '/');
      if ($plugin === '') {
        continue;
      }
      if (self::active_plugin_file_exists($plugin)) {
        $kept[] = $plugin;
      } else {
        $dropped[] = $plugin;
      }
    }

    // This plugin stays active regardless of what the restored list said: the
    // browser is still polling it for this restore's own completion status,
    // and a backup taken on a site where RestorePilot was inactive would
    // otherwise deactivate it here, mid-poll.
    $self = plugin_basename(RESTOREPILOT_BACKUP_MIGRATION_FILE);
    if (!in_array($self, $kept, true)) {
      $kept[] = $self;
    }

    update_option('active_plugins', array_values($kept));
    delete_option(self::DEFERRED_PLUGINS_OPTION);

    self::write_log(sprintf(
      'Restore: reactivated %d plugin(s) from the restored database.%s',
      count($kept),
      $dropped
        ? ' Left deactivated because their files are not in the backup: ' . implode(', ', $dropped) . '.'
        : ''
    ));
  }

  /**
   * True when a restore held plugins back (see above) and never got far
   * enough to put them back — i.e. it failed or was abandoned partway.
   *
   * Deliberately does NOT reactivate them automatically. The files on disk
   * at that point are whatever a halted file phase happened to leave there,
   * so some plugins may be half-written; reactivating them unprompted is the
   * very failure this whole mechanism exists to avoid, only now with nothing
   * driving a restore that could recover from it. Surfaced as an admin
   * notice instead, so a human decides when the site is in a fit state.
   */
  private static function has_orphaned_deferred_plugins(): bool {
    if (!is_array(get_option(self::DEFERRED_PLUGINS_OPTION, null))) {
      return false;
    }

    return !self::restore_lock_is_active();
  }

  private static function cleanup_missing_active_plugins(): void {
    $removed = [];
    $active = get_option('active_plugins', []);

    if (is_array($active)) {
      $kept = [];
      foreach ($active as $plugin) {
        $plugin = trim(str_replace('\\', '/', (string) $plugin), '/');
        if ($plugin === '') {
          continue;
        }
        if (self::active_plugin_file_exists($plugin)) {
          $kept[] = $plugin;
        } else {
          $removed[] = $plugin;
        }
      }

      if ($kept !== $active) {
        update_option('active_plugins', array_values($kept));
      }
    }

    if (is_multisite()) {
      $network_active = get_site_option('active_sitewide_plugins', []);
      if (is_array($network_active)) {
        $kept_network = [];
        foreach ($network_active as $plugin => $activated_at) {
          $plugin = trim(str_replace('\\', '/', (string) $plugin), '/');
          if ($plugin === '') {
            continue;
          }
          if (self::active_plugin_file_exists($plugin)) {
            $kept_network[$plugin] = $activated_at;
          } else {
            $removed[] = $plugin;
          }
        }

        if ($kept_network !== $network_active) {
          update_site_option('active_sitewide_plugins', $kept_network);
        }
      }
    }

    $removed = array_values(array_unique($removed));
    if ($removed) {
      self::write_log('Removed missing active plugin references after restore: ' . implode(', ', $removed));
    }
  }

  private static function active_plugin_file_exists(string $plugin): bool {
    $plugin = trim(str_replace('\\', '/', $plugin), '/');
    if ($plugin === '' || self::path_is_unsafe($plugin)) {
      return false;
    }

    return is_file(trailingslashit(self::plugins_dir()) . $plugin);
  }

  /**
   * $restored_file_index is the resumption's files_index checkpoint — the
   * entry index restore_files() will start from. Entries before it were
   * already written to disk by an earlier chunk and so are already counted
   * in the free-space figure below; re-counting them would demand room for
   * a second copy of content that is already there. Without this, a
   * resumable restore of a backup larger than the free space remaining
   * could never finish no matter how much progress it had made: every
   * resumption re-estimated the whole archive from zero and refused to
   * continue, even with only a fraction of it actually left to write.
   */
  private static function assert_restore_disk_space(string $zip_path, RestorePilot_Backup_Archive $zip, int $restored_file_index = 0): void {
    $free = @disk_free_space(self::content_dir());
    if ($free === false) {
      self::write_log('Disk space check skipped — could not read free space.');
      return;
    }

    // Sum uncompressed sizes from the ZIP central directory for a realistic
    // estimate, counting only what is still left to write (see the docblock).
    $total_entries = $zip->num_files();
    $start_index = max(0, min($restored_file_index, $total_entries));
    $uncompressed = 0;
    for ($i = $start_index; $i < $total_entries; $i++) {
      $stat = $zip->stat_index($i);
      if ($stat !== false) {
        $uncompressed += (int) $stat['size'];
      }
    }

    // If the ZIP reports no sizes (e.g. ZIP64 without local headers), fall back
    // to 3× the compressed file size as a conservative estimate. Scaled by the
    // fraction still outstanding, for the same reason the exact sum above is:
    // a whole-archive figure would demand room for content already restored.
    if ($uncompressed === 0) {
      // Size the whole volume set, not just its first volume.
      $compressed = 0;
      foreach (self::volume_paths_for($zip_path) as $volume_path) {
        $size = @filesize($volume_path);
        if ($size !== false) {
          $compressed += (int) $size;
        }
      }
      $remaining_fraction = $total_entries > 0 ? (($total_entries - $start_index) / $total_entries) : 1.0;
      $uncompressed = (int) ($compressed * 3 * $remaining_fraction);
    }

    // Require at least 20 MB overhead on top of the uncompressed content.
    $needed = $uncompressed + 20 * 1024 * 1024;

    self::write_log(sprintf(
      'Restore disk check: free %s, estimated needed %s%s.',
      size_format((int) $free),
      size_format($needed),
      $start_index > 0
        ? sprintf(' for the %d of %d entries still to restore', $total_entries - $start_index, $total_entries)
        : ''
    ));

    if ($free < $needed) {
      throw new RuntimeException(sprintf(
        /* translators: %1$s: free disk space, %2$s: estimated space needed */
        __('Not enough free disk space to safely restore this backup. Available: %1$s. Estimated needed: %2$s. Free up disk space and try again.', 'restorepilot-backup-migration'),
        size_format((int) $free),
        size_format($needed)
      ));
    }
  }

  /**
   * Where the bar should sit while the restore's table pass is $done tables
   * into $total, interpolated across the span that pass owns.
   *
   * The surrounding restore phases report fixed figures — validating 12,
   * rollback 24, maintenance 36, database 48, swap 64, files 70, finalizing
   * 92 — so restoring tables occupies 48 up to 64. Left at the single figure
   * it used to report, the bar sat unchanged for the whole pass, which on a
   * site with many tables is minutes of looking exactly like a dead restore.
   */
  private static function restore_database_phase_progress(int $done, int $total): int {
    $floor = 48;
    $ceiling = 64;
    if ($total < 1) {
      return $floor;
    }
    $ratio = max(0.0, min(1.0, $done / $total));
    // Stops one short of the ceiling: 64 is the table-swap step's own figure,
    // and reaching it here would announce a step that has not started.
    return min($ceiling - 1, $floor + (int) floor($ratio * ($ceiling - $floor)));
  }

  /**
   * "Restoring database (table 123 of 149)" — the count is the point. The
   * position was already being tracked to make the restore resumable; this
   * only surfaces what the checkpoint already knows, so a restore that is
   * working can be told apart from one that has stopped.
   */
  private static function restore_database_phase_label(int $position, int $total): string {
    if ($total < 1) {
      return self::restore_phase_label('database');
    }

    return sprintf(
      /* translators: 1: number of the table being restored, 2: total tables to restore */
      __('Restoring database (table %1$d of %2$d)', 'restorepilot-backup-migration'),
      $position,
      $total
    );
  }

  private static function prepare_restore_upload(): string {
    // The chunked upload reports where it put the file in its own field, so a
    // path the operator typed and a path the browser just uploaded stay
    // separate. Both are validated identically below -- being written by our
    // own JavaScript earns the uploaded one no trust it has not been checked
    // for, since anything reaching here arrived in a request.
    $server_path = trim(self::post_value('uploaded_backup_path'));
    if ($server_path === '') {
      $server_path = self::post_value('server_backup_path');
    }
    $server_path = trim($server_path);
    if ($server_path !== '') {
      $server_path = self::normalize_server_path($server_path);
      if (!preg_match('/\.zip$/i', basename($server_path))) {
        throw new RuntimeException(__('Server backup path must point to a zip file.', 'restorepilot-backup-migration'));
      }
      if (!self::server_backup_path_is_allowed($server_path)) {
        throw new RuntimeException(__('Server backup path must be inside this site\'s WordPress uploads directory.', 'restorepilot-backup-migration'));
      }
      if (!is_file($server_path) || !is_readable($server_path)) {
        throw new RuntimeException(__('Server backup path is not readable.', 'restorepilot-backup-migration'));
      }
      if ((int) filesize($server_path) < 1) {
        throw new RuntimeException(__('Server backup file is empty.', 'restorepilot-backup-migration'));
      }
      self::write_log('Restore using server backup path: ' . basename($server_path));
      return $server_path;
    }

    $backup_upload = self::uploaded_file_array('backup_upload');
    if (!$backup_upload) {
      throw new RuntimeException(self::missing_restore_upload_message());
    }

    $files = self::normalize_uploaded_files($backup_upload);
    if (!$files) {
      throw new RuntimeException(self::missing_restore_upload_message());
    }

    if (count($files) === 1 && preg_match('/\.zip$/i', $files[0]['name'])) {
      if (!is_uploaded_file($files[0]['tmp_name'])) {
        throw new RuntimeException(__('Please upload a valid backup zip.', 'restorepilot-backup-migration'));
      }
      if (!function_exists('wp_handle_upload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
      }
      // Route the uploaded zip through WordPress' upload handler for its
      // is_uploaded_file verification and filename sanitization. test_type is
      // disabled because the archive is validated structurally (zip integrity,
      // manifest, and per-entry path safety) by validate_backup_zip() before any
      // data is restored — a stronger guarantee than a MIME sniff for a backup.
      $zip_upload = [
        'name'     => $files[0]['name'],
        'type'     => 'application/zip',
        'tmp_name' => $files[0]['tmp_name'],
        'error'    => 0,
        'size'     => $files[0]['size'],
      ];
      $handled_zip = wp_handle_upload($zip_upload, [
        'test_form' => false,
        'test_type' => false,
      ]);
      if (!is_array($handled_zip) || isset($handled_zip['error']) || empty($handled_zip['file'])) {
        throw new RuntimeException(__('Could not save uploaded backup zip.', 'restorepilot-backup-migration'));
      }
      $restore_path = self::storage_dir() . '/restore-upload-' . gmdate('Ymd-His') . '-' . wp_generate_uuid4() . '-' . sanitize_file_name($files[0]['name']);
      if (!@rename($handled_zip['file'], $restore_path)) {
        if (!@copy($handled_zip['file'], $restore_path)) {
          @unlink($handled_zip['file']);
          throw new RuntimeException(__('Could not save uploaded backup zip.', 'restorepilot-backup-migration'));
        }
        @unlink($handled_zip['file']);
      }
      return $restore_path;
    }

    foreach ($files as $file) {
      if (!preg_match('/\.zip\.part[0-9]{3}$/i', $file['name'])) {
        throw new RuntimeException(__('When restoring from safe download files, select only RestorePilot part files.', 'restorepilot-backup-migration'));
      }
      if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException(__('One or more uploaded part files are invalid.', 'restorepilot-backup-migration'));
      }
    }

    usort($files, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

    if (!function_exists('wp_handle_upload')) {
      require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    // Move each uploaded part through WordPress' upload handler (same as the
    // single-zip and chunk paths), then reassemble the full zip from the moved
    // files. test_type is disabled because .part fragments are not standalone
    // typed files; the reassembled archive is validated by validate_backup_zip()
    // before any restore runs.
    $moved_parts = [];
    foreach ($files as $file) {
      $part_upload = [
        'name'     => $file['name'],
        'type'     => 'application/octet-stream',
        'tmp_name' => $file['tmp_name'],
        'error'    => 0,
        'size'     => $file['size'],
      ];
      $handled_part = wp_handle_upload($part_upload, [
        'test_form' => false,
        'test_type' => false,
      ]);
      if (!is_array($handled_part) || isset($handled_part['error']) || empty($handled_part['file'])) {
        foreach ($moved_parts as $mp) {
          @unlink($mp);
        }
        throw new RuntimeException(__('One or more uploaded part files are invalid.', 'restorepilot-backup-migration'));
      }
      $moved_parts[] = $handled_part['file'];
    }

    $restore_path = self::storage_dir() . '/restore-upload-' . gmdate('Ymd-His') . '-' . wp_generate_uuid4() . '.zip';
    $output = fopen($restore_path, 'wb');
    if ($output === false) {
      foreach ($moved_parts as $mp) {
        @unlink($mp);
      }
      throw new RuntimeException(__('Could not prepare uploaded backup parts.', 'restorepilot-backup-migration'));
    }

    foreach ($moved_parts as $part_path) {
      $input = fopen($part_path, 'rb');
      if ($input === false) {
        fclose($output);
        @unlink($restore_path);
        foreach ($moved_parts as $mp) {
          @unlink($mp);
        }
        throw new RuntimeException(__('Could not read one of the uploaded backup parts.', 'restorepilot-backup-migration'));
      }

      while (!feof($input)) {
        $chunk = fread($input, 1024 * 1024);
        if ($chunk === false) {
          fclose($input);
          fclose($output);
          @unlink($restore_path);
          foreach ($moved_parts as $mp) {
            @unlink($mp);
          }
          throw new RuntimeException(__('Could not combine uploaded backup parts.', 'restorepilot-backup-migration'));
        }
        if ($chunk !== '') {
          fwrite($output, $chunk);
        }
      }
      fclose($input);
    }

    fclose($output);
    foreach ($moved_parts as $mp) {
      @unlink($mp);
    }
    return $restore_path;
  }

  private static function restore_chunk_dir(string $upload_id): string {
    return self::storage_dir() . '/restore-chunks/' . sanitize_key($upload_id);
  }

  private static function server_backup_path_is_allowed(string $path): bool {
    $real_path = realpath($path);
    if ($real_path === false || !is_file($real_path)) {
      return false;
    }

    $upload = wp_upload_dir(null, false);
    if (!empty($upload['error']) || empty($upload['basedir'])) {
      return false;
    }

    $uploads_base = realpath($upload['basedir']);
    if ($uploads_base === false) {
      return false;
    }

    $real_path = str_replace('\\', '/', $real_path);
    $uploads_base = rtrim(str_replace('\\', '/', $uploads_base), '/');
    return $real_path === $uploads_base || strpos($real_path, $uploads_base . '/') === 0;
  }

  private static function assemble_restore_chunks(string $upload_id, string $file_name, int $total_chunks): string {
    $chunk_dir = self::restore_chunk_dir($upload_id);

    // Every chunk is uploaded — and so already sitting on disk, all of it —
    // before assembly ever starts (see handle_chunk_restore_upload(), which
    // only calls this once the final chunk has landed). Whatever the chunk
    // set's own total size is has therefore ALREADY been spent; it is not
    // additional space this function is about to consume. Combined with the
    // incremental per-chunk unlink() below (each chunk is freed the moment
    // its bytes are durably in the combined file), the only NEW headroom
    // this loop ever actually needs at once is one chunk's worth — briefly,
    // the chunk currently being read still exists on disk at the same
    // moment its bytes have already been written to the combined file,
    // right before that chunk's own unlink() runs. Checking against the
    // chunk set's full total here — as an earlier version of this check
    // did — would wrongly require the destination to ALSO have another
    // full copy's worth of free space on top of the chunks already using
    // it, defeating the entire point of freeing each one as it's consumed.
    $chunk_paths = [];
    $total_size = 0;
    $max_chunk_size = 0;
    for ($i = 0; $i < $total_chunks; $i++) {
      $part_path = $chunk_dir . '/part-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT);
      $chunk_paths[] = $part_path;
      $size = @filesize($part_path);
      if ($size !== false) {
        $total_size += $size;
        $max_chunk_size = max($max_chunk_size, $size);
      }
    }

    $free = @disk_free_space(self::storage_dir());
    if ($free !== false) {
      $needed = max($max_chunk_size, self::PART_SIZE) + 20 * 1024 * 1024;
      self::write_log(sprintf(
        'Restore upload assembly disk check: free %s, estimated transient headroom needed %s (chunk set already on disk: %s).',
        size_format((int) $free),
        size_format($needed),
        size_format($total_size)
      ));
      if ($free < $needed) {
        throw new RuntimeException(sprintf(
          /* translators: %1$s: free disk space, %2$s: transient space needed to assemble one chunk at a time */
          __('Not enough free disk space to assemble this upload. Available: %1$s. Estimated headroom needed: %2$s. The already-uploaded pieces are removed after this failure like any other failed attempt, so free up disk space before uploading again — the file will need to be uploaded from the start.', 'restorepilot-backup-migration'),
          size_format((int) $free),
          size_format($needed)
        ));
      }
    } else {
      self::write_log('Restore upload assembly disk check skipped — could not read free space.');
    }

    $restore_path = self::storage_dir() . '/restore-upload-' . gmdate('Ymd-His') . '-' . wp_generate_uuid4() . '-' . sanitize_file_name($file_name);
    $output = fopen($restore_path, 'wb');
    if ($output === false) {
      throw new RuntimeException(__('Could not create assembled restore upload.', 'restorepilot-backup-migration'));
    }

    try {
      foreach ($chunk_paths as $part_path) {
        if (!is_file($part_path) || !is_readable($part_path)) {
          throw new RuntimeException(__('One or more restore upload chunks are missing.', 'restorepilot-backup-migration'));
        }

        $input = fopen($part_path, 'rb');
        if ($input === false) {
          throw new RuntimeException(__('Could not read restore upload chunk.', 'restorepilot-backup-migration'));
        }

        while (!feof($input)) {
          $chunk = fread($input, 1024 * 1024);
          if ($chunk === false) {
            fclose($input);
            throw new RuntimeException(__('Could not assemble restore upload.', 'restorepilot-backup-migration'));
          }
          if ($chunk !== '') {
            self::write_stream($output, $restore_path, $chunk, 'assemble restore upload');
          }
        }
        fclose($input);

        // Freed as soon as this chunk's content is durably in the combined
        // file, not left until the whole set finishes — this is what keeps
        // peak disk usage close to the backup's own size instead of double
        // it. Safe even if a later chunk then fails: the failure path below
        // still removes the whole chunk directory, and any already-freed
        // chunk here simply has nothing left to remove.
        @unlink($part_path);
      }
    } catch (Throwable $e) {
      fclose($output);
      @unlink($restore_path);
      throw $e;
    }

    fclose($output);
    if (!is_file($restore_path) || (int) filesize($restore_path) < 1) {
      @unlink($restore_path);
      throw new RuntimeException(__('Assembled restore upload is empty.', 'restorepilot-backup-migration'));
    }

    return $restore_path;
  }

  private static function cleanup_restore_chunk_uploads(): void {
    $base = self::storage_dir() . '/restore-chunks';
    if (!is_dir($base)) {
      return;
    }

    $entries = glob($base . '/*', GLOB_ONLYDIR) ?: [];
    foreach ($entries as $dir) {
      if (!is_dir($dir)) {
        continue;
      }

      $mtime = (int) filemtime($dir);
      if ($mtime > 0 && (time() - $mtime) < 6 * HOUR_IN_SECONDS) {
        continue;
      }
      self::delete_directory($dir, self::storage_dir());
    }
  }

  private static function normalize_uploaded_files(array $upload): array {
    $files = [];
    $names = $upload['name'] ?? [];
    $tmp_names = $upload['tmp_name'] ?? [];
    $errors = $upload['error'] ?? [];
    $sizes = $upload['size'] ?? [];

    if (!is_array($names)) {
      $names = [$names];
      $tmp_names = [$tmp_names];
      $errors = [$errors];
      $sizes = [$sizes];
    }

    foreach ($names as $i => $name) {
      $error = (int) ($errors[$i] ?? UPLOAD_ERR_NO_FILE);
      if ($error === UPLOAD_ERR_NO_FILE) {
        continue;
      }
      if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(self::upload_error_message($error, (string) $name));
      }

      $files[] = [
        'name' => sanitize_file_name((string) $name),
        'tmp_name' => (string) ($tmp_names[$i] ?? ''),
        'size' => (int) ($sizes[$i] ?? 0),
      ];
    }

    return $files;
  }

  private static function upload_error_message(int $error, string $name = ''): string {
    $filename = sanitize_file_name($name);
    /* translators: %s: uploaded file name */
    $label = $filename !== '' ? sprintf(__('Upload failed for %s.', 'restorepilot-backup-migration'), $filename) . ' ' : '';
    $limit = size_format((int) wp_max_upload_size());

    switch ($error) {
      case UPLOAD_ERR_INI_SIZE:
      case UPLOAD_ERR_FORM_SIZE:
        return $label . sprintf(
          /* translators: %s: maximum allowed browser upload size */
          __('The backup is larger than this server allows for browser uploads. Current maximum upload size: %s. Upload the zip into this site\'s uploads directory first, then use Advanced restore settings > Server backup path.', 'restorepilot-backup-migration'),
          $limit
        );
      case UPLOAD_ERR_PARTIAL:
        return $label . __('The upload was interrupted before the full backup arrived. Try again, or use Server backup path for large backups already inside this site\'s uploads directory.', 'restorepilot-backup-migration');
      case UPLOAD_ERR_NO_TMP_DIR:
        return $label . __('The server is missing a temporary upload folder. Ask the host to fix PHP uploads, or use Server backup path for a backup already inside this site\'s uploads directory.', 'restorepilot-backup-migration');
      case UPLOAD_ERR_CANT_WRITE:
        return $label . __('The server could not write the uploaded backup to disk. Check disk space and permissions, or use Server backup path for a backup already inside this site\'s uploads directory.', 'restorepilot-backup-migration');
      case UPLOAD_ERR_EXTENSION:
        return $label . __('A PHP extension stopped the upload. Use Server backup path for a backup already inside this site\'s uploads directory, or check server security/upload settings.', 'restorepilot-backup-migration');
      default:
        return $label . sprintf(
          /* translators: %d: PHP upload error code */
          __('The upload failed with PHP error code %d. For large backups, upload the zip into this site\'s uploads directory first and use Advanced restore settings > Server backup path.', 'restorepilot-backup-migration'),
          $error
        );
    }
  }

  private static function missing_restore_upload_message(): string {
    return sprintf(
      /* translators: %s: maximum allowed browser upload size */
      __('Please upload a backup zip or use Advanced restore settings > Server backup path for a zip already inside this site\'s uploads directory. If you selected a file, it may be larger than this server allows for browser uploads. Current maximum upload size: %s.', 'restorepilot-backup-migration'),
      size_format((int) wp_max_upload_size())
    );
  }

  /**
   * The restore currently holding the site-wide lock, if any, plus whether it
   * still looks alive. Used by the maintenance page an administrator sees.
   */
  private static function active_restore_snapshot(): array {
    $lock = get_option(self::RESTORE_LOCK_OPTION, []);
    $job_id = (is_array($lock) && !empty($lock['job_id'])) ? (string) $lock['job_id'] : '';
    $job = $job_id !== '' ? self::get_restore_job($job_id) : [];
    $updated = (int) ($job['updated'] ?? $job['created'] ?? 0);
    $since = $updated > 0 ? max(0, time() - $updated) : -1;

    return [
      'job_id' => $job_id,
      'job' => is_array($job) ? $job : [],
      'seconds_since_update' => $since,
      // A live restore touches its job record at least every few seconds
      // (maybe_touch_restore_job throttles to one write per 5s), so several
      // minutes of silence means it is no longer running — well before the
      // two hours the lock's own staleness check waits for.
      'looks_stuck' => $since < 0 || $since > 5 * MINUTE_IN_SECONDS,
    ];
  }

  private static function restore_phase_label(string $phase): string {
    $labels = [
      'queued' => __('Queued', 'restorepilot-backup-migration'),
      'starting' => __('Starting restore', 'restorepilot-backup-migration'),
      'validating' => __('Validating backup', 'restorepilot-backup-migration'),
      'rollback' => __('Creating rollback point', 'restorepilot-backup-migration'),
      'maintenance' => __('Enabling maintenance mode', 'restorepilot-backup-migration'),
      'database' => __('Restoring database', 'restorepilot-backup-migration'),
      'files' => __('Restoring files', 'restorepilot-backup-migration'),
      'finalizing' => __('Finalizing restore', 'restorepilot-backup-migration'),
      'complete' => __('Complete', 'restorepilot-backup-migration'),
      'error' => __('Error', 'restorepilot-backup-migration'),
      'stale' => __('Needs attention', 'restorepilot-backup-migration'),
    ];

    return $labels[$phase] ?? __('Working', 'restorepilot-backup-migration');
  }
}
