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
 * The restore admin account is now always the operator's own email and
 * password. Nothing is generated for them to be handed back, so the checks
 * that matter are: the address is honoured, sign-in by email works, and no
 * working credential is ever stored or returned anywhere.
 */

require_once __DIR__ . '/env.php';
rp_test_boot();
require_once ABSPATH . 'wp-admin/includes/user.php';

$pass = 0; $fail = 0; $failures = [];
function check(string $label, bool $ok) {
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "PASS  $label\n"; }
    else { $fail++; $failures[] = $label; echo "FAIL  $label\n"; }
}
function cleanup_user($login) { $u = get_user_by('login', $login); if ($u) { wp_delete_user($u->ID); } }
function cleanup_email($email) { $id = email_exists($email); if ($id) { wp_delete_user($id); } }

$ref = new ReflectionClass('RestorePilot_Backup_Migration');
$create = $ref->getMethod('create_new_admin_login');
$create->setAccessible(true);

cleanup_email('rp-owner@example.test');

// ── The normal case ──
$r = $create->invoke(null, 'rp-owner@example.test');
check('The chosen email is used', ($r['email'] ?? '') === 'rp-owner@example.test');

$u = get_user_by('email', 'rp-owner@example.test');
check('Account exists', (bool) $u);
check('Account is an administrator', $u && in_array('administrator', $u->roles, true));
check('A username was derived, not asked for', !empty($r['username']));
check('Derived username comes from the address', $u && strpos($u->user_login, 'rp-owner') === 0);
check('A user_id is returned for the password step', !empty($r['user_id']));

// ── THE POINT: sign-in is by email ──
$signin = wp_authenticate_email_password(null, 'rp-owner@example.test', $r['password']);
$signin_ok = $signin instanceof WP_User && (int) $signin->ID === (int) $r['user_id'];

// This check has failed twice inside a full suite run and passed every time it
// has been run on its own, including immediately afterwards in the state the
// suite left behind -- so it is not leftover users and not the site's stored
// state. Rather than record "flaky" a third time, the failure now carries
// enough to identify itself: what came back, whether the account is really
// there, and which address it ended up with. An intermittent failure nobody
// can describe is one nobody will fix.
$why = '';
if (!$signin_ok) {
    $found = get_user_by('email', 'rp-owner@example.test');
    $why = sprintf(
        'authenticate returned %s; created user_id=%s; user by that email is %s; its address is %s; %d users on the site',
        is_wp_error($signin) ? ('WP_Error ' . $signin->get_error_code() . ' "' . $signin->get_error_message() . '"')
                             : (is_object($signin) ? get_class($signin) . ' id=' . ($signin->ID ?? '?') : gettype($signin)),
        var_export($r['user_id'] ?? null, true),
        $found ? ('id ' . $found->ID) : 'missing',
        $found ? $found->user_email : 'n/a',
        count(get_users(['fields' => 'ID']))
    );
}
check('WordPress accepts the EMAIL as the sign-in identifier', $signin_ok, $why);

// ── The interim password is a throwaway, replaced by the operator's ──
$before = $u->user_pass;
wp_set_password('OperatorChosen-Passw0rd!', $r['user_id']);
$after = get_user_by('id', $r['user_id'])->user_pass;
check('The operator password replaces the interim one', $before !== $after);
$signin2 = wp_authenticate_email_password(null, 'rp-owner@example.test', 'OperatorChosen-Passw0rd!');
check('Signing in with email + chosen password works', $signin2 instanceof WP_User);

// ── An address already used in the restored site still yields an account ──
$r2 = $create->invoke(null, 'rp-owner@example.test');
check('Taken address does not block account creation', !empty($r2['user_id']));
check('Tagged variant still delivers to the same mailbox',
    strpos($r2['email'] ?? '', 'rp-owner+rp') === 0);

// ── A missing or malformed address never fails the restore ──
$r3 = $create->invoke(null, '');
check('Blank address still creates an account (restore is never left without one)', !empty($r3['user_id']));
check('Blank address produces a valid derived address', (bool) is_email($r3['email'] ?? ''));
$r4 = $create->invoke(null, 'not-an-email');
check('Malformed address still creates an account', !empty($r4['user_id']));
check('Malformed address produces a valid derived address', (bool) is_email($r4['email'] ?? ''));

// ── Nothing anywhere hands back a working credential ──
// Read the whole plugin rather than one named file: the code these checks
// look for lives wherever the current layout puts it, and hardcoding a
// filename makes the test fail on a refactor that changed nothing.
$plugin_dir = rp_test_plugin_dir();
$src = '';
$php_files = array_merge(
    glob($plugin_dir . '/*.php') ?: [],
    glob($plugin_dir . '/includes/*.php') ?: []
);
foreach ($php_files as $f) { $src .= file_get_contents($f) . "\n"; }
$js  = file_get_contents($plugin_dir . '/assets/js/admin.js');

check('No new_admin_credentials anywhere in PHP', strpos($src, 'new_admin_credentials') === false);
check('No credential-display path left in JS', strpos($js, 'new_admin_credentials') === false);
check('perform_restore no longer returns a password', strpos($src, "'new_admin_password' =>") === false);
check('The status response never sends a password',
    strpos($src, "\$response['new_admin_email']") !== false
    && strpos($src, "\$response['new_admin_credentials']") === false);
check('The sync fallback points at password reset instead of printing one',
    strpos($src, 'Lost your password?') !== false);

// Endpoint hardening still in place.
$ep = substr($src, strpos($src, 'public static function handle_set_restore_admin_password'), 4000);
check('Endpoint still requires the restore to be complete', strpos($ep, "!== 'complete'") !== false);
check('Endpoint still consumes the pointer before applying', strpos($ep, "'new_admin_user_id' => 0") < strpos($ep, 'wp_set_password'));
check('Endpoint still compares tokens with hash_equals', strpos($ep, 'hash_equals') !== false);

// Cleanup.
foreach ([$r, $r2, $r3, $r4] as $made) {
    if (!empty($made['user_id'])) { wp_delete_user((int) $made['user_id']); }
}

echo "\n";
if ($fail === 0) { echo "ALL $pass CHECKS PASSED\n"; }
else { echo "$fail FAILURE(S): " . implode('; ', $failures) . "\n"; }
exit($fail === 0 ? 0 : 1);
