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
 * Which WordPress these tests are allowed to destroy.
 *
 * RP-008. Every test in this directory used to name one developer's machine:
 * the same absolute path to a WordPress install, repeated in thirty-nine files,
 * with the PHP binary and MySQL socket hard-coded in the runners beside them.
 * Nobody else could run them, CI could not run them, and the design quietly
 * encouraged pointing tests at whichever site happened to be lying around.
 *
 * That last part is the dangerous one. These are not read-only tests. They
 * restore backups over the database, delete users, reset passwords, truncate
 * tables and run Master Reset. Run against a site someone cared about, they
 * would do exactly what they say on the tin.
 *
 * So the location is configuration now, and it is refused unless the site says
 * it is disposable -- a marker file the owner of that site has to put there by
 * hand. An environment variable alone would not do: it is too easy to export
 * one in the wrong shell, and the cost of being wrong here is someone's site.
 *
 * Resolution order for every setting: environment variable, then
 * tests/config.local.php (untracked), then a sensible default.
 */

/** The marker a site must carry before these tests will touch it. */
const RP_TEST_FIXTURE_MARKER = '.restorepilot-disposable';

function rp_test_config(): array {
    static $cfg = null;
    if ($cfg !== null) { return $cfg; }

    $file  = __DIR__ . '/config.local.php';
    $local = is_file($file) ? (array) require $file : [];

    $get = function (string $key, string $default = '') use ($local): string {
        $env = getenv($key);
        if (is_string($env) && $env !== '') { return $env; }
        if (isset($local[$key]) && is_string($local[$key]) && $local[$key] !== '') {
            return $local[$key];
        }
        return $default;
    };

    $cfg = [
        // The disposable WordPress these tests run against.
        'site'   => rtrim($get('RP_TEST_SITE'), '/'),
        // The working copy under test. Defaults to the plugin this file sits in.
        'plugin' => rtrim($get('RP_PLUGIN_DIR', dirname(__DIR__)), '/'),
        // The interpreter and socket to use when a test shells out to another
        // process; a child that inherits neither cannot reach the database, and
        // WordPress answers that by printing an error page and exiting 0.
        'php'    => $get('RP_TEST_PHP', PHP_BINARY),
        'socket' => $get('RP_TEST_MYSQL_SOCKET', (string) ini_get('mysqli.default_socket')),
    ];
    return $cfg;
}

function rp_test_fail(string $why): void {
    fwrite(STDERR,
        "\n  Test environment not usable: $why\n\n"
      . "  These tests restore databases, delete users and run Master Reset, so\n"
      . "  they only run against a WordPress install that has been marked\n"
      . "  disposable by its owner.\n\n"
      . "  1. Point them at one:\n"
      . "       export RP_TEST_SITE=/path/to/disposable/wordpress\n"
      . "     or copy tests/config.example.php to tests/config.local.php.\n\n"
      . "  2. Mark that install as disposable, from inside it:\n"
      . "       touch \"\$RP_TEST_SITE/" . RP_TEST_FIXTURE_MARKER . "\"\n\n");
    exit(2);
}

/** The fixture path, once it has been checked. */
function rp_test_site(): string {
    static $checked = '';
    if ($checked !== '') { return $checked; }

    $cfg  = rp_test_config();
    $site = $cfg['site'];

    if ($site === '') {
        rp_test_fail('RP_TEST_SITE is not set');
    }
    if (!is_file($site . '/wp-load.php')) {
        rp_test_fail("no wp-load.php in $site");
    }
    if (!is_file($site . '/' . RP_TEST_FIXTURE_MARKER)) {
        rp_test_fail("$site is not marked disposable");
    }
    // A working copy is not a fixture. Restoring an old backup over the plugin
    // being edited would take the edits with it.
    if (strpos(realpath($cfg['plugin']) ?: $cfg['plugin'], realpath($site) ?: $site) === 0) {
        rp_test_fail('the plugin under test lives inside the fixture site; keep them separate');
    }

    $checked = $site;
    return $checked;
}

/** Load the fixture's WordPress. */
function rp_test_boot(): void {
    $site = rp_test_site();
    if (!defined('WP_USE_THEMES')) { define('WP_USE_THEMES', false); }
    require_once $site . '/wp-load.php';
}

/** The plugin working copy under test. */
function rp_test_plugin_dir(): string {
    return rp_test_config()['plugin'];
}

/** Its main file, which several tests load directly. */
function rp_test_plugin_file(): string {
    return rp_test_plugin_dir() . '/restorepilot-backup-migration.php';
}

/** The interpreter a test should shell out to. */
function rp_test_php(): string {
    return rp_test_config()['php'];
}

/** The MySQL socket a child process needs to reach the same database. */
function rp_test_socket(): string {
    return rp_test_config()['socket'];
}

/** Arguments that give a child process the same database the parent has. */
function rp_test_php_command(string $script, string $args = '', string $ini = 'memory_limit=1024M'): string {
    $cfg = rp_test_config();
    return escapeshellarg($cfg['php'])
        . ' -d ' . escapeshellarg('mysqli.default_socket=' . $cfg['socket'])
        . ' -d ' . escapeshellarg('pdo_mysql.default_socket=' . $cfg['socket'])
        . ' -d ' . escapeshellarg($ini) . ' '
        . escapeshellarg($script)
        . ($args === '' ? '' : ' ' . $args);
}
