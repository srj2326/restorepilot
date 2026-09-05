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
 * Whether a backup can be fetched by anyone who asks.
 *
 * The plugin wrote an .htaccess into its storage directory and treated the
 * matter as settled. It is not settled: nginx does not read .htaccess, and
 * nginx is what most managed WordPress hosting runs. Demonstrated on this very
 * test site, which runs nginx 1.26.1 -- a file placed in the backup directory
 * came back over plain HTTP with no session at all, HTTP 200, contents intact,
 * with the .htaccess sitting beside it doing nothing.
 *
 * A backup archive is the entire database: every account, every password hash,
 * the site's salts, and whatever plugins keep in wp_options. Its filename had
 * about 48 bits of entropy, which is not nothing and is also not an access
 * control -- URLs reach logs, referrers, browser history and screenshots.
 *
 * So the archives move somewhere this site has no URL for. These checks are
 * about the property that matters -- a request cannot fetch one -- and about
 * the move never being the thing that loses a backup.
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
function priv($name, $args = []) {
    $m = new ReflectionMethod('RestorePilot_Backup_Migration', $name);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
}
function konst(string $name) {
    return (new ReflectionClass('RestorePilot_Backup_Migration'))->getConstant($name);
}

/** Ask the web server directly, with no cookies and no session. */
function fetch(string $url): array {
    $r = wp_remote_get($url, ['timeout' => 10, 'sslverify' => false, 'cookies' => []]);
    if (is_wp_error($r)) { return ['code' => 0, 'body' => '', 'error' => $r->get_error_message()]; }
    return ['code' => (int) wp_remote_retrieve_response_code($r), 'body' => (string) wp_remote_retrieve_body($r), 'error' => ''];
}

$public_dir = priv('public_storage_dir');
$uploads    = wp_upload_dir(null, false);
$public_url = trailingslashit($uploads['baseurl']) . 'restorepilot-backup-migration';

// Start from the state a site upgrading into this release is in.
$original = get_option(konst('STORAGE_PATH_OPTION'), '');
delete_option(konst('STORAGE_PATH_OPTION'));
delete_transient(konst('STORAGE_EXPOSURE_TRANSIENT'));
priv('ensure_storage');

register_shutdown_function(function () use ($original) {
    if ($original !== '') { update_option(konst('STORAGE_PATH_OPTION'), $original, false); }
});

// ── The exposure this exists to close, shown rather than argued ────────────
echo "=== before: is the uploads location actually served? ===\n";
$token = 'canary-' . wp_generate_password(24, false, false);
@file_put_contents($public_dir . '/backups/exposure-probe.txt', $token);
$before = fetch($public_url . '/backups/exposure-probe.txt');
@unlink($public_dir . '/backups/exposure-probe.txt');
printf("  HTTP %d from %s\n", $before['code'], $public_url . '/backups/');
$was_exposed = ($before['code'] === 200 && strpos($before['body'], $token) !== false);
echo '  ' . ($was_exposed
    ? "this server serves that directory -- which is the whole problem\n"
    : "this server refuses it, so the move is belt and braces here\n");

check('The plugin can tell whether its storage is reachable',
    priv('storage_is_web_readable', [true]) === $was_exposed,
    'it must not report safe when a request can fetch a file');

// ── The move ───────────────────────────────────────────────────────────────
echo "\n=== moving the archives somewhere this site has no URL for ===\n";
$private = priv('private_storage_root');
check('A private location outside the site is available here', $private !== '', $private ?: 'none found');

// Something worth losing, so the move is proven on real content rather than on
// an empty directory.
$sentinel_name = 'sentinel-' . wp_generate_password(8, false, false) . '.zip';
$sentinel_body = str_repeat('BACKUP-CONTENT-', 500);
@file_put_contents($public_dir . '/backups/' . $sentinel_name, $sentinel_body);

$result = priv('migrate_storage_to_private');
printf("  moved %d file(s) to %s\n", $result['moved'], $result['to'] ?: '(nowhere)');
check('The move reported no failures', empty($result['failed']),
    $result['failed'] ? implode(', ', array_slice($result['failed'], 0, 3)) : '');

$storage_now = priv('storage_dir');
check('Backups are now kept outside the WordPress directory',
    strpos(trailingslashit($storage_now), trailingslashit(untrailingslashit(ABSPATH))) !== 0,
    $storage_now);

// ── Nothing was lost on the way ────────────────────────────────────────────
echo "\n=== the archive that was there is still there ===\n";
$moved_to = $storage_now . '/backups/' . $sentinel_name;
check('The file that existed before the move still exists after it', is_file($moved_to), $moved_to);
check('And its contents are unchanged',
    is_file($moved_to) && file_get_contents($moved_to) === $sentinel_body);
