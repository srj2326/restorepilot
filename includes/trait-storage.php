<?php
/**
 * Where files live: the storage directory, archives, volumes, and downloads.
 *
 * @package RestorePilot_Backup_Migration
 */

if (!defined('ABSPATH')) {
  exit;
}

trait RestorePilot_Storage {
  /**
   * Picks the newest WordPress-bundled default theme that is both installed
   * on this site and passes validate_theme_requirements() (WP/PHP version
   * compatibility) — the same check switch_theme() itself runs internally.
   * Returns '' if none of them are usable, so Master Reset can refuse
   * cleanly instead of calling switch_theme() with something it would then
   * wp_die() on.
   */
  private static function pick_master_reset_theme(): string {
    $candidates = [
      'twentytwentyfive', 'twentytwentyfour', 'twentytwentythree', 'twentytwentytwo',
      'twentytwentyone', 'twentytwenty', 'twentynineteen', 'twentyseventeen',
      'twentysixteen', 'twentyfifteen', 'twentyfourteen', 'twentythirteen',
      'twentytwelve', 'twentyeleven', 'twentyten',
    ];
    foreach ($candidates as $slug) {
      if (!wp_get_theme($slug)->exists()) {
        continue;
      }
      if (!is_wp_error(validate_theme_requirements($slug))) {
        return $slug;
      }
    }
    return '';
  }

  /**
   * Empty $dir (but keep $dir itself) of everything, confined to $allowed_parent.
   * Returns false if the operation was refused, or if anything inside $dir
   * could not be removed.
   */
  private static function master_reset_wipe_dir(string $dir, string $allowed_parent): bool {
    $real_dir    = realpath($dir);
    $real_parent = realpath($allowed_parent);
    if ($real_dir === false || $real_parent === false || !is_dir($real_dir)) { return false; }
    // Safety: dir must be inside allowed_parent, not equal to it
    $real_dir_s    = str_replace('\\', '/', $real_dir);
    $real_parent_s = rtrim(str_replace('\\', '/', $real_parent), '/');
    if ($real_dir_s === $real_parent_s || strpos($real_dir_s, $real_parent_s . '/') !== 0) { return false; }

    // RestorePilot's own storage (stored backups, rollback points, and — via
    // Advanced restore settings > Server backup path — a backup zip the user
    // was told to place inside wp-content/uploads for exactly this kind of
    // large-file restore) is never itself "site content" any more than the
    // plugin's own files are, which Master Reset already keeps (see the
    // "delete all plugins except RestorePilot" step above). Wiping it here
    // once destroyed a restore file the user had staged there minutes
    // earlier, and would just as easily destroy the "download a backup
    // first" safety copy this same reset's own confirmation modal tells
    // the user to make, if that download step were skipped in favor of the
    // one already sitting on the server.
    $real_storage_dir = realpath(self::storage_dir());

    $all_removed = true;
    foreach (new DirectoryIterator($real_dir) as $item) {
      if ($item->isDot()) { continue; }
      $item_path = $item->getPathname();
      if ($real_storage_dir !== false && realpath($item_path) === $real_storage_dir) {
        continue;
      }
      if ($item->isDir()) {
        if (!self::delete_directory($item_path, $real_dir)) {
          $all_removed = false;
        }
      } else {
        if (!@unlink($item_path) && file_exists($item_path)) {
          $all_removed = false;
        }
      }
    }

    return $all_removed;
  }

