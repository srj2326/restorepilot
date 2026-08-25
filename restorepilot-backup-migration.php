<?php
/**
 * Plugin Name: RestorePilot Backup & Migration
 * Description: Back up, restore, and migrate WordPress sites with serialized-safe URL replacement.
 * Version:     0.5.0
 * Author:      Surajit Roy
 * Author URI:  https://profiles.wordpress.org/srjdev/
 * Text Domain: restorepilot-backup-migration
 * Requires at least: 6.2
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
  exit;
}

/*
 * Plugin location constants, resolved from __FILE__ so they stay correct
 * regardless of where WordPress is installed or where wp-content is moved to.
 */
if (!defined('RESTOREPILOT_BACKUP_MIGRATION_FILE')) {
  define('RESTOREPILOT_BACKUP_MIGRATION_FILE', __FILE__);
}
if (!defined('RESTOREPILOT_BACKUP_MIGRATION_DIR')) {
  define('RESTOREPILOT_BACKUP_MIGRATION_DIR', plugin_dir_path(__FILE__));
}
if (!defined('RESTOREPILOT_BACKUP_MIGRATION_URL')) {
  define('RESTOREPILOT_BACKUP_MIGRATION_URL', plugin_dir_url(__FILE__));
}

/*
 * RestorePilot is a backup/restore plugin, so it intentionally performs
 * low-level filesystem streaming, uploaded chunk assembly, direct database
 * export/restore SQL, and raw binary download output. The surrounding code
 * validates capabilities, nonces, file names, zip paths, table identifiers, and
 * output boundaries before these operations.
 *
 * Every table and column name reaching the database is bound through
 * $wpdb->prepare()'s %i identifier placeholder, and every value through its
 * value placeholders; nothing is concatenated into a statement. The single
 * exception is the CREATE TABLE statement replayed during a restore, which is
 * schema DDL rather than bound values — it carries its own local
 * phpcs:ignore and is whitelisted in full by assert_create_table_is_safe()
 * before execution.
 *
 * Each remaining file-wide disable below is for a category triggered at many
 * call sites throughout this file (backup/restore/export/import), not a
 * single reviewable spot; narrowing it to per-statement phpcs:ignore comments
 * needs an actual PHPCS/WPCS run to confirm complete coverage, which this
 * environment does not have available. Categories with few enough trigger
 * sites to audit and confirm by hand have been removed from this list rather
 * than left here unverified.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions -- fopen/fwrite/fclose-style streaming for backup zips, chunked uploads, and log files; used at dozens of sites, always on plugin-owned paths validated before use.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery -- direct $wpdb queries for operations with no WordPress ORM equivalent (SHOW TABLES/CREATE TABLE, TRUNCATE, RENAME TABLE, raw SELECT for streamed export); all identifiers and values are bound via prepare().
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught exception messages are plugin-generated (not raw user input) and are written to this plugin's own log, JSON API responses, or wp_die()/esc_html() output; not echoed as unescaped HTML.
 */

function restorepilot_backup_migration_bootstrap(): void {
  static $bootstrapped = false;
  if ($bootstrapped) {
    return;
  }

  $bootstrapped = true;
  add_action('admin_menu', ['RestorePilot_Backup_Migration', 'admin_menu']);
  add_action('admin_init', ['RestorePilot_Backup_Migration', 'register_privacy_policy_content']);
  add_action('admin_enqueue_scripts', ['RestorePilot_Backup_Migration', 'enqueue_admin_assets']);
  add_action('admin_notices', ['RestorePilot_Backup_Migration', 'render_restore_success_notice']);
  add_action('admin_notices', ['RestorePilot_Backup_Migration', 'render_operation_notices']);
  add_action('admin_notices', ['RestorePilot_Backup_Migration', 'render_deferred_plugins_notice']);
  add_action('admin_footer', ['RestorePilot_Backup_Migration', 'render_restore_success_dialog']);
  add_action('admin_post_restorepilot_backup', ['RestorePilot_Backup_Migration', 'handle_backup']);
  add_action('wp_ajax_restorepilot_ajax_backup', ['RestorePilot_Backup_Migration', 'handle_ajax_backup']);
  add_action('wp_ajax_restorepilot_backup_status', ['RestorePilot_Backup_Migration', 'handle_backup_status']);
  add_action('wp_ajax_restorepilot_cancel_backup', ['RestorePilot_Backup_Migration', 'handle_cancel_backup']);
  add_action('wp_ajax_restorepilot_read_log', ['RestorePilot_Backup_Migration', 'handle_read_log']);
  add_action('wp_ajax_restorepilot_clear_log', ['RestorePilot_Backup_Migration', 'handle_clear_log']);
  add_action('wp_ajax_restorepilot_chunk_restore_upload', ['RestorePilot_Backup_Migration', 'handle_chunk_restore_upload']);
  add_action('wp_ajax_restorepilot_ajax_restore', ['RestorePilot_Backup_Migration', 'handle_ajax_restore']);
  add_action('wp_ajax_restorepilot_restore_status', ['RestorePilot_Backup_Migration', 'handle_restore_status']);
  add_action('wp_ajax_restorepilot_run_restore_job_admin', ['RestorePilot_Backup_Migration', 'handle_run_restore_job_admin']);
  add_action('wp_ajax_restorepilot_run_backup_job_admin', ['RestorePilot_Backup_Migration', 'handle_run_backup_job_admin']);
  add_action('wp_ajax_restorepilot_run_backup_job', ['RestorePilot_Backup_Migration', 'handle_run_backup_job']);
  // nopriv endpoints for the loopback background workers: each request carries
  // a per-job single-use token (32 random alphanumeric chars, compared with
  // hash_equals) that is generated at job-queue time and never exposed to the
  // browser.  Authentication here is intentionally token-based rather than
  // cookie-based so the loopback HTTP call works even if the session is absent.
  add_action('wp_ajax_nopriv_restorepilot_run_backup_job',    ['RestorePilot_Backup_Migration', 'handle_run_backup_job']);
  add_action('wp_ajax_nopriv_restorepilot_run_restore_job',   ['RestorePilot_Backup_Migration', 'handle_run_restore_job']);
  // Status polling must work even when maintenance mode is active and after a
  // DB restore that invalidates the admin session. Uses a poll_token instead
  // of cookie auth so it is safe to expose to the browser and works nopriv.
  add_action('wp_ajax_nopriv_restorepilot_restore_status',    ['RestorePilot_Backup_Migration', 'handle_restore_status']);
  // Same reasoning as the status endpoint: this runs after the database swap
  // has invalidated the admin session, so it authenticates on the job's own
  // poll_token. See handle_set_restore_admin_password() for why the chosen
  // password takes this route rather than travelling with the job.
  add_action('wp_ajax_restorepilot_set_restore_admin_password',        ['RestorePilot_Backup_Migration', 'handle_set_restore_admin_password']);
  add_action('wp_ajax_nopriv_restorepilot_set_restore_admin_password', ['RestorePilot_Backup_Migration', 'handle_set_restore_admin_password']);
  add_action('init', ['RestorePilot_Backup_Migration', 'maybe_block_for_maintenance']);
  add_action('restorepilot_cron_backup_job', ['RestorePilot_Backup_Migration', 'run_backup_job'], 10, 2);
  add_action('restorepilot_cron_restore_job', ['RestorePilot_Backup_Migration', 'run_restore_job'], 10, 2);
  add_action('restorepilot_cleanup_direct_download', ['RestorePilot_Backup_Migration', 'cleanup_direct_downloads_cron'], 10, 1);
  add_action('admin_post_restorepilot_restore', ['RestorePilot_Backup_Migration', 'handle_restore']);
  add_action('admin_post_restorepilot_check_restore', ['RestorePilot_Backup_Migration', 'handle_restore_check']);
  add_action('admin_post_restorepilot_download', ['RestorePilot_Backup_Migration', 'handle_download']);
  add_action('admin_post_restorepilot_download_stream', ['RestorePilot_Backup_Migration', 'handle_download']);
  add_action('admin_post_restorepilot_download_part', ['RestorePilot_Backup_Migration', 'handle_download_partial']);
  add_action('admin_post_restorepilot_split', ['RestorePilot_Backup_Migration', 'handle_split']);
  add_action('admin_post_restorepilot_delete', ['RestorePilot_Backup_Migration', 'handle_delete']);
  add_action('admin_post_restorepilot_health', ['RestorePilot_Backup_Migration', 'handle_health_check']);
  add_action('admin_post_restorepilot_download_log', ['RestorePilot_Backup_Migration', 'handle_download_log']);
  add_action('admin_post_restorepilot_clear_log_post', ['RestorePilot_Backup_Migration', 'handle_clear_log_post']);
  add_action('admin_post_restorepilot_reactivate_plugins', ['RestorePilot_Backup_Migration', 'handle_reactivate_deferred_plugins']);
  add_action('admin_post_restorepilot_abandon_restore', ['RestorePilot_Backup_Migration', 'handle_abandon_restore']);
  add_action('admin_post_restorepilot_cleanup_temp', ['RestorePilot_Backup_Migration', 'handle_cleanup_temp']);
  add_action('admin_post_restorepilot_reset_runtime', ['RestorePilot_Backup_Migration', 'handle_reset_runtime']);
  add_action('wp_ajax_restorepilot_master_reset',     ['RestorePilot_Backup_Migration', 'handle_master_reset']);
  add_action('admin_post_restorepilot_save_settings', ['RestorePilot_Backup_Migration', 'handle_save_settings']);
  add_action('restorepilot_scheduled_backup', ['RestorePilot_Backup_Migration', 'handle_scheduled_backup']);
  add_filter('plugin_action_links_' . plugin_basename(__FILE__), ['RestorePilot_Backup_Migration', 'plugin_action_links']);
  add_filter('plugin_row_meta', ['RestorePilot_Backup_Migration', 'plugin_row_meta'], 10, 2);
  add_action('admin_enqueue_scripts', ['RestorePilot_Backup_Migration', 'enqueue_plugins_page_assets']);
}

// Uninstall cleanup is handled exclusively by uninstall.php, WordPress's
// dedicated mechanism for this — there is no separate uninstall function here.
function restorepilot_backup_migration_clear_scheduled_events(): void {
  // wp_clear_scheduled_hook() clears all instances of a hook (any args) since
  // WordPress 5.1.  The plugin requires WordPress 6.2+, so the private
  // _get_cron_array() call that was previously here is not needed.
  wp_clear_scheduled_hook('restorepilot_cron_backup_job');
  wp_clear_scheduled_hook('restorepilot_cron_restore_job');
  wp_clear_scheduled_hook('restorepilot_scheduled_backup');
  wp_clear_scheduled_hook('restorepilot_cleanup_direct_download');
  // The scheduled cleanup events just cleared above are the only thing that
  // would ever remove an outstanding direct-download hardlink — deactivation
  // must not leave one sitting on disk, publicly reachable, with nothing left
  // to clean it up.
  RestorePilot_Backup_Migration::purge_direct_downloads();
}

if (function_exists('register_activation_hook')) {
  register_activation_hook(__FILE__, ['RestorePilot_Backup_Migration', 'activate']);
}
if (function_exists('register_deactivation_hook')) {
  // Clear all scheduled RestorePilot cron events on deactivation so a
  // deactivated-but-not-deleted plugin never leaves orphaned daily backup or
  // background worker events firing with no registered callback. Full data
  // removal (options, stored backups, temp files) happens on uninstall via
  // uninstall.php, which is the single authoritative uninstall method.
  register_deactivation_hook(__FILE__, 'restorepilot_backup_migration_clear_scheduled_events');
}
restorepilot_backup_migration_bootstrap();

if (defined('RESTOREPILOT_BACKUP_MIGRATION_LOADED')) {
  return;
}
define('RESTOREPILOT_BACKUP_MIGRATION_LOADED', true);

class RestorePilot_Backup_Cancelled_Exception extends RuntimeException {}

/**
 * Signals that a backup chunk's time budget ran out. Not an error: it means
 * the current PHP process should stop cleanly, leaving everything written so
 * far exactly as it is, so a rescheduled resumption can continue from there.
 * See create_backup_package()'s dedicated catch block, which must run before
 * (and instead of) the generic Throwable cleanup that deletes an in-progress
 * backup — that cleanup is correct for a real failure but would destroy a
 * yield's progress.
 */
class RestorePilot_Backup_Chunk_Yield_Exception extends RuntimeException {}

/** Restore-side counterpart to RestorePilot_Backup_Chunk_Yield_Exception. */
class RestorePilot_Restore_Chunk_Yield_Exception extends RuntimeException {}

final class RestorePilot_Backup_Zip_Writer {
  const MAX_16 = 65535;
  const MAX_32 = 4294967295;
  const FLAG_DATA_DESCRIPTOR = 8;
  const METHOD_STORE = 0;

  private $handle;
  private $journal_handle;
  private $path = '';
  private $offset = 0;
  private $entries = [];
  private $closed = false;
  // Only true for create_streaming() — an HTTP response is not a local file
  // the OS will flush on its own schedule; without an explicit flush after
  // every write, PHP's own output buffer would happily grow to hold the
  // entire response (defeating the whole point of streaming a combined
  // multi-volume download instead of writing it to disk first) rather than
  // handing bytes to the browser as they're produced.
  private $flush_after_write = false;

  private function __construct() {}

  /**
   * Start a brand-new volume file, truncating anything already there.
   */
  public static function create(string $path): self {
    $writer = new self();
    $writer->path = $path;
    $writer->handle = @fopen($path, 'wb');
    $writer->journal_handle = @fopen($path . '.journal', 'wb');
    if ($writer->handle === false || $writer->journal_handle === false) {
      throw new RuntimeException(__('Could not create backup zip.', 'restorepilot-backup-migration'));
    }
    return $writer;
  }

  /**
   * Reopen a volume a previous, interrupted chunk was still writing.
   *
   * The zip file on disk may extend past the last entry this writer's
   * journal actually recorded (a process can die mid-write, after the raw
   * bytes landed but before the journal line describing them did). That
   * excess is truncated away unconditionally: the journal is the only
   * trusted record of what is really in the archive, and re-adding whatever
   * got cut is always safe because every entry is added independently.
   */
  public static function resume(string $path): self {
    $writer = new self();
    $writer->path = $path;
    [$writer->entries, $writer->offset] = self::read_journal($path . '.journal');

    $writer->handle = @fopen($path, 'c+b');
    if ($writer->handle === false) {
      throw new RuntimeException(__('Could not reopen backup zip to resume it.', 'restorepilot-backup-migration'));
    }
    if (!ftruncate($writer->handle, $writer->offset) || fseek($writer->handle, $writer->offset) !== 0) {
      throw new RuntimeException(__('Could not resume backup zip at the expected position.', 'restorepilot-backup-migration'));
    }

    $writer->journal_handle = @fopen($path . '.journal', 'ab');
    if ($writer->journal_handle === false) {
      throw new RuntimeException(__('Could not reopen backup zip journal to resume it.', 'restorepilot-backup-migration'));
    }
    return $writer;
  }

  /**
   * Writes to an already-open stream — specifically php://output, to
   * reconstruct a multi-volume backup's set into a single response on the
   * fly (see serve_combined_volume_download()) without ever writing that
   * combined size to a local file, which is exactly the file-size cap the
   * volumes exist to work around in the first place. No journal: a
   * synchronous HTTP response has nothing to resume into if it's
   * interrupted, the browser just retries the request from the start.
   */
  public static function create_streaming($handle): self {
    $writer = new self();
    $writer->handle = $handle;
    $writer->flush_after_write = true;
    return $writer;
  }

  /**
   * Replays a journal file into an entry list plus the offset the next
   * entry should start at. Stops at the first line that fails to parse
   * (typically the tail end of a line that was being written when the
   * process died) rather than trusting anything after it.
   *
   * @return array{0: array[], 1: int}
   */
  private static function read_journal(string $journal_path): array {
    $entries = [];
    $offset = 0;
    $handle = @fopen($journal_path, 'rb');
    if ($handle === false) {
      return [$entries, $offset];
    }

    while (($line = fgets($handle)) !== false) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      $decoded = json_decode($line, true);
      if (!is_array($decoded) || !isset(
        $decoded['name'], $decoded['offset'], $decoded['end_offset'], $decoded['time'], $decoded['date'],
        $decoded['crc'], $decoded['compressed_size'], $decoded['uncompressed_size']
      )) {
        break;
      }

      $end_offset = (int) $decoded['end_offset'];
      unset($decoded['end_offset']);
      $decoded['offset'] = (int) $decoded['offset'];
      $decoded['time'] = (int) $decoded['time'];
      $decoded['date'] = (int) $decoded['date'];
      $decoded['crc'] = (int) $decoded['crc'];
      $decoded['compressed_size'] = (int) $decoded['compressed_size'];
      $decoded['uncompressed_size'] = (int) $decoded['uncompressed_size'];
      $decoded['zip64'] = !empty($decoded['zip64']);
      $entries[] = $decoded;
      $offset = $end_offset;
    }
    fclose($handle);

