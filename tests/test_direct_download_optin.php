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
 * A download link that is checked, rather than deleted later and hoped about.
 *
 * RP-040. Large archives were handed to the web server as a static file at a
 * secret URL, and that URL's "six hour expiry" was a WP-Cron job deleting the
 * file. Static files are served without WordPress running, so nothing checked
 * anything at request time: where cron is disabled or the site is quiet, the
 * address kept working. It downloads the entire database -- accounts, password
 * hashes, salts -- to anyone holding the URL from browser history, a pasted
 * link, or a proxy log.
 *
 * Streaming through WordPress is the default now, because that is checked on
 * every request and stops working exactly when it should. The fast path is
 * still reachable for hosts that kill long-running PHP, but only when an
 * administrator turns it on deliberately.
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

$root = dirname(__DIR__);
$storage_src = file_get_contents($root . '/includes/trait-storage.php');

// ── Off unless asked for ───────────────────────────────────────────────────
echo "=== the static direct link is off by default ===\n";

check('With nothing configured, direct downloads are disabled',
    priv('direct_downloads_enabled') === false,
    'a fresh install streams every download');

// The constant has to be read in a process that actually defines it.
$probe = sys_get_temp_dir() . '/rp-direct-probe-' . getmypid() . '.php';
register_shutdown_function(function () use ($probe) { @unlink($probe); });
file_put_contents($probe, "<?php\n"
    . "if (\$argv[1] === 'on')    { define('RESTOREPILOT_DIRECT_DOWNLOADS', true); }\n"
    . "if (\$argv[1] === 'truthy'){ define('RESTOREPILOT_DIRECT_DOWNLOADS', 1); }\n"
    . "if (\$argv[1] === 'off')   { define('RESTOREPILOT_DIRECT_DOWNLOADS', false); }\n"
    . "require_once " . var_export(__DIR__ . '/env.php', true) . ";\n"
    . "rp_test_boot();\n"
    . "\$m = new ReflectionMethod('RestorePilot_Backup_Migration', 'direct_downloads_enabled');\n"
    . "\$m->setAccessible(true);\n"
    . "echo \$m->invoke(null) ? 'ENABLED' : 'DISABLED';\n");

function probe(string $probe, string $mode): string {
    $out = [];
    exec(rp_test_php_command($probe, escapeshellarg($mode)) . ' 2>&1', $out);
    return trim(implode('', $out));
}

check('Defining the constant true turns it on',
    probe($probe, 'on') === 'ENABLED');
check('Defining it false leaves it off',
    probe($probe, 'off') === 'DISABLED');
check('And a merely truthy value does not count as consent',
    probe($probe, 'truthy') === 'DISABLED',
    'an explicit true is required, so a stray 1 in wp-config cannot expose backups');

// ── The download path honours it ───────────────────────────────────────────
echo "\n=== and the download path asks before taking the shortcut ===\n";

check('The redirect to a static file is gated on the setting',
    preg_match('/if \(self::direct_downloads_enabled\(\)\s*\n\s*&& \$action !== \'restorepilot_download_stream\'/', $storage_src) === 1);

// The streamed path is what everyone gets now, so its resumability is no
// longer a nicety: it is the reason turning the shortcut off is affordable.
check('The streamed download still honours Range requests',
    strpos($storage_src, "!empty(\$_SERVER['HTTP_RANGE'])") !== false
    && strpos($storage_src, "header('Accept-Ranges: bytes');") !== false
    && strpos($storage_src, '$status = 206;') !== false,
    'an interrupted transfer can be resumed rather than restarted');

// ── The documentation says what changed and how to change it back ──────────
echo "\n=== and it is written down where an operator will look ===\n";
$readme = file_get_contents($root . '/readme.txt');
check('The readme explains the slower large download',
    stripos($readme, 'Why is a large download slower') !== false);
check('It names the constant that restores the old behaviour',
    strpos($readme, "define('RESTOREPILOT_DIRECT_DOWNLOADS', true);") !== false);
check('And it states the trade-off rather than just the recipe',
    stripos($readme, 'trades the confidentiality') !== false);

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
