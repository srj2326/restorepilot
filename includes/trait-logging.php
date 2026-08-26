<?php
/**
 * The plugin log, and capturing PHP errors into it.
 *
 * @package RestorePilot_Backup_Migration
 */

if (!defined('ABSPATH')) {
  exit;
}

trait RestorePilot_Logging {
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
}