    return [$entries, $offset];
  }

  public function addFromString(string $name, string $contents): bool {
    $name = self::normalize_name($name);
    if ($name === '') {
      return false;
    }

    $size = strlen($contents);

    $entry = $this->start_entry($name, time());
    $this->write($contents, 'write zip entry');
    $this->finish_entry($entry, self::crc32_hex_to_int(hash('crc32b', $contents)), $size, $size);
    return true;
  }

  public function addEmptyDir(string $name): bool {
    $name = rtrim(self::normalize_name($name), '/') . '/';
    if ($name === '/') {
      return false;
    }

    $entry = $this->start_entry($name, time());
    $this->finish_entry($entry, 0, 0, 0);
    return true;
  }

  public function addFile(string $path, string $name, ?callable $progress = null): bool {
    $name = self::normalize_name($name);
    if ($name === '' || !is_readable($path) || !is_file($path)) {
      return false;
    }

    $source_size = filesize($path);
    if ($source_size === false) {
      return false;
    }
    // Large files (>4 GB) are handled via per-entry ZIP64 — known from the
    // size already in hand here, so the local header gets it right on the
    // first write instead of needing a later seek-back patch.

    $input = @fopen($path, 'rb');
    if ($input === false) {
      return false;
    }

    $zip64 = $source_size > self::MAX_32 || $this->offset > self::MAX_32;
    $entry = $this->start_entry($name, (int) (filemtime($path) ?: time()), $zip64);
    $hash = hash_init('crc32b');
    $written = 0;

    try {
      while (!feof($input)) {
        $chunk = fread($input, 1048576);
        if ($chunk === false) {
          /* translators: %s: file name being added to the backup */
          throw new RuntimeException(sprintf(__('Could not read file for backup: %s', 'restorepilot-backup-migration'), basename($path)));
        }
        if ($chunk === '') {
          continue;
        }

        hash_update($hash, $chunk);
        $chunk_size = strlen($chunk);
        $this->write($chunk, 'write zip file data');
        $written += $chunk_size;

        if ($progress) {
          $progress($chunk_size, $written, (int) $source_size);
        }
      }
    } catch (Throwable $e) {
      fclose($input);
      throw $e;
    }

    fclose($input);
    $this->finish_entry($entry, self::crc32_hex_to_int(hash_final($hash)), $written, $written);
    return true;
  }

  /**
   * Same shape as addFile(), but the source is an already-open stream (a
   * single entry read back out of one of the source volumes via
   * RestorePilot_Backup_Archive::get_stream()) rather than a path on this
   * filesystem — the only thing serve_combined_volume_download() needs to
   * re-emit one volume's entry into the combined output at a fresh,
   * cumulative offset. Every RestorePilot entry is stored, never deflated
   * (METHOD_STORE), so the bytes read back out are already exactly the
   * final bytes this writes — no decompress/recompress round trip. The CRC
   * is recomputed here rather than trusted from the source archive's own
   * stat, the same way addFile() always computes its own rather than
   * trusting filesystem metadata. $known_size only decides the local
   * header's ZIP64 marker up front (see start_entry()) — it is never used
   * as the recorded size itself, that is always the real streamed count.
   * Does not close $stream — the caller owns it.
   */
  public function addFileFromStream($stream, string $name, int $mtime, int $known_size = 0): bool {
    $name = self::normalize_name($name);
    if ($name === '' || !is_resource($stream)) {
      return false;
    }

    $zip64 = $known_size > self::MAX_32 || $this->offset > self::MAX_32;
    $entry = $this->start_entry($name, $mtime > 0 ? $mtime : time(), $zip64);
    $hash = hash_init('crc32b');
    $written = 0;

    while (!feof($stream)) {
      $chunk = fread($stream, 1048576);
      if ($chunk === false) {
        /* translators: %s: file name being combined into the download */
        throw new RuntimeException(sprintf(__('Could not read %s from the backup.', 'restorepilot-backup-migration'), $name));
      }
      if ($chunk === '') {
        continue;
      }
      hash_update($hash, $chunk);
      $this->write($chunk, 'write combined download data');
      $written += strlen($chunk);
    }

    // $known_size came from the source volume's own central directory, not
    // from bytes this writer actually counted — it only exists to let the
    // local header's "version needed" be right on the first write, since a
    // non-seekable stream gets no second chance to patch it (see
    // start_entry()). If it disagrees with what was really read back, the
    // local header already told a reader the wrong thing and cannot be
    // fixed here; better to fail the download loudly than hand back a zip
    // that looks complete but is quietly inconsistent past this entry.
    if (($known_size > self::MAX_32) !== ($written > self::MAX_32)) {
      throw new RuntimeException(sprintf(
        /* translators: %s: file name inside the backup whose size did not match its own record */
        __('%s did not match its recorded size while combining this backup into one file.', 'restorepilot-backup-migration'),
        $name
      ));
    }

    $this->finish_entry($entry, self::crc32_hex_to_int(hash_final($hash)), $written, $written);
    return true;
  }

  public function close(): bool {
    if ($this->closed) {
      return true;
    }

    $central_start = $this->offset;
    foreach ($this->entries as $entry) {
      $this->write_central_directory_entry($entry);
    }

    $entry_count = count($this->entries);
    $central_size = $this->offset - $central_start;
    $has_large_entry = false;
    foreach ($this->entries as $e) {
      if (!empty($e['zip64'])) { $has_large_entry = true; break; }
    }
    $needs_zip64 = $has_large_entry || $entry_count > self::MAX_16 || $central_start > self::MAX_32 || $central_size > self::MAX_32;

    if ($needs_zip64) {
      $this->write_zip64_footer($entry_count, $central_size, $central_start);
    }

    $this->write(pack(
      'VvvvvVVv',
      0x06054b50,
      0,
      0,
      min($entry_count, self::MAX_16),
      min($entry_count, self::MAX_16),
      min($central_size, self::MAX_32),
      min($central_start, self::MAX_32),
      0
    ), 'write zip footer');

    $closed = fclose($this->handle);
    $this->closed = true;
    if (is_resource($this->journal_handle)) {
      fclose($this->journal_handle);
    }
    // The journal's only job was letting a resumption rebuild $entries; once
    // the central directory itself is on disk, the archive is self-describing
    // and the journal would just be stale bookkeeping. A streaming writer
    // (create_streaming()) never had a path or a journal file to begin with —
    // skip the unlink rather than let it fail (and get logged) every time.
    if ($this->path !== '') {
      @unlink($this->path . '.journal');
    }
    return $closed;
  }

  public function abort(): void {
    if (!$this->closed && is_resource($this->handle)) {
      fclose($this->handle);
    }
    if (is_resource($this->journal_handle)) {
      fclose($this->journal_handle);
    }
    $this->closed = true;
  }

  /**
   * Bytes written to this archive so far, excluding the central directory
   * that close() will append. Used by the volume writer to decide when to
   * roll over to the next volume.
   */
  public function current_size(): int {
    return $this->offset;
  }

  /**
   * Rough size of the central directory close() still has to write: ~46
   * bytes of fixed header per entry plus its name, and a little slack for
   * the ZIP64 records. Counted towards the volume budget so a volume with a
   * very large number of entries does not overshoot when it is finalised.
   */
  public function pending_directory_size(): int {
    $total = 128;
    foreach ($this->entries as $entry) {
      $total += 46 + strlen($entry['name']) + 32;
    }
    return $total;
  }

  /**
   * $known_zip64, when the caller can determine in advance that this entry
   * will need ZIP64 (its own size, or its offset in this archive, already
   * exceeds 4GB — both knowable up front for addFileFromStream(), where the
   * source's own stat already has the size), writes the correct "version
   * needed" into the local header immediately instead of the false-by-
   * default value write_central_directory_entry() would otherwise have to
   * seek back and patch once the true size is known. That patch requires a
   * seekable handle; a streaming writer (create_streaming(), writing
   * directly to php://output) has no seek to fall back on, so for it this
   * is the only chance to get it right.
   */
  private function start_entry(string $name, int $timestamp, bool $known_zip64 = false): array {
    [$dos_time, $dos_date] = $this->dos_time_date($timestamp);
    $name_length = strlen($name);
    $entry = [
      'name' => $name,
      'offset' => $this->offset,
      'time' => $dos_time,
      'date' => $dos_date,
      'header_zip64' => $known_zip64,
    ];

    $this->write(pack(
      'VvvvvvVVVvv',
      0x04034b50,
      $known_zip64 ? 45 : 20,
      self::FLAG_DATA_DESCRIPTOR,
      self::METHOD_STORE,
      $dos_time,
      $dos_date,
      0,
      0,
      0,
      $name_length,
      0
    ) . $name, 'write zip local header');

    return $entry;
  }

  private function finish_entry(array $entry, int $crc, int $compressed_size, int $uncompressed_size): void {
    $needs_zip64 = $compressed_size > self::MAX_32 || $uncompressed_size > self::MAX_32;

    if ($needs_zip64) {
      // ZIP64 data descriptor: 4-byte signature + 4-byte CRC + 8-byte comp + 8-byte uncomp.
      $this->write(
        pack('VV', 0x08074b50, $crc) .
        self::pack_uint64($compressed_size) .
        self::pack_uint64($uncompressed_size),
        'write zip64 data descriptor'
      );
    } else {
      $this->write(pack(
        'VVVV',
        0x08074b50,
        $crc,
        $compressed_size,
        $uncompressed_size
      ), 'write zip data descriptor');
    }

    $entry['crc']             = $crc;
    $entry['compressed_size'] = $compressed_size;
    $entry['uncompressed_size'] = $uncompressed_size;
    $entry['zip64']           = $needs_zip64;
    $this->entries[] = $entry;
    $this->journal_entry($entry);
  }

  /**
   * Durably records one finished entry so a resumed process can rebuild
   * $entries and know exactly where to continue, without holding the
   * central-directory list itself anywhere but PHP memory. A failure to
   * write the journal line is not fatal — it only means this entry (and
   * possibly a few after it, until the next line lands) gets safely redone
   * from scratch by the next resumption, which resume() already handles via
   * truncation.
   */
  private function journal_entry(array $entry): void {
    if (!is_resource($this->journal_handle)) {
      return;
    }
    $line = wp_json_encode(array_merge($entry, ['end_offset' => $this->offset]));
    if (!is_string($line)) {
      return;
    }
    fwrite($this->journal_handle, $line . "\n");
    fflush($this->journal_handle);
    if (is_resource($this->handle)) {
      fflush($this->handle);
    }
  }

  /** Entry names added so far, as a lookup set for a resumed writer. */
  public function entry_name_set(): array {
    return array_fill_keys(array_column($this->entries, 'name'), true);
  }

  private function write_central_directory_entry(array $entry): void {
    $name   = (string) $entry['name'];
    $offset = (int) $entry['offset'];
    $comp   = (int) $entry['compressed_size'];
    $uncomp = (int) $entry['uncompressed_size'];

    $needs_zip64 = $comp > self::MAX_32 || $uncomp > self::MAX_32 || $offset > self::MAX_32;

    $extra          = '';
    $version_needed = 20;
    $comp_field     = $comp;
    $uncomp_field   = $uncomp;
    $offset_field   = $offset;

    if ($needs_zip64) {
      $version_needed = 45;
      $zip64_data     = '';
      if ($uncomp > self::MAX_32) { $uncomp_field = self::MAX_32; $zip64_data .= self::pack_uint64($uncomp); }
      if ($comp   > self::MAX_32) { $comp_field   = self::MAX_32; $zip64_data .= self::pack_uint64($comp); }
      if ($offset > self::MAX_32) { $offset_field = self::MAX_32; $zip64_data .= self::pack_uint64($offset); }
      $extra = pack('vv', 0x0001, strlen($zip64_data)) . $zip64_data;

      // Seek back into the local file header to patch `version needed to
      // extract` (2 bytes at offset+4) so extractors see a consistent ZIP64
      // marker in both the local header and central directory — unless
      // start_entry() already knew and wrote the correct value the first
      // time (see $known_zip64 there), which is the only option at all for
      // a streaming writer with no seekable handle to patch.
      if (empty($entry['header_zip64']) && $this->flush_after_write === false && is_resource($this->handle)) {
        $cur = ftell($this->handle);
        fseek($this->handle, $entry['offset'] + 4);
        fwrite($this->handle, pack('v', 45));
        fseek($this->handle, $cur);
      }
    }

    $this->write(pack(
      'VvvvvvvVVVvvvvvVV',
      0x02014b50,
      $version_needed,
      $version_needed,
      self::FLAG_DATA_DESCRIPTOR,
      self::METHOD_STORE,
      (int) $entry['time'],
      (int) $entry['date'],
      (int) $entry['crc'],
      $comp_field,
      $uncomp_field,
      strlen($name),
      strlen($extra),
      0,
      0,
      0,
      0,
      $offset_field
    ) . $name . $extra, 'write zip central directory');
  }

  private function write_zip64_footer(int $entry_count, int $central_size, int $central_start): void {
    $zip64_start = $this->offset;
    $this->write(
      pack('V', 0x06064b50) .
      self::pack_uint64(44) .
      pack('vvVV', 45, 45, 0, 0) .
      self::pack_uint64($entry_count) .
      self::pack_uint64($entry_count) .
      self::pack_uint64($central_size) .
      self::pack_uint64($central_start),
      'write zip64 footer'
    );

    $this->write(
      pack('VV', 0x07064b50, 0) .
      self::pack_uint64($zip64_start) .
      pack('V', 1),
      'write zip64 locator'
    );
  }

  private function write(string $bytes, string $context): void {
    $length = strlen($bytes);
    $written_total = 0;

    while ($written_total < $length) {
      $written = fwrite($this->handle, substr($bytes, $written_total));
      if ($written === false || $written < 1) {
        // Explain why the write actually failed. The errno PHP reports in
        // error_get_last() is the reliable signal here — the three common
        // causes look identical from the plugin's side but need completely
        // different fixes, and none of them can be inferred from free space:
        //
        //   EFBIG  (27)  a per-file size cap, usually the host's RLIMIT_FSIZE
        //                ("ulimit -f"). Free space is irrelevant — no single
        //                file may exceed the cap, however empty the disk is.
        //   ENOSPC (28)  the filesystem genuinely ran out of space.
        //   EDQUOT (122) a hosting account disk quota, which disk_free_space()
        //                cannot see because it reports the whole filesystem.
        $os_error = error_get_last();
        $os_message = ($os_error && stripos((string) $os_error['message'], 'fwrite') !== false)
          ? trim((string) $os_error['message'])
          : '';
        $reason = $os_message !== ''
          ? $os_message
          : __('the operating system reported no further detail', 'restorepilot-backup-migration');

        $errno = 0;
        if ($os_message !== '' && preg_match('/errno=(\d+)/', $os_message, $m)) {
          $errno = (int) $m[1];
        }

        $written_note = sprintf(
          /* translators: %s: amount of data successfully written into the backup before it failed */
          __('The backup had reached %s before the failure.', 'restorepilot-backup-migration'),
          size_format((int) $this->offset)
        );

        if ($errno === 27) {
          $advice = sprintf(
            /* translators: %s: size the backup had reached before hitting the limit */
            __('This server refuses to create a single file larger than about %s, regardless of free disk space — typically a per-process file size limit (RLIMIT_FSIZE / "ulimit -f") set by the host. Either ask the host to raise it, or make the backup smaller by excluding large folders in the advanced file selection panel on the Backup tab.', 'restorepilot-backup-migration'),
            size_format((int) $this->offset)
          );
        } elseif ($errno === 122 || stripos($os_message, 'quota') !== false) {
          $advice = __('This is a hosting account disk quota, which is separate from filesystem free space and is not visible to WordPress. Ask your host what your account quota is and how much of it this site is using.', 'restorepilot-backup-migration');
        } else {
          $free = @disk_free_space(dirname($this->path));
          if ($free !== false && (int) $free <= 512 * 1024 * 1024) {
            /* translators: %s: free space remaining when the write failed */
            $advice = sprintf(__('Only %s remained free, so the destination ran out of space.', 'restorepilot-backup-migration'), size_format((int) $free));
          } elseif ($free !== false) {
            /* translators: %s: free space remaining when the write failed */
            $advice = sprintf(__('The filesystem still reports %s free, so the limit is being imposed by the hosting environment rather than by the disk itself. Your host can say which limit was reached.', 'restorepilot-backup-migration'), size_format((int) $free));
          } else {
            $advice = '';
          }
        }

        throw new RuntimeException(trim(sprintf(
          /* translators: 1: description of the write operation being attempted, 2: reason reported by the operating system, 3: how much had been written, 4: guidance on what to do about it */
          __('Could not write backup zip data while trying to %1$s. Reason: %2$s. %3$s %4$s', 'restorepilot-backup-migration'),
          $context,
          $reason,
          $written_note,
          $advice
        )));
      }
      $written_total += $written;
      $this->offset += $written;
    }

    if ($this->flush_after_write) {
      @flush();
    }
  }

  public static function normalize_name(string $name): string {
    $name = str_replace('\\', '/', $name);
    $name = ltrim($name, '/');
    $parts = [];

    foreach (explode('/', $name) as $part) {
      if ($part === '' || $part === '.') {
        continue;
      }
      if ($part === '..') {
        return '';
      }
      $parts[] = $part;
    }

    return implode('/', $parts);
  }

  private function dos_time_date(int $timestamp): array {
    $parts = getdate($timestamp);
    $year = max(1980, (int) $parts['year']);
    $dos_time = ((int) $parts['hours'] << 11) | ((int) $parts['minutes'] << 5) | ((int) floor((int) $parts['seconds'] / 2));
    $dos_date = (($year - 1980) << 9) | ((int) $parts['mon'] << 5) | (int) $parts['mday'];
    return [$dos_time, $dos_date];
  }

  private static function crc32_hex_to_int(string $hex): int {
    return (int) hexdec(substr($hex, -8));
  }

  private static function pack_uint64(int $value): string {
    $low = $value & 0xffffffff;
    $high = ($value >> 32) & 0xffffffff;
    return pack('VV', $low, $high);
  }
}

/**
 * Writes a backup as a set of volumes, each one a complete, independently
 * valid zip holding a subset of the entries.
 *
 * A single archive is limited by whatever the host will let one file grow to
 * — many shared hosts cap this via RLIMIT_FSIZE and refuse the write with
 * EFBIG regardless of free disk space. Rolling over to a new volume before
 * that ceiling keeps every individual file small, so total backup size is
 * bounded by available disk rather than by any per-file limit.
 *
 * Rollover only ever happens between whole entries, so no entry is ever
 * split across two volumes and each volume can be opened on its own.
 */
final class RestorePilot_Backup_Volume_Writer {
  /** @var RestorePilot_Backup_Zip_Writer|null */
  private $writer = null;
  private $base_path = '';
  private $split_bytes = 0;
  private $volumes = [];
  private $closed = false;
  private $oversize_entries = [];
  /** @var array<string,bool> Names already durably written, populated on resume(). */
  private $completed_names = [];

  public function __construct(string $base_path, int $split_bytes) {
    $this->base_path = $base_path;
    $this->split_bytes = max(1048576, $split_bytes);
  }

  /**
   * Reopen a volume set a previous, interrupted chunk was still writing.
   *
   * Every volume before the last one is already closed and finalized — a
   * volume writes its central directory and moves on the moment it rolls
   * over, never before — so only the last volume can possibly still have an
   * open journal. $existing_volume_paths comes from the caller (discovering
   * volumes on disk is the main class's job; this writer only ever tracks
   * the ones it itself created) and must be in volume-index order.
   */
  public static function resume(string $base_path, int $split_bytes, array $existing_volume_paths): self {
    $instance = new self($base_path, $split_bytes);
    if (!$existing_volume_paths) {
      return $instance;
    }

    $closed_paths = $existing_volume_paths;
    $last_path = end($closed_paths);
    $active_writer = null;

    if (is_file($last_path . '.journal')) {
      array_pop($closed_paths);
      $active_writer = RestorePilot_Backup_Zip_Writer::resume($last_path);
    }

    $completed_names = [];
    foreach ($closed_paths as $closed_path) {
      $completed_names += self::names_in_closed_volume($closed_path);
    }
    if ($active_writer !== null) {
      $completed_names += $active_writer->entry_name_set();
    }

    $instance->volumes = $existing_volume_paths;
    $instance->writer = $active_writer;
    $instance->completed_names = $completed_names;
    return $instance;
  }

  /** Every entry name in an already-finalized volume, read from its own central directory. */
  private static function names_in_closed_volume(string $path): array {
    $names = [];
    if (!class_exists('ZipArchive') || !is_file($path)) {
      return $names;
    }
    $za = new ZipArchive();
    if ($za->open($path) !== true) {
      return $names;
    }
    for ($i = 0; $i < $za->numFiles; $i++) {
      $name = $za->getNameIndex($i);
      if (is_string($name)) {
        $names[$name] = true;
      }
    }
    $za->close();
    return $names;
  }

  /**
   * Whether $name was already durably written in a previous chunk. Callers
   * walking the filesystem check this before adding each file or directory
   * so a resumed backup never duplicates an entry already safely in the zip.
   * Always false for a writer that was not resumed.
   */
  /**
   * Checked against the exact form Zip_Writer::addEmptyDir() actually
   * stores — a trailing slash appended after its own normalization — not
   * against whatever raw string the caller happened to build, which is why
   * both forms are tried: a caller here has no cheap way to know in advance
   * whether $name names a file or a directory, and the two cannot collide
   * (a filesystem cannot have a file and a directory of the same name in
   * the same parent), so checking both is always unambiguous.
   */
  public function has_entry(string $name): bool {
    $normalized = RestorePilot_Backup_Zip_Writer::normalize_name($name);
    if ($normalized === '') {
      return false;
    }
    return isset($this->completed_names[$normalized]) || isset($this->completed_names[$normalized . '/']);
  }

  /**
   * Path of volume $index (1-based). Volume 1 keeps the plain name so a
   * single-volume backup is byte-for-byte the same shape as before this
   * existed; later volumes get a -vNNN suffix.
   */
  public static function volume_path(string $base_path, int $index): string {
    if ($index <= 1) {
      return $base_path;
    }
    $suffix = '-v' . str_pad((string) $index, 3, '0', STR_PAD_LEFT);
    if (substr($base_path, -4) === '.zip') {
      return substr($base_path, 0, -4) . $suffix . '.zip';
    }
    // The temporary path (built from *.restorepilot-tmp, not *.zip) still
    // gets a .zip-suffixed follow-on name. discover_volumes() only ever
    // matches names ending in .zip, and it now has to find temp volumes too
    // — both to resume an interrupted chunk and to clean up every volume of
    // a backup that fails outright (previously only volume 1 of the temp set
    // was ever found and removed on failure; 2+ were silently orphaned).
    return $base_path . $suffix . '.zip';
  }

  private function active(): RestorePilot_Backup_Zip_Writer {
    if ($this->writer === null) {
      $path = self::volume_path($this->base_path, count($this->volumes) + 1);
      $this->writer = RestorePilot_Backup_Zip_Writer::create($path);
      $this->volumes[] = $path;
    }
    return $this->writer;
  }

  /**
   * Finalise the current volume if adding $incoming_bytes more would take it
   * past the split threshold. Called before an entry is written, never
   * during one.
   */
  private function roll_if_needed(int $incoming_bytes): void {
    if ($this->writer === null) {
      return;
    }
    $projected = $this->writer->current_size()
      + $this->writer->pending_directory_size()
      + $incoming_bytes;
    if ($projected <= $this->split_bytes) {
      return;
    }
    // Never roll an empty volume: if a single entry is larger than the whole
    // split budget, it has to live in a volume of its own and that volume is
    // allowed to exceed the threshold — an entry cannot span volumes.
    if ($this->writer->current_size() <= 0) {
      return;
    }
    if ($this->writer->close() === false) {
      throw new RuntimeException(__('Could not finalize a backup volume.', 'restorepilot-backup-migration'));
    }
    $this->writer = null;
  }

  /**
   * @param bool $allow_roll Pass false to force the entry into the volume
   *                         that is already open. Used for the manifest,
   *                         which records the final volume count and so must
   *                         not itself create another volume.
   */
  public function addFromString(string $name, string $contents, bool $allow_roll = true): bool {
    if ($allow_roll) {
      $this->roll_if_needed(strlen($contents));
    }
    return $this->active()->addFromString($name, $contents);
  }

  public function addEmptyDir(string $name): bool {
    $this->roll_if_needed(0);
    return $this->active()->addEmptyDir($name);
  }

  public function addFile(string $path, string $name, ?callable $progress = null): bool {
    $size = @filesize($path);
    $size = ($size === false) ? 0 : (int) $size;
    if ($size > $this->split_bytes) {
      // Recorded so the caller can warn: this file forces a volume larger
      // than the configured split size, which may still hit a host file-size
      // limit. Nothing can be done about it here — a zip entry is indivisible.
      $this->oversize_entries[] = $name;
    }
    $this->roll_if_needed($size);
    return $this->active()->addFile($path, $name, $progress);
  }

  public function close(): bool {
    if ($this->closed) {
      return true;
    }
    $this->closed = true;
    if ($this->writer === null) {
      return true;
    }
    $result = $this->writer->close();
    $this->writer = null;
    return $result;
  }

  public function abort(): void {
    if ($this->writer !== null) {
      $this->writer->abort();
      $this->writer = null;
    }
    $this->closed = true;
  }

  /** Paths of every volume created so far, in order. */
  public function volumes(): array {
    return $this->volumes;
  }

  /** Entry names that individually exceeded the split threshold. */
  public function oversize_entries(): array {
    return $this->oversize_entries;
  }
}

/**
 * Read-side counterpart to the volume writer: presents a set of backup
 * volumes as if it were a single archive.
 *
 * Every entry lives whole inside exactly one volume, so lookups resolve to
 * the volume that holds them and reads are served directly from there — no
 * concatenation and no temporary copy, which matters because the whole point
 * of volumes is that the full backup may be far larger than anything that
 * can exist as one file.
 *
 * A single-volume backup (including every backup made before volumes
 * existed) is just the one-element case.
 */
final class RestorePilot_Backup_Archive {
  /** @var ZipArchive[] */
  private $zips = [];
  private $paths = [];
  /** @var array<string,array{0:int,1:int}> entry name => [volume index, index within that volume] */
  private $index = [];
  /** @var array<int,array{0:int,1:int}> global entry number => [volume index, index within volume] */
  private $ordered = [];

  /**
   * @param string[] $paths Volume paths in order; the first is volume 1.
   */
  public function __construct(array $paths) {
    foreach ($paths as $path) {
      $zip = new ZipArchive();
      $opened = $zip->open($path);
      if ($opened !== true) {
        $this->close();
        throw new RuntimeException(sprintf(
          /* translators: %s: file name of the backup volume that could not be opened */
          __('Could not open backup volume %s.', 'restorepilot-backup-migration'),
          basename($path)
        ));
      }
      $volume = count($this->zips);
      $this->zips[] = $zip;
      $this->paths[] = $path;

      for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (!is_string($name) || $name === '') {
          continue;
        }
        $this->ordered[] = [$volume, $i];
        // First volume containing a name wins; names are unique across a
        // well-formed set, and preferring the earliest keeps behaviour
        // deterministic if a set is ever malformed.
        if (!isset($this->index[$name])) {
          $this->index[$name] = [$volume, $i];
        }
      }
    }
  }

  public function volume_count(): int {
    return count($this->zips);
  }

  /** Total number of entries across every volume. */
  public function num_files(): int {
    return count($this->ordered);
  }

  public function get_name_index(int $i) {
    if (!isset($this->ordered[$i])) {
      return false;
    }
    [$volume, $local] = $this->ordered[$i];
    return $this->zips[$volume]->getNameIndex($local);
  }

  public function stat_index(int $i) {
    if (!isset($this->ordered[$i])) {
      return false;
    }
    [$volume, $local] = $this->ordered[$i];
    return $this->zips[$volume]->statIndex($local);
  }

  public function stat_name(string $name) {
    if (!isset($this->index[$name])) {
      return false;
    }
    [$volume, $local] = $this->index[$name];
    return $this->zips[$volume]->statIndex($local);
  }

  public function get_from_name(string $name) {
    if (!isset($this->index[$name])) {
      return false;
    }
    [$volume, $local] = $this->index[$name];
    return $this->zips[$volume]->getFromIndex($local);
  }

  public function get_stream(string $name) {
    if (!isset($this->index[$name])) {
      return false;
    }
    [$volume] = $this->index[$name];
    return $this->zips[$volume]->getStream($name);
  }

  public function close(): void {
    foreach ($this->zips as $zip) {
      @$zip->close();
    }
    $this->zips = [];
    $this->index = [];
    $this->ordered = [];
  }
}