  /**
   * Build a partial zip from an existing backup, returning the path to the
   * temporary zip file.  The caller is responsible for deleting it.
   *
   * @param string $src_file Absolute path to the source backup zip.
   * @param string $part     One of: database | plugins | themes | uploads
   * @return string Absolute path to the temporary partial zip.
   */
  /**
   * $src_file is the backup's base (volume 1) filename — open_backup_archive()
   * discovers every sibling volume from it, the same way perform_restore()
   * does, so a partial download of a split backup finds a match no matter
   * which volume it actually landed in instead of silently only ever seeing
   * volume 1's own entries.
   */
  private static function build_partial_zip(string $src_file, string $part): string {
    $src = self::open_backup_archive($src_file);

    $tmp_path = self::storage_dir() . '/partial-' . wp_generate_uuid4() . '.zip';
    $writer   = RestorePilot_Backup_Zip_Writer::create($tmp_path);

    try {
      $partial_manifest = self::partial_backup_manifest($src, $part);
      $partial_manifest_json = wp_json_encode($partial_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
      if (!is_string($partial_manifest_json) || $partial_manifest_json === '') {
        throw new RuntimeException(__('Could not prepare partial archive manifest.', 'restorepilot-backup-migration'));
      }
      if ($writer->addFromString('manifest.json', $partial_manifest_json) === false) {
        throw new RuntimeException(__('Could not add partial archive manifest.', 'restorepilot-backup-migration'));
      }

      if ($part === 'database') {
        $database_parts = self::database_part_names($partial_manifest);
        if ($database_parts) {
          // Copy each newline-delimited part across by streaming it through a
          // temporary file rather than materialising it in memory, so this
          // works for a database export of any size.
          $staging = self::storage_dir() . '/partial-db-' . wp_generate_uuid4();
          if (!wp_mkdir_p($staging) && !is_dir($staging)) {
            throw new RuntimeException(__('Could not prepare partial archive storage.', 'restorepilot-backup-migration'));
          }
          try {
            foreach ($database_parts as $part_name) {
              $in = $src->get_stream($part_name);
              if (!is_resource($in)) {
                throw new RuntimeException(sprintf(
                  /* translators: %s: name of the missing database export part inside the backup archive */
                  __('Backup database export part %s is missing or unreadable.', 'restorepilot-backup-migration'),
                  $part_name
                ));
              }
              $staged = $staging . '/' . basename($part_name);
              $out = fopen($staged, 'wb');
              if ($out === false) {
                fclose($in);
                throw new RuntimeException(__('Could not prepare partial archive storage.', 'restorepilot-backup-migration'));
              }
              stream_copy_to_stream($in, $out);
              fclose($in);
              fclose($out);
              $writer->addFile($staged, $part_name);
            }
          } finally {
            self::delete_directory($staging, self::storage_dir());
          }
        } else {
          $content = $src->get_from_name('database.json');
          if (is_string($content) && $content !== '') {
            $writer->addFromString('database.json', $content);
          }
        }
      } else {
        // Determine which zip entries to include.
        // 'others' = everything in files/wp-content/ that is NOT in the four
        // named subtrees (plugins, themes, uploads, mu-plugins).
        $exclude_prefixes_for_others = [
          'files/wp-content/plugins/',
          'files/wp-content/themes/',
          'files/wp-content/uploads/',
          'files/wp-content/mu-plugins/',
        ];

        // For all named parts the prefix is the straightforward subtree path.
        $zip_prefix = ($part === 'others') ? '' : 'files/wp-content/' . $part . '/';

        for ($i = 0; $i < $src->num_files(); $i++) {
          $entry_name = $src->get_name_index($i);
          if (!is_string($entry_name) || $entry_name === '') {
            continue;
          }
          if (substr($entry_name, -1) === '/') {
            continue; // skip directory entries — files carry the directory implicitly
          }

          if ($part === 'others') {
            // Must be inside files/wp-content/ but outside the four named subtrees
            if (strpos($entry_name, 'files/wp-content/') !== 0) {
              continue;
            }
            $excluded = false;
            foreach ($exclude_prefixes_for_others as $excl) {
              if (strpos($entry_name, $excl) === 0) {
                $excluded = true;
                break;
              }
            }
            if ($excluded) {
              continue;
            }
          } else {
            if (strpos($entry_name, $zip_prefix) !== 0) {
              continue;
            }
          }

          $stream = $src->get_stream($entry_name);
          if ($stream === false) {
            continue;
          }

          // Write each entry to its own short-lived temp file to avoid loading
          // large media files entirely into PHP memory.
          $tmp_entry = self::storage_dir() . '/rp-ent-' . uniqid('', true) . '.tmp';
          $fh = fopen($tmp_entry, 'wb');
          if ($fh === false) {
            fclose($stream);
            continue;
          }

          try {
            stream_copy_to_stream($stream, $fh);
            fclose($stream);
            fclose($fh);
            $writer->addFile($tmp_entry, $entry_name);
          } finally {
            // Ensure the per-entry temp is always removed, even if addFile throws.
            @unlink($tmp_entry);
          }
        }
      }

      $writer->close();
    } catch (Throwable $e) {
      $writer->abort();
      $src->close();
      @unlink($tmp_path);
      throw $e;
    }

    $src->close();
    return $tmp_path;
  }

  private static function partial_backup_manifest(RestorePilot_Backup_Archive $src, string $part): array {
    $manifest = [];
    $manifest_raw = $src->get_from_name('manifest.json');
    if (is_string($manifest_raw) && $manifest_raw !== '') {
      $decoded = json_decode($manifest_raw, true);
      if (is_array($decoded)) {
        $manifest = $decoded;
      }
    }

    $source_created_gmt = isset($manifest['created_gmt']) ? (string) $manifest['created_gmt'] : '';
    $source_home_url = isset($manifest['home_url']) ? (string) $manifest['home_url'] : '';

    $manifest['plugin'] = self::SLUG;
    $manifest['version'] = self::VERSION;
    $manifest['backup_type'] = 'partial';
    $manifest['partial_type'] = sanitize_key($part);
    $manifest['restorable'] = false;
    $manifest['created_gmt'] = gmdate('c');
    $manifest['source_backup_created_gmt'] = $source_created_gmt;
    $manifest['source_home_url'] = $source_home_url;
    $manifest['includes_database'] = $part === 'database';
    if ($part !== 'database') {
      // A files-only partial carries no database export, so it must not
      // inherit the source archive's part descriptors and claim one.
      unset($manifest['database_format'], $manifest['database_parts']);
    }
    $manifest['includes_files'] = $part !== 'database';
    $manifest['restore_note'] = 'Partial archives are for manual recovery only. Use the full backup archive for RestorePilot restore or migration.';
    // Same reasoning as rewrite_combined_manifest_entry(): this manifest was
    // copied from a source that may have been split into several volumes,
    // but a partial archive is always written as a single file with no
    // siblings. Left at the source's true count, open_backup_archive()'s
    // completeness check (used by Backup Check, `wp restorepilot health`,
    // and a restore attempt against this file) would reject it as an
    // incomplete multi-volume set — before restorable=false above is ever
    // even reached to produce the correct, more specific error instead.
    $manifest['volumes'] = 1;

    return $manifest;
  }

  private static function serve_download(): void {
    self::verify_admin_request();
    $file = self::safe_backup_file_from_request();

    if (!is_file($file) || !is_readable($file)) {
      self::redirect_error(__('Backup file not found.', 'restorepilot-backup-migration'));
    }

    $action = sanitize_key(self::query_value('action'));

    // The base name of a multi-volume backup — requested via the ordinary
    // "restorepilot_download" action, never an explicit "..._stream" request
    // for one exact volume (used by the individual-volume fallback links,
    // and by follow-on volumes, which name exactly one physical file) —
    // gets reconstructed into one combined download instead of only ever
    // handing back its first volume. See serve_combined_volume_download()
    // for why that has to be streamed on the fly rather than written to a
    // second file on this server first.
    if ($action !== 'restorepilot_download_stream' && !self::is_follow_on_volume(basename($file))) {
      $volumes = self::volume_paths_for($file);
      if (count($volumes) > 1) {
        self::serve_combined_volume_download($file, $volumes);
        return;
      }
    }

    $size = filesize($file);
    if ($size === false || $size < 1) {
      self::redirect_error(__('Backup file is empty or unreadable.', 'restorepilot-backup-migration'));
    }

    $is_full_zip = preg_match('/\.zip$/i', basename($file));
    if ($action !== 'restorepilot_download_stream' && $is_full_zip && $size > self::PART_SIZE) {
      $url = self::create_direct_download_url($file);
      self::write_log('Direct web-server download prepared for: ' . basename($file));
      wp_safe_redirect($url);
      exit;
    }

    self::write_log('Download started: ' . basename($file) . ' (' . size_format((int) $size) . ').');

    // Disable output compression/buffering so large backup archives stream to the
    // browser byte-for-byte without being altered or held entirely in memory.
    @ini_set('zlib.output_compression', 'Off'); // phpcs:ignore WordPress.PHP.IniSet.Risky
    @ini_set('output_buffering', 'Off'); // phpcs:ignore WordPress.PHP.IniSet.Risky
    if (function_exists('set_time_limit')) {
      @set_time_limit(0);
    }

    $handle = fopen($file, 'rb');
    if ($handle === false) {
      self::redirect_error(__('Could not open backup file for download.', 'restorepilot-backup-migration'));
    }

    while (ob_get_level() > 0) {
      @ob_end_clean();
    }

    $start = 0;
    $end = $size - 1;
    $status = 200;

    if (!empty($_SERVER['HTTP_RANGE'])) {
      $range = sanitize_text_field(wp_unslash($_SERVER['HTTP_RANGE']));
      if (preg_match('/bytes=(\d*)-(\d*)/', $range, $matches)) {
        if ($matches[1] !== '') {
          $start = (int) $matches[1];
        }
        if ($matches[2] !== '') {
          $end = (int) $matches[2];
        }
        if ($matches[1] === '' && $matches[2] !== '') {
          $suffix = (int) $matches[2];
          $start = max(0, $size - $suffix);
          $end = $size - 1;
        }
        if ($start <= $end && $start >= 0 && $end < $size) {
          $status = 206;
        } else {
          fclose($handle);
          status_header(416);
          header('Content-Range: bytes */' . $size);
          exit;
        }
      }
    }

    $length = $end - $start + 1;

    nocache_headers();
    status_header($status);
    $download_name = self::download_header_filename(basename($file));

    header('Content-Type: application/zip');
    header('Content-Transfer-Encoding: binary');
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $length);
    header('Content-Disposition: attachment; filename="' . $download_name . '"; filename*=UTF-8\'\'' . rawurlencode($download_name));
    header('X-Content-Type-Options: nosniff');
    header('X-Accel-Buffering: no');
    if ($status === 206) {
      header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    $chunk_size = 1024 * 1024;
    fseek($handle, $start);
    $sent = 0;

    while (!feof($handle) && $sent < $length) {
      $remaining = $length - $sent;
      $read_size = (int) min($chunk_size, $remaining);
      $chunk = fread($handle, $read_size);
      if ($chunk === false) {
        break;
      }
      $sent += strlen($chunk);
      echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw binary file stream, not HTML
      flush();
      if (connection_aborted()) {
        self::write_log('Download connection closed before completion: ' . basename($file));
        break;
      }
    }
    fclose($handle);
    exit;
  }

  /**
   * Reconstructs every volume of a split backup into a single zip, streamed
   * directly to the browser as it's built — never written to a second file
   * on this server, which would just reproduce the exact per-host file size
   * cap the volumes exist to work around, only on the way out instead of
   * the way in. Every RestorePilot entry is stored, never deflated, so this
   * is a straight byte copy of each volume's entries at a new, cumulative
   * offset — no decompression, no recompression, no full pass to size
   * anything first (see the missing Content-Length below).
   */
  private static function serve_combined_volume_download(string $base_path, array $volume_paths): void {
    $zip = self::open_backup_archive($base_path);

    try {
      self::prepare_for_long_operation();
      @ini_set('zlib.output_compression', 'Off'); // phpcs:ignore WordPress.PHP.IniSet.Risky
      @ini_set('output_buffering', 'Off'); // phpcs:ignore WordPress.PHP.IniSet.Risky
      while (ob_get_level() > 0) {
        @ob_end_clean();
      }

      $download_name = self::download_header_filename(basename($base_path));
      nocache_headers();
      header('Content-Type: application/zip');
      header('Content-Transfer-Encoding: binary');
      header('Content-Disposition: attachment; filename="' . $download_name . '"; filename*=UTF-8\'\'' . rawurlencode($download_name));
      header('X-Content-Type-Options: nosniff');
      header('X-Accel-Buffering: no');
      // No Content-Length: the true combined size is only known once every
      // volume has been re-emitted, and a separate pass just to total it
      // first would mean reading the whole archive twice for a header the
      // download works fine without (chunked transfer encoding).

      self::write_log('Combined multi-volume download started: ' . basename($base_path) . ' (' . count($volume_paths) . ' volumes).');

      $handle = @fopen('php://output', 'wb');
      if ($handle === false) {
        throw new RuntimeException(__('Could not open the download stream.', 'restorepilot-backup-migration'));
      }
      self::write_combined_volumes($zip, RestorePilot_Backup_Zip_Writer::create_streaming($handle));
      self::write_log('Combined multi-volume download completed: ' . basename($base_path) . '.');
    } finally {
      $zip->close();
    }

    exit;
  }

  /**
   * Re-emits every entry from an already-open multi-volume archive facade
   * into $writer, which owns whatever it's writing to (a streaming HTTP
   * response for serve_combined_volume_download(), or a plain local file in
   * tests). Split out from serve_combined_volume_download() so this — the
   * part with actual logic to get right — can be exercised directly without
   * the surrounding HTTP response, which by its nature only exists once and
   * exits the process when done.
   */
  private static function write_combined_volumes(RestorePilot_Backup_Archive $zip, RestorePilot_Backup_Zip_Writer $writer): void {
    for ($i = 0; $i < $zip->num_files(); $i++) {
      if (connection_aborted()) {
        self::write_log('Combined download connection closed before completion.');
        break;
      }

      $name = $zip->get_name_index($i);
      if (!is_string($name) || $name === '') {
        continue;
      }
      if (substr($name, -1) === '/') {
        $writer->addEmptyDir($name);
        continue;
      }

      // manifest.json records how many volumes the backup is split into
      // (open_backup_archive() reads this to detect a set that's missing its
      // last volume) — a number this combined output makes stale the instant
      // it's rewritten as one file. Copying it byte-for-byte, like every
      // other entry, would leave a single-file backup whose own manifest
      // still claims to need N-1 sibling volumes that no longer exist,
      // making it fail exactly the completeness check the field exists for.
      if ($name === 'manifest.json') {
        self::rewrite_combined_manifest_entry($zip, $writer);
        continue;
      }

      $stat = $zip->stat_index($i);
      $known_size = is_array($stat) && isset($stat['size']) ? (int) $stat['size'] : 0;
      $mtime = is_array($stat) && isset($stat['mtime']) ? (int) $stat['mtime'] : 0;

      $stream = $zip->get_stream($name);
      if (!is_resource($stream)) {
        /* translators: %s: file name inside the backup that could not be read */
        throw new RuntimeException(sprintf(__('Could not read %s from the backup.', 'restorepilot-backup-migration'), $name));
      }
      $writer->addFileFromStream($stream, $name, $mtime, $known_size);
      fclose($stream);
    }

    $writer->close();
  }

  private static function rewrite_combined_manifest_entry(RestorePilot_Backup_Archive $zip, RestorePilot_Backup_Zip_Writer $writer): void {
    $raw = $zip->get_from_name('manifest.json');
    $manifest = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($manifest)) {
      throw new RuntimeException(__('Could not read the backup manifest while combining this backup into one file.', 'restorepilot-backup-migration'));
    }

    $manifest['volumes'] = 1;
    $rewritten = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($rewritten) || $rewritten === '') {
      throw new RuntimeException(__('Could not rewrite the backup manifest while combining this backup into one file.', 'restorepilot-backup-migration'));
    }

