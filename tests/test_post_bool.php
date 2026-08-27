<?php
/**
 * post_bool() tested presence, not value, so a hidden field submitting "0"
 * read as true. create_new_admin does exactly that, which meant every restore
 * created an administrator account whether or not one was asked for.
 */

define('WP_USE_THEMES', false);
require_once '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public/wp-load.php';

$pass = 0; $fail = 0; $failures = [];
function check(string $label, bool $ok) {
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "PASS  $label\n"; }
    else { $fail++; $failures[] = $label; echo "FAIL  $label\n"; }
}

$ref = new ReflectionClass('RestorePilot_Backup_Migration');
$m = $ref->getMethod('post_bool');
$m->setAccessible(true);
$post_bool = function (string $key) use ($m) { return $m->invoke(null, $key); };

function with_post(array $post, callable $fn) {
    $saved = $_POST;
    $_POST = $post;
    try { return $fn(); } finally { $_POST = $saved; }
}

// ── The regression that caused the lockout ──
check('THE BUG: "0" is false, not true', with_post(['f' => '0'], function () use ($post_bool) {
    return $post_bool('f') === false;
}));

// ── Everything that must still be true ──
check('"1" is true', with_post(['f' => '1'], function () use ($post_bool) {
    return $post_bool('f') === true;
}));
check('A real checkbox ("on") is true', with_post(['f' => 'on'], function () use ($post_bool) {
    return $post_bool('f') === true;
}));
check('An arbitrary non-empty value is true', with_post(['f' => 'yes-please'], function () use ($post_bool) {
    return $post_bool('f') === true;
}));

// ── Everything a form can plausibly mean by false ──
foreach (['' => 'empty string', 'false' => '"false"', 'off' => '"off"', 'no' => '"no"', '  0  ' => 'padded "0"', 'FALSE' => 'uppercase "FALSE"'] as $value => $desc) {
    check("$desc is false", with_post(['f' => (string) $value], function () use ($post_bool) {
        return $post_bool('f') === false;
    }));
}

// ── An absent key stays false ──
check('An absent field is false', with_post([], function () use ($post_bool) {
    return $post_bool('f') === false;
}));

// ── The real call sites behave correctly ──
check('create_new_admin unchecked (empty) does NOT request an admin',
    with_post(['create_new_admin' => ''], function () use ($post_bool) {
        return $post_bool('create_new_admin') === false;
    }));
check('create_new_admin unchecked (legacy "0") does NOT request an admin',
    with_post(['create_new_admin' => '0'], function () use ($post_bool) {
        return $post_bool('create_new_admin') === false;
    }));
check('create_new_admin checked DOES request an admin',
    with_post(['create_new_admin' => '1'], function () use ($post_bool) {
        return $post_bool('create_new_admin') === true;
    }));
check('Blank password field means "generate one" (so it gets displayed)',
    with_post(['new_admin_custom_password' => ''], function () use ($post_bool) {
        return $post_bool('new_admin_custom_password') === false;
    }));

// ── The always-"1" hidden fields are unaffected ──
foreach (['auto_detect_urls', 'restore_files', 'confirm_restore', 'include_files'] as $field) {
    check("$field=1 still reads true", with_post([$field => '1'], function () use ($post_bool, $field) {
        return $post_bool($field) === true;
    }));
}

echo "\n";
if ($fail === 0) { echo "ALL $pass CHECKS PASSED\n"; }
else { echo "$fail FAILURE(S): " . implode('; ', $failures) . "\n"; }
exit($fail === 0 ? 0 : 1);
