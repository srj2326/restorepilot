<?php
/**
 * Candidate implementation of the hardened CREATE TABLE validator.
 * Kept standalone so it can be exercised against real SHOW CREATE TABLE
 * output and against crafted attack strings before going into the plugin.
 */

/**
 * Finds the offset of the ")" that closes the "(" at $open_pos, honouring
 * MySQL's backtick identifiers and single/double quoted strings so a paren
 * inside a column default or comment does not end the scan early.
 * Returns -1 if unbalanced.
 */
function matching_paren(string $sql, int $open_pos): int {
  $len = strlen($sql);
  $depth = 0;
  $i = $open_pos;
  while ($i < $len) {
    $ch = $sql[$i];

    if ($ch === '`' || $ch === "'" || $ch === '"') {
      $quote = $ch;
      $i++;
      while ($i < $len) {
        if ($sql[$i] === '\\' && $quote !== '`' && $i + 1 < $len) {
          $i += 2;
          continue;
        }
        if ($sql[$i] === $quote) {
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
 * True when the trailing text after the column-definition block consists of
 * nothing but recognised, harmless table options.
 */
function tail_is_safe(string $tail): bool {
  $tail = trim($tail);
  $tail = rtrim($tail, ';');
  $tail = trim($tail);

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
    $tail = substr($tail, strlen($m[0]));
    $tail = ltrim($tail);
  }
  return true;
}

function validate_create(string $create, string $tmp_table): string {
  // 1. Must open with exactly CREATE TABLE `tmp` (
  if (!preg_match('/^\s*CREATE\s+TABLE\s+`' . preg_quote($tmp_table, '/') . '`\s*\(/i', $create, $m)) {
    return 'REJECTED (must begin with CREATE TABLE `tmp` ( )';
  }

  // 2. Reject MySQL executable comments outright — /*!50100 ... */ runs as SQL.
  if (strpos($create, '/*') !== false) {
    return 'REJECTED (contains a MySQL comment / executable comment)';
  }

  // 3. Find the paren that closes the column-definition block.
  $open = strpos($create, '(');
  $close = matching_paren($create, $open);
  if ($close === -1) {
    return 'REJECTED (unbalanced parentheses)';
  }

  // 4. Everything after it must be recognised table options only. This is
  //    what stops CREATE TABLE ... SELECT / ... AS SELECT.
  if (!tail_is_safe(substr($create, $close + 1))) {
    return 'REJECTED (unexpected SQL after the column definition block)';
  }

  return '*** ACCEPTED ***';
}
