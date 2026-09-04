<?php
// Command line only. These scripts live inside the plugin directory so they
// survive alongside the code they test -- which also puts them under the web
// root, where a request could otherwise reach one. Several boot WordPress as
// user 1 and then reset sites, delete users, or set passwords, so reaching one
// over HTTP has to be impossible rather than unlikely. Checked before anything
// else runs, including the WordPress load below.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

// Directly tests RestorePilot_Backup_Zip_Writer::resume() against on-disk
// states an abrupt process kill can actually leave: a fully-written entry
// whose journal line never landed, and a journal whose last line is itself
// truncated mid-write. Still needs wp-load.php: journal_entry() calls
// wp_json_encode() and error messages use __().

require '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public/wp-load.php';
if (!class_exists('RestorePilot_Backup_Migration')) {
  require_once '/Users/surajitroy/Local Sites/morecalculators-dev/app/public/wp-content/plugins/restorepilot-backup-migration/restorepilot-backup-migration.php';
}

$failures = [];
function check($label, $cond) {
  global $failures;
  echo ($cond ? 'PASS' : 'FAIL') . "  $label\n";
  if (!$cond) $failures[] = $label;
}

$tmp = sys_get_temp_dir() . '/rp-zipwriter-kill-test-' . getmypid() . '.zip';
@unlink($tmp);
@unlink($tmp . '.journal');

// --- Scenario A: kill after a fully-written, fully-journaled entry, with
// extra garbage bytes appended to simulate a NEXT entry that started writing
// but whose journal line never landed. resume() must discard the garbage.
$w = RestorePilot_Backup_Zip_Writer::create($tmp);
$w->addFromString('a.txt', 'hello world');
$w->addFromString('b.txt', str_repeat('b', 5000));
$good_offset_after_two = filesize($tmp);

// Simulate: entry 3's local header + partial data got written to the raw
// file, but the process died before finish_entry() (and so before its
// journal line) ever ran.
$fh = fopen($tmp, 'ab');
fwrite($fh, 'GARBAGE_FROM_AN_INCOMPLETE_THIRD_ENTRY_THAT_NEVER_FINISHED');
fclose($fh);
clearstatcache(true, $tmp);
check('Scenario A: file is now longer than the last good journal position', filesize($tmp) > $good_offset_after_two);

$resumed = RestorePilot_Backup_Zip_Writer::resume($tmp);
clearstatcache(true, $tmp);
check('Scenario A: resume() recovers exactly 2 entries', count($resumed->entry_name_set()) === 2);
check('Scenario A: resume() truncates back to the last good offset', filesize($tmp) === $good_offset_after_two);

$resumed->addFromString('c.txt', 'recovered and continuing');
$resumed->close();

$za = new ZipArchive();
check('Scenario A: finalized archive opens', $za->open($tmp) === true);
check('Scenario A: exactly 3 entries in the final archive', $za->numFiles === 3);
check('Scenario A: a.txt content correct', $za->getFromName('a.txt') === 'hello world');
check('Scenario A: b.txt content correct', $za->getFromName('b.txt') === str_repeat('b', 5000));
check('Scenario A: c.txt content correct', $za->getFromName('c.txt') === 'recovered and continuing');
check('Scenario A: no leftover garbage entry', $za->locateName('GARBAGE_FROM_AN_INCOMPLETE_THIRD_ENTRY_THAT_NEVER_FINISHED') === false);
$za->close();
check('Scenario A: journal deleted after close()', !is_file($tmp . '.journal'));

@unlink($tmp);
@unlink($tmp . '.journal');

// --- Scenario B: the journal file's own last line is itself truncated
// (process died mid-fwrite of the journal line, a separate failure point
// from the raw zip data write).
$w2 = RestorePilot_Backup_Zip_Writer::create($tmp);
$w2->addFromString('x.txt', 'first entry, fully safe');
$w2->addFromString('y.txt', 'second entry, fully safe');
$good_offset_after_two_b = filesize($tmp);
$w2->addFromString('z.txt', 'third entry, about to be corrupted');
// Truncate the journal mid-way through its last line to simulate a partial
// fwrite of that line, while leaving the raw zip data for "z.txt" intact.
$journal_contents = file_get_contents($tmp . '.journal');
$lines = explode("\n", rtrim($journal_contents, "\n"));
check('Scenario B: journal has 3 lines before corruption', count($lines) === 3);
$last_line = $lines[2];
$truncated_last_line = substr($last_line, 0, (int) (strlen($last_line) / 2));
$new_journal = $lines[0] . "\n" . $lines[1] . "\n" . $truncated_last_line; // no trailing newline
file_put_contents($tmp . '.journal', $new_journal);

$resumed2 = RestorePilot_Backup_Zip_Writer::resume($tmp);
clearstatcache(true, $tmp);
check('Scenario B: resume() recovers exactly 2 entries (bad line ignored)', count($resumed2->entry_name_set()) === 2);
check('Scenario B: resume() truncates the zip back to before the corrupted entry', filesize($tmp) === $good_offset_after_two_b);

$resumed2->addFromString('z.txt', 'third entry, redone correctly');
$resumed2->close();

$za2 = new ZipArchive();
check('Scenario B: finalized archive opens', $za2->open($tmp) === true);
check('Scenario B: exactly 3 entries', $za2->numFiles === 3);
check('Scenario B: x.txt correct', $za2->getFromName('x.txt') === 'first entry, fully safe');
check('Scenario B: y.txt correct', $za2->getFromName('y.txt') === 'second entry, fully safe');
check('Scenario B: z.txt redone correctly (not the corrupted attempt)', $za2->getFromName('z.txt') === 'third entry, redone correctly');
$za2->close();

@unlink($tmp);
@unlink($tmp . '.journal');

// --- Scenario C: resume() on a volume that was never actually started
// (edge case: existing_volume_paths would be empty, handled one level up
// in Volume_Writer::resume(), not here — this just confirms resume() itself
// behaves correctly against an empty journal for a file that does exist).
$w3 = RestorePilot_Backup_Zip_Writer::create($tmp);
$w3->close();
$resumed3_path = $tmp;
check('Scenario C: journal was deleted by close() on an empty archive', !is_file($tmp . '.journal'));
@unlink($tmp);

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
