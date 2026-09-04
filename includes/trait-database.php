<?php
/**
 * Reading and writing table data, and the schema rules that keep it safe.
 *
 * @package RestorePilot_Backup_Migration
 */

if (!defined('ABSPATH')) {
  exit;
}

trait RestorePilot_Database {
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

  /**
   * Tables belonging to this site that WordPress itself did not create.
   *
   * Master Reset deletes every plugin's files but used to leave the tables
   * those plugins made, so a site advertised as reset to "a clean WordPress
   * installation" still carried their data -- unreadable, since the code that
   * understood it was gone, but still occupying space and still copied into
   * every backup. On the site this was found on that was 108 MB across three
   * form-plugin tables, out of a 206 MB database.
   *
   * Core tables are named exhaustively rather than guessed at, so a table is
   * only ever treated as disposable because it is absent from that list.
   */
  private static function foreign_plugin_tables(): array {
    $wpdb = self::wpdb();
    $prefix = $wpdb->prefix;
    if (!is_string($prefix) || $prefix === '') {
      return [];
    }

    // Every table WordPress creates for a single site, plus the network ones,
    // which are not this site's to remove even when the prefix matches.
    $core = [];
    foreach ([
      'posts', 'postmeta', 'comments', 'commentmeta', 'terms', 'termmeta',
      'term_taxonomy', 'term_relationships', 'links', 'options', 'users', 'usermeta',
      'blogs', 'blogmeta', 'site', 'sitemeta', 'signups', 'registration_log',
      'blog_versions',
    ] as $t) {
      if (!empty($wpdb->$t) && is_string($wpdb->$t)) {
        $core[strtolower($wpdb->$t)] = true;
      }
    }
    // usermeta/users can be shared across installs and are handled separately
    // by the reset itself; never consider them disposable here.
    $core[strtolower($prefix . 'users')] = true;
    $core[strtolower($prefix . 'usermeta')] = true;

    // like_prefix_literal() returns a complete quoted literal, wildcard and
    // all, for concatenation -- passing it through prepare()'s %s binding
    // escapes the quoting it already did and matches nothing. Every other
    // call site concatenates it the same way.
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the literal is built by like_prefix_literal() from esc_like()+esc_sql(); no caller-supplied value reaches this string.
    $found = $wpdb->get_col('SHOW TABLES LIKE ' . self::like_prefix_literal($prefix));
    if (!is_array($found)) {
      return [];
    }

    $tables = [];
    foreach ($found as $table) {
      $table = (string) $table;
      if (isset($core[strtolower($table)])) {
        continue;
      }
      // On multisite prefixes, wp_2_* belongs to another site, not this one.
      if (self::table_belongs_to_other_site($table, $prefix)) {
        continue;
      }
      // Never remove a scratch table a restore is mid-way through using; the
      // reset's own cleanup owns those.
      if (strpos($table, self::RESTORE_TMP_TABLE_MARKER) !== false
        || strpos($table, self::RESTORE_OLD_TABLE_MARKER) !== false) {
        continue;
      }
      $tables[] = $table;
    }

    return $tables;
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
  /**
   * Records the scratch tables a restore is about to create, against the job
   * that owns them.
   *
   * Keyed by job, because the journal used to be one flat list shared by every
   * restore: a second restore starting while a first was still going would
   * journal over it, and its opening sweep would drop the first restore's live
   * tables out from under it. That is reachable in normal use -- abandoning a
   * stuck restore releases the locks but does not stop the worker already
   * mid-chunk, so the next restore can begin while it is still writing.
   */
  private static function journal_restore_scratch_tables(string $job_id, array $table_names): void {
    $table_names = array_values(array_unique(array_filter($table_names, static function ($name) {
      return is_string($name) && $name !== '' && preg_match('/^[A-Za-z0-9_]+$/', $name);
    })));

    $journal = get_option(self::RESTORE_TABLE_JOURNAL_OPTION, []);
    if (!is_array($journal)) {
      $journal = [];
    }
    // A journal written before this was keyed by job is a flat list of names.
    // Park it under a key of its own so the sweep can still clear it.
    if ($journal && array_keys($journal) === range(0, count($journal) - 1)) {
      $journal = ['' => $journal];
    }

    $journal[$job_id] = $table_names;
    update_option(self::RESTORE_TABLE_JOURNAL_OPTION, $journal, false);
  }

  /** Forgets one restore's scratch tables, leaving any other restore's alone. */
  private static function clear_restore_table_journal(?string $job_id = null): void {
    if ($job_id === null) {
      delete_option(self::RESTORE_TABLE_JOURNAL_OPTION);
      return;
    }

    $journal = get_option(self::RESTORE_TABLE_JOURNAL_OPTION, []);
    if (!is_array($journal) || !isset($journal[$job_id])) {
      return;
    }
    unset($journal[$job_id]);
    if ($journal) {
      update_option(self::RESTORE_TABLE_JOURNAL_OPTION, $journal, false);
    } else {
      delete_option(self::RESTORE_TABLE_JOURNAL_OPTION);
    }
  }

  /**
   * Drops only the exact tables recorded by a previous restore attempt's own
   * journal_restore_scratch_tables() call — never a name-pattern scan. A
   * wildcard "SHOW TABLES LIKE '{prefix}{marker}%'" scan would also match and
   * destroy an unrelated table that happens to share the marker string,
   * which has no restore journal to prove RestorePilot created it.
   */
  /**
   * Drops scratch tables left behind by restores that are no longer running.
   *
   * A restore still in flight is skipped entirely. Its tables are not stale --
   * they are being written to right now, and dropping them fails that restore
   * mid-insert with a table that no longer exists.
   */
  private static function sweep_stale_restore_tables(string $prefix, string $current_job_id = ''): void {
    $journal = get_option(self::RESTORE_TABLE_JOURNAL_OPTION, []);
    if (!is_array($journal) || !$journal) {
      return;
    }
    if (array_keys($journal) === range(0, count($journal) - 1)) {
      $journal = ['' => $journal];
    }

    $wpdb = self::wpdb();
    $kept = [];

    foreach ($journal as $owner_job => $tables) {
      $owner_job = (string) $owner_job;
      if (!is_array($tables)) {
        continue;
      }

      // Never this restore's own tables: it is about to use them.
      if ($current_job_id !== '' && $owner_job === $current_job_id) {
        $kept[$owner_job] = $tables;
        continue;
      }

      // Nor another restore's, while that restore is still going.
      if ($owner_job !== '') {
        $owner = self::get_restore_job($owner_job, true);
        $owner_status = (string) ($owner['status'] ?? '');
        if (in_array($owner_status, ['queued', 'running'], true)) {
          $kept[$owner_job] = $tables;
          continue;
        }
      }

      foreach ($tables as $stale) {
        $stale = (string) $stale;
        // Extra safety: only drop names that are still identifier-safe and
        // belong to this site's prefix plus one of our own scratch markers --
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
    }

    if ($kept) {
      update_option(self::RESTORE_TABLE_JOURNAL_OPTION, $kept, false);
    } else {
      delete_option(self::RESTORE_TABLE_JOURNAL_OPTION);
    }
  }

  /**
   * Is this row already in the scratch table it was about to be written to?
   *
   * Asked only when an insert has just failed, so the cost falls on the rare
   * path. Answered by looking at the table rather than by reading the error:
   * the obvious test is $wpdb->last_error for "Duplicate entry", which is an
   * English sentence a MySQL server is free to translate, and a restore that
   * only survives on servers set to English is not a fix.
   *
   * A row is the same row when its key matches, not when every column does.
   * The payload can legitimately differ between attempts -- URL replacement is
   * applied to the values on the way in, and a retry after a changed target URL
   * would write different text under the same key. The key is what says this is
   * the same source row arriving a second time.
   *
   * Returns false whenever it cannot be sure: no key to identify the row by, a
   * payload missing that key, or a table that is no longer there. In each case
   * the insert's own error is the truthful answer and must be allowed to stand.
   */
  private static function restore_row_already_present(array $plan, array $row): bool {
    $create = (string) ($plan['create'] ?? '');
    $table  = (string) ($plan['tmp_table'] ?? '');
    if ($create === '' || $table === '') {
      return false;
    }

    $key_columns = self::primary_key_columns($create);
    if (!$key_columns) {
      $key_columns = self::unique_key_columns($create);
    }
    if (!$key_columns) {
      // Nothing identifies this row, so a repeat cannot be told from a new
      // row that failed for some other reason.
      return false;
    }

    $where = [];
    $values = [];
    foreach ($key_columns as $column) {
      if (!array_key_exists($column, $row) || $row[$column] === null) {
        return false;
      }
      $where[] = '`' . str_replace('`', '``', $column) . '` = %s';
      $values[] = (string) $row[$column];
    }

    $wpdb = self::wpdb();
    $wpdb->last_error = '';
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is a scratch name this restore generated; every value is bound.
    $found = $wpdb->get_var($wpdb->prepare(
      'SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '` WHERE ' . implode(' AND ', $where),
      $values
    ));

    // A dropped table answers with an error, not a count -- which is the other
    // half of the failure this exists for, and emphatically not a reason to
    // treat the row as safely written.
    if (!empty($wpdb->last_error)) {
      return false;
    }

    return (int) $found > 0;
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

  private static function wpdb(): wpdb {
    global $wpdb;
    return $wpdb;
  }
}
