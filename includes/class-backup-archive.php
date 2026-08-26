<?php
/**
 * Reads a backup archive, transparently spanning its volumes.
 *
 * @package RestorePilot_Backup_Migration
 */

if (!defined('ABSPATH')) {
  exit;
}

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