check('It is no longer left behind in the served directory',
    !is_file($public_dir . '/backups/' . $sentinel_name));

// ── And it can no longer be fetched ────────────────────────────────────────
echo "\n=== after: asking the web server for it again ===\n";
$after = fetch($public_url . '/backups/' . $sentinel_name);
printf("  HTTP %d\n", $after['code']);
check('THE FIX: a request for that backup no longer returns it',
    $after['code'] !== 200 || strpos($after['body'], 'BACKUP-CONTENT-') === false,
    $after['code'] === 200 ? 'it was served' : 'refused');

// The plugin must now agree that it is not reachable.
delete_transient(konst('STORAGE_EXPOSURE_TRANSIENT'));
check('And the plugin reports the storage as not reachable',
    priv('storage_is_web_readable', [true]) !== true);

// ── A failed move must never switch over ───────────────────────────────────
// The ordering is the safety property: copy, verify, switch, only then delete.
// If it switched first, a move that failed part way would leave archives
// somewhere nothing looks for them.
echo "\n=== a move that cannot finish leaves everything findable ===\n";
$src = file_get_contents(dirname(__DIR__) . '/includes/trait-storage.php');
$switch_at = strpos($src, "update_option(self::STORAGE_PATH_OPTION, \$private, false);\n    \$result['to'] = \$private;");
$delete_at = strpos($src, 'foreach (array_reverse($files) as $item) {');
$bail_at   = strpos($src, "if (\$result['failed']) {");
check('It refuses to switch when any file failed to copy',
    $bail_at !== false && $switch_at !== false && $bail_at < $switch_at);
check('It deletes the originals only after switching',
    $switch_at !== false && $delete_at !== false && $switch_at < $delete_at);

@unlink($moved_to);

// ── The probe has to test the directory it wrote to ────────────────────────
// It wrote its canary into backup_dir() and then always asked for the uploads
// URL. Once storage moved those were different places, so it requested a file
// it had not written, got the 404 it was always going to get, and reported
// "not reachable" without having tested anything. Correct for the default
// private location, and dangerously wrong for a RESTOREPILOT_STORAGE_DIR that
// happens to sit inside the web root -- the one case the answer matters for.
echo "\n=== the probe asks about the directory it actually wrote to ===\n";

$uploads_dir = trailingslashit($uploads['basedir']) . 'restorepilot-backup-migration';
check('A path under uploads maps to its uploads URL',
    priv('public_url_for_path', [$uploads_dir]) === trailingslashit($uploads['baseurl']) . 'restorepilot-backup-migration',
    priv('public_url_for_path', [$uploads_dir]));

check('A path elsewhere under WordPress maps to a site URL',
    strpos(priv('public_url_for_path', [ABSPATH . 'wp-admin']), site_url()) === 0);

check('And a path outside the site maps to nothing',
    priv('public_url_for_path', [priv('private_storage_root')]) === '',
    'no URL means no request can reach it, which is a stronger answer than a 404');

check('The probe builds its URL from the file it wrote',
    strpos(file_get_contents(dirname(__DIR__) . '/includes/trait-storage.php'),
        '$url = self::public_url_for_path($path);') !== false);

// The case the old code could not see: storage deliberately placed somewhere
// the web server does serve. An operator pointing RESTOREPILOT_STORAGE_DIR at
// a directory inside wp-content must be told it is reachable, not reassured.
echo "\n=== storage placed somewhere the web server does serve ===\n";
$exposed = trailingslashit($uploads['basedir']) . 'rp-exposed-storage-test';
@mkdir($exposed . '/backups', 0755, true);
$restore_option = (string) get_option(konst('STORAGE_PATH_OPTION'), '');
update_option(konst('STORAGE_PATH_OPTION'), $exposed, false);
delete_transient(konst('STORAGE_EXPOSURE_TRANSIENT'));

$verdict = priv('storage_is_web_readable', [true]);
check('THE FIX: it is reported as reachable rather than assumed safe',
    $verdict === $was_exposed,
    'probe says ' . var_export($verdict, true) . '; this server serves that directory: ' . var_export($was_exposed, true));

foreach (glob($exposed . '/backups/*') ?: [] as $f) { @unlink($f); }
@rmdir($exposed . '/backups');
@rmdir($exposed);
if ($restore_option !== '') { update_option(konst('STORAGE_PATH_OPTION'), $restore_option, false); }
else { delete_option(konst('STORAGE_PATH_OPTION')); }
delete_transient(konst('STORAGE_EXPOSURE_TRANSIENT'));

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
