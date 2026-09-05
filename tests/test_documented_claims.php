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

/**
 * What the plugin promises, checked against what it does.
 *
 * RP-030. The README and readme.txt said a backup or restore "picks up from
 * exactly where it stopped" and that there was "no size ceiling". Restores do
 * resume, including partway through a single table, and file collection
 * resumes -- but the database export inside a backup does not. It is taken as
 * one consistent snapshot, and a database transaction cannot outlive the PHP
 * process that opened it, so an interrupted export starts again. A scheduled
 * daily backup does not chunk at all.
 *
 * For a backup product those are not marketing details. Someone choosing this
 * for disaster recovery is deciding what to rely on, and a claim broader than
 * the implementation is the kind of thing that is discovered on the worst day.
 *
 * This checks both directions: that the documents no longer make the
 * unqualified claim, and that the code still behaves the way they now describe.
 * Either drifting from the other should fail here.
 */

require_once __DIR__ . '/env.php';
rp_test_boot();

$failures = [];
function check(string $label, bool $ok, string $detail = '') {
    global $failures;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if ($detail !== '') { echo '        ' . $detail . "\n"; }
    if (!$ok) { $failures[] = $label; }
}

$root      = dirname(__DIR__);
$readme_md = file_get_contents($root . '/README.md');
$readme_tx = file_get_contents($root . '/readme.txt');
$backup_src = file_get_contents($root . '/includes/trait-backup.php');

// ── The claims, as written ─────────────────────────────────────────────────
echo "=== what the documents promise ===\n";

foreach (['README.md' => $readme_md, 'readme.txt' => $readme_tx] as $name => $doc) {
    check("$name no longer claims an unqualified size ceiling",
        stripos($doc, 'No size ceiling') === false && stripos($doc, 'No size limit') === false,
        'volume splitting removes the per-file limit, which is not the same as having no limit');

    check("$name says the database export is the step that does not resume",
        stripos($doc, 'consistent snapshot') !== false
        && (stripos($doc, 'starts again') !== false || stripos($doc, 'restarts') !== false),
        'the exception has to be stated where the promise is made, not only in a FAQ');

    check("$name says a scheduled backup runs as a single process",
        stripos($doc, 'single process') !== false || stripos($doc, 'does not chunk') !== false);
}

check('readme.txt tells the reader that a repeatedly interrupted restore slows down',
    stripos($readme_tx, 're-reads the rows') !== false,
    'each resumption re-reads what it has already written to find its place');

// ── And the code still works the way they now say ──────────────────────────
echo "\n=== and what the code actually does ===\n";

// The comment explaining why the export cannot be resumed is load-bearing: if
// somebody makes it resumable, this test should fail so the documents get
// revisited rather than quietly becoming wrong again.
check('The export is still deliberately not resumed mid-flight',
    stripos($backup_src, 'The database export is never resumed mid-flight') !== false,
    'if this changes, the documents above need to change with it');

// A scheduled backup has no job id, and the chunk deadline is only armed when
// there is one -- which is what "runs as a single process" means in code.
check('Chunking is armed only for a job that something can resume',
    strpos($backup_src, "self::\$chunk_deadline = \$job_id !== ''") !== false,
    'without a job id there is nothing to catch a yield or reschedule it');

$deadline = new ReflectionProperty('RestorePilot_Backup_Migration', 'chunk_deadline');
$deadline->setAccessible(true);
$deadline->setValue(null, 0.0);
$yield = new ReflectionMethod('RestorePilot_Backup_Migration', 'throw_if_chunk_time_exceeded');
$yield->setAccessible(true);
$threw = false;
try { $yield->invoke(null); } catch (Throwable $e) { $threw = true; }
check('With no deadline armed, nothing ever yields',
    !$threw, 'a scheduled backup runs to completion in one call');

// The restore side of the promise, which is the part that is true.
$restore_src = file_get_contents($root . '/includes/trait-restore.php');
check('A restore really does resume partway through a table',
    stripos($restore_src, '$skip_remaining') !== false,
    'it counts what is already written and carries on from there');

// ── Where the documents say backups live ───────────────────────────────────
// RP-042. Moving storage out of the uploads directory left the description,
// the privacy section, the FAQ and the generated privacy-policy text all still
// saying "uploads directory". An operator reads those to decide what their own
// server backups need to include, and to answer deletion requests.
echo "\n=== where the documents say backups are kept ===\n";

$bootstrap = file_get_contents($root . '/includes/trait-bootstrap.php');

foreach (['README.md' => $readme_md, 'readme.txt' => $readme_tx] as $name => $doc) {
    check("$name does not claim backups live in the uploads directory",
        stripos($doc, 'written to your own WordPress uploads directory') === false
        && stripos($doc, 'inside the WordPress uploads directory unless') === false);

    check("$name describes the private location and the fallback",
        stripos($doc, 'beside') !== false || stripos($doc, 'next to your WordPress') !== false);
}

check('readme.txt says what happens to backups on uninstall',
    stripos($readme_tx, 'Deleting the plugin removes the stored backups') !== false,
    'the privacy section makes a deletion promise, so it has to be the one that is kept');

check('The generated privacy policy agrees with the readme',
    stripos($bootstrap, 'uploads directory unless an administrator') === false
    && stripos($bootstrap, 'beside the WordPress installation') !== false);

check('And it does not promise to delete a directory the administrator chose',
    stripos($bootstrap, 'configured explicitly is left in place') !== false);

// The code still works the way they now describe.
check('A private location outside WordPress is still what the code prefers',
    strpos(file_get_contents($root . '/includes/trait-storage.php'), 'private_storage_root') !== false);
check('And uploads is still the fallback when it is not writable',
    strpos(file_get_contents($root . '/includes/trait-storage.php'),
        "\$upload = wp_upload_dir(null, false);\n    return trailingslashit(\$upload['basedir']) . 'restorepilot-backup-migration';") !== false);

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