    $writer->addFromString('manifest.json', $rewritten);
  }

  /**
   * Builds and throws a detailed write-failure exception for a real file on
   * disk. Mirrors RestorePilot_Backup_Zip_Writer's own write() diagnostics
   * (EFBIG/ENOSPC/EDQUOT detection via error_get_last(), plus a disk_free_space()
   * fallback) so every large write in the plugin gives the same actionable
   * detail — which OS-level reason, how far it got, what to do about it —
   * instead of a bare "could not write" that tells the log nothing the
   * on-screen error didn't already say.
   *
   * $fn narrows error_get_last() to a message that actually names the PHP
   * function this call site used (fwrite() vs file_put_contents()), since
   * that global is not scoped to this write and could otherwise be a stale,
   * unrelated warning from earlier in the same request.
   */
  private static function throw_write_failure(string $path, string $context, string $fn): void {
    $os_error = error_get_last();
    $os_message = ($os_error && stripos((string) $os_error['message'], $fn) !== false)
      ? trim((string) $os_error['message'])
      : '';
    $reason = $os_message !== ''
      ? $os_message
      : __('the operating system reported no further detail', 'restorepilot-backup-migration');

    $errno = 0;
    if ($os_message !== '' && preg_match('/errno=(\d+)/', $os_message, $m)) {
      $errno = (int) $m[1];
    }

    $reached = @filesize($path);
    $written_note = $reached !== false
      ? sprintf(
        /* translators: %s: size already written to disk before the write failed */
        __('%s had already been written before the failure.', 'restorepilot-backup-migration'),
        size_format((int) $reached)
      )
      : '';

    if ($errno === 27) {
      $advice = __('This server refuses to create a single file beyond a fixed size, regardless of free disk space — typically a per-process file size limit (RLIMIT_FSIZE / "ulimit -f") set by the host. Ask the host to raise it, or reduce what a single operation writes.', 'restorepilot-backup-migration');
    } elseif ($errno === 122 || stripos($os_message, 'quota') !== false) {
      $advice = __('This is a hosting account disk quota, which is separate from filesystem free space and is not visible to WordPress. Ask your host what your account quota is and how much of it this site is using.', 'restorepilot-backup-migration');
    } else {
      $free = @disk_free_space(dirname($path));
      if ($free !== false && (int) $free <= 512 * 1024 * 1024) {
        /* translators: %s: free disk space remaining when the write failed */
        $advice = sprintf(__('Only %s remained free on this server, so the destination ran out of space.', 'restorepilot-backup-migration'), size_format((int) $free));
      } elseif ($free !== false) {
        /* translators: %s: free disk space remaining when the write failed */
        $advice = sprintf(__('The filesystem still reports %s free, so the limit is being imposed by the hosting environment rather than by the disk itself. Your host can say which limit was reached.', 'restorepilot-backup-migration'), size_format((int) $free));
      } else {
        $advice = '';
      }
    }

    throw new RuntimeException(trim(sprintf(
      /* translators: 1: operation being attempted, 2: reason reported by the operating system, 3: how much had been written, 4: guidance on what to do about it */
      __('Could not write data while trying to %1$s. Reason: %2$s. %3$s %4$s', 'restorepilot-backup-migration'),
      $context,
      $reason,
      $written_note,
      $advice
    )));
  }

  private static function write_stream($handle, string $path, string $data, string $context): void {
    $length = strlen($data);
    $written = 0;

    while ($written < $length) {
      $result = fwrite($handle, substr($data, $written));
      if ($result === false || $result === 0) {
        self::throw_write_failure($path, $context, 'fwrite');
      }
      $written += $result;
    }
  }

  private static function write_file(string $path, string $contents, string $context): void {
    $written = @file_put_contents($path, $contents, LOCK_EX);
    if ($written === false || $written !== strlen($contents)) {
      self::throw_write_failure($path, $context, 'file_put_contents');
    }
  }

  private static function deny_htaccess(): string {
    return "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
  }

  /**
   * True when $name is a follow-on volume (-vNNN.zip, or -vNNNN once a set
   * passes 999 volumes — volume_path()'s str_pad() only pads *up to* 3
   * digits, it does not truncate, so index 1000+ naturally overflows to 4
   * digits) rather than the first volume of a backup. Follow-on volumes are
   * members of a set, not backups in their own right, so they are never
   * listed or counted separately. {3,} (not the fixed {3} this used to be)
   * is what makes a set stay discoverable past that overflow point — with a
   * fixed 3-digit match, volume 1000 was silently invisible to every
   * follow-on check and to discover_volumes() below despite
   * MAX_BACKUP_VOLUMES = 4096 implying the format supports up to 4096.
   */
  private static function is_follow_on_volume(string $name): bool {
    return (bool) preg_match('/-v[0-9]{3,}\.zip$/', $name);
  }

  /**
   * Every volume belonging to the backup whose first volume is $base_path,
   * in order, starting with $base_path itself. Discovery is by filename, so
   * it works for a set that was copied onto the site by hand as well as one
   * this install created.
   */
  private static function volume_paths_for(string $base_path): array {
    return self::discover_volumes($base_path)['paths'];
  }

  /**
   * Finds every volume of a set, keyed by its volume number.
   *
   * Deliberately scans for all of them rather than stopping at the first
   * gap: a set that is missing a middle volume must be reported as such,
   * not silently truncated to the volumes before the gap — which would look
   * to the caller like a complete, shorter backup.
   *
   * @return array{paths: string[], indexes: int[], highest: int}
   */
  private static function discover_volumes(string $base_path): array {
    $found = [];
    if (is_file($base_path)) {
      $found[1] = $base_path;
    }

    $dir = dirname($base_path);
    $stem = basename($base_path);
    if (substr($stem, -4) === '.zip') {
      $stem = substr($stem, 0, -4);
    }

    $entries = @scandir($dir);
    if (is_array($entries)) {
      $pattern = '/^' . preg_quote($stem, '/') . '-v([0-9]{3,})\.zip$/';
      foreach ($entries as $entry) {
        if (preg_match($pattern, $entry, $m)) {
          $index = (int) $m[1];
          if ($index >= 2 && $index <= self::MAX_BACKUP_VOLUMES) {
            $found[$index] = $dir . '/' . $entry;
          }
        }
      }
    }

    ksort($found);
    return [
      'paths' => array_values($found),
      'indexes' => array_keys($found),
      'highest' => $found ? max(array_keys($found)) : 0,
    ];
  }

  /**
   * Opens a backup as a volume set, verifying the set is complete first.
   *
   * A restore that silently proceeded with a missing volume would produce a
   * partially-restored site, so a shortfall against the manifest's recorded
   * volume count is a hard failure here, before anything is touched.
   */
  private static function open_backup_archive(string $base_path): RestorePilot_Backup_Archive {
    $discovered = self::discover_volumes($base_path);
    $paths = $discovered['paths'];
    if (!$paths) {
      throw new RuntimeException(__('Backup file not found.', 'restorepilot-backup-migration'));
    }

    // Every volume from 1 to the highest one present must exist. A gap means
    // part of the backup is simply absent, and continuing would restore a
    // subset of the site while reporting success.
    $missing = array_values(array_diff(range(1, $discovered['highest']), $discovered['indexes']));
    if ($missing) {
      throw new RuntimeException(sprintf(
        /* translators: %s: comma-separated list of missing backup volume numbers */
        __('This backup is split into volumes and some are missing (volume %s). Place every volume of the set in the same folder before restoring.', 'restorepilot-backup-migration'),
        implode(', ', $missing)
      ));
    }

    $archive = new RestorePilot_Backup_Archive($paths);

    $manifest_raw = $archive->get_from_name('manifest.json');
    $manifest = is_string($manifest_raw) ? json_decode($manifest_raw, true) : null;

    // The manifest is written last, so it lives in the final volume. If no
    // volume in the set contains one, the set has been truncated — say so,
    // rather than letting this surface later as a bare "manifest is missing".
    if (!is_array($manifest) && count($paths) > 1) {
      $archive->close();
      throw new RuntimeException(sprintf(
        /* translators: %d: number of backup volumes that were found */
        __('This backup is split into volumes and the final volume is missing (%d were found). Place every volume of the set in the same folder before restoring.', 'restorepilot-backup-migration'),
        count($paths)
      ));
    }

    $expected = (is_array($manifest) && isset($manifest['volumes'])) ? (int) $manifest['volumes'] : 1;

    if ($expected > count($paths)) {
      $archive->close();
      throw new RuntimeException(sprintf(
        /* translators: 1: number of backup volumes found, 2: number of volumes the backup should have */
        __('This backup is split into %2$d volumes but only %1$d were found. Place every volume of the set in the same folder before restoring.', 'restorepilot-backup-migration'),
        count($paths),
        $expected
      ));
    }

    return $archive;
  }

  private static function list_backups(): array {
    self::ensure_storage();
    $files = glob(self::backup_dir() . '/*.zip') ?: [];
    $items = [];

    foreach ($files as $file) {
      // A -vNNN volume is part of another backup, not a backup of its own.
      if (self::is_follow_on_volume(basename($file))) {
        continue;
      }
      $manifest = self::peek_manifest($file);
      // Report the size of the whole set, so a split backup does not appear
      // to be only as large as its first volume.
      $size = 0;
      $volumes = self::volume_paths_for($file);
      foreach ($volumes as $volume_path) {
        $size += (int) filesize($volume_path);
      }
      $items[] = [
        'name'         => basename($file),
        'size'         => $size,
        'modified'     => filemtime($file),
        'backup_type'  => $manifest['backup_type'],
        'triggered_by' => $manifest['triggered_by'],
        'volumes'      => count($volumes),
      ];
    }

    usort($items, fn($a, $b) => $b['modified'] <=> $a['modified']);
    return $items;
  }

  private static function peek_manifest(string $path): array {
    $defaults = ['backup_type' => 'full', 'triggered_by' => 'manual'];

    // The manifest is written last, so in a multi-volume set it lives in the
    // final volume. Search from the end backwards: a single-volume backup —
    // the common case — is still found on the first try.
    $raw = false;
    foreach (array_reverse(self::volume_paths_for($path)) as $volume_path) {
      $zip = new ZipArchive();
      if ($zip->open($volume_path, ZipArchive::RDONLY) !== true) {
        continue;
      }
      $found = $zip->getFromName('manifest.json');
      $zip->close();
      if (is_string($found) && $found !== '') {
        $raw = $found;
        break;
      }
    }
    if (!is_string($raw) || $raw === '') {
      return $defaults;
    }
    $manifest = json_decode($raw, true);
    if (!is_array($manifest)) {
      return $defaults;
    }
    $type = isset($manifest['backup_type']) ? sanitize_key((string) $manifest['backup_type']) : '';
    $triggered = isset($manifest['triggered_by']) ? sanitize_key((string) $manifest['triggered_by']) : '';
    return [
      'backup_type'  => $type !== '' ? $type : 'full',
      'triggered_by' => $triggered !== '' ? $triggered : 'manual',
    ];
  }

  private static function peek_backup_type(string $path): string {
    return self::peek_manifest($path)['backup_type'];
  }

  private static function friendly_backup_filename(): string {
    $date = function_exists('wp_date') ? wp_date('Y-m-d-His') : date_i18n('Y-m-d-His');
    $random = substr(str_replace('-', '', wp_generate_uuid4()), 0, 12);
    $base = self::site_filename_slug() . '-backup-' . $date . '-' . $random;
    $candidate = $base . '.zip';
    $counter = 2;

    while (is_file(self::backup_dir() . '/' . $candidate) || is_file(self::storage_dir() . '/' . $candidate . '.restorepilot-tmp')) {
      $candidate = $base . '-' . $counter . '.zip';
      $counter++;
    }

    return $candidate;
  }

  private static function friendly_rollback_filename(): string {
    $date = function_exists('wp_date') ? wp_date('Y-m-d-His') : date_i18n('Y-m-d-His');
    $random = substr(str_replace('-', '', wp_generate_uuid4()), 0, 12);
    $base = self::site_filename_slug() . '-restore-rollback-' . $date . '-' . $random;
    $candidate = $base . '.zip';
    $counter = 2;

    while (is_file(self::rollback_dir() . '/' . $candidate) || is_file(self::storage_dir() . '/' . $candidate . '.restorepilot-tmp')) {
      $candidate = $base . '-' . $counter . '.zip';
      $counter++;
    }

    return $candidate;
  }

  private static function site_filename_slug(): string {
    $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    if ($host === '') {
      $host = (string) wp_parse_url(site_url(), PHP_URL_HOST);
    }

    $host = strtolower(preg_replace('/^www\./i', '', $host));
    $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $host));
    $slug = trim($slug, '-');
    if ($slug === '') {
      $slug = 'wordpress-site';
    }

    return substr($slug, 0, 60);
  }

  private static function enforce_backup_retention(): void {
    // A resumable restore re-reads its source backup from disk on every one
    // of its many chunks, across separate requests possibly minutes apart —
    // deleting the oldest backup here while one is in progress could delete
    // the exact file a restore is mid-way through reading (this runs on
    // every admin page view and after every completed backup, including a
    // routine scheduled one that happens to land while a restore is still
    // running). Retention runs again on the next page view or backup once
    // the restore finishes; skipping it this once costs nothing but keeping
    // one extra backup around briefly.
    if (self::restore_lock_is_active()) {
      return;
    }

    // list_backups() returns backups sorted newest-first.
    $backups = self::list_backups();
    $limit   = self::retention_count();

    if (count($backups) <= $limit) {
      return;
    }

    // Delete oldest backups beyond the limit (any type counts toward the total).
    $remove = array_slice($backups, $limit);
    foreach ($remove as $backup) {
      $name = sanitize_file_name($backup['name'] ?? '');
      if ($name === '') {
        continue;
      }
      self::delete_backup_parts($name);
      $path = self::backup_dir() . '/' . $name;
      // Remove every volume of the set, not just the first — otherwise
      // retention would leave orphaned volumes behind for ever.
      $removed = false;
      foreach (self::volume_paths_for($path) as $volume_path) {
        @unlink($volume_path);
        $removed = true;
      }
      if ($removed) {
        self::write_log('Retention cleanup removed old backup: ' . $name);
      }
    }
  }

  /**
   * Every stored pre-restore rollback point, one entry per logical point —
   * grouped exactly like list_backups() groups a backup's volumes — newest
   * first. A rollback point is created via create_backup_package(), the
   * same volume-splitting writer regular backups use, so a database large
   * enough to exceed BACKUP_VOLUME_BYTES produces a multi-volume rollback
   * set too. Treating every physical volume as its own independent
   * rollback point (glob() with no grouping) let retention's oldest-first
   * eviction delete the manifest-bearing base volume of a set while
   * leaving a sibling behind — silently and permanently breaking that
   * rollback point, discoverable only when an admin actually needs it to
   * recover from a bad restore. All four places that used to glob()
   * rollback_dir() directly now go through this instead.
   */
  private static function list_restore_rollback_points(): array {
    $files = glob(self::rollback_dir() . '/*.zip') ?: [];
    $points = [];
    foreach ($files as $file) {
      if (self::is_follow_on_volume(basename($file))) {
        continue;
      }
      $size = 0;
      foreach (self::volume_paths_for($file) as $volume_path) {
        $size += (int) @filesize($volume_path);
      }
      $points[] = [
        'path' => $file,
        'modified' => (int) filemtime($file),
        'size' => $size,
      ];
    }

    usort($points, fn($a, $b) => $b['modified'] <=> $a['modified']);
    return $points;
  }

  private static function enforce_restore_rollback_retention(string $protect_path = ''): void {
    $points = self::list_restore_rollback_points();
    $remove = array_slice($points, self::MAX_RESTORE_ROLLBACKS);
    // Compared by realpath rather than by string: the path a restore records
    // for its source and the path discovered by scanning the rollback folder
    // are both absolute but need not be spelled identically.
    $protect = ($protect_path !== '' && file_exists($protect_path)) ? realpath($protect_path) : false;
    foreach ($remove as $point) {
      $point_real = file_exists($point['path']) ? realpath($point['path']) : false;
      if ($protect !== false && $point_real !== false && $point_real === $protect) {
        // Keeping one extra rollback point is harmless; deleting the archive
        // a restore is actively reading from is not. See the docblock on
        // create_restore_rollback_point().
        self::write_log('Kept rollback point beyond the retention limit because the running restore is reading from it: ' . basename($point['path']));
        continue;
      }
      foreach (self::volume_paths_for($point['path']) as $volume_path) {
        @unlink($volume_path);
      }
      self::write_log('Removed old restore rollback point: ' . basename($point['path']));
    }
  }

  private static function create_direct_download_url(string $file): string {
    if (!self::site_uses_https()) {
      self::write_log('Direct web-server download skipped because this site is not using HTTPS; using resumable PHP stream for: ' . basename($file));
      return self::action_url('restorepilot_download_stream', basename($file));
    }

    self::ensure_direct_download_dir();
    self::cleanup_direct_downloads();

    $token = sanitize_key(wp_generate_password(32, false, false));
    $name = basename($file);
    $token_dir = self::direct_download_dir() . '/' . $token;
    if (!wp_mkdir_p($token_dir) && !is_dir($token_dir)) {
      self::write_log('Direct download token folder could not be created; falling back to resumable PHP stream for: ' . basename($file));
      return self::action_url('restorepilot_download_stream', basename($file));
    }
    $target = $token_dir . '/' . $name;

    if (!function_exists('link')) {
      self::write_log('Direct hardlink is unavailable on this server; falling back to resumable PHP stream for: ' . basename($file));
      return self::action_url('restorepilot_download_stream', basename($file));
    }

    if (!@link($file, $target)) {
      self::write_log('Direct hardlink failed; falling back to resumable PHP stream for: ' . basename($file));
      return self::action_url('restorepilot_download_stream', basename($file));
    }

    $index = $token_dir . '/index.php';
    if (!file_exists($index)) {
      self::write_file($index, "<?php\n// Silence is golden.\n", 'direct download token index');
    }

    $source_size = filesize($file);
    $target_size = filesize($target);
    if ($source_size === false || $target_size === false || (int) $source_size !== (int) $target_size) {
      @unlink($target);
      self::write_log('Direct hardlink size check failed; falling back to resumable PHP stream for: ' . basename($file));
      return self::action_url('restorepilot_download_stream', basename($file));
    }

    @touch($target, time());
    @touch($token_dir, time());

    // Schedule reliable cleanup for THIS link at the moment it expires, rather
    // than depending on the next unrelated RestorePilot action to eventually
    // sweep it. A small buffer past the exact expiry avoids a race against
    // filemtime-based age comparisons at the boundary.
    //
    // The token is passed as the event's argument so each link gets its own
    // distinct event: wp_schedule_single_event() treats two events with the
    // same hook and IDENTICAL args scheduled within 10 minutes of each other
    // as duplicates and silently drops the second one. Without a per-link
    // argument, creating two download links close together would leave only
    // one cleanup event outstanding — once it fired, nothing would ever
    // clean up the other link again. cleanup_direct_downloads_cron() below
    // still also runs the general age-based sweep as a safety net.
    $scheduled = wp_schedule_single_event(
      time() + self::DIRECT_DOWNLOAD_MAX_AGE_SECONDS + 5 * MINUTE_IN_SECONDS,
      'restorepilot_cleanup_direct_download',
      [$token]
    );
    if ($scheduled === false) {
      self::write_log('Could not schedule dedicated cleanup for direct download token ' . $token . '; it will still be removed by the periodic sweep.');
    }

    return trailingslashit(self::direct_download_url()) . rawurlencode(basename($token_dir)) . '/' . rawurlencode($name);
  }

  private static function download_header_filename(string $filename): string {
    $filename = sanitize_file_name($filename);
    $filename = str_replace(['"', '\\', "\r", "\n"], '', $filename);
    if ($filename === '' || !preg_match('/\.zip(\.part[0-9]{3})?$/', $filename)) {
      return 'restorepilot-backup.zip';
    }

    return $filename;
  }

  private static function site_uses_https(): bool {
    if (is_ssl()) {
      return true;
    }

    $scheme = (string) wp_parse_url(home_url(), PHP_URL_SCHEME);
    return strtolower($scheme) === 'https';
  }

  private static function ensure_direct_download_dir(): void {
    if (!wp_mkdir_p(self::direct_download_dir()) && !is_dir(self::direct_download_dir())) {
      throw new RuntimeException(__('Could not create temporary download folder.', 'restorepilot-backup-migration'));
    }

    $index = self::direct_download_dir() . '/index.php';
    if (!file_exists($index)) {
      self::write_file($index, "<?php\n// Silence is golden.\n", 'temporary download index');
    }
  }

  private static function cleanup_direct_downloads(): void {
    $files = glob(self::direct_download_dir() . '/*.zip') ?: [];
    foreach ($files as $file) {
      if (is_file($file) && (time() - (int) filemtime($file)) > self::DIRECT_DOWNLOAD_MAX_AGE_SECONDS) {
        @unlink($file);
      }
    }

    $dirs = glob(self::direct_download_dir() . '/*', GLOB_ONLYDIR) ?: [];
    foreach ($dirs as $dir) {
      if (is_dir($dir) && (time() - (int) filemtime($dir)) > self::DIRECT_DOWNLOAD_MAX_AGE_SECONDS) {
        self::delete_directory($dir, self::direct_download_dir());
      }
    }
  }

  /**
   * WP-Cron entry point for scheduled direct-download cleanup. Each direct
   * download link schedules exactly one of these events — with its own
   * token as the argument, so it cannot be deduplicated against any other
   * link's event — timed to fire just after that link's own expiry. That
   * turns the confidentiality window from "removed only if some unrelated
   * RestorePilot action happens to run afterward" into "removed reliably,
   * close to the moment it expires," without depending on admin traffic.
   * The general sweep still runs every time too, as a safety net for a
   * token created before this argument existed, or whose own event failed
   * to schedule.
   */
  public static function cleanup_direct_downloads_cron(string $token = ''): void {
    if ($token !== '') {
      self::delete_direct_download_token($token);
    }
    self::cleanup_direct_downloads();
  }

  /**
   * Removes exactly one direct-download token's directory. Called only once
   * its own scheduled cleanup event fires, which is timed to fire after that
   * token's expiry — so, unlike cleanup_direct_downloads()'s age sweep, no
   * separate age check is needed here.
   */
  private static function delete_direct_download_token(string $token): void {
    $token = sanitize_key($token);
    if ($token === '') {
      return;
    }
    $token_dir = self::direct_download_dir() . '/' . $token;
    if (is_dir($token_dir)) {
      self::delete_directory($token_dir, self::direct_download_dir());
    }
  }

  /**
   * Removes every direct-download token directory unconditionally, including
   * ones that have not yet expired. Used on deactivation: the scheduled
   * cleanup events for any outstanding links are cleared at the same time
   * (see restorepilot_backup_migration_clear_scheduled_events()), so once
   * deactivated, nothing will ever automatically remove them again while the
   * plugin stays inactive — leaving a confidentiality-sensitive public link
   * on disk indefinitely is worse than ending an in-progress download.
   */
  public static function purge_direct_downloads(): void {
    $dir = self::direct_download_dir();
    if (!is_dir($dir)) {
      return;
    }
    $upload = wp_upload_dir(null, false);
    if (empty($upload['error']) && !empty($upload['basedir'])) {
      self::master_reset_wipe_dir($dir, $upload['basedir']);
    }
  }

  private static function list_backup_parts(string $backup_name): array {
    $backup_name = sanitize_file_name($backup_name);
    $files = glob(self::backup_dir() . '/' . $backup_name . '.part*') ?: [];
    sort($files, SORT_NATURAL);

    $parts = [];
    $index = 1;
    foreach ($files as $file) {
      if (!is_file($file)) {
        continue;
      }
      $parts[] = [
        'name' => basename($file),
        /* translators: %d: sequential part number of a multi-part backup */
        'label' => sprintf(__('Part %d', 'restorepilot-backup-migration'), $index),
        'size' => filesize($file),
      ];
      $index++;
    }

    return $parts;
  }

  private static function delete_backup_parts(string $backup_name): void {
    $backup_name = sanitize_file_name($backup_name);
    $files = glob(self::backup_dir() . '/' . $backup_name . '.part*') ?: [];
    foreach ($files as $file) {
      if (is_file($file)) {
        @unlink($file);
      }
    }
  }

  private static function ensure_storage(): void {
    if (!wp_mkdir_p(self::backup_dir()) && !is_dir(self::backup_dir())) {
      throw new RuntimeException(__('Could not create backup storage folder.', 'restorepilot-backup-migration'));
    }
    if (!wp_mkdir_p(self::rollback_dir()) && !is_dir(self::rollback_dir())) {
      throw new RuntimeException(__('Could not create restore rollback storage folder.', 'restorepilot-backup-migration'));
    }

    $index = self::storage_dir() . '/index.php';
    if (!file_exists($index)) {
      self::write_file($index, "<?php\n// Silence is golden.\n", 'storage index');
    }

    $backup_index = self::backup_dir() . '/index.php';
    if (!file_exists($backup_index)) {
      self::write_file($backup_index, "<?php\n// Silence is golden.\n", 'backup index');
    }

    $rollback_index = self::rollback_dir() . '/index.php';
    if (!file_exists($rollback_index)) {
      self::write_file($rollback_index, "<?php\n// Silence is golden.\n", 'restore rollback index');
    }

    $htaccess = self::storage_dir() . '/.htaccess';
    if (!file_exists($htaccess)) {
      self::write_file($htaccess, self::deny_htaccess(), 'storage protection');
    }

    $backup_htaccess = self::backup_dir() . '/.htaccess';
    if (!file_exists($backup_htaccess)) {
      self::write_file($backup_htaccess, self::deny_htaccess(), 'backup protection');
    }
  }

  private static function storage_dir(): string {
    $upload = wp_upload_dir(null, false);
    return trailingslashit($upload['basedir']) . 'restorepilot-backup-migration';
  }

  private static function backup_dir(): string {
    return self::storage_dir() . '/backups';
  }

  private static function rollback_dir(): string {
    return self::storage_dir() . '/restore-rollbacks';
  }

  private static function direct_download_dir(): string {
    $upload = wp_upload_dir(null, false);
    return trailingslashit($upload['basedir']) . 'restorepilot-direct-downloads';
  }

  private static function direct_download_url(): string {
    $upload = wp_upload_dir(null, false);
    return trailingslashit($upload['baseurl']) . 'restorepilot-direct-downloads';
  }

  /**
   * Absolute path to the WordPress content directory.
   *
   * A backup/restore plugin must address wp-content itself (to archive it, to
   * measure free space on its volume, and to write the maintenance drop-in).
   * WordPress exposes that location as WP_CONTENT_DIR — the same constant the
   * "Determining Plugin and Content Directories" handbook page documents for
   * this purpose — and installs that relocate wp-content redefine it. Every
   * reference goes through this one accessor so the location is resolved in a
   * single place rather than being repeated throughout the file.
   *
   * The plugin's OWN files are never located this way: those use
   * RESTOREPILOT_BACKUP_MIGRATION_DIR / plugin_dir_url() / plugins_url(), and
   * all writable data lives under wp_upload_dir().
   */
  private static function content_dir(): string {
    return defined('WP_CONTENT_DIR') ? (string) WP_CONTENT_DIR : dirname(RESTOREPILOT_BACKUP_MIGRATION_DIR, 2);
  }

  /**
   * Absolute path to the plugins directory.
   *
   * Only used by Master Reset, which must enumerate installed plugin folders in
   * order to remove them. WordPress exposes this as WP_PLUGIN_DIR.
   */
  private static function plugins_dir(): string {
    return defined('WP_PLUGIN_DIR') ? (string) WP_PLUGIN_DIR : self::content_dir() . '/plugins';
  }

  private static function temp_storage_bytes(): int {
    $total = 0;
    foreach (self::temp_file_patterns() as $pattern) {
      $files = glob($pattern) ?: [];
      foreach ($files as $file) {
        $total += self::path_size($file);
      }
    }

    $total += self::path_size(self::storage_dir() . '/restore-chunks');
    $total += self::path_size(self::direct_download_dir());

    return $total;
  }

  private static function cleanup_stale_temp_files(): array {
    self::ensure_storage();

    $removed_count = 0;
    $removed_bytes = 0;
    $stale_file_age = HOUR_IN_SECONDS;
    $direct_download_age = 6 * HOUR_IN_SECONDS;

    foreach (self::temp_file_patterns() as $pattern) {
      $files = glob($pattern) ?: [];
      foreach ($files as $file) {
        if (!is_file($file) || (time() - (int) filemtime($file)) < $stale_file_age) {
          continue;
        }
        $size = self::path_size($file);
        if (@unlink($file)) {
          $removed_count++;
          $removed_bytes += $size;
        }
      }
    }

    $chunk_base = self::storage_dir() . '/restore-chunks';
    $chunk_dirs = is_dir($chunk_base) ? (glob($chunk_base . '/*', GLOB_ONLYDIR) ?: []) : [];
    foreach ($chunk_dirs as $dir) {
      if (!is_dir($dir) || (time() - (int) filemtime($dir)) < $stale_file_age) {
        continue;
      }
      $size = self::path_size($dir);
      self::delete_directory($dir, self::storage_dir());
      if (!is_dir($dir)) {
        $removed_count++;
        $removed_bytes += $size;
      }
    }

    $direct_files = is_dir(self::direct_download_dir()) ? (glob(self::direct_download_dir() . '/*') ?: []) : [];
    foreach ($direct_files as $path) {
      if (is_file($path) && in_array(basename($path), ['index.php', '.htaccess'], true)) {
        continue;
      }
      if ((time() - (int) filemtime($path)) < $direct_download_age) {
        continue;
      }
      $size = self::path_size($path);
      if (is_dir($path)) {
        self::delete_directory($path, self::direct_download_dir());
        if (!is_dir($path)) {
          $removed_count++;
          $removed_bytes += $size;
        }
      } elseif (is_file($path) && @unlink($path)) {
        $removed_count++;
        $removed_bytes += $size;
      }
    }

    return [
      'count' => $removed_count,
      'bytes' => $removed_bytes,
    ];
  }

  private static function temp_file_patterns(): array {
    return [
      self::storage_dir() . '/restore-upload-*',
      self::storage_dir() . '/*.restorepilot-tmp',
      self::storage_dir() . '/partial-*.zip',
      self::storage_dir() . '/rp-ent-*.tmp',
      self::storage_dir() . '/poll-token-*.txt',
      self::storage_dir() . '/restore-status-*.json',
    ];
  }

  private static function path_size(string $path): int {
    if (is_file($path)) {
      $size = filesize($path);
      return $size === false ? 0 : (int) $size;
    }

    if (!is_dir($path)) {
      return 0;
    }

    $total = 0;
    try {
      $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
      );
      foreach ($iterator as $item) {
        if ($item->isFile()) {
          $total += (int) $item->getSize();
        }
      }
    } catch (Throwable $e) {
      return $total;
    }

    return $total;
  }

  /**
   * Recursively delete $path, confined to $allowed_base. Returns true when
   * $path does not exist as a directory afterward (the postcondition callers
   * that verify a destructive operation actually completed care about),
   * false when deletion was refused or something under it could not be removed.
   */
  private static function delete_directory(string $path, string $allowed_base): bool {
    $real_path = realpath($path);
    if ($real_path === false) {
      return true; // Nothing exists here — the postcondition already holds.
    }

    $real_base = realpath($allowed_base);
    if ($real_base === false || !is_dir($real_path)) {
      return false;
    }

    $real_path = str_replace('\\', '/', $real_path);
    $real_base = rtrim(str_replace('\\', '/', $real_base), '/');
    if ($real_path === $real_base || strpos($real_path, $real_base . '/') !== 0) {
      return false;
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

    return !is_dir($real_path);
  }
}
