<?php
/**
 * Which file a restore actually reads, when more than one field could name one.
 *
 * The chunked upload used to report where it had put its temporary file by
 * writing into the visible "Server backup path" box under Advanced settings --
 * a field meant for a path the operator typed themselves. That left an internal
 * temp filename sitting in a setting nobody had entered, pointing at a file the
 * restore deletes on its way out, so pressing Restore a second time tried to
 * read something that no longer existed.
 *
 * It has its own hidden field now, which introduces a hazard of its own: a
 * hidden field survives until the page is reloaded, and it outranks the visible
 * one. Upload a backup, restore it, then pick a different backup from the list
 * without reloading, and the stale value would quietly restore the first file
 * instead of the one just chosen. The browser is stopped from getting there --
 * the field is cleared whenever the source changes -- but precedence is decided
 * on the server, so that is where it is worth pinning down.
 */

define('WP_USE_THEMES', false);
require_once '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public/wp-load.php';

$failures = [];
function check(string $label, bool $ok, string $detail = '') {
    global $failures;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if ($detail !== '') { echo '        ' . $detail . "\n"; }
    if (!$ok) { $failures[] = $label; }
}
function priv($name, $args = []) {
    $m = new ReflectionMethod('RestorePilot_Backup_Migration', $name);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
}

$storage = priv('storage_dir');
if (!is_dir($storage)) { wp_mkdir_p($storage); }

/** A real, readable, non-empty zip, since the resolver checks all three. */
function make_zip(string $path): void {
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', '{}');
    $zip->close();
}

$uploaded = $storage . '/restore-upload-precedence-' . wp_generate_password(6, false, false) . '.zip';
$typed    = $storage . '/typed-by-hand-' . wp_generate_password(6, false, false) . '.zip';
make_zip($uploaded);
make_zip($typed);

register_shutdown_function(function () use ($uploaded, $typed) {
    @unlink($uploaded);
    @unlink($typed);
});

function resolve(array $post) {
    $_POST = $post;
    try {
        return ['ok' => true, 'path' => priv('prepare_restore_upload')];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    } finally {
        $_POST = [];
    }
}

// ── The upload wins, because it is what the operator just did ──────────────
echo "=== an upload and a typed path, both present ===\n";
$r = resolve([
    'uploaded_backup_path' => $uploaded,
    'server_backup_path'   => $typed,
]);
check('The file just uploaded is the one restored', $r['ok'] && $r['path'] === $uploaded,
    $r['ok'] ? basename($r['path']) : $r['error']);

// ── With no upload, the typed path is used ─────────────────────────────────
echo "\n=== only a typed path ===\n";
$r = resolve(['uploaded_backup_path' => '', 'server_backup_path' => $typed]);
check('A path entered by hand still works', $r['ok'] && $r['path'] === $typed,
    $r['ok'] ? basename($r['path']) : $r['error']);

// ── The new field earns no trust the old one had to prove ──────────────────
// It is written by our own JavaScript, which means nothing: it arrives in a
// request like everything else, and a request can say anything.
echo "\n=== the uploaded path is validated, not trusted ===\n";
$outside = '/etc/passwd.zip';
$r = resolve(['uploaded_backup_path' => $outside, 'server_backup_path' => '']);
check('A path outside the uploads directory is refused', !$r['ok'],
    $r['ok'] ? 'ACCEPTED ' . $r['path'] : $r['error']);

$not_zip = $storage . '/not-a-zip.txt';
file_put_contents($not_zip, 'x');
$r = resolve(['uploaded_backup_path' => $not_zip, 'server_backup_path' => '']);
check('A path that is not a zip is refused', !$r['ok'],
    $r['ok'] ? 'ACCEPTED' : $r['error']);
@unlink($not_zip);

$missing = $storage . '/restore-upload-gone-forever.zip';
$r = resolve(['uploaded_backup_path' => $missing, 'server_backup_path' => '']);
check('A path whose file has been deleted is refused, not read', !$r['ok'],
    $r['ok'] ? 'ACCEPTED' : $r['error']);

// ── The interface the browser relies on ────────────────────────────────────
echo "\n=== the admin page ===\n";
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$admins = get_users(['role' => 'administrator', 'number' => 1]);
if ($admins) { wp_set_current_user($admins[0]->ID); }
$_GET['page'] = 'restorepilot-backup-migration';
ob_start();
priv('render_admin_page');
$html = ob_get_clean();

check('The uploaded path has a hidden field of its own',
    strpos($html, 'id="rp-restore-uploaded-path"') !== false);
check('The visible Server backup path box is still there for typed paths',
    strpos($html, 'id="rp_server_backup_path"') !== false);
check('The new admin password can be revealed',
    strpos($html, 'id="rp-new-admin-password-toggle"') !== false);

$d = strpos($html, 'id="rp-panel-danger"');
$l = strpos($html, 'id="rp-panel-logs"');
$button = strpos($html, 'id="rp-master-reset-open"');
$modal  = strpos($html, 'id="rp-master-reset-modal"');
check('Master Reset has its own tab', strpos($html, 'rp-nav-tab--danger') !== false);
check('Its button lives in that tab', $d !== false && $button > $d && $button < $l);
// A modal left behind in another panel is inside a hidden container, so the
// button would open something that can never be seen.
check('And so does its modal, or it would open into a hidden panel',
    $d !== false && $modal > $d && $modal < $l);
check('Every gate in front of it is still there',
    strpos($html, 'id="rp-master-reset-ack"') !== false
    && strpos($html, 'id="rp-master-reset-confirm-input"') !== false
    && strpos($html, 'placeholder="RESET"') !== false);
check('Removing backups and must-use plugins are still opt-in',
    strpos($html, 'id="rp-master-reset-purge-backups"') !== false
    && strpos($html, 'id="rp-master-reset-purge-mu"') !== false);

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
