<?php
/**
 * Exceptions used to unwind a cancelled or time-limited chunk.
 *
 * @package RestorePilot_Backup_Migration
 */

if (!defined('ABSPATH')) {
  exit;
}

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
