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
 * Every dialog goes through the one focus controller.
 *
 * RP-009. Four dialogs had four different ideas of what a modal does. Two
 * closed on Escape and two did not; one set initial focus and three did not;
 * none contained Tab, gave focus back to the control that opened them, or
 * stopped the page behind from being reached -- while all four declared
 * aria-modal="true", which says the opposite.
 *
 * The behaviour itself only exists in a browser, so it was checked in one,
 * against the plugin's own rendered markup (see build_dialog_harness.php).
 * Driving real key presses rather than synthetic events, each of the four:
 *
 *   - took initial focus inside itself, skipping controls disabled until an
 *     acknowledgement is given;
 *   - held Tab and Shift+Tab, wrapping at both ends, with nothing outside ever
 *     receiving focus;
 *   - made the rest of the page inert while open and released it on close,
 *     leaving no marked elements behind;
 *   - returned focus to the exact control that opened it, via Escape and via a
 *     backdrop click;
 *   - and in the restore confirmation, kept the acknowledgement reset that
 *     Cancel already performed, so Escape cannot leave a half-confirmed
 *     destructive action armed.
 *
 * The restore confirmation also reveals three more fields when "create a new
 * administrator" is ticked. All three enter the tab order while the dialog is
 * open, which is why the controller recomputes what is focusable on every Tab
 * instead of caching it when the dialog opens.
 *
 * What this file can check without a browser is that the wiring stays in place:
 * that each dialog is attached to the shared controller, that opening and
 * closing drive it, and that the ad-hoc Escape and backdrop handlers it
 * replaced have not come back to fire twice. It also builds the harness, so a
 * change that stops the dialogs rendering is caught here rather than the next
 * time someone opens a browser.
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

$root = dirname(__DIR__);
$js   = file_get_contents($root . '/assets/js/admin.js');
$ui   = file_get_contents($root . '/includes/trait-admin-ui.php');

// ── The controller exists and is used by every dialog ──────────────────────
echo "=== one controller, four dialogs ===\n";

check('The shared controller is defined before anything uses it',
    strpos($js, 'window.RestorePilotDialog = (function () {') !== false
    && strpos($js, 'window.RestorePilotDialog = (function () {') < strpos($js, 'RestorePilotDialog.attach'));

$attach_count = substr_count($js, 'RestorePilotDialog.attach(');
check('Every one of the four dialogs is attached to it', $attach_count === 4,
    "found $attach_count attach() call(s)");

// Each dialog declares aria-modal, which is a promise about focus. Any dialog
// added later has to make the same promise good, so the count is pinned to the
// markup rather than written down here.
preg_match_all('/aria-modal="true"/', $ui, $m);
check('And there are exactly that many dialogs claiming to be modal',
    count($m[0]) === $attach_count,
    sprintf('%d aria-modal dialogs, %d attached', count($m[0]), $attach_count));

check('Opening and closing drive the controller',
    substr_count($js, '.activate();') >= 4 && substr_count($js, '.deactivate();') >= 4,
    sprintf('%d activate, %d deactivate', substr_count($js, '.activate();'), substr_count($js, '.deactivate();')));

// ── The handlers it replaced are gone ──────────────────────────────────────
echo "\n=== and the per-dialog handlers it replaced ===\n";

// Two dialogs each had their own document-level Escape listener. Left in place
// alongside the controller they would close twice -- harmless for Escape, but
// the second close would run against an already-deactivated controller and
// move focus a second time.
check('No dialog keeps its own document-level Escape listener',
    !preg_match('/addEventListener\(\s*[\'"]keydown[\'"].*\n.*Escape.*classList\.contains\([\'"]is-active[\'"]\)/', $js),
    'Escape is handled once, by the controller');

// The backdrop listeners are now created inside attach(), so the only
// remaining "click on the backdrop itself" comparisons should be that one.
$backdrop_checks = preg_match_all('/e(?:vent)?\.target === (?:modal|restoreConfirmModal|el)\b/', $js, $bm);
check('Backdrop dismissal is wired in one place',
    $backdrop_checks === 1,
    $backdrop_checks === 1 ? 'inside attach()' : implode(', ', $bm[0]));

// ── The safety semantics are untouched ─────────────────────────────────────
echo "\n=== the confirmations themselves are unchanged ===\n";

check('Escape on the restore confirmation still clears the acknowledgement',
    preg_match('/onRequestClose: function \(\) \{\s*\n\s*resetRestoreConfirmModal\(\);\s*\n\s*closeRestoreConfirmModal\(\);/', $js) === 1,
    'closing by any route must leave it needing confirmation again');

check('Master Reset still requires the typed word and the acknowledgement',
    strpos($js, "var typedOk = input && input.value === 'RESET';") !== false
    && strpos($js, 'var ackOk   = !ackBox || ackBox.checked;') !== false);

check('The restore-from-existing acknowledgement still gates its submit',
    strpos($js, 'if (submitBtn) submitBtn.disabled = !(ackBox && ackBox.checked);') !== false);

// ── The harness still builds, with all four dialogs in it ──────────────────
echo "\n=== the browser harness still has something to test ===\n";

$tmp = sys_get_temp_dir() . '/rp-dialog-harness-' . getmypid();
// The child needs the same socket this process was given, or it cannot reach
// the database -- and WordPress answers that by rendering an error page and
// exiting 0, so an exit code alone would call that a pass. It did, until the
// file check below was made the real assertion.
$sock = ini_get('mysqli.default_socket');
$cmd = escapeshellarg(PHP_BINARY)
     . ' -d ' . escapeshellarg('mysqli.default_socket=' . $sock)
     . ' -d ' . escapeshellarg('pdo_mysql.default_socket=' . $sock)
     . ' -d memory_limit=1024M '
     . escapeshellarg(__DIR__ . '/build_dialog_harness.php')
     . ' ' . escapeshellarg($tmp) . ' 2>&1';
$out = [];
$code = 0;
exec($cmd, $out, $code);
$built = is_file($tmp . '/index.html');
check('build_dialog_harness.php renders all four dialogs', $code === 0 && $built,
    $built ? implode(' | ', array_slice($out, -1)) : 'no harness written: ' . implode(' | ', array_slice($out, -2)));

if ($built) {
    $html = file_get_contents($tmp . '/index.html');
    foreach (['rp-restore-existing-modal', 'rp-restore-confirm-modal',
              'rp-master-reset-modal', 'rp-restore-success-dialog'] as $id) {
        check("  harness contains $id", strpos($html, 'id="' . $id . '"') !== false);
    }
    array_map('unlink', glob($tmp . '/*') ?: []);
    @rmdir($tmp);
}

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