final class RestorePilot_Backup_Migration {
  const VERSION = '0.5.0';
  const SLUG = 'restorepilot-backup-migration';
  const NONCE = 'restorepilot_nonce';
  const PART_SIZE = 104857600; // 100 MB
  // A chunked restore upload declares its own chunk count up front. Without a
  // ceiling, a client could declare an arbitrarily large total_chunks and
  // have the server accept and store parts one at a time indefinitely before
  // any archive-level validation ever runs. 5000 * PART_SIZE is ~500 GB —
  // far above any realistic single-site backup — so this only ever rejects a
  // pathological request, never a genuine large restore upload.
  const MAX_RESTORE_UPLOAD_CHUNKS = 5000;
  const MAX_BACKUPS = 2;
  const MAX_RESTORE_ROLLBACKS = 3;
  const MAX_LOG_BYTES = 80000;
  // Safety ceilings for a restore archive, applied before any destructive
  // action. These are deliberately generous — well above what any real
  // RestorePilot backup produces — so a genuine large-site backup is never
  // rejected; they exist to fail a pathological or maliciously crafted
  // archive predictably instead of exhausting memory, CPU, or disk.
  const MAX_RESTORE_ZIP_ENTRIES = 2000000;
  const MAX_MANIFEST_JSON_BYTES = 5 * 1024 * 1024; // 5 MB — manifest.json is a small, fixed-shape document.
  const MAX_DATABASE_JSON_BYTES = 2147483648; // 2 GB — comfortably above a real single-site database export.
  // The database export is written as newline-delimited JSON split into parts
  // of this size. Keeping each part small means neither export nor restore
  // ever holds more than one line in memory, so database size is bounded by
  // disk rather than by PHP's memory_limit, and each part stays comfortably
  // inside a single backup volume once volume splitting is in play.
  const DATABASE_PART_BYTES = 33554432; // 32 MB
  // Directory inside the archive holding the newline-delimited export parts.
  const DATABASE_PART_DIR = 'database';
  // A backup is written as volumes of at most this size. Kept well below the
  // per-file ceilings shared hosts impose (RLIMIT_FSIZE, which surfaces as
  // EFBIG) so total backup size is limited by free disk rather than by how
  // large a single file may be. Filterable for hosts that are stricter still.
  const BACKUP_VOLUME_BYTES = 1073741824; // 1 GB
  // Upper bound on volumes in one backup set, so volume discovery can never
  // loop unbounded on a malformed directory. At the default volume size this
  // allows a backup of several terabytes.
  const MAX_BACKUP_VOLUMES = 4096;
  const MAX_RESTORE_TABLE_COUNT = 5000; // A real WordPress install has a few dozen tables at most.
  const DIRECT_DOWNLOAD_MAX_AGE_SECONDS = 6 * HOUR_IN_SECONDS;
  const BACKUP_STALE_SECONDS = 7200; // 2 hours — used for restore, which can block on one huge table.
  // A backup worker touches its job at least every ~5 seconds in both the
  // database-export and file-collection loops, so a "running" backup that has
  // not updated in 15 minutes is certainly dead. Used only for releasing a
  // backup lock left by a crashed worker, so the next backup is not blocked for
  // the full 2-hour window.
  const BACKUP_HEARTBEAT_STALE_SECONDS = 900;
  const BACKUP_START_TIMEOUT_SECONDS = 180;
  // How long one backup worker process is allowed to keep adding files
  // before it must checkpoint and reschedule itself instead of continuing.
  // Kept well under every common host execution-time limit (PHP-FPM,
  // proxy/CDN timeouts) so a chunk always finishes cleanly on its own terms
  // rather than being killed mid-write. Filterable via
  // restorepilot_backup_chunk_seconds. Does not bound the database export,
  // which still runs as one uninterrupted transaction — see
  // write_database_export().
  const BACKUP_CHUNK_SECONDS = 20;
  const BACKUP_LOCK_OPTION = 'restorepilot_backup_lock';
  const BACKUP_WORKER_LOCK_PREFIX = 'restorepilot_backup_worker_';
  const RESTORE_LOCK_OPTION = 'restorepilot_restore_lock';
  const MAINTENANCE_OPTION = 'restorepilot_maintenance_until';
  const RESTORE_JOB_PREFIX = 'restorepilot_restore_job_';
  // Holds the restored site's real active_plugins list while a restore is
  // between its database swap and the end of its file phase — see
  // defer_active_plugins_during_restore() for why the list cannot simply be
  // left in place across that window. Deliberately NOT one of the prefixes
  // purge_foreign_runtime_state() wipes: that function runs again on every
  // resumption, and erasing this option would lose the site's plugin set
  // permanently.
  const DEFERRED_PLUGINS_OPTION = 'restorepilot_deferred_active_plugins';
  // Per-job restore worker lock, mirroring BACKUP_WORKER_LOCK_PREFIX: released
  // every chunk (unlike RESTORE_LOCK_OPTION, held for the whole job), so it
  // only ever guards against two workers touching the same job at once.
  const RESTORE_WORKER_LOCK_PREFIX = 'restorepilot_restore_worker_';
  // Same chunking model as BACKUP_CHUNK_SECONDS, applied to restore_database()
  // (between and within tables) and restore_files() (between files). Filterable
  // via restorepilot_restore_chunk_seconds.
  const RESTORE_CHUNK_SECONDS = 20;
  // Markers for restore scratch tables. Distinctive and RestorePilot-specific
  // so they cannot be mistaken for another plugin's table; cleanup of these
  // tables never relies on the marker alone — see RESTORE_TABLE_JOURNAL_OPTION.
  const RESTORE_TMP_TABLE_MARKER = 'restorepilot_rtmp_';
  const RESTORE_OLD_TABLE_MARKER = 'restorepilot_rold_';
  // Exact record of scratch tables a restore attempt has created, written
  // before any CREATE TABLE and cleared only after that attempt's own cleanup
  // finishes. If a restore is interrupted (timeout, crash, kill -9), the next
  // restore sweeps precisely these journaled names — never a wildcard
  // "LIKE '{prefix}marker%'" scan, which could match and destroy an unrelated
  // table that happens to share the marker string.
  const RESTORE_TABLE_JOURNAL_OPTION = 'restorepilot_restore_table_journal';
  const LOG_OPTION = 'restorepilot_recent_log';
  const SETTINGS_OPTION = 'restorepilot_settings';
  const RESTORE_SUCCESS_OPTION = 'restorepilot_restore_success_notice';
  private static $initialized = false;
  private static $error_logging_enabled = false;
  private static $handling_php_error = false;
  /** @var array<string,bool> Deduplicates runtime errors already written this request. */
  private static $logged_runtime_errors = [];
  const MAX_RUNTIME_ERRORS_PER_REQUEST = 25;
  private static $active_backup_job_id = '';
  // Absolute microtime() deadline for the current chunk. Set once at the top
  // of create_backup_package() and read from deep inside the file-walk loops
  // (throw_if_chunk_time_exceeded()) without threading a parameter through
  // every call in between — the same reasoning as $active_backup_job_id.
  private static $chunk_deadline = 0.0;
  // Whether this resumption has completed at least one real unit of work
  // (a file added to the zip, a table's database rows). Reset to false
  // alongside $chunk_deadline; throw_if_chunk_time_exceeded() will not yield
  // until this is true, regardless of how far past the deadline the clock
  // already is — a chunk budget set too low relative to a large archive's
  // own fixed per-resumption overhead (reopening it, validating it) would
  // otherwise be able to expire before any real work ever ran, yielding
  // every single resumption with zero forward progress, forever. The
  // default budget has wide margin over that overhead (measured up to
  // 25,000 archive entries at under 100ms), so this only ever matters for
  // an aggressively low custom restorepilot_backup_chunk_seconds value on
  // an unusually large site — but there it is the difference between a
  // slower-than-configured chunk and a permanently stuck job.
  private static $chunk_progress_made = false;
  // Same purpose as $chunk_deadline, kept separate so a future change to one
  // side's chunk budget can never accidentally leak into the other's.
  private static $restore_chunk_deadline = 0.0;
  /** Restore-side counterpart to $chunk_progress_made — see there for the reasoning. */
  private static $restore_chunk_progress_made = false;
  private static $active_restore_job_id = '';
  private static $active_scheduled_backup = false;
  private static $file_scan_progress = [];
  private static $restore_success_notice = null;
  /**
   * Tracks which known-safe exclusion categories were actually hit during the
   * current backup, so the manifest can report exactly what was left out
   * instead of silently claiming a "full" backup. Reset per backup run.
   * @var array<string,bool>
   */
  private static $backup_exclusion_labels = [];

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
    if (plugin_basename(__FILE__) !== $file) {
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
      . '<p>' . __('Backup archives are stored locally on this server inside the WordPress uploads directory unless an administrator downloads or moves them. RestorePilot does not send backup data to the plugin author or to any third-party service.', 'restorepilot-backup-migration') . '</p>'
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
      'i18n'         => [
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

  public static function render_admin_page(): void {
    self::enable_error_logging();

    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('You do not have permission to access this page.', 'restorepilot-backup-migration'));
    }

    $notice = self::query_value('rp_notice');
    $error = self::query_value('rp_error');
    $tab = sanitize_key(self::query_value('tab', 'backup'));
    if (!in_array($tab, ['backup', 'daily', 'restore', 'logs', 'settings'], true)) {
      $tab = 'backup';
    }
    self::enforce_backup_retention();
    $backups = self::list_backups();
    $backup_file_items = self::list_backup_file_items();
    $settings = self::get_settings();
    $next_daily_backup = function_exists('wp_next_scheduled') ? wp_next_scheduled('restorepilot_scheduled_backup') : false;
    ?>
    <div class="wrap">
  
      <div class="rp-page">

      <?php
      /*
       * WordPress injects admin_notices from other plugins right after the
       * first <h1> inside .wrap. Placing a screen-reader-only <h1> here
       * keeps those notices above our visual header card instead of inside it.
       */
      ?>
      <h1 class="screen-reader-text"><?php echo esc_html__('RestorePilot', 'restorepilot-backup-migration'); ?></h1>

      <?php if ($notice): ?>
        <div class="notice notice-success is-dismissible rp-admin-notice"><p><?php echo esc_html($notice); ?></p></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="notice notice-error is-dismissible rp-admin-notice"><p><?php echo esc_html($error); ?></p></div>
      <?php endif; ?>

      <!-- Page header — title is a <div> (not <h1>) so it does not become a
           second notice-injection anchor and does not re-trigger the issue. -->
      <div class="rp-page-header">
        <div class="rp-page-header__logo" aria-hidden="true">
          <span class="dashicons dashicons-shield-alt"></span>
        </div>
        <div>
          <div class="rp-page-header__title" role="heading" aria-level="1">
            <?php echo esc_html__('RestorePilot', 'restorepilot-backup-migration'); ?>
          </div>
          <p class="rp-page-header__subtitle"><?php echo esc_html__('Backup, restore, and migrate WordPress sites with safe URL replacement.', 'restorepilot-backup-migration'); ?></p>
        </div>
        <span class="rp-page-header__badge">v<?php echo esc_html(self::VERSION); ?></span>
      </div>

      <!-- Tab nav -->
      <nav class="nav-tab-wrapper">
        <a class="nav-tab <?php echo esc_attr($tab === 'backup' ? 'nav-tab-active' : ''); ?>"
           href="<?php echo esc_url(add_query_arg('tab', 'backup', self::admin_url())); ?>"
           data-rp-tab="backup">
          <span class="dashicons dashicons-backup" aria-hidden="true"></span>
          <?php echo esc_html__('Backup', 'restorepilot-backup-migration'); ?>
        </a>
        <a class="nav-tab <?php echo esc_attr($tab === 'daily' ? 'nav-tab-active' : ''); ?>"
           href="<?php echo esc_url(add_query_arg('tab', 'daily', self::admin_url())); ?>"
           data-rp-tab="daily">
          <span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
          <?php echo esc_html__('Daily Backup', 'restorepilot-backup-migration'); ?>
        </a>
        <a class="nav-tab <?php echo esc_attr($tab === 'restore' ? 'nav-tab-active' : ''); ?>"
           href="<?php echo esc_url(add_query_arg('tab', 'restore', self::admin_url())); ?>"
           data-rp-tab="restore">
          <span class="dashicons dashicons-upload" aria-hidden="true"></span>
          <?php echo esc_html__('Restore', 'restorepilot-backup-migration'); ?>
        </a>
        <a class="nav-tab <?php echo esc_attr($tab === 'logs' ? 'nav-tab-active' : ''); ?>"
           href="<?php echo esc_url(add_query_arg('tab', 'logs', self::admin_url())); ?>"
           data-rp-tab="logs">
          <span class="dashicons dashicons-list-view" aria-hidden="true"></span>
          <?php echo esc_html__('Logs', 'restorepilot-backup-migration'); ?>
        </a>
        <a class="nav-tab <?php echo esc_attr($tab === 'settings' ? 'nav-tab-active' : ''); ?>"
           href="<?php echo esc_url(add_query_arg('tab', 'settings', self::admin_url())); ?>"
           data-rp-tab="settings">
          <span class="dashicons dashicons-admin-settings" aria-hidden="true"></span>
          <?php echo esc_html__('Status', 'restorepilot-backup-migration'); ?>
        </a>
      </nav>

      <section id="rp-panel-backup" class="rp-tab-panel <?php echo esc_attr($tab === 'backup' ? 'is-active' : ''); ?>">
      <div class="rp-stack">
      <?php if (is_multisite()): self::render_multisite_unsupported_notice(); else: ?>

        <!-- Create backup card -->
        <div class="rp-card">
          <div class="rp-card__head">
            <div class="rp-card__head-icon" aria-hidden="true"><span class="dashicons dashicons-backup"></span></div>
            <h2><?php echo esc_html__('Create Backup', 'restorepilot-backup-migration'); ?></h2>
          </div>
          <div class="rp-card__body">
            <p><?php echo esc_html__('Create a complete backup of your database and files. Large zips are handed off to the web server for reliable downloads.', 'restorepilot-backup-migration'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="rp-backup-form">
              <?php wp_nonce_field(self::NONCE); ?>
              <input type="hidden" name="action" value="restorepilot_backup">
              <input type="hidden" name="include_files" value="1">
              <input type="hidden" name="file_selection_enabled" value="1">

              <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:4px;">
                <button type="submit" class="button button-primary rp-button-with-icon" id="rp-backup-button">
                  <span class="dashicons dashicons-backup" aria-hidden="true"></span>
                  <span class="rp-button-label"><?php echo esc_html__('Create Backup', 'restorepilot-backup-migration'); ?></span>
                </button>
                <button type="button" class="button" id="rp-cancel-backup-button" style="display:none;">
                  <?php echo esc_html__('Cancel', 'restorepilot-backup-migration'); ?>
                </button>
              </div>

              <div class="rp-progress" id="rp-backup-progress" aria-live="polite">
                <div class="rp-progress__wrap">
                  <div class="rp-progress__header">
                    <span class="rp-progress__label" id="rp-backup-progress-label"><?php echo esc_html__('Backup in progress', 'restorepilot-backup-migration'); ?></span>
                    <span class="rp-progress__pct" id="rp-backup-progress-pct">0%</span>
                  </div>
                  <div class="rp-progress__track">
                    <div class="rp-progress__bar" id="rp-backup-progress-bar"></div>
                  </div>
                  <div class="rp-progress__text" id="rp-backup-progress-text">
                    <?php echo esc_html__('Starting…', 'restorepilot-backup-migration'); ?>
                  </div>
                </div>
              </div>

              <div class="rp-advanced rp-disclosure">
                <button type="button" class="rp-disclosure__summary" aria-expanded="false">
                  <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                  <?php echo esc_html__('Advanced file selection', 'restorepilot-backup-migration'); ?>
                </button>
                <div class="rp-disclosure__panel">
                  <div class="rp-disclosure__panel-inner">
                    <p style="margin-top:12px;font-size:13px;color:#646970;"><?php echo esc_html__('Choose which top-level wp-content folders and files to include alongside the database export.', 'restorepilot-backup-migration'); ?></p>
                    <div class="rp-file-tools">
                      <button type="button" class="button" id="rp-select-all-files"><?php echo esc_html__('Select all', 'restorepilot-backup-migration'); ?></button>
                      <button type="button" class="button" id="rp-clear-files"><?php echo esc_html__('Clear all', 'restorepilot-backup-migration'); ?></button>
                    </div>
                    <div class="rp-file-list">
                      <?php if (!$backup_file_items): ?>
                        <p style="margin:0;font-size:13px;color:#646970;"><?php echo esc_html__('No selectable wp-content items were found.', 'restorepilot-backup-migration'); ?></p>
                      <?php else: ?>
                        <?php foreach ($backup_file_items as $item): ?>
                          <label class="rp-file-item">
                            <input type="checkbox" name="backup_paths[]" value="<?php echo esc_attr($item['path']); ?>" checked>
                            <span><?php echo esc_html($item['label']); ?></span>
                          </label>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </form>
          </div>
        </div>

        <!-- Existing backups card -->
        <div class="rp-card">
          <div class="rp-card__head">
            <div class="rp-card__head-icon" aria-hidden="true"><span class="dashicons dashicons-media-archive"></span></div>
            <h2><?php echo esc_html__('Existing Backups', 'restorepilot-backup-migration'); ?></h2>
          </div>
          <div class="rp-card__retention-notice">
            <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
            <?php echo esc_html(sprintf(
              /* translators: %1$s: sentence about the retention limit, %2$s: sentence about how many backups are stored now */
              __('%1$s %2$s Download backups you want to keep — older ones are removed automatically.', 'restorepilot-backup-migration'),
              sprintf(
                /* translators: %d: maximum number of backups kept */
                _n(
                  'Free version: keeps the newest %d backup total.',
                  'Free version: keeps the newest %d backups total.',
                  self::MAX_BACKUPS,
                  'restorepilot-backup-migration'
                ),
                self::MAX_BACKUPS
              ),
              sprintf(
                /* translators: %d: number of backups currently stored */
                _n(
                  'You currently have %d backup stored.',
                  'You currently have %d backups stored.',
                  count($backups),
                  'restorepilot-backup-migration'
                ),
                count($backups)
              )
            )); ?>
          </div>
          <?php if (!$backups): ?>
            <div class="rp-empty-state">
              <span class="dashicons dashicons-media-archive" aria-hidden="true"></span>
              <p><?php echo esc_html__('No backups yet. Create your first backup above.', 'restorepilot-backup-migration'); ?></p>
            </div>
          <?php else: ?>
            <table class="rp-backup-table rp-backup-table--selectable">
              <thead>
                <tr>
                  <th class="check-column"><input type="checkbox" id="rp-select-all-backups" aria-label="<?php echo esc_attr__('Select all backups', 'restorepilot-backup-migration'); ?>"></th>
                  <th><?php echo esc_html__('Backup date', 'restorepilot-backup-migration'); ?></th>
                  <th><?php echo esc_html__('Backup file', 'restorepilot-backup-migration'); ?></th>
                  <th><?php echo esc_html__('Actions', 'restorepilot-backup-migration'); ?></th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($backups as $backup):
                $backup_abs_path = self::backup_dir() . '/' . $backup['name'];
              ?>
                <tr>
                  <th class="check-column">
                    <input type="checkbox" name="backup_ids[]" value="<?php echo esc_attr($backup['name']); ?>"
                           <?php /* translators: %s: backup file name */ ?>
                           aria-label="<?php echo esc_attr(sprintf(__('Select %s', 'restorepilot-backup-migration'), $backup['name'])); ?>">
                  </th>
                  <td class="rp-backup-date" data-rp-label="<?php echo esc_attr__('Backup date', 'restorepilot-backup-migration'); ?>">
                    <strong><?php echo esc_html(wp_date((string) get_option('date_format'), (int) $backup['modified'])); ?></strong>
                    <span><?php echo esc_html(wp_date((string) get_option('time_format'), (int) $backup['modified'])); ?> &mdash; <?php echo esc_html(size_format((int) $backup['size'])); ?></span>
                    <?php
                    $rp_triggered = isset($backup['triggered_by']) ? (string) $backup['triggered_by'] : 'manual';
                    $rp_type      = isset($backup['backup_type'])  ? (string) $backup['backup_type']  : 'full';
                    $rp_trigger_label = $rp_triggered === 'scheduled'
                      ? __('Auto', 'restorepilot-backup-migration')
                      : __('Manual', 'restorepilot-backup-migration');
                    $rp_type_label = $rp_type === 'database'
                      ? __('DB only', 'restorepilot-backup-migration')
                      : ($rp_type === 'selected-content'
                        ? __('Partial', 'restorepilot-backup-migration')
                        : __('Full', 'restorepilot-backup-migration'));
                    ?>
                    <span class="rp-backup-badges">
                      <span class="rp-badge rp-badge--<?php echo esc_attr($rp_triggered); ?>"><?php echo esc_html($rp_trigger_label); ?></span>
                      <span class="rp-badge rp-badge--type"><?php echo esc_html($rp_type_label); ?></span>
                    </span>
                  </td>
                  <td class="rp-backup-parts" data-rp-label="<?php echo esc_attr__('Backup file', 'restorepilot-backup-migration'); ?>">
                    <?php self::render_backup_download_controls($backup['name'], (int) $backup['size'], (int) ($backup['volumes'] ?? 1)); ?>
                  </td>
                  <td class="rp-backup-actions" data-rp-label="<?php echo esc_attr__('Actions', 'restorepilot-backup-migration'); ?>">
                    <a class="button"
                       href="<?php echo esc_url(self::action_url('restorepilot_health', $backup['name'])); ?>">
                      <?php echo esc_html__('Check', 'restorepilot-backup-migration'); ?>
                    </a>
                    <button type="button"
                            class="button rp-btn-restore rp-restore-from-existing"
                            data-backup-name="<?php echo esc_attr($backup['name']); ?>"
                            data-backup-path="<?php echo esc_attr($backup_abs_path); ?>">
                      <?php echo esc_html__('Restore', 'restorepilot-backup-migration'); ?>
                    </button>
                    <a class="button rp-btn-danger"
                       href="<?php echo esc_url(self::action_url('restorepilot_delete', $backup['name'])); ?>"
                       data-confirm="<?php echo esc_attr(__('Delete this backup? This cannot be undone.', 'restorepilot-backup-migration')); ?>">
                      <?php echo esc_html__('Delete', 'restorepilot-backup-migration'); ?>
                    </a>
                    <a class="button" href="<?php echo esc_url(add_query_arg('tab', 'logs', self::admin_url())); ?>">
                      <?php echo esc_html__('View Log', 'restorepilot-backup-migration'); ?>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>

            <!-- Restore-from-existing confirmation modal (shared, populated by JS) -->
            <div class="rp-modal-backdrop" id="rp-restore-existing-modal" role="dialog" aria-modal="true" aria-labelledby="rp-restore-existing-title" style="display:none;">
              <div class="rp-modal">
                <div class="rp-modal__head">
                  <div class="rp-modal__head-icon" aria-hidden="true"><span class="dashicons dashicons-warning"></span></div>
                  <h2 id="rp-restore-existing-title"><?php echo esc_html__('Restore Full Backup', 'restorepilot-backup-migration'); ?></h2>
                </div>
                <div class="rp-modal__body">
                  <div class="rp-modal__warning">
                    <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                    <?php echo esc_html__('This will permanently overwrite this site\'s live database and wp-content files. There is no automatic undo.', 'restorepilot-backup-migration'); ?>
                  </div>
                  <p style="margin:12px 0 4px;"><?php echo esc_html__('Restoring from:', 'restorepilot-backup-migration'); ?><br>
                     <strong id="rp-restore-existing-name" style="word-break:break-all;"></strong></p>
                  <div class="rp-modal__panel">
                    <strong><?php echo esc_html__('What happens next', 'restorepilot-backup-migration'); ?></strong>
                    <ul>
                      <li><?php echo esc_html__('The backup is validated — nothing changes until validation passes.', 'restorepilot-backup-migration'); ?></li>
                      <li><?php echo esc_html__('A rollback snapshot of the current database is saved before any tables are replaced.', 'restorepilot-backup-migration'); ?></li>
                      <li><?php echo esc_html__('The database is fully replaced with the backup. wp-content files from the backup overwrite matching files here — files that exist on this site but are not in the backup are not removed.', 'restorepilot-backup-migration'); ?></li>
                      <li><?php echo esc_html__('You will be asked to log in again — this is expected.', 'restorepilot-backup-migration'); ?></li>
                    </ul>
                  </div>
                  <div class="rp-modal__panel rp-modal__panel--warn">
                    <strong><?php echo esc_html__('⚠ If restore fails or the site becomes unreachable', 'restorepilot-backup-migration'); ?></strong>
                    <ul>
                      <li><?php echo esc_html__('RestorePilot removes maintenance mode automatically — but a partial restore may leave the database in a mixed state.', 'restorepilot-backup-migration'); ?></li>
                      <li><?php echo esc_html__('If you cannot log in after a failed restore, use the Pre-Restore Rollback Point to recover: go to Restore → Pre-Restore Rollback Points.', 'restorepilot-backup-migration'); ?></li>
                      <li><strong><?php echo esc_html__('Recommended: download a fresh backup and keep it somewhere safe before proceeding.', 'restorepilot-backup-migration'); ?></strong></li>
                    </ul>
                  </div>
                  <label class="rp-modal__confirm-check">
                    <input type="checkbox" id="rp-restore-existing-ack">
                    <?php echo esc_html__('I have a backup and understand this will overwrite my live database and matching wp-content files, and cannot be undone automatically.', 'restorepilot-backup-migration'); ?>
                  </label>
                </div>
                <div class="rp-modal__foot">
                  <button type="button" class="button" id="rp-restore-existing-cancel"><?php echo esc_html__('Cancel', 'restorepilot-backup-migration'); ?></button>
                  <form id="rp-restore-existing-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                    <?php wp_nonce_field(self::NONCE); ?>
                    <input type="hidden" name="action" value="restorepilot_restore">
                    <input type="hidden" name="confirm_restore" value="1">
                    <input type="hidden" name="restore_files" value="1">
                    <input type="hidden" name="auto_detect_urls" value="1">
                    <input type="hidden" name="server_backup_path" id="rp-restore-existing-path" value="">
                    <button type="submit" class="button button-primary" id="rp-restore-existing-submit" disabled><?php echo esc_html__('Yes, restore full backup', 'restorepilot-backup-migration'); ?></button>
                  </form>
                </div>
              </div>
            </div>
          <?php endif; ?>
        </div>

      </div>
      <?php endif; ?>
      </section>

      <section id="rp-panel-daily" class="rp-tab-panel <?php echo esc_attr($tab === 'daily' ? 'is-active' : ''); ?>">
      <div class="rp-stack">
      <?php if (is_multisite()): self::render_multisite_unsupported_notice(); else: ?>

        <div class="rp-card">
          <div class="rp-card__head">
            <div class="rp-card__head-icon" aria-hidden="true"><span class="dashicons dashicons-calendar-alt"></span></div>
            <h2><?php echo esc_html__('Daily Backup', 'restorepilot-backup-migration'); ?></h2>
          </div>
          <div class="rp-card__body">
            <p><?php echo esc_html__('Create one automatic backup every day using WP-Cron. Manual and daily backups share the same storage limit.', 'restorepilot-backup-migration'); ?></p>

            <div class="rp-info-block" style="margin-bottom:18px;">
              <strong><?php echo esc_html__('Free backup limit', 'restorepilot-backup-migration'); ?></strong>
              <?php /* translators: %d: maximum number of backups kept */ ?>
              <span><?php echo esc_html(sprintf(__('RestorePilot keeps the newest %d backups total across manual and daily backups. Older backups are deleted automatically.', 'restorepilot-backup-migration'), self::MAX_BACKUPS)); ?></span>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
              <?php wp_nonce_field(self::NONCE); ?>
              <input type="hidden" name="action" value="restorepilot_save_settings">
              <input type="hidden" name="redirect_tab" value="daily">

              <div class="rp-field">
                <label class="rp-toggle">
                  <input type="checkbox" name="scheduled_enabled" value="1" <?php checked(!empty($settings['scheduled_enabled'])); ?>>
                  <span>
                    <span class="rp-toggle__label"><?php echo esc_html__('Enable daily automatic backup', 'restorepilot-backup-migration'); ?></span>
                    <span class="rp-toggle__desc"><?php echo esc_html__('Runs once per day when WordPress cron is triggered by site traffic or server cron.', 'restorepilot-backup-migration'); ?></span>
                  </span>
                </label>
              </div>

              <div class="rp-field">
                <label class="rp-field__label"><?php echo esc_html__('Backup time', 'restorepilot-backup-migration'); ?></label>
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                  <select name="scheduled_hour" style="width:auto;">
                    <?php for ($h = 0; $h < 24; $h++): ?>
                      <option value="<?php echo esc_attr((string) $h); ?>" <?php selected((int) $settings['scheduled_hour'], $h); ?>>
                        <?php echo esc_html(sprintf('%02d', $h)); ?>
                      </option>
                    <?php endfor; ?>
                  </select>
                  <span style="font-weight:600;font-size:15px;line-height:1;">:</span>
                  <select name="scheduled_minute" style="width:auto;">
                    <?php foreach ([0, 15, 30, 45] as $m): ?>
                      <option value="<?php echo esc_attr((string) $m); ?>" <?php selected((int) $settings['scheduled_minute'], $m); ?>>
                        <?php echo esc_html(sprintf('%02d', $m)); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <span class="description" style="margin:0;">
                    <?php
                    $tz_name = function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC';
                    /* translators: %s: site timezone name */
                    echo esc_html(sprintf(__('Site timezone: %s', 'restorepilot-backup-migration'), $tz_name));
                    ?>
                  </span>
                </div>
                <span class="description"><?php echo esc_html__('The backup will start at this time each day. 02:00 is recommended (low traffic period).', 'restorepilot-backup-migration'); ?></span>
              </div>

              <div class="rp-field">
                <label class="rp-toggle">
                  <input type="checkbox" name="email_notifications" value="1" <?php checked(!empty($settings['email_notifications'])); ?>>
                  <span>
                    <span class="rp-toggle__label"><?php echo esc_html__('Email notifications', 'restorepilot-backup-migration'); ?></span>
                    <span class="rp-toggle__desc"><?php echo esc_html__('Receive an email after each daily backup succeeds, fails, or is skipped.', 'restorepilot-backup-migration'); ?></span>
                  </span>
                </label>
              </div>

              <div class="rp-field">
                <label class="rp-field__label" for="rp_notify_email"><?php echo esc_html__('Notification email', 'restorepilot-backup-migration'); ?></label>
                <input id="rp_notify_email" class="regular-text" type="email" name="notify_email"
                       value="<?php echo esc_attr($settings['notify_email']); ?>">
              </div>

              <div class="rp-info-block">
                <strong><?php echo esc_html__('Next scheduled run', 'restorepilot-backup-migration'); ?></strong>
                <span>
                  <?php
                  if ($next_daily_backup) {
                    echo esc_html(wp_date(
                      (string) get_option('date_format') . ' ' . (string) get_option('time_format'),
                      (int) $next_daily_backup
                    ));
                  } else {
                    echo esc_html__('Not scheduled. Enable daily backup and save settings.', 'restorepilot-backup-migration');
                  }
                  ?>
                </span>
              </div>

              <div style="margin-top:20px;">
                <button type="submit" class="button button-primary"><?php echo esc_html__('Save Daily Backup Settings', 'restorepilot-backup-migration'); ?></button>
              </div>
            </form>
          </div>
        </div>

        <?php self::render_existing_backups_card($backups, __('No backups yet. Daily and manual backups will appear here.', 'restorepilot-backup-migration')); ?>

      </div>
      <?php endif; ?>
      </section>

      <section id="rp-panel-restore" class="rp-tab-panel <?php echo esc_attr($tab === 'restore' ? 'is-active' : ''); ?>">
      <div class="rp-stack">
      <?php if (is_multisite()): self::render_multisite_unsupported_notice(); else: ?>

        <!-- Restore card -->
        <div class="rp-card">
          <div class="rp-card__head">
            <div class="rp-card__head-icon" aria-hidden="true"><span class="dashicons dashicons-upload"></span></div>
            <h2><?php echo esc_html__('Restore Full Backup', 'restorepilot-backup-migration'); ?></h2>
          </div>
          <div class="rp-card__body">
            <p><?php echo esc_html__('Upload the full RestorePilot backup zip downloaded from Existing Backups. The file is validated before any changes are made to this site.', 'restorepilot-backup-migration'); ?></p>

            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="rp-restore-form">
              <?php wp_nonce_field(self::NONCE); ?>
              <input type="hidden" name="action" id="rp_restore_action" value="restorepilot_restore">
              <input type="hidden" name="auto_detect_urls" value="1">
              <input type="hidden" name="restore_files" value="1">
              <input type="hidden" name="confirm_restore" value="1">
              <!-- Set from the pre-restore confirmation modal's own checkbox, which
                   lives outside this form in the DOM — see admin.js. -->
              <input type="hidden" name="create_new_admin" id="rp_create_new_admin_hidden" value="0">
              <!-- Copied from the modal the same way. Neither is a secret, so
                   both travel with the job; the chosen password deliberately
                   does not — see the modal's own comment. -->
              <input type="hidden" name="new_admin_email" id="rp_new_admin_email_hidden" value="">

              <div class="rp-upload-box">
                <span class="dashicons dashicons-cloud-upload" aria-hidden="true"></span>
                <label for="rp_file"><?php echo esc_html__('Choose full backup zip', 'restorepilot-backup-migration'); ?></label>
                <input id="rp_file" type="file" name="backup_upload[]" multiple
                       data-max-upload-size="<?php echo esc_attr((string) wp_max_upload_size()); ?>">
              </div>

              <div class="rp-restore-actions is-waiting">
                <button type="submit" class="button rp-restore-check rp-button-with-icon" data-rp-action="restorepilot_check_restore" disabled>
                  <span class="dashicons dashicons-search" aria-hidden="true"></span>
                  <span class="rp-button-label"><?php echo esc_html__('Check Backup', 'restorepilot-backup-migration'); ?></span>
                </button>
                <button type="submit" class="button button-primary rp-restore-submit rp-button-with-icon" data-rp-action="restorepilot_restore" disabled>
                  <span class="dashicons dashicons-upload" aria-hidden="true"></span>
                  <span class="rp-button-label"><?php echo esc_html__('Restore Full Backup', 'restorepilot-backup-migration'); ?></span>
                </button>
                <span class="description rp-restore-ready-hint"><?php echo esc_html__('Choose a backup zip or enter a server backup path to continue.', 'restorepilot-backup-migration'); ?></span>
              </div>

              <div class="rp-progress" id="rp-restore-progress" aria-live="polite">
                <div class="rp-progress__wrap">
                  <div class="rp-progress__header">
                    <span class="rp-progress__label" id="rp-restore-progress-label"><?php echo esc_html__('Uploading…', 'restorepilot-backup-migration'); ?></span>
                    <span class="rp-progress__pct" id="rp-restore-progress-pct">0%</span>
                  </div>
                  <div class="rp-progress__track">
                    <div class="rp-progress__bar" id="rp-restore-progress-bar"></div>
                  </div>
                  <div class="rp-progress__text" id="rp-restore-progress-text">
                    <?php echo esc_html__('Preparing restore…', 'restorepilot-backup-migration'); ?>
                  </div>
                </div>
              </div>

              <?php
              // There is deliberately no panel here for showing a login. The
              // plugin no longer generates a credential, so it never has one
              // to hand back: the operator sets the email and password
              // themselves, and the page can simply carry on to the restore
              // tab when it is done. Every problem that panel used to cause —
              // a password shown once and missed, a page that could not
              // redirect without destroying it, a live credential sitting on
              // a screen people photograph — went away with the thing that
              // made it necessary.
              ?>

              <div class="rp-advanced rp-disclosure">
                <button type="button" class="rp-disclosure__summary" aria-expanded="false">
                  <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                  <?php echo esc_html__('Advanced restore settings', 'restorepilot-backup-migration'); ?>
                </button>
                <div class="rp-disclosure__panel">
                  <div class="rp-disclosure__panel-inner">
                    <div class="rp-advanced-panel">

                      <div class="rp-advanced-panel__section">
                        <label class="rp-toggle">
                          <input id="rp_auto_urls" type="checkbox" checked>
                          <span>
                            <span class="rp-toggle__label"><?php echo esc_html__('Auto-detect source and target URLs', 'restorepilot-backup-migration'); ?></span>
                            <span class="rp-toggle__desc"><?php echo esc_html__('Reads the original URL from the backup manifest and replaces it with this site\'s URL.', 'restorepilot-backup-migration'); ?></span>
                          </span>
                        </label>
                        <div id="rp_manual_urls" style="display:none;gap:12px;margin-top:12px;padding-top:12px;border-top:1px solid #dcdcde;">
                          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div class="rp-field">
                              <label class="rp-field__label" for="rp_source"><?php echo esc_html__('Source URL', 'restorepilot-backup-migration'); ?></label>
                              <input id="rp_source" class="regular-text" type="url" name="source_url" placeholder="https://old-domain.com">
                            </div>
                            <div class="rp-field">
                              <label class="rp-field__label" for="rp_target"><?php echo esc_html__('Target URL', 'restorepilot-backup-migration'); ?></label>
                              <input id="rp_target" class="regular-text" type="url" name="target_url" value="<?php echo esc_attr(home_url()); ?>">
                            </div>
                          </div>
                        </div>
                      </div>

                      <div class="rp-advanced-panel__section">
                        <label class="rp-field__label" for="rp_server_backup_path"><?php echo esc_html__('Server backup path', 'restorepilot-backup-migration'); ?></label>
                        <input id="rp_server_backup_path" class="regular-text" type="text" name="server_backup_path" placeholder="wp-content/uploads/backup.zip">
                        <span class="description"><?php echo esc_html__('Only needed if the full backup zip is already sitting inside this site\'s uploads directory (for example, placed there via your host\'s file manager) — this skips uploading it again.', 'restorepilot-backup-migration'); ?></span>
                      </div>

                    </div>
                  </div>
                </div>
              </div>
            </form>
          </div>
        </div>

        <?php
        $rp_rollback_points = self::list_restore_rollback_points();
        if (!empty($rp_rollback_points)):
        ?>
        <div class="rp-card" id="rp-rollback-card">
          <div class="rp-card__head">
            <div class="rp-card__head-icon" aria-hidden="true"><span class="dashicons dashicons-backup"></span></div>
            <h2><?php echo esc_html__('Pre-Restore Rollback Points', 'restorepilot-backup-migration'); ?></h2>
          </div>
          <div class="rp-card__retention-notice">
            <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
            <?php echo esc_html__('Snapshots taken automatically before each restore. Use one if a restore left the site in a broken state. Database only — files are not included.', 'restorepilot-backup-migration'); ?>
          </div>
          <table class="rp-backup-table rp-backup-table--plain rp-backup-table--2col">
            <thead>
              <tr>
                <th><?php echo esc_html__('Created', 'restorepilot-backup-migration'); ?></th>
                <th><?php echo esc_html__('Actions', 'restorepilot-backup-migration'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rp_rollback_points as $rp_point): ?>
              <tr>
                <td class="rp-backup-date" data-rp-label="<?php echo esc_attr__('Created', 'restorepilot-backup-migration'); ?>">
                  <strong><?php echo esc_html(wp_date((string) get_option('date_format'), $rp_point['modified'])); ?></strong>
                  <span><?php echo esc_html(wp_date((string) get_option('time_format'), $rp_point['modified'])); ?> &mdash; <?php echo esc_html(size_format($rp_point['size'])); ?></span>
                  <span class="rp-backup-badges">
                    <span class="rp-badge rp-badge--type"><?php echo esc_html__('DB only', 'restorepilot-backup-migration'); ?></span>
                    <span class="rp-badge rp-badge--scheduled"><?php echo esc_html__('Auto', 'restorepilot-backup-migration'); ?></span>
                  </span>
                </td>
                <td class="rp-backup-actions" data-rp-label="<?php echo esc_attr__('Actions', 'restorepilot-backup-migration'); ?>">
                  <button type="button" class="button rp-btn-restore rp-rollback-restore-btn"
                    data-rp-rollback-path="<?php echo esc_attr($rp_point['path']); ?>">
                    <?php echo esc_html__('Restore from this point', 'restorepilot-backup-migration'); ?>
                  </button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

        <!-- Pre-restore confirmation modal -->
        <div class="rp-modal-backdrop" id="rp-restore-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="rp-restore-confirm-title">
          <div class="rp-modal">
            <div class="rp-modal__head">
              <div class="rp-modal__head-icon" aria-hidden="true">
                <span class="dashicons dashicons-warning"></span>
              </div>
              <h2 id="rp-restore-confirm-title"><?php echo esc_html__('Before Restore Starts', 'restorepilot-backup-migration'); ?></h2>
            </div>
            <div class="rp-modal__body">
              <div class="rp-modal__warning">
                <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                <?php echo esc_html__('This will permanently overwrite this site\'s live database and wp-content files. There is no automatic undo.', 'restorepilot-backup-migration'); ?>
              </div>
              <div class="rp-modal__panel">
                <strong><?php echo esc_html__('What happens next', 'restorepilot-backup-migration'); ?></strong>
                <ul>
                  <li><?php echo esc_html__('The backup is validated — nothing changes until validation passes.', 'restorepilot-backup-migration'); ?></li>
                  <li><?php echo esc_html__('A rollback snapshot of the current database is saved before any tables are replaced.', 'restorepilot-backup-migration'); ?></li>
                  <li><?php echo esc_html__('The database is fully replaced with the backup. wp-content files from the backup overwrite matching files here — files that exist on this site but are not in the backup are not removed.', 'restorepilot-backup-migration'); ?></li>
                  <li><?php echo esc_html__('You will be asked to log in again — this is expected.', 'restorepilot-backup-migration'); ?></li>
                </ul>
              </div>
              <div class="rp-modal__panel rp-modal__panel--warn">
                <strong><?php echo esc_html__('⚠ If restore fails or the site becomes unreachable', 'restorepilot-backup-migration'); ?></strong>
                <ul>
                  <li><?php echo esc_html__('RestorePilot removes maintenance mode automatically — but a partial restore may leave the database in a mixed state.', 'restorepilot-backup-migration'); ?></li>
                  <li><?php echo esc_html__('If you cannot log in after a failed restore, use the Pre-Restore Rollback Point to recover: go to Restore → Pre-Restore Rollback Points.', 'restorepilot-backup-migration'); ?></li>
                  <li><strong><?php echo esc_html__('Recommended: download a fresh backup and keep it somewhere safe before proceeding.', 'restorepilot-backup-migration'); ?></strong></li>
                </ul>
              </div>
              <div class="rp-modal__panel">
                <label class="rp-toggle">
                  <input type="checkbox" id="rp-restore-confirm-new-admin">
                  <span>
                    <span class="rp-toggle__label"><?php echo esc_html__('Create a new admin login for this site', 'restorepilot-backup-migration'); ?></span>
                    <span class="rp-toggle__desc"><?php echo esc_html__('Useful when restoring a backup from a different domain and you don\'t have (or don\'t want to reuse) that site\'s admin password. Adds a new administrator account, with an email and password you choose, instead of changing any existing one.', 'restorepilot-backup-migration'); ?></span>
                  </span>
                </label>

                <div class="rp-new-admin-fields" id="rp-new-admin-fields" hidden>
                  <p class="rp-new-admin-fields__intro">
                    <?php echo esc_html__('You will sign in with this email address and password.', 'restorepilot-backup-migration'); ?>
                  </p>

                  <?php
                  // Both fields are required, and nothing is generated on the
                  // operator's behalf. A generated credential is one the
                  // plugin has to hand back, which means displaying it, which
                  // means it can be missed, screenshotted, or lost by
                  // navigating away — every one of which has to be designed
                  // around. A credential the operator already knows removes
                  // that whole problem rather than managing it.
                  //
                  // The email travels with the restore: it is not a secret,
                  // and having it on the job means the account is created even
                  // if this browser never comes back, leaving WordPress's own
                  // password reset as a way in. The password does not travel
                  // — it stays here and is applied in one call once the
                  // restore is done, so it is never written to the job record,
                  // which is mirrored to a file under uploads to survive the
                  // database swap.
                  ?>
                  <p class="rp-field">
                    <label for="rp-new-admin-email-input"><?php echo esc_html__('Email address', 'restorepilot-backup-migration'); ?></label>
                    <input type="email" id="rp-new-admin-email-input" autocomplete="off" spellcheck="false" required
                      placeholder="you@example.com">
                    <span class="rp-field__hint"><?php echo esc_html__('Use an address you can actually receive mail at — it is how you recover this account if anything goes wrong.', 'restorepilot-backup-migration'); ?></span>
                  </p>

                  <p class="rp-field">
                    <label for="rp-new-admin-password-input"><?php echo esc_html__('Password', 'restorepilot-backup-migration'); ?></label>
                    <input type="password" id="rp-new-admin-password-input" autocomplete="new-password" spellcheck="false" required
                      placeholder="<?php echo esc_attr__('At least 8 characters', 'restorepilot-backup-migration'); ?>">
                    <span class="rp-field__hint"><?php echo esc_html__('Never stored on the server during the restore — it is applied from this page once the restore finishes.', 'restorepilot-backup-migration'); ?></span>
                  </p>

                  <div class="rp-field__error" id="rp-new-admin-error" hidden></div>
                </div>
              </div>
              <label class="rp-modal__confirm-check">
                <input type="checkbox" id="rp-restore-confirm-check">
                <?php echo esc_html__('I have a backup and understand this will overwrite my live database and matching wp-content files, and cannot be undone automatically.', 'restorepilot-backup-migration'); ?>
              </label>
            </div>
            <div class="rp-modal__foot">
              <button type="button" class="button" id="rp-restore-confirm-cancel"><?php echo esc_html__('Go back', 'restorepilot-backup-migration'); ?></button>
              <button type="button" class="button button-primary" id="rp-restore-confirm-continue" disabled><?php echo esc_html__('Yes, start restore', 'restorepilot-backup-migration'); ?></button>
            </div>
          </div>
        </div>

        <?php if (strpos($notice, 'Restore completed') !== false): ?>
          <div class="rp-card">
            <div class="rp-card__head">
              <div class="rp-card__head-icon" aria-hidden="true"><span class="dashicons dashicons-yes-alt"></span></div>
              <h2><?php echo esc_html__('Post-Restore Checklist', 'restorepilot-backup-migration'); ?></h2>
            </div>
            <div class="rp-card__body">
              <ul class="rp-checklist">
                <li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php echo esc_html__('Visit the front of the site and check important pages.', 'restorepilot-backup-migration'); ?></li>
                <li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php echo esc_html__('Log in again if WordPress prompts you.', 'restorepilot-backup-migration'); ?></li>
                <li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php echo esc_html__('Reconnect SMTP, SEO, cache, or license-based plugins if they show notices.', 'restorepilot-backup-migration'); ?></li>
              </ul>
            </div>
          </div>
        <?php endif; ?>

      </div>
      <?php endif; ?>
      </section>

      <section id="rp-panel-settings" class="rp-tab-panel <?php echo esc_attr($tab === 'settings' ? 'is-active' : ''); ?>">
      <?php $rp_backup_tab_url = esc_url(add_query_arg('tab', 'backup', self::admin_url())); ?>
      <div class="rp-settings-layout">

        <!-- Main column: Diagnostics & Maintenance -->
        <div class="rp-settings-col rp-settings-col--main">
          <div class="rp-card">
            <div class="rp-card__head">
              <div class="rp-card__head-icon" aria-hidden="true"><span class="dashicons dashicons-admin-tools"></span></div>
              <h2><?php echo esc_html__('Diagnostics & Maintenance', 'restorepilot-backup-migration'); ?></h2>
            </div>
            <div class="rp-card__body">
              <div class="rp-status-list rp-status-list--grid rp-diagnostics-list">
                <?php foreach (self::diagnostic_status_items($backups) as $item):
                  $s = isset($item['status']) ? $item['status'] : 'info';
                  $icon = $s === 'ok' ? 'yes' : ($s === 'error' ? 'no' : ($s === 'warn' ? 'warning' : 'minus'));
                ?>
                  <div class="rp-status rp-status--<?php echo esc_attr($s); ?>">
                    <div class="rp-status__dot" aria-hidden="true">
                      <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span>
                    </div>
                    <div class="rp-status__body">
                      <div class="rp-status__name"><?php echo esc_html($item['label']); ?></div>
                      <div class="rp-status__value"><?php echo esc_html($item['value']); ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>

              <hr class="rp-divider">

              <div class="rp-info-block">
                <strong><?php echo esc_html__('Safe maintenance tools', 'restorepilot-backup-migration'); ?></strong>
                <span><?php echo esc_html__('These tools do not delete completed backup zips. They only clean stale temporary files or reset stuck runtime state.', 'restorepilot-backup-migration'); ?></span>
              </div>
              <div class="rp-maintenance-actions">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                  <?php wp_nonce_field(self::NONCE); ?>
                  <input type="hidden" name="action" value="restorepilot_cleanup_temp">
                  <button type="submit" class="button rp-button-with-icon">
                    <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                    <span class="rp-button-label"><?php echo esc_html__('Clean Stale Temp Files', 'restorepilot-backup-migration'); ?></span>
                  </button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                  <?php wp_nonce_field(self::NONCE); ?>
                  <input type="hidden" name="action" value="restorepilot_reset_runtime">
                  <button type="submit" class="button rp-button-with-icon" data-confirm="<?php echo esc_attr(__('Reset stuck RestorePilot locks and remove maintenance mode? Do this only if no backup or restore is currently running.', 'restorepilot-backup-migration')); ?>">
                    <span class="dashicons dashicons-update" aria-hidden="true"></span>
                    <span class="rp-button-label"><?php echo esc_html__('Reset Stuck Runtime', 'restorepilot-backup-migration'); ?></span>
                  </button>
                </form>
                <a class="button rp-button-with-icon" href="<?php echo esc_url(self::admin_action_url('restorepilot_download_log')); ?>">
                  <span class="dashicons dashicons-download" aria-hidden="true"></span>
                  <span class="rp-button-label"><?php echo esc_html__('Download Logs', 'restorepilot-backup-migration'); ?></span>
                </a>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                  <?php wp_nonce_field(self::NONCE); ?>
                  <input type="hidden" name="action" value="restorepilot_clear_log_post">
                  <button type="submit" class="button rp-button-with-icon" data-confirm="<?php echo esc_attr(__('Clear RestorePilot logs?', 'restorepilot-backup-migration')); ?>">
                    <span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
                    <span class="rp-button-label"><?php echo esc_html__('Clear Logs', 'restorepilot-backup-migration'); ?></span>
                  </button>
                </form>
              </div>
            </div>
          </div>
        </div>

        <!-- Side column: System Readiness, Status, Danger Zone -->
        <div class="rp-settings-col rp-settings-col--side">

          <!-- System readiness card -->
          <div class="rp-card">
            <div class="rp-card__head">
              <div class="rp-card__head-icon" aria-hidden="true"><span class="dashicons dashicons-dashboard"></span></div>
              <h2><?php echo esc_html__('System Readiness', 'restorepilot-backup-migration'); ?></h2>
            </div>
            <div class="rp-card__body">
              <div class="rp-status-list rp-status-list--grid">
                <?php foreach (self::system_status_items() as $item):
                  $s = isset($item['status']) ? $item['status'] : 'info';
                  $icon = $s === 'ok' ? 'yes' : ($s === 'error' ? 'no' : ($s === 'warn' ? 'warning' : 'minus'));
                ?>
                  <div class="rp-status rp-status--<?php echo esc_attr($s); ?>">
                    <div class="rp-status__dot" aria-hidden="true">
                      <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span>
                    </div>
                    <div class="rp-status__body">
                      <div class="rp-status__name"><?php echo esc_html($item['label']); ?></div>
                      <div class="rp-status__value"><?php echo esc_html($item['value']); ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <!-- Status card -->
          <div class="rp-card">
            <div class="rp-card__head">
              <div class="rp-card__head-icon" aria-hidden="true"><span class="dashicons dashicons-admin-settings"></span></div>
              <h2><?php echo esc_html__('Status', 'restorepilot-backup-migration'); ?></h2>
            </div>
            <div class="rp-card__body rp-card__body--compact">
              <div class="rp-info-block">
                <strong><?php echo esc_html__('Backup limit', 'restorepilot-backup-migration'); ?></strong>
                <?php /* translators: %d: maximum number of backups kept */ ?>
                <span><?php echo esc_html(sprintf(__('RestorePilot keeps the newest %d backups total in this free version. Manual and daily backups share this limit.', 'restorepilot-backup-migration'), self::MAX_BACKUPS)); ?></span>
              </div>
              <div class="rp-info-block">
                <strong><?php echo esc_html__('Daily backups', 'restorepilot-backup-migration'); ?></strong>
                <span><?php echo esc_html__('Use the Daily Backup tab to enable automatic daily backups and email notifications.', 'restorepilot-backup-migration'); ?></span>
              </div>
              <div class="rp-info-block">
                <strong><?php echo esc_html__('Cleanup on uninstall', 'restorepilot-backup-migration'); ?></strong>
                <span><?php echo esc_html__('Deleting the plugin removes all RestorePilot backups, logs, temporary files, options, and scheduled events.', 'restorepilot-backup-migration'); ?></span>
              </div>
            </div>
          </div>

          <!-- Master Reset danger zone -->
          <div class="rp-card rp-card--danger rp-danger-zone">
            <div class="rp-card__head rp-card__head--danger">
              <div class="rp-card__head-icon rp-card__head-icon--danger" aria-hidden="true"><span class="dashicons dashicons-warning"></span></div>
              <h2><?php echo esc_html__('Danger Zone — Master Reset', 'restorepilot-backup-migration'); ?></h2>
            </div>
            <div class="rp-card__body rp-danger-body">
              <div class="rp-danger-body__text">
                <p class="rp-danger-intro"><?php echo esc_html__('Permanently wipe this site back to a clean WordPress installation. Everything listed below is deleted and cannot be recovered.', 'restorepilot-backup-migration'); ?></p>
                <ul class="rp-danger-list">
                  <li><?php echo esc_html__('All posts, pages, custom post types, and taxonomy terms', 'restorepilot-backup-migration'); ?></li>
                  <li><?php echo esc_html__('All uploaded media files (wp-content/uploads) — except RestorePilot\'s own stored backups, rollback points, and log, which this reset keeps', 'restorepilot-backup-migration'); ?></li>
                  <li><?php echo esc_html__('All plugins except RestorePilot', 'restorepilot-backup-migration'); ?></li>
                  <li><?php echo esc_html__('All themes except Twenty Twenty-Five', 'restorepilot-backup-migration'); ?></li>
                  <li><?php echo esc_html__('All user accounts except the current administrator', 'restorepilot-backup-migration'); ?></li>
                  <li><?php echo esc_html__('All plugin settings and site customisations', 'restorepilot-backup-migration'); ?></li>
                </ul>
              </div>
              <div class="rp-danger-body__action">
                <?php if (is_multisite()): ?>
                  <span class="rp-danger-hint"><?php echo esc_html__('Master Reset is not available on multisite networks, because plugins and themes are shared across all sites.', 'restorepilot-backup-migration'); ?></span>
                <?php else: ?>
                  <button type="button" id="rp-master-reset-open" class="button rp-button-danger rp-button-with-icon">
                    <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                    <span class="rp-button-label"><?php echo esc_html__('Master Reset…', 'restorepilot-backup-migration'); ?></span>
                  </button>
                  <span class="rp-danger-hint"><?php echo esc_html__('You will be asked to confirm.', 'restorepilot-backup-migration'); ?></span>
                <?php endif; ?>
              </div>
            </div>
          </div>

        </div>
      </div>

      <!-- Master Reset confirmation modal -->
      <div id="rp-master-reset-modal" class="rp-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="rp-master-reset-title">
        <div class="rp-modal rp-modal--danger">
          <div class="rp-modal__head">
            <div class="rp-modal__head-icon rp-modal__head-icon--danger" aria-hidden="true">
              <span class="dashicons dashicons-warning"></span>
            </div>
            <h2 id="rp-master-reset-title"><?php echo esc_html__('Master Reset — this cannot be undone', 'restorepilot-backup-migration'); ?></h2>
          </div>
          <div class="rp-modal__body">
            <div class="rp-modal__backup-prompt">
              <span class="dashicons dashicons-download" aria-hidden="true"></span>
              <div class="rp-modal__backup-prompt-text">
                <strong><?php echo esc_html__('Create and download a full backup first', 'restorepilot-backup-migration'); ?></strong>
                <span><?php echo esc_html__('A backup is the only way to recover this site after a reset. Download one now if you have not already.', 'restorepilot-backup-migration'); ?></span>
                <a class="button button-primary rp-modal__backup-btn" href="<?php echo esc_url($rp_backup_tab_url); ?>">
                  <span class="dashicons dashicons-backup" aria-hidden="true"></span>
                  <?php echo esc_html__('Go to Backup tab', 'restorepilot-backup-migration'); ?>
                </a>
              </div>
            </div>
            <label class="rp-modal__ack">
              <input type="checkbox" id="rp-master-reset-ack">
              <span><?php echo esc_html__('I have a full backup, or I understand this site cannot be recovered.', 'restorepilot-backup-migration'); ?></span>
            </label>
            <div class="rp-modal__warning">
              <span class="dashicons dashicons-warning" aria-hidden="true"></span>
              <span><?php echo esc_html__('You are about to permanently delete all site content, uploads, plugins, themes, and user accounts. Only the current administrator account will be kept. This action cannot be reversed.', 'restorepilot-backup-migration'); ?></span>
            </div>
            <p class="rp-modal__confirm-prompt"><?php echo esc_html__('Type RESET in the box below to confirm:', 'restorepilot-backup-migration'); ?></p>
            <input type="text" id="rp-master-reset-confirm-input" class="regular-text rp-master-reset-input" placeholder="RESET" autocomplete="off" autocorrect="off" spellcheck="false">
            <div id="rp-master-reset-error" class="rp-master-reset-error" style="display:none;"></div>
          </div>
          <div class="rp-modal__foot">
            <button type="button" id="rp-master-reset-cancel" class="button"><?php echo esc_html__('Cancel', 'restorepilot-backup-migration'); ?></button>
            <button type="button" id="rp-master-reset-confirm" class="button rp-button-danger" disabled>
              <span class="dashicons dashicons-warning" aria-hidden="true"></span>
              <?php echo esc_html__('Reset Everything', 'restorepilot-backup-migration'); ?>
            </button>
          </div>
        </div>
      </div>

      </section>
      <section id="rp-panel-logs" class="rp-tab-panel <?php echo esc_attr($tab === 'logs' ? 'is-active' : ''); ?>">
        <?php self::render_logs_tab(); ?>
      </section>
      </div>
    </div>
    <?php
  }

  /**
   * $volume_count > 1 means this backup was split (see BACKUP_VOLUME_BYTES) —
   * serve_download() only ever streams the ONE file it is given, so a "Download
   * Full Backup" link built from the base filename alone would silently
   * deliver just the first volume of a large site's backup. list_backups()
   * already computes the true volume count for exactly this reason; every
   * caller must pass it through rather than defaulting to 1.
   */
  private static function render_backup_download_controls(string $backup_name, int $backup_size = 0, int $volume_count = 1): void {
    $backup_name = sanitize_file_name($backup_name);
    $backup_size_label = $backup_size > 0 ? size_format($backup_size) : '';
    $dl_full = self::action_url('restorepilot_download', $backup_name);
    $dl_db   = self::admin_action_url('restorepilot_download_part', ['file' => $backup_name, 'part' => 'database']);
    $dl_plg  = self::admin_action_url('restorepilot_download_part', ['file' => $backup_name, 'part' => 'plugins']);
    $dl_thm  = self::admin_action_url('restorepilot_download_part', ['file' => $backup_name, 'part' => 'themes']);
    $dl_upl  = self::admin_action_url('restorepilot_download_part', ['file' => $backup_name, 'part' => 'uploads']);
    $dl_mu   = self::admin_action_url('restorepilot_download_part', ['file' => $backup_name, 'part' => 'mu-plugins']);
    $dl_oth  = self::admin_action_url('restorepilot_download_part', ['file' => $backup_name, 'part' => 'others']);
    ?>
    <div class="rp-backup-primary">
      <a class="button button-primary rp-button-with-icon" href="<?php echo esc_url($dl_full); ?>">
        <span class="dashicons dashicons-download" aria-hidden="true"></span>
        <span class="rp-button-label"><?php echo esc_html__('Download Full Backup', 'restorepilot-backup-migration'); ?></span>
        <?php if ($backup_size_label !== ''): ?>
          <span class="rp-button-size"><?php echo esc_html($backup_size_label); ?></span>
        <?php endif; ?>
      </a>
      <span class="rp-full-backup-hint"><?php echo esc_html__('Use this zip to restore or migrate the full site.', 'restorepilot-backup-migration'); ?></span>

      <?php if ($volume_count > 1):
        $volume_paths = self::volume_paths_for(self::backup_dir() . '/' . $backup_name);
      ?>
      <div class="rp-advanced-downloads rp-disclosure">
        <button type="button" class="rp-disclosure__summary" aria-expanded="false">
          <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
          <?php echo esc_html__('Download volumes individually', 'restorepilot-backup-migration'); ?>
        </button>
        <div class="rp-disclosure__panel">
          <div class="rp-disclosure__panel-inner">
            <p class="rp-part-note"><?php echo esc_html(sprintf(
              /* translators: %d: number of volumes this backup is split into */
              __('This backup is stored as %d files behind the scenes. Download Full Backup above reassembles them into one file automatically — use these only if that download fails partway and you need to retry a single piece.', 'restorepilot-backup-migration'),
              $volume_count
            )); ?></p>
            <div class="rp-part-list">
              <?php foreach ($volume_paths as $i => $volume_path):
                $volume_name = basename($volume_path);
                $volume_size = @filesize($volume_path);
                $dl_volume = self::action_url('restorepilot_download_stream', $volume_name);
              ?>
                <a class="rp-part-btn" href="<?php echo esc_url($dl_volume); ?>"><?php echo esc_html(sprintf(
                  /* translators: 1: this volume's position, 2: total number of volumes */
                  __('Volume %1$d of %2$d', 'restorepilot-backup-migration'),
                  $i + 1,
                  $volume_count
                )); ?><?php if ($volume_size !== false && $volume_size > 0): ?> (<?php echo esc_html(size_format((int) $volume_size)); ?>)<?php endif; ?></a>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div class="rp-advanced-downloads rp-disclosure">
        <button type="button" class="rp-disclosure__summary" aria-expanded="false">
          <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
          <?php echo esc_html__('Advanced downloads', 'restorepilot-backup-migration'); ?>
        </button>
        <div class="rp-disclosure__panel">
          <div class="rp-disclosure__panel-inner">
            <p class="rp-part-note"><?php echo esc_html__('Individual archives are for manual recovery only. Use Download Full Backup for restore or migration.', 'restorepilot-backup-migration'); ?></p>
            <div class="rp-part-list">
              <a class="rp-part-btn" href="<?php echo esc_url($dl_db); ?>"><?php echo esc_html__('Database only', 'restorepilot-backup-migration'); ?></a>
              <a class="rp-part-btn" href="<?php echo esc_url($dl_plg); ?>"><?php echo esc_html__('Plugins only', 'restorepilot-backup-migration'); ?></a>
              <a class="rp-part-btn" href="<?php echo esc_url($dl_thm); ?>"><?php echo esc_html__('Themes only', 'restorepilot-backup-migration'); ?></a>
              <a class="rp-part-btn" href="<?php echo esc_url($dl_upl); ?>"><?php echo esc_html__('Uploads only', 'restorepilot-backup-migration'); ?></a>
              <a class="rp-part-btn" href="<?php echo esc_url($dl_mu); ?>"><?php echo esc_html__('Must-use plugins', 'restorepilot-backup-migration'); ?></a>
              <a class="rp-part-btn" href="<?php echo esc_url($dl_oth); ?>"><?php echo esc_html__('Other wp-content files', 'restorepilot-backup-migration'); ?></a>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php
  }

  private static function render_multisite_unsupported_notice(): void {
    ?>
    <div class="rp-card">
      <div class="rp-empty-state">
        <span class="dashicons dashicons-warning" aria-hidden="true"></span>
        <p><?php echo esc_html(self::multisite_unsupported_message()); ?></p>
      </div>
    </div>
    <?php
  }

  private static function render_existing_backups_card(array $backups, string $empty_message): void {
    ?>
    <div class="rp-card">
      <div class="rp-card__head">
        <div class="rp-card__head-icon" aria-hidden="true"><span class="dashicons dashicons-media-archive"></span></div>
        <h2><?php echo esc_html__('Existing Backups', 'restorepilot-backup-migration'); ?></h2>
      </div>
      <div class="rp-card__retention-notice">
        <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
        <?php echo esc_html(sprintf(
          /* translators: %1$s: sentence about the retention limit, %2$s: sentence about how many backups are stored now */
          __('%1$s %2$s Download backups you want to keep — older ones are removed automatically.', 'restorepilot-backup-migration'),
          sprintf(
            /* translators: %d: maximum number of backups kept */
            _n(
              'Free version: keeps the newest %d backup total.',
              'Free version: keeps the newest %d backups total.',
              self::MAX_BACKUPS,
              'restorepilot-backup-migration'
            ),
            self::MAX_BACKUPS
          ),
          sprintf(
            /* translators: %d: number of backups currently stored */
            _n(
              'You currently have %d backup stored.',
              'You currently have %d backups stored.',
              count($backups),
              'restorepilot-backup-migration'
            ),
            count($backups)
          )
        )); ?>
      </div>
      <?php if (!$backups): ?>
        <div class="rp-empty-state">
          <span class="dashicons dashicons-media-archive" aria-hidden="true"></span>
          <p><?php echo esc_html($empty_message); ?></p>
        </div>
      <?php else: ?>
        <table class="rp-backup-table rp-backup-table--plain">
          <thead>
            <tr>
              <th><?php echo esc_html__('Backup date', 'restorepilot-backup-migration'); ?></th>
              <th><?php echo esc_html__('Backup file', 'restorepilot-backup-migration'); ?></th>
              <th><?php echo esc_html__('Actions', 'restorepilot-backup-migration'); ?></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($backups as $backup):
            $backup_abs_path = self::backup_dir() . '/' . $backup['name'];
          ?>
            <tr>
              <td class="rp-backup-date" data-rp-label="<?php echo esc_attr__('Backup date', 'restorepilot-backup-migration'); ?>">
                <strong><?php echo esc_html(wp_date((string) get_option('date_format'), (int) $backup['modified'])); ?></strong>
                <span><?php echo esc_html(wp_date((string) get_option('time_format'), (int) $backup['modified'])); ?> &mdash; <?php echo esc_html(size_format((int) $backup['size'])); ?></span>
                <?php
                $rp_triggered = isset($backup['triggered_by']) ? (string) $backup['triggered_by'] : 'manual';
                $rp_type      = isset($backup['backup_type'])  ? (string) $backup['backup_type']  : 'full';
                $rp_trigger_label = $rp_triggered === 'scheduled'
                  ? __('Auto', 'restorepilot-backup-migration')
                  : __('Manual', 'restorepilot-backup-migration');
                $rp_type_label = $rp_type === 'database'
                  ? __('DB only', 'restorepilot-backup-migration')
                  : ($rp_type === 'selected-content'
                    ? __('Partial', 'restorepilot-backup-migration')
                    : __('Full', 'restorepilot-backup-migration'));
                ?>
                <span class="rp-backup-badges">
                  <span class="rp-badge rp-badge--<?php echo esc_attr($rp_triggered); ?>"><?php echo esc_html($rp_trigger_label); ?></span>
                  <span class="rp-badge rp-badge--type"><?php echo esc_html($rp_type_label); ?></span>
                </span>
              </td>
              <td class="rp-backup-parts" data-rp-label="<?php echo esc_attr__('Backup file', 'restorepilot-backup-migration'); ?>">
                <?php self::render_backup_download_controls($backup['name'], (int) $backup['size'], (int) ($backup['volumes'] ?? 1)); ?>
              </td>
              <td class="rp-backup-actions" data-rp-label="<?php echo esc_attr__('Actions', 'restorepilot-backup-migration'); ?>">
                <a class="button"
                   href="<?php echo esc_url(self::action_url('restorepilot_health', $backup['name'])); ?>">
                  <?php echo esc_html__('Check', 'restorepilot-backup-migration'); ?>
                </a>
                <button type="button"
                        class="button rp-btn-restore rp-restore-from-existing"
                        data-backup-name="<?php echo esc_attr($backup['name']); ?>"
                        data-backup-path="<?php echo esc_attr($backup_abs_path); ?>">
                  <?php echo esc_html__('Restore', 'restorepilot-backup-migration'); ?>
                </button>
                <a class="button rp-btn-danger"
                   href="<?php echo esc_url(self::action_url('restorepilot_delete', $backup['name'])); ?>"
                   data-confirm="<?php echo esc_attr(__('Delete this backup? This cannot be undone.', 'restorepilot-backup-migration')); ?>">
                  <?php echo esc_html__('Delete', 'restorepilot-backup-migration'); ?>
                </a>
                <a class="button" href="<?php echo esc_url(add_query_arg('tab', 'logs', self::admin_url())); ?>">
                  <?php echo esc_html__('View Log', 'restorepilot-backup-migration'); ?>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    <?php
  }

  private static function render_logs_tab(): void {
    $log = self::read_log_for_display();
    ?>
    <div style="margin-top:20px;">
      <div class="rp-log-card">
        <div class="rp-log-toolbar">
          <button type="button" class="button rp-button-with-icon" id="rp-refresh-log">
            <span class="dashicons dashicons-update" aria-hidden="true"></span>
            <span class="rp-button-label"><?php echo esc_html__('Refresh', 'restorepilot-backup-migration'); ?></span>
          </button>
          <a class="button rp-button-with-icon" href="<?php echo esc_url(self::admin_action_url('restorepilot_download_log')); ?>">
            <span class="dashicons dashicons-download" aria-hidden="true"></span>
            <span class="rp-button-label"><?php echo esc_html__('Download', 'restorepilot-backup-migration'); ?></span>
          </a>
          <button type="button" class="button" id="rp-clear-log"><?php echo esc_html__('Clear', 'restorepilot-backup-migration'); ?></button>

          <div class="rp-log-sep" aria-hidden="true"></div>

          <button type="button" class="button rp-log-filter is-active" data-rp-log-filter="all"><?php echo esc_html__('All', 'restorepilot-backup-migration'); ?></button>
          <button type="button" class="button rp-log-filter" data-rp-log-filter="error"><?php echo esc_html__('Errors', 'restorepilot-backup-migration'); ?></button>
          <button type="button" class="button rp-log-filter" data-rp-log-filter="backup"><?php echo esc_html__('Backups', 'restorepilot-backup-migration'); ?></button>
          <button type="button" class="button rp-log-filter" data-rp-log-filter="restore"><?php echo esc_html__('Restores', 'restorepilot-backup-migration'); ?></button>
        </div>
        <pre class="rp-log-output" id="rp-log-output"><?php echo esc_html($log !== '' ? $log : __('No log entries yet.', 'restorepilot-backup-migration')); ?></pre>
      </div>
    </div>
    <?php
  }

  public static function render_restore_success_notice(): void {
    if (!current_user_can('manage_options')) {
      return;
    }

    $notice = self::get_restore_success_notice();
    if (!$notice) {
      return;
    }
    ?>
    <div class="notice notice-success is-dismissible">
      <p><strong><?php echo esc_html__('Restore completed successfully.', 'restorepilot-backup-migration'); ?></strong></p>
      <p><?php echo esc_html__('You were asked to log in again because WordPress sessions can change after a database restore.', 'restorepilot-backup-migration'); ?></p>
    </div>
    <?php
  }

  public static function render_restore_success_dialog(): void {
    if (!current_user_can('manage_options')) {
      return;
    }

    $notice = self::get_restore_success_notice();
    if (!$notice) {
      return;
    }

    $source = isset($notice['source_url']) ? (string) $notice['source_url'] : '';
    $target = isset($notice['target_url']) ? (string) $notice['target_url'] : '';
    ?>
        <div class="rp-restore-dialog-backdrop" id="rp-restore-success-dialog" role="dialog" aria-modal="true" aria-labelledby="rp-restore-success-title">
      <div class="rp-restore-dialog">
        <h2 id="rp-restore-success-title"><?php echo esc_html__('Restore completed successfully', 'restorepilot-backup-migration'); ?></h2>
        <p><?php echo esc_html__('The site database and selected wp-content files were restored. Login was expected because WordPress may replace session data during restore.', 'restorepilot-backup-migration'); ?></p>
        <?php if ($source !== '' || $target !== ''): ?>
          <div class="rp-restore-dialog__meta">
            <?php if ($source !== ''): ?>
              <p><strong><?php echo esc_html__('Source:', 'restorepilot-backup-migration'); ?></strong> <?php echo esc_html($source); ?></p>
            <?php endif; ?>
            <?php if ($target !== ''): ?>
              <p><strong><?php echo esc_html__('This site:', 'restorepilot-backup-migration'); ?></strong> <?php echo esc_html($target); ?></p>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <ul>
          <li><?php echo esc_html__('Open the front of the site and check important pages.', 'restorepilot-backup-migration'); ?></li>
          <li><?php echo esc_html__('Reconnect SMTP, SEO, cache, or license-based plugins if they show notices.', 'restorepilot-backup-migration'); ?></li>
          <li><?php echo esc_html__('Use Logs if anything looks unexpected.', 'restorepilot-backup-migration'); ?></li>
        </ul>
        <div class="rp-restore-dialog__actions">
          <a class="button" href="<?php echo esc_url(home_url('/')); ?>" target="_blank" rel="noopener"><?php echo esc_html__('View Site', 'restorepilot-backup-migration'); ?></a>
          <a class="button button-primary" href="<?php echo esc_url(add_query_arg('tab', 'restore', self::admin_url())); ?>"><?php echo esc_html__('Open RestorePilot', 'restorepilot-backup-migration'); ?></a>
          <button type="button" class="button" id="rp-restore-success-close"><?php echo esc_html__('Close', 'restorepilot-backup-migration'); ?></button>
        </div>
      </div>
    </div>
    <?php
    delete_option(self::RESTORE_SUCCESS_OPTION);
    self::$restore_success_notice = [];
  }

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
      self::dispatch_backup_worker($job_id, $token);
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
        self::sweep_stale_restore_tables(self::wpdb()->prefix);
        self::journal_restore_scratch_tables(array_merge(
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
      self::clear_restore_table_journal();

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

      self::set_restore_job($job_id, [
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
      // Write poll_token to a file so it survives the DB restore replacing wp_options.
      self::write_poll_token_file($job_id, $poll_token);

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

    // Consumed before the password is applied, so a replayed or duplicated
    // request cannot reset the account again later.
    self::update_restore_job($job_id, ['new_admin_user_id' => 0]);

    $user = get_user_by('id', $user_id);
    if (!$user) {
      wp_send_json_error(['message' => __('The account this restore created could no longer be found.', 'restorepilot-backup-migration')], 410);
    }

    // Read raw: a password is not text to be sanitized, and running it
    // through a sanitizer would silently change what the operator typed.
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorized above via poll_token or nonce+capability.
    $password = isset($_POST['new_password']) ? (string) wp_unslash($_POST['new_password']) : '';
    if ($password === '' || strlen($password) < 8) {
      wp_send_json_error(['message' => __('Choose a password of at least 8 characters.', 'restorepilot-backup-migration')], 400);
    }

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

    $resumption = (int) ($job['checkpoint']['resumption'] ?? 0);

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
      self::dispatch_restore_worker($job_id, $token);
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
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $keep_placeholders is a generated list of %s placeholders; the table name and all values are bound below.
    $wpdb->query($wpdb->prepare(
      "DELETE FROM %i WHERE option_name NOT IN ({$keep_placeholders})",
      array_merge([$wpdb->options], $keep_options)
    ));
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
    update_option('active_plugins', [plugin_basename(__FILE__)]);
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
      if (!self::master_reset_wipe_dir($upload['basedir'], self::content_dir())) {
        $reset_problems[] = 'one or more files in the uploads directory could not be removed';
      }
    } else {
      $reset_problems[] = 'could not determine the uploads directory, so it was not cleared';
    }

    // 5. Delete all plugins except RestorePilot
    $own_dir = realpath(dirname(__FILE__));
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
    if (!is_array($active_after) || !in_array(plugin_basename(__FILE__), $active_after, true)) {
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

    self::write_log('Master Reset complete. Site reset to clean WordPress state.');

    wp_send_json_success([
      'message'  => __('Master Reset complete. Your site has been reset to a clean WordPress installation.', 'restorepilot-backup-migration'),
      'redirect' => admin_url(),
    ]);
  }

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
        self::clear_restore_table_journal();
      }
    }
  }

  private static function backup_check_message(string $file, bool $for_restore): string {
    if (!is_file($file) || !is_readable($file)) {
      throw new RuntimeException(__('Backup file not found.', 'restorepilot-backup-migration'));
    }

    $zip = self::open_backup_archive($file);

    try {
      $validated = self::validate_backup_zip($zip, false);
      $manifest = $validated['manifest'];
      $readiness = self::backup_restore_readiness($manifest, (int) $validated['file_count']);
      $needed = self::estimate_restore_file_bytes($zip);
      $free = is_dir(self::content_dir()) ? @disk_free_space(self::content_dir()) : false;
    } finally {
      $zip->close();
    }

    $source_url = isset($manifest['home_url']) ? (string) $manifest['home_url'] : '';
    if ($source_url === '' && isset($manifest['source_home_url'])) {
      $source_url = (string) $manifest['source_home_url'];
    }
    $source_url = self::normalize_url($source_url);
    $source_label = $source_url !== '' ? $source_url : __('unknown source', 'restorepilot-backup-migration');
    $created_label = self::backup_created_label($manifest);
    $type_label = self::backup_type_label($manifest, $readiness['type']);
    $size = filesize($file);
    $size_label = $size ? size_format((int) $size) : __('unknown size', 'restorepilot-backup-migration');
    $disk_label = $needed > 0
      ? sprintf(
        /* translators: 1: estimated size of files to restore, 2: available disk space */
        __('Estimated files: %1$s. Available disk: %2$s.', 'restorepilot-backup-migration'),
        size_format((int) $needed),
        $free === false ? __('unknown', 'restorepilot-backup-migration') : size_format((int) $free)
      )
      : __('No wp-content files were found in this archive.', 'restorepilot-backup-migration');
    $disk_warning = $free !== false && $needed > 0 && (int) $free < (int) ($needed * 1.15);

    $details = sprintf(
      /* translators: 1: backup type label, 2: source URL, 3: creation date, 4: number of database tables, 5: number of files, 6: archive size, 7: disk space info line */
      __('Type: %1$s. Source: %2$s. Created: %3$s. Tables: %4$d. Files: %5$d. Size: %6$s. %7$s', 'restorepilot-backup-migration'),
      $type_label,
      $source_label,
      $created_label,
      (int) $validated['table_count'],
      (int) $validated['file_count'],
      $size_label,
      $disk_label
    );

    self::write_log('Backup check report for ' . basename($file) . ': ' . $details . ' Restorable: ' . (!empty($readiness['restorable']) ? 'yes' : 'no') . '.');

    if (!empty($readiness['restorable'])) {
      if ($disk_warning) {
        return sprintf(
          /* translators: %s: backup details summary (type, source, tables, files, size, disk info) */
          __('Backup check passed, but available disk space may be too low for restore. %s', 'restorepilot-backup-migration'),
          $details
        );
      }

      return sprintf(
        $for_restore
          /* translators: %s: backup details summary (type, source, tables, files, size, disk info) */
          ? __('Backup check passed. This full backup is ready for restore. %s', 'restorepilot-backup-migration')
          /* translators: %s: backup details summary (type, source, tables, files, size, disk info) */
          : __('Backup check passed. This is a full restorable backup. %s', 'restorepilot-backup-migration'),
        $details
      );
    }

    return sprintf(
      /* translators: %s: backup details summary (type, source, tables, files, size, disk info) */
      __('Backup check completed. This archive is not a full-site restore file. Use Download Full Backup for restore or migration. %s', 'restorepilot-backup-migration'),
      $details
    );
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

  private static function backup_type_label(array $manifest, string $type): string {
    if ($type === 'partial' && !empty($manifest['partial_type'])) {
      $partial_type = sanitize_key((string) $manifest['partial_type']);
      $partial_labels = [
        'database' => __('Database-only archive', 'restorepilot-backup-migration'),
        'plugins' => __('Plugins-only archive', 'restorepilot-backup-migration'),
        'themes' => __('Themes-only archive', 'restorepilot-backup-migration'),
        'uploads' => __('Uploads-only archive', 'restorepilot-backup-migration'),
        'mu-plugins' => __('Must-use plugins archive', 'restorepilot-backup-migration'),
        'others' => __('Other wp-content archive', 'restorepilot-backup-migration'),
      ];
      if (isset($partial_labels[$partial_type])) {
        return $partial_labels[$partial_type];
      }
    }

    $labels = [
      'full' => __('Full backup', 'restorepilot-backup-migration'),
      'database' => __('Database-only backup', 'restorepilot-backup-migration'),
      'partial' => __('Partial archive', 'restorepilot-backup-migration'),
      'selected-content' => __('Selected-content backup', 'restorepilot-backup-migration'),
      'rollback' => __('Restore rollback point', 'restorepilot-backup-migration'),
      'unsupported-configuration' => __('Incomplete backup — user accounts not included (custom user table)', 'restorepilot-backup-migration'),
    ];

    return $labels[$type] ?? __('Unknown archive type', 'restorepilot-backup-migration');
  }

  private static function backup_created_label(array $manifest): string {
    $created = isset($manifest['created_gmt']) ? (string) $manifest['created_gmt'] : '';
    if ($created === '' && isset($manifest['source_backup_created_gmt'])) {
      $created = (string) $manifest['source_backup_created_gmt'];
    }

    $timestamp = $created !== '' ? strtotime($created) : false;
    if ($timestamp === false) {
      return __('unknown time', 'restorepilot-backup-migration');
    }

    return wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), (int) $timestamp);
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
   * Finds the offset of the ")" closing the "(" at $open_pos, honouring
   * MySQL's backtick identifiers and single/double quoted strings so that a
   * parenthesis inside a column default or a COMMENT cannot end the scan
   * early (or be used to hide trailing SQL from it). Returns -1 if the
   * parentheses are unbalanced.
   */
  private static function matching_paren_offset(string $sql, int $open_pos): int {
    $len = strlen($sql);
    $depth = 0;
    $i = $open_pos;

    while ($i < $len) {
      $ch = $sql[$i];

      if ($ch === '`' || $ch === "'" || $ch === '"') {
        $quote = $ch;
        $i++;
        while ($i < $len) {
          // Backslash escapes apply inside string literals, not inside
          // backtick-quoted identifiers.
          if ($sql[$i] === '\\' && $quote !== '`' && $i + 1 < $len) {
            $i += 2;
            continue;
          }
          if ($sql[$i] === $quote) {
            // A doubled quote character is an escaped literal, not the end.
            if ($i + 1 < $len && $sql[$i + 1] === $quote) {
              $i += 2;
              continue;
            }
            break;
          }
          $i++;
        }
        $i++;
        continue;
      }

      if ($ch === '(') {
        $depth++;
      } elseif ($ch === ')') {
        $depth--;
        if ($depth === 0) {
          return $i;
        }
      }
      $i++;
    }

    return -1;
  }

  /**
   * True when the text trailing the column-definition block consists only of
   * recognised, inert table options. Anything else — most importantly a
   * SELECT, which would make this a "CREATE TABLE ... SELECT" that reads
   * arbitrary existing data — causes the whole restore to be refused.
   * Options that reach outside the database (CONNECTION=, DATA DIRECTORY=,
   * INDEX DIRECTORY=) are deliberately absent from this list.
   */
  private static function create_table_tail_is_safe(string $tail): bool {
    $tail = trim(rtrim(trim($tail), ';'));

    $option = '/^(?:'
      . 'ENGINE\s*=?\s*[A-Za-z0-9_]+'
      . '|(?:DEFAULT\s+)?(?:CHARACTER\s+SET|CHARSET)\s*=?\s*[A-Za-z0-9_]+'
      . '|(?:DEFAULT\s+)?COLLATE\s*=?\s*[A-Za-z0-9_]+'
      . '|AUTO_INCREMENT\s*=?\s*[0-9]+'
      . '|ROW_FORMAT\s*=?\s*[A-Za-z0-9_]+'
      . '|KEY_BLOCK_SIZE\s*=?\s*[0-9]+'
      . '|(?:MAX_ROWS|MIN_ROWS|AVG_ROW_LENGTH)\s*=?\s*[0-9]+'
      . '|PACK_KEYS\s*=?\s*(?:0|1|DEFAULT)'
      . '|(?:CHECKSUM|DELAY_KEY_WRITE)\s*=?\s*[01]'
      . '|STATS_PERSISTENT\s*=?\s*(?:0|1|DEFAULT)'
      . '|STATS_AUTO_RECALC\s*=?\s*(?:0|1|DEFAULT)'
      . '|STATS_SAMPLE_PAGES\s*=?\s*(?:[0-9]+|DEFAULT)'
      . '|COMMENT\s*=?\s*\'(?:[^\'\\\\]|\\\\.|\'\')*\''
      . ')\s*/i';

    while ($tail !== '') {
      if (!preg_match($option, $tail, $m)) {
        return false;
      }
      $tail = ltrim(substr($tail, strlen($m[0])));
    }

    return true;
  }

  /**
   * Whitelists the shape of a CREATE TABLE statement taken from an untrusted
   * backup archive before it is executed.
   *
   * A schema definition cannot be expressed through $wpdb->prepare() — its
   * content is SQL, not bound values — so the protection here is to accept
   * only the exact form SHOW CREATE TABLE produces and reject everything
   * else. In particular this refuses "CREATE TABLE ... SELECT" /
   * "... AS SELECT" (which would let a crafted archive populate the new
   * table from existing site data), MySQL executable comments, and any
   * trailing option not on the inert-options list.
   */
  private static function assert_create_table_is_safe(string $create, string $tmp_table, string $old_table): void {
    $invalid = static function () use ($old_table) {
      /* translators: %s: database table name */
      return new RuntimeException(sprintf(__('Invalid CREATE TABLE statement in backup for table %s. This archive cannot be restored safely.', 'restorepilot-backup-migration'), $old_table));
    };

    // Must be exactly "CREATE TABLE `<our temp name>` (" — no CREATE ... LIKE,
    // no IF NOT EXISTS, no other target table.
    if (!preg_match('/^\s*CREATE\s+TABLE\s+`' . preg_quote($tmp_table, '/') . '`\s*\(/i', $create)) {
      throw $invalid();
    }

    // MySQL executable comments (/*!50100 ... */) run as real SQL on the
    // server, so their content would bypass every check below.
    if (strpos($create, '/*') !== false) {
      throw $invalid();
    }

    $open = strpos($create, '(');
    $close = self::matching_paren_offset($create, (int) $open);
    if ($close === -1) {
      throw $invalid();
    }

    if (!self::create_table_tail_is_safe(substr($create, $close + 1))) {
      throw $invalid();
    }
  }

  /**
   * Builds and fully validates the restore plan — every table mapping, every
   * row's shape, every rewritten CREATE statement, and required-table
   * coverage — as one side-effect-free pass over the untrusted backup data,
   * before the rollback point is created or maintenance mode is enabled.
   * restore_database() executes exactly this plan and re-derives nothing, so
   * what was validated and what gets written to the database cannot drift
   * apart. Any problem throws here instead of being silently skipped during
   * execution.
   */
  /**
   * Names of the newline-delimited database export parts inside the archive,
   * in the order they must be read. Returns [] for a legacy single-file
   * database.json backup, which is handled by the fallback in
   * stream_database_records().
   */
  private static function database_part_names(array $manifest): array {
    if (($manifest['database_format'] ?? '') !== 'ndjson') {
      return [];
    }

    $count = isset($manifest['database_parts']) ? (int) $manifest['database_parts'] : 0;
    if ($count < 1 || $count > self::MAX_RESTORE_ZIP_ENTRIES) {
      throw new RuntimeException(__('Backup manifest does not describe a valid database export.', 'restorepilot-backup-migration'));
    }

    $names = [];
    for ($i = 1; $i <= $count; $i++) {
      $names[] = self::DATABASE_PART_DIR . '/database-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT) . '.ndjson';
    }
    return $names;
  }

  /**
   * Feeds every record of the database export to $callback, one at a time,
   * without ever holding more than a single record in memory.
   *
   * $callback receives ('table', ['name' => ..., 'create' => ...]) for each
   * table header and ('row', [...]) for each row belonging to the most recent
   * header, in archive order.
   *
   * Newline-delimited parts are read through ZipArchive::getStream(), so a
   * multi-gigabyte export costs the same memory as a small one. A legacy
   * single-file database.json backup is decoded the old way and replayed
   * through the same callback, so every caller has one code path regardless
   * of which format the archive uses.
   */
  private static function stream_database_records(RestorePilot_Backup_Archive $zip, array $manifest, callable $callback): void {
    $parts = self::database_part_names($manifest);

    if (!$parts) {
      // Legacy format: the whole export is one JSON document. This is bounded
      // by memory rather than disk, which is exactly what the newline-delimited
      // format exists to avoid — but archives created before it must still
      // restore, so the old path is kept for them.
      $raw = $zip->get_from_name('database.json');
      if (!is_string($raw) || $raw === '') {
        throw new RuntimeException(__('Backup database export is missing.', 'restorepilot-backup-migration'));
      }
      $decoded = json_decode($raw, true);
      unset($raw);
      if (!is_array($decoded) || empty($decoded['tables']) || !is_array($decoded['tables'])) {
        throw new RuntimeException(__('Backup database export is not readable.', 'restorepilot-backup-migration'));
      }
      foreach ($decoded['tables'] as $table) {
        if (!is_array($table)) {
          throw new RuntimeException(__('Backup database export contains a malformed table record.', 'restorepilot-backup-migration'));
        }
        $callback('table', [
          'name' => $table['name'] ?? null,
          'create' => $table['create'] ?? null,
        ]);
        $rows = (isset($table['rows']) && is_array($table['rows'])) ? $table['rows'] : null;
        if ($rows === null) {
          throw new RuntimeException(__('Backup database export contains a malformed table record.', 'restorepilot-backup-migration'));
        }
        foreach ($rows as $row) {
          $callback('row', $row);
        }
      }
      return;
    }

    foreach ($parts as $index => $part) {
      $stream = $zip->get_stream($part);
      if (!is_resource($stream)) {
        throw new RuntimeException(sprintf(
          /* translators: %s: name of the missing database export part inside the backup archive */
          __('Backup database export part %s is missing or unreadable.', 'restorepilot-backup-migration'),
          $part
        ));
      }

      try {
        $line_number = 0;
        while (($line = fgets($stream)) !== false) {
          $line_number++;
          $line = trim($line);
          if ($line === '') {
            continue;
          }
          $record = json_decode($line, true);
          if (!is_array($record) || !isset($record['t'])) {
            throw new RuntimeException(sprintf(
              /* translators: 1: name of the database export part, 2: line number within that part */
              __('Backup database export is corrupted at %1$s line %2$d.', 'restorepilot-backup-migration'),
              $part,
              $line_number
            ));
          }

          if ($record['t'] === 'table') {
            $callback('table', [
              'name' => $record['name'] ?? null,
              'create' => $record['create'] ?? null,
            ]);
          } elseif ($record['t'] === 'row') {
            $callback('row', $record['d'] ?? null);
          } else {
            throw new RuntimeException(sprintf(
              /* translators: 1: name of the database export part, 2: line number within that part */
              __('Backup database export contains an unrecognised record at %1$s line %2$d.', 'restorepilot-backup-migration'),
              $part,
              $line_number
            ));
          }
        }
      } finally {
        fclose($stream);
      }
      unset($index);
    }
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
    $own_plugin_rel = 'plugins/' . basename(dirname(__FILE__)) . '/';
    for ($i = $start_index; $i < $zip->num_files(); $i++) {
      try {
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

  private static function replace_urls_deep($value, string $source_url, string $target_url) {
    if ($source_url === '' || $target_url === '' || $source_url === $target_url) {
      return $value;
    }

    if (is_string($value)) {
      // Only attempt the serialized path when the value is a serialized array
      // or object — never for scalar serialized forms like `i:42;` or `b:1;`,
      // which would unserialize to a PHP scalar, then maybe_serialize back as a
      // plain string, silently corrupting the stored value.
      if (is_serialized($value)) {
        // allowed_classes => false: never instantiate a PHP class from a backup
        // archive. Archive contents are untrusted input (a backup may come from
        // another site or an unknown source), and instantiating arbitrary classes
        // here would allow object injection via a crafted serialized payload.
        // With object instantiation disabled, every serialized object decodes to
        // __PHP_Incomplete_Class, which contains_incomplete_object() detects
        // below — so object payloads are preserved byte-for-byte instead of being
        // rewritten, while arrays and scalars still get URL replacement.
        $maybe = @unserialize($value, ['allowed_classes' => false]);
        if (is_array($maybe) || is_object($maybe)) {
          if (self::contains_incomplete_object($maybe)) {
            self::write_log('Skipped URL replacement inside a serialized value containing a PHP object; the original value was preserved unchanged.');
            return $value;
          }
          $replaced = self::replace_urls_deep($maybe, $source_url, $target_url);
          // Only re-serialize if something actually changed; otherwise return
          // the original bytes so non-canonical serialized strings are not
          // altered unnecessarily.
          return $replaced === $maybe ? $value : maybe_serialize($replaced);
        }
        // Scalar serialized value (i:N, d:N, b:N, N;) — can never contain a
        // URL; return unchanged.
        return $value;
      }
      [$search, $replace] = self::url_replacement_pairs($source_url, $target_url);
      return str_replace($search, $replace, $value);
    }

    if (is_array($value)) {
      foreach ($value as $k => $v) {
        $value[$k] = self::replace_urls_deep($v, $source_url, $target_url);
      }
      return $value;
    }

    if (is_object($value)) {
      if (self::is_incomplete_object($value)) {
        return $value;
      }
      foreach (get_object_vars($value) as $k => $v) {
        $value->$k = self::replace_urls_deep($v, $source_url, $target_url);
      }
      return $value;
    }

    return $value;
  }

  private static function contains_incomplete_object($value): bool {
    if (is_object($value)) {
      if (self::is_incomplete_object($value)) {
        return true;
      }

      foreach (get_object_vars($value) as $property) {
        if (self::contains_incomplete_object($property)) {
          return true;
        }
      }
      return false;
    }

    if (is_array($value)) {
      foreach ($value as $item) {
        if (self::contains_incomplete_object($item)) {
          return true;
        }
      }
    }

    return false;
  }

  private static function is_incomplete_object($value): bool {
    return is_object($value) && get_class($value) === '__PHP_Incomplete_Class';
  }

  private static function url_replacement_pairs(string $source_url, string $target_url): array {
    $source = rtrim(self::normalize_url($source_url), '/');
    $target = rtrim(self::normalize_url($target_url), '/');
    $source_no_scheme = rtrim((string) preg_replace('#^https?://#i', '', $source), '/');
    $target_no_scheme = rtrim((string) preg_replace('#^https?://#i', '', $target), '/');

    if ($source_no_scheme === '') {
      return [[], []];
    }

    // Build explicit scheme-prefixed pairs only. A bare domain replacement
    // (e.g. "old.com" → "new.com") matches inside email addresses, longer
    // domain names, and any substring — so it is intentionally omitted.
    //
    // Escaped-slash variants (https:\/\/) cover Gutenberg block markup stored
    // in post_content as JSON, where forward slashes are backslash-escaped.
    // That content is not serialized, so it takes the plain str_replace path.
    $candidates = [
      ['https://' . $source_no_scheme . '/', 'https://' . $target_no_scheme . '/'],
      ['https://' . $source_no_scheme,       'https://' . $target_no_scheme],
      ['http://'  . $source_no_scheme . '/', 'http://'  . $target_no_scheme . '/'],
      ['http://'  . $source_no_scheme,       'http://'  . $target_no_scheme],
      ['//'       . $source_no_scheme . '/', '//'       . $target_no_scheme . '/'],
      ['//'       . $source_no_scheme,       '//'       . $target_no_scheme],
      // Escaped-slash variants for block-editor JSON in post_content.
      ['https:\/\/' . $source_no_scheme . '\/', 'https:\/\/' . $target_no_scheme . '\/'],
      ['https:\/\/' . $source_no_scheme,         'https:\/\/' . $target_no_scheme],
      ['http:\/\/'  . $source_no_scheme . '\/', 'http:\/\/'  . $target_no_scheme . '\/'],
      ['http:\/\/'  . $source_no_scheme,         'http:\/\/'  . $target_no_scheme],
    ];

    $search  = [];
    $replace = [];
    $seen    = [];
    foreach ($candidates as [$s, $r]) {
      if ($s === '' || $s === $r || isset($seen[$s])) {
        continue;
      }
      $seen[$s]  = true;
      $search[]  = $s;
      $replace[] = $r;
    }

    return [$search, $replace];
  }

  private static function normalize_url($url): string {
    $url = trim((string) $url);
    $url = untrailingslashit($url);
    return esc_url_raw($url);
  }

  private static function validate_restore_url(string $url, string $label, bool $allow_empty = false): string {
    $url = self::normalize_url($url);
    if ($url === '' && $allow_empty) {
      return '';
    }

    $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
    $host = (string) wp_parse_url($url, PHP_URL_HOST);
    if ($url === '' || !in_array($scheme, ['http', 'https'], true) || $host === '') {
      throw new RuntimeException(sprintf(
        /* translators: %s: URL field label (e.g. Source URL or Target URL) */
        __('%s must be a valid http or https URL.', 'restorepilot-backup-migration'),
        $label
      ));
    }

    return $url;
  }

  private static function normalize_server_path(string $path): string {
    $path = trim(str_replace("\0", '', $path));
    if ($path === '') {
      return '';
    }

    if (!preg_match('#^([a-z]:)?[/\\\\]#i', $path)) {
      $path = trailingslashit(ABSPATH) . ltrim($path, '/\\');
    }

    $real = realpath($path);
    return $real === false ? $path : $real;
  }

  /**
   * True when a table belongs to a DIFFERENT site of a multisite network.
   *
   * On a multisite main site $wpdb->prefix is the bare network prefix (e.g.
   * "wp_"), which a plain strpos() prefix test also matches for every subsite
   * table ("wp_2_posts", "wp_10_options", ...). Without this check a main-site
   * administrator's backup would capture — and a restore would overwrite —
   * other sites' data. Subsites are already safe because their prefix
   * ("wp_2_") does not match the main site's tables.
   *
   * WordPress reserves the "<prefix><digits>_" namespace for subsite tables, so
   * treating that shape as foreign is safe on single-site installs too.
   */
  /**
   * True when this site defines CUSTOM_USER_TABLE or CUSTOM_USER_META_TABLE —
   * WordPress's supported mechanism for pointing $wpdb->users/$wpdb->usermeta
   * at a table outside this site's own prefix, typically shared across
   * independent installs. Database export only ever captures tables that
   * start with $wpdb->prefix (see write_database_json()), so a shared users
   * table this site does not own is never backed up, and destructive
   * operations that assume $wpdb->users/$wpdb->usermeta belong solely to
   * this site (like Master Reset) must refuse instead of acting on it.
   */
  private static function uses_custom_user_tables(): bool {
    return defined('CUSTOM_USER_TABLE') || defined('CUSTOM_USER_META_TABLE');
  }

  private static function table_belongs_to_other_site(string $table, string $prefix): bool {
    if (!is_multisite() || $prefix === '' || strpos($table, $prefix) !== 0) {
      return false;
    }
    $suffix = substr($table, strlen($prefix));
    return (bool) preg_match('/^[0-9]+_/', $suffix);
  }

  private static function map_table_name(string $old_table, string $backup_prefix, string $target_prefix): string {
    if ($backup_prefix !== '' && strpos($old_table, $backup_prefix) === 0) {
      return $target_prefix . substr($old_table, strlen($backup_prefix));
    }
    return $old_table;
  }

  private static function restore_scratch_table_name(string $target_prefix, string $marker, string $restore_id, int $index): string {
    // Build the unique suffix (marker + restore ID + index) first and
    // truncate only the site-prefix portion to fit the 64-character MySQL
    // identifier limit. Truncating the whole concatenated string instead
    // (the previous approach) could cut into the suffix for a long site
    // prefix, making two different tables collide on the same truncated name.
    $prefix = preg_replace('/[^A-Za-z0-9_]/', '', $target_prefix);
    $suffix = $marker . $restore_id . '_' . $index;
    $max_prefix_len = max(0, 64 - strlen($suffix));
    return substr($prefix, 0, $max_prefix_len) . $suffix;
  }

  private static function temporary_table_name(string $target_prefix, string $restore_id, int $index): string {
    return self::restore_scratch_table_name($target_prefix, self::RESTORE_TMP_TABLE_MARKER, $restore_id, $index);
  }

  private static function old_table_name(string $target_prefix, string $restore_id, int $index): string {
    return self::restore_scratch_table_name($target_prefix, self::RESTORE_OLD_TABLE_MARKER, $restore_id, $index);
  }

  private static function table_exists(string $table): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
      return false;
    }

    $wpdb = self::wpdb();
    // Direct query: SHOW TABLES has no WordPress ORM equivalent.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    return is_string($found) && strtolower($found) === strtolower($table);
  }

  /**
   * Records the exact scratch table names a restore attempt is about to
   * create, before any of them exist. Overwrites any previous journal —
   * only one restore can run at a time (see RESTORE_LOCK_OPTION), and a
   * fresh restore always sweeps and clears the prior journal first.
   */
  private static function journal_restore_scratch_tables(array $table_names): void {
    $table_names = array_values(array_unique(array_filter($table_names, static function ($name) {
      return is_string($name) && $name !== '' && preg_match('/^[A-Za-z0-9_]+$/', $name);
    })));
    update_option(self::RESTORE_TABLE_JOURNAL_OPTION, $table_names, false);
  }

  private static function clear_restore_table_journal(): void {
    delete_option(self::RESTORE_TABLE_JOURNAL_OPTION);
  }

  /**
   * Drops only the exact tables recorded by a previous restore attempt's own
   * journal_restore_scratch_tables() call — never a name-pattern scan. A
   * wildcard "SHOW TABLES LIKE '{prefix}{marker}%'" scan would also match and
   * destroy an unrelated table that happens to share the marker string,
   * which has no restore journal to prove RestorePilot created it.
   */
  private static function sweep_stale_restore_tables(string $prefix): void {
    $journaled = get_option(self::RESTORE_TABLE_JOURNAL_OPTION, []);
    if (!is_array($journaled) || !$journaled) {
      return;
    }

    $wpdb = self::wpdb();
    foreach ($journaled as $stale) {
      $stale = (string) $stale;
      // Extra safety: only drop names that are still identifier-safe and
      // belong to this site's prefix plus one of our own scratch markers —
      // the journal is trusted, but this keeps the DROP scope identical to
      // what temporary_table_name()/old_table_name() can ever produce.
      if (!preg_match('/^[A-Za-z0-9_]+$/', $stale)) {
        continue;
      }
      if (strpos($stale, $prefix) !== 0) {
        continue;
      }
      $rest = substr($stale, strlen($prefix));
      if (strpos($rest, self::RESTORE_TMP_TABLE_MARKER) !== 0 && strpos($rest, self::RESTORE_OLD_TABLE_MARKER) !== 0) {
        continue;
      }
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
      $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $stale));
      self::write_log('Swept stale restore table: ' . $stale);
    }

    self::clear_restore_table_journal();
  }

