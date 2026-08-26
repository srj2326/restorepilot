<?php
/**
 * Splits an archive across volumes so no single file grows too large.
 *
 * @package RestorePilot_Backup_Migration
 */

if (!defined('ABSPATH')) {
  exit;
}

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
