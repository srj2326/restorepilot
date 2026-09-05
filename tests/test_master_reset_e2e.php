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
 * Master Reset, run for real, once.
 *
 * Everything else that covers Master Reset calls its pieces. The test named
 * after it says so in its own comments -- it "mirrors handle_master_reset()
 * step 4" by calling master_reset_wipe_dir() directly. So when 0.5.8 changed
 * what the "also delete stored backups" box does, the pieces were tested and
 * the wiring between the box and the deletion was not. A handler that never
 * reaches the purge, or reads the wrong field, passes every one of those tests.
 *
 * This runs the actual handler, with the actual POST fields a browser sends.
 * It can only be done once per site, because afterwards there is no site:
 * posts, plugins, themes, users and uploads are gone. That is why it lives
 * behind RP_TEST_DESTRUCTIVE and runs in CI, whose WordPress is built from
 * nothing at the start of every job and thrown away at the end.
 *
 * It is deliberately last. Anything after it would be running against wreckage.
 */

require_once __DIR__ . '/env.php';
rp_test_boot();

if (getenv('RP_TEST_DESTRUCTIVE') !== '1') {
    echo "SKIP  Master Reset end-to-end leaves no site behind.\n";
    echo "      Set RP_TEST_DESTRUCTIVE=1 to run it, and only against a fixture\n";
    echo "      you are willing to lose. CI sets it; a developer machine should not.\n";
    echo "\nSKIPPED\n";
    exit(0);
}

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

global $wpdb;

// ── Put the site into the state an operator would be resetting ─────────────
echo "=== before the reset ===\n";

$private = priv('private_storage_root');
if ($private === '') {
    check('A private storage location is available on this host', false,
        'without one this cannot test the case that was broken');
    echo "\n1 FAILURE(S)\n";
    exit(1);
}

wp_cache_flush();
update_option(konst('STORAGE_PATH_OPTION'), $private, false);
wp_cache_flush();
priv('ensure_storage');

$storage = priv('storage_dir');
$backup  = $storage . '/backups/customer-backup.zip';
@mkdir(dirname($backup), 0755, true);
file_put_contents($backup, 'PRETEND CUSTOMER BACKUP');

// Something beside it that is emphatically not ours.
$bystander = dirname($private) . '/not-restorepilot-data';
@mkdir($bystander, 0755, true);
file_put_contents($bystander . '/keep.txt', 'NOT OURS');

// And proof the reset really ran, rather than failing early and leaving
// everything -- including the backups -- untouched.
$post_id = wp_insert_post(['post_title' => 'Doomed', 'post_status' => 'publish', 'post_type' => 'post']);

printf("  storage        : %s\n", $storage);
printf("  backup planted : %s\n", is_file($backup) ? 'yes' : 'no');
printf("  bystander      : %s\n", is_file($bystander . '/keep.txt') ? 'yes' : 'no');
printf("  post to delete : %d\n", (int) $post_id);

check('The backup is in place before the reset', is_file($backup));
check('The storage really is outside the uploads directory',
    strpos(trailingslashit($storage), trailingslashit(wp_upload_dir(null, false)['basedir'])) !== 0,
    $storage);

// ── Run the handler exactly as the browser calls it ────────────────────────
echo "\n=== running handle_master_reset() with \"also delete stored backups\" ticked ===\n";

// A child process: the handler ends in wp_send_json_success(), which calls
// wp_die() and takes the process with it.
$runner = sys_get_temp_dir() . '/rp-master-reset-e2e-' . getmypid() . '.php';
file_put_contents($runner, "<?php\n"
    . "require_once " . var_export(__DIR__ . '/env.php', true) . ";\n"
    . "rp_test_boot();\n"
    . "\$admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID']);\n"
    . "if (!\$admins) { fwrite(STDERR, 'no administrator'); exit(2); }\n"
    . "wp_set_current_user(\$admins[0]->ID);\n"
    . "\$_POST['confirm_word']   = 'RESET';\n"
    . "\$_POST['purge_backups']  = '1';\n"
    . "\$_POST['acknowledged']   = '1';\n"
    . "\$_REQUEST['_ajax_nonce'] = wp_create_nonce(RestorePilot_Backup_Migration::NONCE);\n"
    . "\$_POST['_ajax_nonce']    = \$_REQUEST['_ajax_nonce'];\n"
    . "RestorePilot_Backup_Migration::handle_master_reset();\n");
register_shutdown_function(function () use ($runner) { @unlink($runner); });

$out = [];
$code = 0;
exec(rp_test_php_command($runner) . ' 2>&1', $out, $code);
$response = trim(implode("\n", $out));
printf("  handler said: %s\n", substr($response, 0, 220));

check('The handler ran and reported success',
    strpos($response, '"success":true') !== false,
    $response === '' ? 'no output at all' : substr($response, 0, 220));

// ── What it actually did ───────────────────────────────────────────────────
echo "\n=== after the reset ===\n";
clearstatcache();
wp_cache_flush();

check('THE FIX: the migrated backup is gone', !is_file($backup),
    is_file($backup) ? 'still on disk: ' . $backup : 'deleted');

check('And its storage directory went with it', !is_dir($private), $private);

check('THE LIMIT: the directory beside it is untouched',
    is_file($bystander . '/keep.txt'),
    'a reset that reaches outside its own storage would be far worse than one that misses');

// If the reset had failed early, the backup would also still be there -- for
// the wrong reason. This distinguishes "purged" from "never got that far".
$posts_left = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = " . (int) $post_id);
check('The reset genuinely ran, rather than failing before the purge',
    $posts_left === 0,
    'the post it was asked to remove is ' . ($posts_left === 0 ? 'gone' : 'still there'));

// Clean up the bystander; the site itself is now wreckage by design.
@unlink($bystander . '/keep.txt');
@rmdir($bystander);

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
