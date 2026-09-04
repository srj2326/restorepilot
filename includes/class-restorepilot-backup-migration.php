<?php
/**
 * The plugin's single entry-point class; its behaviour lives in the traits it uses.
 *
 * @package RestorePilot_Backup_Migration
 */

if (!defined('ABSPATH')) {
  exit;
}

final class RestorePilot_Backup_Migration {
  use RestorePilot_Bootstrap;
  use RestorePilot_AdminUi;
  use RestorePilot_RequestHandlers;
  use RestorePilot_Backup;
  use RestorePilot_Restore;
  use RestorePilot_Database;
  use RestorePilot_Migration;
  use RestorePilot_Storage;
  use RestorePilot_Jobs;
  use RestorePilot_Locks;
  use RestorePilot_Logging;
  use RestorePilot_Maintenance;
  use RestorePilot_Support;

  const VERSION = '0.5.6';
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
  // Longest single newline-delimited record a restore will read. fgets() with
  // no length reads to the next newline however far away it is, so one line
  // with no newline in it was read whole into memory before anything could
  // object -- and a crafted archive is not needed to produce that shape, a
  // truncated one does it too. Generous: export parts are 32 MB, so a single
  // legitimate row is far below this even carrying a large blob.
  const MAX_DATABASE_LINE_BYTES = 67108864; // 64 MB
  // How much is asked for per read while assembling one record. PHP allocates
  // a buffer of whatever length fgets() is given, on every call -- passing the
  // 64 MB ceiling above made reading 200,000 short lines take 2164 ms instead
  // of 52 ms, forty times slower, and cost a resumable restore enough of its
  // chunk budget to leave a row unrestored. A record longer than this is
  // assembled across several reads and measured as it grows.
  const DATABASE_LINE_READ_BYTES = 1048576; // 1 MB
  // Most a single archive entry may expand by. RestorePilot writes its
  // archives stored rather than deflated -- a real backup measures 1.0:1, all
  // 7438 entries of it -- so this sits 200x above anything the plugin
  // produces, and well above the 20:1 or so that deflated text reaches. It is
  // here to refuse an archive that unpacks to orders of magnitude more than it
  // occupies, before a single entry is extracted.
  const MAX_ARCHIVE_EXPANSION_RATIO = 200;
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





































































































































































































































  /**
   * How old a worker lock has to be before another worker may take it over.
   * Long, because a legitimate chunk can sit on one very large table.
   */
  const WORKER_LOCK_STALE_SECONDS = 6 * HOUR_IN_SECONDS;





























































}

RestorePilot_Backup_Migration::init();

if (defined('WP_CLI') && WP_CLI) {
  add_action('cli_init', static function (): void {
    WP_CLI::add_command('restorepilot backup', ['RestorePilot_Backup_Migration', 'cli_backup']);
    WP_CLI::add_command('restorepilot health', ['RestorePilot_Backup_Migration', 'cli_health']);
  });
}
