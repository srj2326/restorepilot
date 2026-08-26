<?php
/**
 * Writes a backup archive, one entry at a time.
 *
 * @package RestorePilot_Backup_Migration
 */

if (!defined('ABSPATH')) {
  exit;
}

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
