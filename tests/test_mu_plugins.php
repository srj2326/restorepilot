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
 * Must-use plugins survived Master Reset entirely, so a site it described as
 * "a clean WordPress installation" still had every one of them loading on
 * every request.
 *
 * They are removable now, but only on request. Some are put there by the host
 * -- auto-updates, preview domains -- or by a management service, and taking
 * those out can break things the person doing the reset cannot reinstall. The
 * checks that matter most here are therefore about the default: that nothing
 * goes unless it was actually asked for, and that the operator can see what
 * they are agreeing to.
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
function call_private($name, $args = []) {
    $m = new ReflectionMethod('RestorePilot_Backup_Migration', $name);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
}

$mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
$made_dir = false;
if (!is_dir($mu_dir)) { mkdir($mu_dir, 0755, true); $made_dir = true; }

// This test calls the real wipe, which empties the real directory. The site's
// own must-use plugins live there -- the host's auto-updates and preview
// domain among them -- so they are copied out first and put back at the end.
// A test that destroys what it was only meant to inspect is not a test worth
// having.
$safe_dir = sys_get_temp_dir() . '/rp-mu-safe-' . getmypid();
mkdir($safe_dir, 0755, true);
$preserved = [];
foreach (new DirectoryIterator($mu_dir) as $item) {
    if ($item->isDot()) { continue; }
    $name = $item->getFilename();
    if ($item->isDir()) {
        // Directories are copied recursively so nothing real is lost.
        $dst = $safe_dir . '/' . $name;
        mkdir($dst, 0755, true);
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($item->getPathname(), FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $sub) {
            $rel = substr($sub->getPathname(), strlen($item->getPathname()) + 1);
            if ($sub->isDir()) { @mkdir($dst . '/' . $rel, 0755, true); }
            else { @copy($sub->getPathname(), $dst . '/' . $rel); }
        }
    } else {
        @copy($item->getPathname(), $safe_dir . '/' . $name);
    }
    $preserved[] = $name;
}
echo 'Preserved ' . count($preserved) . " existing must-use plugin(s) before testing: "
   . implode(', ', $preserved) . "\n\n";

// Put them back no matter how this script ends.
register_shutdown_function(function () use ($mu_dir, $safe_dir) {
    if (!is_dir($safe_dir)) { return; }
    foreach (new DirectoryIterator($safe_dir) as $item) {
        if ($item->isDot()) { continue; }
        $dst = $mu_dir . '/' . $item->getFilename();
        if (file_exists($dst)) { continue; }
        if ($item->isDir()) {
            @mkdir($dst, 0755, true);
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($item->getPathname(), FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST);
            foreach ($it as $sub) {
                $rel = substr($sub->getPathname(), strlen($item->getPathname()) + 1);
                if ($sub->isDir()) { @mkdir($dst . '/' . $rel, 0755, true); }
                else { @copy($sub->getPathname(), $dst . '/' . $rel); }
            }
        } else {
            @copy($item->getPathname(), $dst);
        }
    }
    echo "  (existing must-use plugins restored)\n";
});

// Fixtures shaped like the real thing: a host's, a service's, and the site
// owner's own -- plus WordPress's own index.php guard, which is not anybody's
// plugin and must never be counted or removed.
$fixtures = [
    'rp-test-host-thing.php'    => "<?php // pretend host plugin\n",
    'rp-test-service-loader.php' => "<?php // pretend management service\n",
    'rp-test-mine.php'          => "<?php // the site owner's own\n",
];
foreach ($fixtures as $name => $body) { file_put_contents($mu_dir . '/' . $name, $body); }
$index_existed = is_file($mu_dir . '/index.php');
if (!$index_existed) { file_put_contents($mu_dir . '/index.php', "<?php\n// Silence is golden.\n"); }

// --- Listing ---------------------------------------------------------------
$entries = call_private('mu_plugin_entries');
echo 'Found: ' . implode(', ', $entries) . "\n\n";

