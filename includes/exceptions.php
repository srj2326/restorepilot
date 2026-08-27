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

/**
 * Signals that the job this worker is running has already been finished by
 * another worker. Not an error, and emphatically not an abandonment: the
 * restore succeeded, and this process simply has nothing left to do.
 *
 * It exists because the abandonment check used to treat 'complete' as one of
 * the statuses meaning "an administrator ended this restore", so a second
 * worker still inside its chunk when the first finished would throw, be caught
 * by the generic handler, and mark a restore that had *just succeeded* as
 * failed -- telling the operator to recover their database from a rollback
 * point they did not need. Whether it happened at all came down to the timing
 * of two workers, which is the worst way for a message like that to appear.
 */
class RestorePilot_Restore_Already_Finished_Exception extends RuntimeException {}
