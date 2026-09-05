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
 * Renders the real admin screen to a standalone page, so its dialogs can be
 * driven by a keyboard.
 *
 * RP-009 is about behaviour that only exists in a browser: where focus goes
 * when a dialog opens, whether Tab can leave it, where focus lands when it
 * closes. None of that can be checked by reading the source, and checking it
 * on the live admin screen would mean logging a browser in.
 *
 * So the markup is taken from the plugin itself -- render_admin_page() and
 * render_restore_success_dialog(), the same methods WordPress calls -- rather
 * than hand-copied into a fixture that would drift from it. The plugin's own
 * stylesheet and admin.js are loaded alongside, because the focus logic asks
 * whether elements are visible, and that question has no answer without CSS.
 *
 * Usage: build_dialog_harness.php <output-directory>
 */

define('WP_USE_THEMES', false);
require_once '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public/wp-load.php';

$out = isset($argv[1]) ? rtrim($argv[1], '/') : '';
if ($out === '') { fwrite(STDERR, "usage: build_dialog_harness.php <output-directory>\n"); exit(1); }
if (!is_dir($out) && !mkdir($out, 0755, true)) { fwrite(STDERR, "cannot create $out\n"); exit(1); }

// Whichever account actually administers this site. User 1 is not a safe
// assumption here: the suite creates and deletes users, and on this fixture
// user 1 has no roles at all -- which renders a permission notice instead of
// the screen, and a harness with no dialogs in it.
$admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID']);
if (!$admins) { fwrite(STDERR, "no administrator on the fixture site\n"); exit(1); }
wp_set_current_user($admins[0]->ID);

// The renderer uses admin-side helpers, which wp-load does not pull in.
require_once ABSPATH . 'wp-admin/includes/admin.php';
set_current_screen('toplevel_page_restorepilot-backup-migration');

$root = dirname(__DIR__);

// The success dialog only renders when a restore has just finished, and it
// clears the option as it renders. Give it something to render.
$opt = (new ReflectionClass('RestorePilot_Backup_Migration'))->getConstant('RESTORE_SUCCESS_OPTION');
update_option($opt, [
    // 'created' is what get_restore_success_notice() tests for; without it the
    // notice is treated as absent and the dialog renders nothing at all.
    'created'    => time(),
    'source_url' => 'https://example-source.test',
    'target_url' => home_url(),
], false);

// That getter memoises into a static, and render_admin_page() below reaches it
// first, so the cached "no notice" would outlive the option we just wrote.
$memo = new ReflectionProperty('RestorePilot_Backup_Migration', 'restore_success_notice');
$memo->setAccessible(true);
$memo->setValue(null, null);

ob_start();
RestorePilot_Backup_Migration::render_admin_page();
$page = ob_get_clean();

ob_start();
RestorePilot_Backup_Migration::render_restore_success_dialog();
$success = ob_get_clean();

copy($root . '/assets/css/admin.css', $out . '/admin.css');
copy($root . '/assets/js/admin.js',   $out . '/admin.js');

// admin.js reads a localized object; without it the first handler that touches
// it throws and every later IIFE in the file never runs.
$data = wp_json_encode([
    'ajaxUrl' => admin_url('admin-ajax.php'),
    'nonce'   => 'harness',
    'i18n'    => ['restoreRunning' => 'Restoring', 'backupRunning' => 'Backing up'],
]);

$html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
      . '<title>RestorePilot dialog harness</title>'
      . '<link rel="stylesheet" href="admin.css">'
      // Enough of wp-admin for the layout to behave; the dialogs bring their own.
      . '<style>body{margin:0;font:13px -apple-system,sans-serif;background:#f0f0f1}'
      . '.wrap{padding:20px}.button{padding:4px 10px;border:1px solid #2271b1;background:#f6f7f7;cursor:pointer}'
      . '.button-primary{background:#2271b1;color:#fff}.nav-tab{display:inline-block;padding:8px 12px;border:1px solid #c3c4c7;text-decoration:none}'
      . '.screen-reader-text{position:absolute;left:-9999px}</style>'
      . '</head><body>'
      . '<a href="#" id="harness-before">a link before the app</a>'
      . '<div id="wpwrap">' . $page . '</div>'
      . $success
      . '<a href="#" id="harness-after">a link after the app</a>'
      . '<script>window.restorePilotData = ' . $data . ';</script>'
      . '<script src="admin.js"></script>'
      . '</body></html>';

file_put_contents($out . '/index.html', $html);

// Report what actually made it in, so a harness that silently lost a dialog
// cannot be mistaken for dialogs that behave.
$ids = ['rp-restore-existing-modal', 'rp-restore-confirm-modal', 'rp-master-reset-modal', 'rp-restore-success-dialog'];
$missing = [];
foreach ($ids as $id) {
    if (strpos($html, 'id="' . $id . '"') === false) { $missing[] = $id; }
}
printf("harness written to %s (%d KB)\n", $out . '/index.html', strlen($html) >> 10);
if ($missing) {
    fwrite(STDERR, "MISSING from harness: " . implode(', ', $missing) . "\n");
    exit(1);
}
echo "all four dialogs present\n";
exit(0);