foreach (array_keys($fixtures) as $name) {
    check("Lists $name", in_array($name, $entries, true));
}
check("Does NOT list WordPress's own index.php guard", !in_array('index.php', $entries, true));
check('Listing is sorted, so the confirmation reads predictably',
    $entries === array_values($entries) && $entries === (function ($e) { sort($e); return $e; })($entries));

// --- THE DEFAULT: nothing goes unless asked ---------------------------------
// Master Reset's file-wiping helper is given the uploads directory; it must
// not wander into mu-plugins on its own.
$before = call_private('mu_plugin_entries');
$upload = wp_upload_dir();
call_private('master_reset_wipe_dir', [$upload['basedir'], call_private('content_dir'), false]);
$after_default = call_private('mu_plugin_entries');
check('THE DEFAULT: a reset that was not asked to remove them leaves every one alone',
    $after_default === $before,
    sprintf('%d before, %d after', count($before), count($after_default)));

// --- On request: they go ----------------------------------------------------
$result = call_private('master_reset_wipe_mu_plugins');
$removed = (int) $result['removed'];
$after_purge = call_private('mu_plugin_entries');
check('When asked, they are removed', empty($after_purge),
    $after_purge ? 'left behind: ' . implode(', ', $after_purge) : sprintf('%d removed', $removed));
check('It reports how many went, so the result can say so', $removed >= count($fixtures));

// It used to return a count and nothing else, so anything that would not
// delete was invisible and the reset could still call the site clean. A
// must-use plugin loads on every request: one left behind is still running.
check('It also reports what it could NOT remove, not just what it could',
    is_array($result) && array_key_exists('failed', $result) && is_array($result['failed']));
check('Nothing is reported as failed when everything went', $result['failed'] === [],
    $result['failed'] ? implode(', ', $result['failed']) : '');

// A file the filesystem refuses to delete has to be named, not counted.
$stubborn_dir = $mu_dir . '/rp-test-stubborn';
// Writable first, then sealed. Creating it read-only means the file inside it
// cannot be written either, and the fixture tests nothing while emitting
// warnings that look like the failure it is supposed to be staging.
mkdir($stubborn_dir, 0755, true);
file_put_contents($stubborn_dir . '/inner.php', "<?php\n");
chmod($stubborn_dir, 0555);
$hard = call_private('master_reset_wipe_mu_plugins');
$named = in_array('rp-test-stubborn', $hard['failed'], true);
// Running as the owner, macOS may still allow the unlink; only assert the
// contract when the directory genuinely resisted.
if (is_dir($stubborn_dir)) {
    check('An entry that would not delete is named in the failures', $named,
        'failed: ' . implode(', ', $hard['failed']));
} else {
    echo "SKIP  the filesystem allowed the delete, so there was no failure to report\n";
}
chmod($stubborn_dir, 0755);
@chmod($stubborn_dir . '/inner.php', 0644);
@unlink($stubborn_dir . '/inner.php');
@rmdir($stubborn_dir);

// And the reset must surface it rather than reporting a clean installation.
$handler_src = file_get_contents(dirname(__DIR__) . '/includes/trait-request-handlers.php');
check('Master Reset records that failure as a problem with the reset',
    strpos($handler_src, "could not remove must-use plugin(s): ") !== false);
check("WordPress's own index.php guard is left in place", is_file($mu_dir . '/index.php'));

// --- The operator can see what they are agreeing to -------------------------
$src = '';
foreach (glob('/Users/surajitroy/Local Sites/morecalculators-dev/app/public/wp-content/plugins/restorepilot-backup-migration/includes/*.php') as $f) {
    $src .= file_get_contents($f);
}
check('The confirmation lists the actual filenames, not just a count',
    strpos($src, 'implode(\', \', $rp_mu_plugins)') !== false);
check('The confirmation warns that some belong to the host',
    stripos($src, 'installed by your host') !== false);
check('Removal is opt-in, never automatic',
    strpos($src, "if (\$purge_mu) {") !== false);

// Cleanup: leave the site as it was found.
foreach (array_keys($fixtures) as $name) { @unlink($mu_dir . '/' . $name); }
if (!$index_existed) { @unlink($mu_dir . '/index.php'); }
if ($made_dir) { @rmdir($mu_dir); }

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