  private static function throw_on_db_error(string $context): void {
    $wpdb = self::wpdb();
    if (!empty($wpdb->last_error)) {
      /* translators: 1: database operation context, 2: database error message */
      throw new RuntimeException(sprintf(__('Database error during %1$s: %2$s', 'restorepilot-backup-migration'), $context, $wpdb->last_error));
    }
  }

  /**
   * Extract every column of a table's PRIMARY KEY, in definition order, from
   * a SHOW CREATE TABLE statement — one column for a simple key, several for
   * a composite key (e.g. wp_term_relationships' (object_id,
   * term_taxonomy_id)), or an empty array if the table has no primary key at
   * all. This is what makes deterministic keyset export pagination possible;
   * see write_database_json().
   */
  private static function primary_key_columns(string $create_sql): array {
    // The column list can itself contain a key-length specifier in
    // parentheses, e.g. PRIMARY KEY (`a`(100),`b`) — the alternation lets the
    // capture group cross that nested "(100)" instead of stopping at its
    // closing paren, which would truncate the match before the real one.
    if (!preg_match('/PRIMARY KEY\s*\(((?:[^()]|\(\d+\))*)\)/i', $create_sql, $m)) {
      return [];
    }

    $columns = [];
    foreach (explode(',', $m[1]) as $part) {
      // Strip an optional MySQL key-part length specifier, e.g. `col`(20).
      $part = trim(preg_replace('/\(\d+\)\s*(ASC|DESC)?\s*$/i', '', trim($part)));
      $part = trim($part, "` \t\n\r\0\x0B");
      if ($part !== '' && preg_match('/^[A-Za-z0-9_]+$/', $part)) {
        $columns[] = $part;
      }
    }
    return $columns;
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

  /**
   * Every column the table declares NOT NULL, as a lookup keyed by name.
   *
   * SHOW CREATE TABLE always prints one column definition per line, and a
   * column line always opens with a backtick-quoted name — key definitions
   * open with PRIMARY/UNIQUE/KEY/INDEX/CONSTRAINT instead, so anchoring on
   * that leading backtick keeps the two apart. Matching NOT NULL as a whole
   * word is what stops the trailing "DEFAULT NULL" of a nullable column from
   * reading as one.
   */
  private static function not_null_columns(string $create_sql): array {
    $columns = [];
    foreach (preg_split('/\r\n|\r|\n/', $create_sql) as $line) {
      if (!preg_match('/^\s*`([A-Za-z0-9_]+)`\s+/', $line, $m)) {
        continue;
      }
      if (preg_match('/\bNOT\s+NULL\b/i', $line)) {
        $columns[$m[1]] = true;
      }
    }
    return $columns;
  }

  /**
   * Columns of a UNIQUE key that is safe to paginate on, or [] if none is.
   *
   * A table can carry a perfectly good ordered, unique, indexed column and
   * still have no PRIMARY KEY — `UNIQUE KEY id (id)` on a NOT NULL
   * AUTO_INCREMENT column is a real pattern in third-party plugin schemas.
   * Without this, such a table falls back to OFFSET pagination, which re-scans
   * and discards every preceding row on each batch; on a table of a few
   * hundred thousand rows that is minutes of extra work per backup.
   *
   * Every column of the key must be NOT NULL. MySQL permits repeated NULLs in
   * a UNIQUE index, so a nullable one is not actually unique, and a NULL can
   * never satisfy the "> last seen" tuple comparison keyset pagination walks
   * with — rows would be silently skipped rather than exported.
   */
  private static function unique_key_columns(string $create_sql): array {
    $not_null = self::not_null_columns($create_sql);
    if (!$not_null) {
      return [];
    }

    // Same nested-paren allowance as primary_key_columns(), for key-length
    // specifiers like UNIQUE KEY `k` (`a`(100),`b`).
    if (!preg_match_all(
      '/\bUNIQUE\s+(?:KEY|INDEX)\s*(?:`[^`]*`)?\s*\(((?:[^()]|\(\d+\))*)\)/i',
      $create_sql,
      $matches,
      PREG_SET_ORDER
    )) {
      return [];
    }

    foreach ($matches as $match) {
      $columns = [];
      foreach (explode(',', $match[1]) as $part) {
        $part = trim(preg_replace('/\(\d+\)\s*(ASC|DESC)?\s*$/i', '', trim($part)));
        $part = trim($part, "` \t\n\r\0\x0B");
        if ($part === '' || !preg_match('/^[A-Za-z0-9_]+$/', $part) || empty($not_null[$part])) {
          // Unusable key — try the next one rather than giving up entirely.
          continue 2;
        }
        $columns[] = $part;
      }
      if ($columns) {
        return $columns;
      }
    }

    return [];
  }

  /**
   * The columns to paginate a table's export by: its PRIMARY KEY when it has
   * one, otherwise a UNIQUE NOT NULL key that serves the same purpose, and []
   * when the table offers neither and has to fall back to OFFSET.
   */
  private static function keyset_cursor_columns(string $create_sql): array {
    $primary = self::primary_key_columns($create_sql);
    if ($primary) {
      return $primary;
    }
    return self::unique_key_columns($create_sql);
  }

  /**
   * Extract the storage engine (e.g. "InnoDB", "MyISAM") from a table's own
   * SHOW CREATE TABLE statement, or '' if it cannot be determined.
   */
  private static function table_engine(string $create_sql): string {
    if (preg_match('/\)\s*ENGINE\s*=\s*([A-Za-z0-9_]+)/i', $create_sql, $m)) {
      return $m[1];
    }
    return '';
  }

  private static function json_fragment($value, string $context): string {
    // Always sanitized first, rather than trying a raw encode and falling
    // back only on failure: wp_json_encode() does not fail on invalid UTF-8
    // the way PHP's own json_encode() does — it silently substitutes the
    // offending bytes and returns a string regardless, so a "try raw, check
    // for failure" pattern here never actually detects binary data at all
    // and simply corrupts it in place (confirmed live: BINARY(16)/BINARY(32)
    // primary-key columns in a real Wordfence table were being exported this
    // way, occasionally colliding two different source rows onto the same
    // corrupted value and making the backup fail to restore with a
    // duplicate-key error on data that was never actually duplicated).
    // make_json_safe()'s own per-string check is what's actually reliable
    // here, so it must run unconditionally rather than as a fallback.
    $safe = self::make_json_safe($value);
    $json = wp_json_encode($safe, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
      /* translators: %s: backup operation context (e.g. the phase being processed) */
      throw new RuntimeException(sprintf(__('Could not encode backup data during %s.', 'restorepilot-backup-migration'), $context));
    }

    return $json;
  }

  /**
   * Recursively walk $value and base64-wrap any string that wp_json_encode
   * cannot handle (e.g. non-UTF-8 binary data from BLOB/LONGBLOB columns).
   *
   * @param mixed $value
   * @return mixed
   */
  private static function make_json_safe($value) {
    if (is_string($value)) {
      // wp_json_encode() cannot be used to detect binary data here: unlike
      // PHP's own json_encode(), it never returns false for invalid UTF-8 —
      // it silently substitutes the offending bytes and "succeeds" instead,
      // so checking its result for failure never actually catches anything.
      // preg_match() with the /u modifier validates the subject string's own
      // UTF-8 encoding directly, with no such silent-repair behavior — this
      // is the only check in this codebase confirmed to reliably fail on
      // real binary column data (verified against BINARY(16)/BINARY(32)
      // values from a live Wordfence table that were previously getting
      // corrupted, unbase64'd, straight through this function).
      if (preg_match('//u', $value) !== 1) {
        // Binary-safe transport: a DB column value that is not valid UTF-8 (so it
        // cannot be represented as a JSON string at all) is preserved losslessly
        // as base64 and decoded on restore. This is data handling, not code
        // obfuscation.
        return ['_rp_b64' => 1, 'v' => base64_encode($value)]; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
      }
      return $value;
    }

    if (is_array($value)) {
      $safe = [];
      foreach ($value as $k => $v) {
        $safe[$k] = self::make_json_safe($v);
      }
      return $safe;
    }

    return $value;
  }

  /**
   * Unwrap a base64 sentinel written by make_json_safe() during backup.
   * Real DB column values arrive as plain strings; only the sentinel is an array.
   *
   * @param mixed $value
   * @return mixed
   */
  private static function decode_b64_column_value($value) {
    if (
      is_array($value) &&
      isset($value['_rp_b64'], $value['v']) &&
      $value['_rp_b64'] === 1 &&
      is_string($value['v'])
    ) {
      // Inverse of make_json_safe(): decode a base64-preserved binary column value.
      $decoded = base64_decode($value['v'], true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
      return $decoded !== false ? $decoded : '';
    }
    return $value;
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

  private static function prepare_restore_upload(): string {
    $server_path = self::post_value('server_backup_path');
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

  /**
   * Shown to a logged-in administrator instead of the plain 503. Rendered by
   * hand rather than through the admin UI because wp-admin itself is (rightly)
   * unavailable during a restore.
   */
  private static function render_maintenance_admin_page(): void {
    $snapshot = self::active_restore_snapshot();
    $job = $snapshot['job'];
    $phase = (string) ($job['phase_label'] ?? ($job['phase'] ?? ''));
    $message = (string) ($job['message'] ?? '');
    $progress = isset($job['progress']) ? (int) $job['progress'] : -1;
    $since = (int) $snapshot['seconds_since_update'];

    nocache_headers();
    status_header(503);
    header('Retry-After: 30');
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php esc_html_e('Restore in progress', 'restorepilot-backup-migration'); ?></title>
</head>
<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f0f0f1;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
<div style="max-width:620px;padding:40px;">
  <h1 style="font-size:22px;margin:0 0 8px;"><?php esc_html_e('A restore is running on this site', 'restorepilot-backup-migration'); ?></h1>
  <p style="color:#50575e;line-height:1.6;margin:0 0 20px;">
    <?php esc_html_e('Visitors are seeing a maintenance page. You are seeing this because you are signed in as an administrator. The site stays unavailable while the database is being replaced, so nothing is written that the restore would overwrite.', 'restorepilot-backup-migration'); ?>
  </p>

  <?php if ($snapshot['job_id'] !== '' && $job): ?>
    <div style="background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:16px 20px;margin:0 0 20px;">
      <?php if ($phase !== ''): ?>
        <p style="margin:0 0 6px;"><strong><?php esc_html_e('Stage:', 'restorepilot-backup-migration'); ?></strong> <?php echo esc_html($phase); ?><?php echo $progress >= 0 ? ' (' . esc_html((string) $progress) . '%)' : ''; ?></p>
      <?php endif; ?>
      <?php if ($message !== ''): ?>
        <p style="margin:0 0 6px;color:#50575e;"><?php echo esc_html($message); ?></p>
      <?php endif; ?>
      <p style="margin:0;color:#50575e;">
        <?php
        if ($since >= 0) {
          echo esc_html(sprintf(
            /* translators: %s: human-readable time difference, e.g. "5 mins" */
            __('Last progress: %s ago.', 'restorepilot-backup-migration'),
            human_time_diff(time() - $since, time())
          ));
        } else {
          esc_html_e('No progress has been recorded yet.', 'restorepilot-backup-migration');
        }
        ?>
      </p>
    </div>
  <?php endif; ?>

  <?php if ($snapshot['looks_stuck']): ?>
    <div style="background:#fcf9e8;border-left:4px solid #dba617;padding:12px 16px;margin:0 0 16px;">
      <p style="margin:0;color:#50575e;line-height:1.6;">
        <?php esc_html_e('This restore has not reported progress for several minutes, so it has probably stopped. If it has, you can end it below and then recover your database from a pre-restore rollback point.', 'restorepilot-backup-migration'); ?>
      </p>
    </div>
  <?php else: ?>
    <p style="color:#50575e;line-height:1.6;margin:0 0 16px;">
      <?php esc_html_e('This restore is still making progress. Let it finish — ending it now would leave the site partly restored.', 'restorepilot-backup-migration'); ?>
    </p>
  <?php endif; ?>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="restorepilot_abandon_restore">
    <?php wp_nonce_field(self::NONCE); ?>
    <button type="submit" style="background:#d63638;border:0;color:#fff;padding:10px 16px;border-radius:3px;font-size:14px;cursor:pointer;"
      onclick="return confirm(<?php echo esc_attr(wp_json_encode(__('End this restore and unlock the site? Only do this if the restore has genuinely stopped. The site may be left partly restored, and you should then recover from a pre-restore rollback point.', 'restorepilot-backup-migration'))); ?>);">
      <?php esc_html_e('End this restore and unlock the site', 'restorepilot-backup-migration'); ?>
    </button>
  </form>

  <p style="color:#787c82;font-size:13px;line-height:1.6;margin:16px 0 0;">
    <?php esc_html_e('Ending a restore turns off maintenance mode and releases the lock so you can start another restore straight away — including restoring a rollback point. It does not undo anything already written.', 'restorepilot-backup-migration'); ?>
  </p>
</div>
</body>
</html>
    <?php
    exit;
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

  /**
   * The page every visitor sees while a restore holds the site.
   *
   * Deliberately self-contained: no external stylesheet, font, or image, and
   * no database read of any kind. This renders during the window where the
   * database is being replaced, so anything that queried an option could be
   * reading a half-swapped table — and any asset request would 503 against
   * this same gate. Everything needed is inlined.
   *
   * It carries no plugin name or link. This is the site owner's front end,
   * shown to their visitors, and plugin credits there have to be opt-in and
   * default to off (plugin guideline 10) — so the honest default is to say
   * nothing about which plugin is doing the work.
   *
   * The bar is indeterminate on purpose. Real progress is not readable from
   * here without a database the restore is actively rewriting, and a made-up
   * percentage that stalls or slides backwards reads worse than an honest
   * "something is happening".
   */
  private static function render_maintenance_page(): void {
    nocache_headers();
    status_header(503);
    header('Retry-After: 30');
    header('Content-Type: text/html; charset=utf-8');

    $title   = __('Briefly unavailable', 'restorepilot-backup-migration');
    $heading = __('Briefly unavailable for scheduled maintenance', 'restorepilot-backup-migration');
    $body    = __('This site is finishing a backup restore and will be back in a moment.', 'restorepilot-backup-migration');
    $hint    = __('Please check back shortly.', 'restorepilot-backup-migration');
    $status  = __('Restore in progress', 'restorepilot-backup-migration');

    echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="robots" content="noindex,nofollow">';
    echo '<title>' . esc_html($title) . '</title>';
    echo '<style>
:root{
  --rp-bg:#f6f7f7; --rp-card:#fff; --rp-ink:#1d2327; --rp-muted:#646970;
  --rp-line:#dcdcde; --rp-accent:#2271b1; --rp-track:#e8eaec;
}
@media (prefers-color-scheme:dark){
  :root{
    --rp-bg:#16191d; --rp-card:#1f2429; --rp-ink:#f0f0f1; --rp-muted:#a7aaad;
    --rp-line:#2f353b; --rp-accent:#5ba3dd; --rp-track:#2b3137;
  }
}
*{box-sizing:border-box}
body{
  margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
  padding:24px; background:var(--rp-bg); color:var(--rp-ink);
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;
  -webkit-font-smoothing:antialiased;
}
.rp-card{
  width:100%; max-width:460px; background:var(--rp-card);
  border:1px solid var(--rp-line); border-radius:10px;
  padding:36px 32px; text-align:center;
  box-shadow:0 1px 3px rgba(0,0,0,.06),0 8px 24px rgba(0,0,0,.05);
}
.rp-status{
  display:inline-flex; align-items:center; gap:7px;
  font-size:12px; font-weight:600; letter-spacing:.04em; text-transform:uppercase;
  color:var(--rp-muted); margin-bottom:18px;
}
.rp-dot{
  width:7px; height:7px; border-radius:50%; background:var(--rp-accent);
  animation:rp-pulse 1.8s ease-in-out infinite;
}
h1{font-size:19px; line-height:1.4; margin:0 0 10px; font-weight:600;}
p{font-size:14.5px; line-height:1.65; margin:0; color:var(--rp-muted);}
p + p{margin-top:6px;}
.rp-track{
  margin-top:26px; height:3px; border-radius:3px;
  background:var(--rp-track); overflow:hidden;
}
.rp-bar{
  height:100%; width:38%; border-radius:3px; background:var(--rp-accent);
  animation:rp-slide 1.9s cubic-bezier(.65,0,.35,1) infinite;
}
@keyframes rp-slide{
  0%{transform:translateX(-110%)} 100%{transform:translateX(330%)}
}
@keyframes rp-pulse{
  0%,100%{opacity:1} 50%{opacity:.3}
}
@media (prefers-reduced-motion:reduce){
  .rp-bar{animation:none; width:100%; opacity:.45}
  .rp-dot{animation:none}
}
</style></head>';

    echo '<body><main class="rp-card">';
    echo '<div class="rp-status"><span class="rp-dot" aria-hidden="true"></span>' . esc_html($status) . '</div>';
    echo '<h1>' . esc_html($heading) . '</h1>';
    echo '<p>' . esc_html($body) . '</p>';
    echo '<p>' . esc_html($hint) . '</p>';
    echo '<div class="rp-track" role="progressbar" aria-label="' . esc_attr($status) . '"><div class="rp-bar"></div></div>';
    echo '</main></body></html>';
    exit;
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

  public static function render_operation_notices(): void {
    if (!current_user_can('manage_options')) {
      return;
    }
    $file = self::operation_notice_file();
    if (!is_file($file)) {
      return;
    }
    $raw = @file_get_contents($file);
    @unlink($file);
    if (!$raw) {
      return;
    }
    $notice = json_decode($raw, true);
    if (!is_array($notice) || empty($notice['type']) || empty($notice['message'])) {
      return;
    }
    // Discard stale notices older than 24 hours.
    if (!empty($notice['created']) && (time() - (int) $notice['created']) > 86400) {
      return;
    }
    $type      = $notice['type'] === 'success' ? 'notice-success' : 'notice-error';
    $operation = sanitize_text_field($notice['operation'] ?? '');
    $message   = sanitize_text_field($notice['message']);
    $label     = $operation === 'backup'
      ? ($notice['type'] === 'success' ? __('Backup completed successfully.', 'restorepilot-backup-migration') : __('Backup failed.', 'restorepilot-backup-migration'))
      : ($notice['type'] === 'success' ? __('Restore completed.', 'restorepilot-backup-migration')           : __('Restore failed.', 'restorepilot-backup-migration'));
    ?>
    <div class="notice <?php echo esc_attr($type); ?> is-dismissible">
      <p><strong><?php echo esc_html($label); ?></strong> <?php echo esc_html($message); ?></p>
    </div>
    <?php
  }

  /**
   * Tells the user their plugins are sitting deactivated because a restore
   * held them back (see defer_active_plugins_during_restore()) and then did
   * not finish. Without this the site would just silently come back with
   * everything except RestorePilot switched off and nothing explaining why.
   */
  public static function render_deferred_plugins_notice(): void {
    if (!current_user_can('manage_options') || !self::has_orphaned_deferred_plugins()) {
      return;
    }

    $deferred = get_option(self::DEFERRED_PLUGINS_OPTION, []);
    $count = is_array($deferred) ? count($deferred) : 0;
    ?>
    <div class="notice notice-warning">
      <p>
        <strong><?php esc_html_e('RestorePilot: your other plugins are still deactivated.', 'restorepilot-backup-migration'); ?></strong>
      </p>
      <p>
        <?php
        echo esc_html(sprintf(
          /* translators: %d: number of plugins that were deactivated during a restore */
          _n(
            'A restore switched %d plugin off while it replaced your files, so a half-restored plugin could not crash the site mid-restore. That restore did not finish, so they were never switched back on.',
            'A restore switched %d plugins off while it replaced your files, so a half-restored plugin could not crash the site mid-restore. That restore did not finish, so they were never switched back on.',
            $count,
            'restorepilot-backup-migration'
          ),
          $count
        ));
        ?>
      </p>
      <p>
        <?php esc_html_e('Reactivate them once you are satisfied the site is in a good state — for example after finishing or retrying the restore, or after rolling back. Plugins whose files are missing will be left off.', 'restorepilot-backup-migration'); ?>
      </p>
      <p>
        <a href="<?php echo esc_url(self::admin_action_url('restorepilot_reactivate_plugins')); ?>" class="button button-primary">
          <?php esc_html_e('Reactivate my plugins', 'restorepilot-backup-migration'); ?>
        </a>
      </p>
    </div>
    <?php
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

  private static function log_file(): string {
    return self::storage_dir() . '/restorepilot.log';
  }

  private static function write_log(string $message): void {
    $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . "\n";
    self::append_db_log($line);

    try {
      self::ensure_storage();
      $written = @file_put_contents(self::log_file(), $line, FILE_APPEND | LOCK_EX);
      if ($written === false || $written !== strlen($line)) {
        return;
      }
      self::trim_log();
    } catch (Throwable $e) {
      return;
    }
  }

  private static function read_log(): string {
    // Merge both stores. A database restore replaces wp_options (and therefore the
    // DB log) with the backup's contents, while the file log keeps the entries
    // written during the restore itself. Reading only one source would hide half
    // the history, so we combine, de-duplicate, and order by timestamp.
    $db_log = self::read_db_log();

    $file_log = '';
    $file = self::log_file();
    if (is_file($file) && is_readable($file)) {
      $file_log = (string) file_get_contents($file);
    }

    if ($db_log === '' && $file_log === '') {
      return '';
    }
    if ($db_log === '') {
      return strlen($file_log) > self::MAX_LOG_BYTES ? substr($file_log, -self::MAX_LOG_BYTES) : $file_log;
    }
    if ($file_log === '') {
      return $db_log;
    }

    $lines = preg_split('/\r\n|\r|\n/', $db_log . "\n" . $file_log) ?: [];
    $seen = [];
    $merged = [];
    foreach ($lines as $line) {
      if (trim((string) $line) === '' || isset($seen[$line])) {
        continue;
      }
      $seen[$line] = true;
      $merged[] = $line;
    }

    // Stable sort by the leading "[YYYY-MM-DD HH:MM:SS UTC]" timestamp; lines
    // without a parseable timestamp keep their relative order at the end.
    usort($merged, static function ($a, $b) {
      $ta = (preg_match('/^\[([\d\-]+ [\d:]+) UTC\]/', $a, $ma)) ? $ma[1] : '';
      $tb = (preg_match('/^\[([\d\-]+ [\d:]+) UTC\]/', $b, $mb)) ? $mb[1] : '';
      if ($ta === $tb) {
        return 0;
      }
      if ($ta === '') {
        return 1;
      }
      if ($tb === '') {
        return -1;
      }
      return strcmp($ta, $tb);
    });

    $contents = implode("\n", $merged) . "\n";
    if (strlen($contents) > self::MAX_LOG_BYTES) {
      $contents = substr($contents, -self::MAX_LOG_BYTES);
    }

    return $contents;
  }

  private static function read_log_for_display(): string {
    $log = trim(self::read_log());
    if ($log === '') {
      return '';
    }

    $lines = preg_split('/\r\n|\r|\n/', $log);
    if (!is_array($lines)) {
      return $log;
    }

    $lines = array_values(array_filter($lines, static function ($line) {
      return trim((string) $line) !== '';
    }));
    $lines = array_reverse($lines);

    return implode("\n", $lines);
  }

  private static function clear_log(): void {
    delete_option(self::LOG_OPTION);
    $file = self::log_file();
    if (is_file($file)) {
      @unlink($file);
    }
  }

  private static function append_db_log(string $line): void {
    try {
      $contents = (string) get_option(self::LOG_OPTION, '');
      $contents .= $line;
      if (strlen($contents) > self::MAX_LOG_BYTES) {
        $contents = substr($contents, -self::MAX_LOG_BYTES);
      }
      update_option(self::LOG_OPTION, $contents, false);
    } catch (Throwable $e) {
      return;
    }
  }

  private static function read_db_log(): string {
    try {
      $contents = (string) get_option(self::LOG_OPTION, '');
      if (strlen($contents) > self::MAX_LOG_BYTES) {
        $contents = substr($contents, -self::MAX_LOG_BYTES);
      }
      return $contents;
    } catch (Throwable $e) {
      return '';
    }
  }

  private static function trim_log(): void {
    $file = self::log_file();
    if (!is_file($file)) {
      return;
    }

    $size = filesize($file);
    if ($size === false || $size <= self::MAX_LOG_BYTES) {
      return;
    }

    $contents = (string) file_get_contents($file);
    $contents = substr($contents, -self::MAX_LOG_BYTES);
    @file_put_contents($file, $contents, LOCK_EX);
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

  private static function system_status_items(): array {
    self::ensure_storage();

    $free_space  = @disk_free_space(self::storage_dir());
    $max_upload  = (int) wp_max_upload_size();
    $cron_ready  = !defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON;
    $zip_ok      = class_exists('ZipArchive');
    $folder_ok   = is_writable(self::backup_dir());
    $free_ok     = $free_space !== false && (int) $free_space > 50 * 1024 * 1024;
    $php_ok      = version_compare(PHP_VERSION, '7.4.0', '>=');

    return [
      [
        'label'  => __('PHP Version', 'restorepilot-backup-migration'),
        'value'  => PHP_VERSION,
        'status' => $php_ok ? 'ok' : 'warn',
      ],
      [
        'label'  => __('ZIP Support', 'restorepilot-backup-migration'),
        'value'  => $zip_ok
          ? __('Available', 'restorepilot-backup-migration')
          : __('Missing — restores require ZipArchive', 'restorepilot-backup-migration'),
        'status' => $zip_ok ? 'ok' : 'error',
      ],
      [
        'label'  => __('Backup Folder', 'restorepilot-backup-migration'),
        'value'  => $folder_ok
          ? __('Writable', 'restorepilot-backup-migration')
          : __('Not writable — backups cannot be saved', 'restorepilot-backup-migration'),
        'status' => $folder_ok ? 'ok' : 'error',
      ],
      [
        'label'  => __('Free Disk Space', 'restorepilot-backup-migration'),
        'value'  => $free_space === false ? __('Unknown', 'restorepilot-backup-migration') : size_format((int) $free_space),
        'status' => $free_space === false ? 'info' : ($free_ok ? 'ok' : 'warn'),
      ],
      [
        'label'  => __('Upload Limit', 'restorepilot-backup-migration'),
        'value'  => $max_upload > 0 ? size_format($max_upload) : __('Unknown', 'restorepilot-backup-migration'),
        'status' => 'info',
      ],
      [
        'label'  => __('WP-Cron', 'restorepilot-backup-migration'),
        'value'  => $cron_ready
          ? __('Available', 'restorepilot-backup-migration')
          : __('Disabled — scheduled backups won\'t run', 'restorepilot-backup-migration'),
        'status' => $cron_ready ? 'ok' : 'warn',
      ],
    ];
  }

  private static function diagnostic_status_items(array $backups): array {
    self::ensure_storage();

    $backup_bytes = 0;
    foreach ($backups as $backup) {
      $backup_bytes += isset($backup['size']) ? max(0, (int) $backup['size']) : 0;
    }

    $rollback_files = self::list_restore_rollback_points();
    $free_space = @disk_free_space(self::storage_dir());
    $temp_bytes = self::temp_storage_bytes();

    return [
      [
        'label' => __('Backup Folder', 'restorepilot-backup-migration'),
        'value' => self::backup_dir(),
        'status' => is_writable(self::backup_dir()) ? 'ok' : 'error',
      ],
      [
        'label' => __('Stored Backups', 'restorepilot-backup-migration'),
        'value' => sprintf(
          /* translators: 1: number of stored backups, 2: maximum number of backups, 3: total size of stored backups */
          __('%1$d of %2$d used — %3$s', 'restorepilot-backup-migration'),
          count($backups),
          self::MAX_BACKUPS,
          size_format((int) $backup_bytes)
        ),
        'status' => count($backups) >= self::MAX_BACKUPS ? 'warn' : 'ok',
      ],
      [
        'label' => __('Temporary Files', 'restorepilot-backup-migration'),
        'value' => size_format((int) $temp_bytes),
        'status' => $temp_bytes > 100 * 1024 * 1024 ? 'warn' : 'ok',
      ],
      [
        'label' => __('Restore Rollback Points', 'restorepilot-backup-migration'),
        'value' => sprintf(
          /* translators: 1: maximum number of rollback points kept, 2: number of rollback points currently stored */
          __('Up to %1$d hidden rollback point(s); currently %2$d stored.', 'restorepilot-backup-migration'),
          self::MAX_RESTORE_ROLLBACKS,
          count($rollback_files)
        ),
        'status' => 'ok',
      ],
      [
        'label' => __('Restore Safety', 'restorepilot-backup-migration'),
        'value' => __('Full backups only; partial archives are blocked from full restore; background restore is enabled.', 'restorepilot-backup-migration'),
        'status' => 'ok',
      ],
      [
        'label' => __('Free Disk Space', 'restorepilot-backup-migration'),
        'value' => $free_space === false ? __('Unknown', 'restorepilot-backup-migration') : size_format((int) $free_space),
        'status' => $free_space === false ? 'info' : ((int) $free_space > 250 * 1024 * 1024 ? 'ok' : 'warn'),
      ],
      [
        'label' => __('Uninstall Cleanup', 'restorepilot-backup-migration'),
        'value' => __('Deleting the plugin removes RestorePilot backups, logs, temp files, options, and scheduled events.', 'restorepilot-backup-migration'),
        'status' => 'info',
      ],
    ];
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

  private static function enable_error_logging(): void {
    if (self::$error_logging_enabled) {
      return;
    }

    self::$error_logging_enabled = true;
    // This plugin's own runtime PHP warning/fatal-error log (see
    // handle_php_error()/handle_shutdown_error() below) is a documented
    // feature (readme.txt: "Runtime PHP warning and fatal error logging
    // during RestorePilot actions"), not development-only debug output.
    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
    set_error_handler([__CLASS__, 'handle_php_error']);
    register_shutdown_function([__CLASS__, 'handle_shutdown_error']);
  }

  public static function handle_php_error(int $severity, string $message, string $file = '', int $line = 0): bool {
    // Only issues raised by this plugin's own files are recorded, and only into
    // RestorePilot's own log. Returning false ALWAYS hands the error back to
    // PHP's normal handler, so the host site's error output, log destination and
    // debugging behaviour are never altered or suppressed by this plugin.
    if (!self::error_file_is_relevant($file)) {
      return false;
    }

    // Low-severity chatter is not actionable in a backup log.
    $ignored_severities = [E_NOTICE, E_DEPRECATED, E_USER_DEPRECATED];
    if (defined('E_STRICT')) {
      $ignored_severities[] = E_STRICT;
    }
    if (in_array($severity, $ignored_severities, true)) {
      return false;
    }

    // Backup and restore intentionally tolerate expected filesystem failures
    // (a missing temp file, an already-removed directory) using suppressed
    // calls, and those run inside loops over thousands of files. Record each
    // distinct problem once, and cap the total per request, so a repeated
    // benign warning cannot flood the capped log and push out the entry that
    // actually explains a failure.
    $key = md5($severity . '|' . $message . '|' . $file . '|' . $line);
    if (isset(self::$logged_runtime_errors[$key])) {
      return false;
    }
    if (count(self::$logged_runtime_errors) >= self::MAX_RUNTIME_ERRORS_PER_REQUEST) {
      return false;
    }
    self::$logged_runtime_errors[$key] = true;

    self::log_runtime_error('PHP ' . self::php_error_label($severity), $message, $file, $line);
    return false;
  }

  public static function handle_shutdown_error(): void {
    $error = error_get_last();
    if (!is_array($error)) {
      return;
    }

    $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array((int) ($error['type'] ?? 0), $fatal_types, true)) {
      return;
    }

    $file = (string) ($error['file'] ?? '');
    if (!self::error_file_is_relevant($file)) {
      return;
    }

    self::log_runtime_error('PHP fatal error', (string) ($error['message'] ?? ''), $file, (int) ($error['line'] ?? 0));

    if (self::$active_backup_job_id !== '') {
      self::update_backup_job(self::$active_backup_job_id, [
        'status' => 'error',
        'phase' => 'error',
        'phase_label' => self::backup_phase_label('error'),
        'progress' => 100,
        'message' => __('Backup stopped because PHP hit a fatal error. Check the Logs tab.', 'restorepilot-backup-migration'),
      ]);
      self::force_release_backup_locks(self::$active_backup_job_id);
    } elseif (self::$active_scheduled_backup) {
      // Scheduled backup has no job record — just release the lock directly.
      delete_option(self::BACKUP_LOCK_OPTION);
      self::write_log('Scheduled backup aborted by a PHP fatal error.');
    }

    if (self::$active_restore_job_id !== '') {
      self::update_restore_job(self::$active_restore_job_id, [
        'status' => 'error',
        'phase' => 'error',
        'phase_label' => self::restore_phase_label('error'),
        'progress' => 100,
        'message' => __('Restore stopped because PHP hit a fatal error. Maintenance mode was removed; check the Logs tab.', 'restorepilot-backup-migration'),
      ]);
      self::force_release_restore_locks(self::$active_restore_job_id);
    }
  }

  private static function log_runtime_error(string $label, string $message, string $file, int $line): void {
    if (self::$handling_php_error) {
      return;
    }

    self::$handling_php_error = true;
    self::write_log($label . ': ' . $message . ' in ' . $file . ':' . $line);
    self::$handling_php_error = false;
  }

  private static function error_file_is_relevant(string $file): bool {
    $file_path = realpath($file) ?: $file;
    $plugin_dir = realpath(dirname(__FILE__)) ?: dirname(__FILE__);

    $file_path = str_replace('\\', '/', $file_path);
    $plugin_dir = str_replace('\\', '/', $plugin_dir);

    return $file_path === str_replace('\\', '/', __FILE__) || strpos($file_path, trailingslashit($plugin_dir)) === 0;
  }

  private static function php_error_label(int $severity): string {
    $labels = [
      E_ERROR => 'error',
      E_WARNING => 'warning',
      E_PARSE => 'parse error',
      E_NOTICE => 'notice',
      E_CORE_ERROR => 'core error',
      E_CORE_WARNING => 'core warning',
      E_COMPILE_ERROR => 'compile error',
      E_COMPILE_WARNING => 'compile warning',
      E_USER_ERROR => 'user error',
      E_USER_WARNING => 'user warning',
      E_USER_NOTICE => 'user notice',
      E_STRICT => 'strict',
      E_RECOVERABLE_ERROR => 'recoverable error',
      E_DEPRECATED => 'deprecated',
      E_USER_DEPRECATED => 'user deprecated',
    ];

    return $labels[$severity] ?? ('error ' . $severity);
  }

  private static function prepare_for_long_operation(): void {
    if (function_exists('ignore_user_abort')) {
      ignore_user_abort(true);
    }
    if (function_exists('set_time_limit')) {
      @set_time_limit(0);
    }
  }

  // Remove RestorePilot runtime/transient options that arrived in the restored
  // database from the source site. Called immediately after the DB swap. The
  // current restore job ($current_job_id) is intentionally NOT recreated here —
  // it lives in its on-disk status file during the swap and is written back to
  // the DB on completion.
  /**
   * $current_restore_lock_token, when given, names the lock this very
   * restore is actively holding. It is excluded from the wipe below (the
   * job record) and put straight back afterward (the lock option, which
   * unlike the job record cannot be excluded by name since it is a single
   * global option) — because a resumable restore's checkpoint and active
   * lock are not foreign state, they are this operation's own, and a
   * DELETE that treats them the same as everything the source site carried
   * over would strand every later resumption with no checkpoint to resume
   * from and no lock to stop a second restore starting alongside it.
   */
  /**
   * A properly quoted, properly escaped SQL string literal for a "starts
   * with $prefix" LIKE pattern — e.g. "'foo\_bar%'" — ready to embed
   * directly in a query string. $prefix must always be a trusted, hardcoded
   * value: every caller in this plugin passes one of its own *_PREFIX
   * constants or a literal string, never anything derived from a request.
   * This is NOT safe to use with untrusted input the way $wpdb->prepare()'s
   * %s binding is.
   *
   * Deliberately does not use prepare()'s %s placeholder for the pattern
   * itself. Confirmed on this install's WordPress 7.0.4: prepare() replaces
   * any literal '%' character inside a *bound value* (not the query
   * template — %i table-name binding is unaffected) with an internal
   * one-time marker token, meant to be restored to a literal '%' once the
   * whole query is assembled — and that restoration does not happen, so
   * `$wpdb->prepare('... LIKE %s', $wpdb->esc_like($x) . '%')`, the
   * standard textbook WordPress pattern used everywhere including WordPress
   * core itself, silently matches nothing at all rather than throwing or
   * warning. This was invisible until it broke three unrelated call sites
   * at once: purge_foreign_runtime_state() below (worker locks it should
   * have deleted piled up in wp_options forever), handle_reset_runtime()
   * (the plugin's own "stuck locks" manual recovery button — the one
   * escape hatch for an admin who believes something is stuck did not
   * actually clear the worker locks it claims to), and
   * prune_finished_job_records() (completed backup/restore job records
   * accumulated indefinitely instead of being pruned).
   */
  private static function like_prefix_literal(string $prefix): string {
    return "'" . esc_sql(self::wpdb()->esc_like($prefix)) . "%'";
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
          $wpdb->query("DELETE FROM `$table` WHERE option_name LIKE {$pattern['like']} AND option_name != '" . esc_sql($pattern['except']) . "'");
        } else {
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

  private static function acquire_backup_worker_lock(string $job_id): bool {
    // The option name is namespaced with the literal 'restorepilot_backup_worker_'
    // prefix directly at each add_option() call below, followed by a
    // sanitize_key()'d job id. $option (built the same way via
    // backup_worker_lock_option()) is used only for the matching get_option()/
    // delete_option() calls, which read/remove the identical name.
    $option = self::backup_worker_lock_option($job_id);
    $lock   = ['started' => time()];

    // Use add_option as the sole atomic gate (MySQL UNIQUE constraint ensures
    // only one caller wins).  Only delete a stale lock first if the option
    // already exists and is confirmed old — and re-attempt via add_option so
    // two callers racing on a stale lock cannot both win.
    if (add_option('restorepilot_backup_worker_' . sanitize_key($job_id), $lock, '', false)) {
      return true;
    }

    $existing = get_option($option, []);
    if (!is_array($existing) || empty($existing['started'])) {
      return false;
    }
    if ((time() - (int) $existing['started']) < 6 * HOUR_IN_SECONDS) {
      return false;
    }

    // Lock is stale: delete it then try to acquire atomically.
    delete_option($option);
    return (bool) add_option('restorepilot_backup_worker_' . sanitize_key($job_id), $lock, '', false);
  }

  private static function release_backup_worker_lock(string $job_id): void {
    delete_option(self::backup_worker_lock_option($job_id));
  }

  private static function backup_worker_lock_option(string $job_id): string {
    return self::BACKUP_WORKER_LOCK_PREFIX . sanitize_key($job_id);
  }

  private static function acquire_restore_worker_lock(string $job_id): bool {
    $option = self::restore_worker_lock_option($job_id);
    $lock   = ['started' => time()];

    if (add_option('restorepilot_restore_worker_' . sanitize_key($job_id), $lock, '', false)) {
      return true;
    }

    $existing = get_option($option, []);
    if (!is_array($existing) || empty($existing['started'])) {
      return false;
    }
    if ((time() - (int) $existing['started']) < 6 * HOUR_IN_SECONDS) {
      return false;
    }

    delete_option($option);
    return (bool) add_option('restorepilot_restore_worker_' . sanitize_key($job_id), $lock, '', false);
  }

  private static function release_restore_worker_lock(string $job_id): void {
    delete_option(self::restore_worker_lock_option($job_id));
  }

  private static function restore_worker_lock_option(string $job_id): string {
    return self::RESTORE_WORKER_LOCK_PREFIX . sanitize_key($job_id);
  }

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

  private static function get_backup_job(string $job_id): array {
    if ($job_id === '') {
      return [];
    }

    $job = get_option(self::backup_job_option($job_id), []);
    return is_array($job) ? $job : [];
  }

  private static function get_restore_job(string $job_id): array {
    if ($job_id === '') {
      return [];
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

  private static function throw_if_backup_cancelled(string $job_id): void {
    if ($job_id === '') {
      return;
    }

    $job = self::get_backup_job($job_id);
    if (($job['status'] ?? '') === 'canceled') {
      throw new RestorePilot_Backup_Cancelled_Exception(__('Backup canceled.', 'restorepilot-backup-migration'));
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

  private static function wpdb(): wpdb {
    global $wpdb;
    return $wpdb;
  }
}

RestorePilot_Backup_Migration::init();

if (defined('WP_CLI') && WP_CLI) {
  add_action('cli_init', static function (): void {
    WP_CLI::add_command('restorepilot backup', ['RestorePilot_Backup_Migration', 'cli_backup']);
    WP_CLI::add_command('restorepilot health', ['RestorePilot_Backup_Migration', 'cli_health']);
  });
}
