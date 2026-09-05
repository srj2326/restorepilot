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
 * Protections that are not protecting anything, and exemptions that are not
 * exempting anything.
 *
 * RP-032. Two of these had accumulated in the main plugin file, and they share
 * a failure mode: both read, to anyone auditing the plugin, as a decision that
 * had been taken and was in force. Neither was.
 *
 *   - A duplicate-load sentinel sat after every require, declaration and hook
 *     registration, so it could only record a duplicate load, never prevent
 *     one. Moving it to the top does not rescue it either, which is the part
 *     worth checking rather than asserting: PHP binds unconditional top-level
 *     function declarations at compile time, so a second include fatals before
 *     any runtime guard in that file executes. That is demonstrated below.
 *
 *   - Three file-wide phpcs:disable lines described operations performed
 *     "throughout this file". The trait split moved every one of those
 *     operations into includes/, and PHPCS directives apply only to the file
 *     they appear in -- so they suppressed nothing while looking like a
 *     blanket exemption covering the whole plugin.
 *
 * The general rule this pins down: a file-wide disable is only honest in a file
 * that actually performs the thing being excused. uninstall.php still carries
 * two, correctly, because its own code triggers them.
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

/**
 * A file's code, with the given token types dropped.
 *
 * Counting trigger sites with a plain grep reads the explanatory comments too,
 * and those name the very functions they are explaining -- which is how a file
 * that performs none of these operations can appear to perform several.
 *
 * What gets dropped differs by question, and getting that wrong is silent.
 * Dropping string literals as well as comments is right for finding calls, and
 * was wrong for finding a defined constant: the name only ever appears inside
 * quotes, so the sentinel check passed against a file that still had the
 * sentinel in it. Caught by putting one back and watching nothing happen.
 */
function strip_tokens(string $path, array $drop): string {
    $out = '';
    foreach (token_get_all(file_get_contents($path)) as $t) {
        if (is_array($t)) {
            if (in_array($t[0], $drop, true)) { continue; }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
}

/** Calls only: comments and string literals both removed. */
function code_only(string $path): string {
    return strip_tokens($path, [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_INLINE_HTML]);
}

/** Anything the file really executes, including names written as strings. */
function code_with_strings(string $path): string {
    return strip_tokens($path, [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML]);
}

/** What each suppressed category actually looks like in code. */
$TRIGGERS = [
    'WordPress.WP.AlternativeFunctions' =>
        '/\b(fopen|fwrite|fclose|fgets|fread|feof|fseek|ftell|file_get_contents|file_put_contents|readfile|curl_init|unlink|rmdir|mkdir|rename|copy)\s*\(/',
    'WordPress.DB.DirectDatabaseQuery' =>
        '/\$wpdb\s*->\s*(query|get_var|get_col|get_row|get_results)\s*\(/',
    'WordPress.Security.EscapeOutput.ExceptionNotEscaped' =>
        '/->\s*getMessage\s*\(\s*\)/',
];

// ── The sentinel is gone, not relocated ────────────────────────────────────
echo "=== the duplicate-load sentinel ===\n";

$main_src = file_get_contents($root . '/restorepilot-backup-migration.php');
check('The plugin no longer defines a duplicate-load sentinel',
    strpos(code_with_strings($root . '/restorepilot-backup-migration.php'), 'RESTOREPILOT_BACKUP_MIGRATION_LOADED') === false,
    'it could not prevent a duplicate load, only record one');

check('And it is not defined at runtime either',
    !defined('RESTOREPILOT_BACKUP_MIGRATION_LOADED'));

// The reason it was not simply moved to the top. If this ever stops being true
// of PHP, the comment in the plugin file needs revisiting.
$probe = tempnam(sys_get_temp_dir(), 'rp-guard') . '.php';
file_put_contents($probe, "<?php\nif (defined('RP_PROBE')) { return; }\ndefine('RP_PROBE', true);\nfunction rp_probe_fn() {}\n");
$runner = tempnam(sys_get_temp_dir(), 'rp-run') . '.php';
file_put_contents($runner, "<?php\ninclude " . var_export($probe, true) . ";\ninclude " . var_export($probe, true) . ";\necho 'SURVIVED';\n");
$out = [];
exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 ' . escapeshellarg($runner) . ' 2>&1', $out);
$joined = implode("\n", $out);
@unlink($probe); @unlink($runner);

check('A top-of-file guard genuinely cannot prevent the redeclaration fatal',
    strpos($joined, 'SURVIVED') === false && stripos($joined, 'Cannot redeclare') !== false,
    'declarations bind at compile time, before the guard runs -- so removal was the fix, not relocation');

// ── Every file-wide disable is in a file that earns it ─────────────────────
echo "\n=== file-wide PHPCS disables ===\n";

$files = array_merge(
    glob($root . '/*.php') ?: [],
    glob($root . '/includes/*.php') ?: []
);

$inert = [];
$live  = 0;
foreach ($files as $file) {
    if (!preg_match_all('/phpcs:disable\s+([A-Za-z0-9_.]+)/', file_get_contents($file), $m)) {
        continue;
    }
    $code = code_only($file);
    foreach ($m[1] as $sniff) {
        if (!isset($TRIGGERS[$sniff])) { continue; }
        if (preg_match($TRIGGERS[$sniff], $code)) {
            $live++;
        } else {
            $inert[] = basename($file) . ' → ' . $sniff;
        }
    }
}

check('THE FIX: no file-wide disable sits in a file that never triggers it',
    $inert === [],
    $inert ? implode('; ', $inert) : 'every remaining disable is in a file that performs the thing it excuses');

check('And the disables that are genuinely earned were kept',
    $live > 0, "$live still in force");

// Stated separately, because this is the specific regression: the main file is
// now a loader, and a loader has nothing to excuse.
check('The main plugin file carries no file-wide disable',
    strpos($main_src, 'phpcs:disable Word') === false,
    'the operations it used to describe now live in includes/');

check('uninstall.php keeps its two, which its own code does trigger',
    preg_match_all('/phpcs:disable\s+WordPress/', file_get_contents($root . '/uninstall.php')) === 2);

// ── The loader still loads ─────────────────────────────────────────────────
echo "\n=== and the plugin is unharmed by the removal ===\n";
check('The class is available', class_exists('RestorePilot_Backup_Migration'));
check('The location constants are still defined',
    defined('RESTOREPILOT_BACKUP_MIGRATION_FILE')
    && defined('RESTOREPILOT_BACKUP_MIGRATION_DIR')
    && defined('RESTOREPILOT_BACKUP_MIGRATION_URL'));
check('Hooks are registered',
    has_action('admin_menu', ['RestorePilot_Backup_Migration', 'admin_menu']) !== false
    && has_action('admin_init', ['RestorePilot_Backup_Migration', 'maybe_migrate_storage']) !== false);

// The static guard inside the bootstrap is what makes a second call harmless,
// and it is the only such guard that was ever doing anything.
$before = count($GLOBALS['wp_filter']['admin_menu']->callbacks[10] ?? []);
restorepilot_backup_migration_bootstrap();
$after = count($GLOBALS['wp_filter']['admin_menu']->callbacks[10] ?? []);
check('Calling the bootstrap again registers nothing twice', $before === $after,
    "admin_menu callbacks: $before then $after");

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
