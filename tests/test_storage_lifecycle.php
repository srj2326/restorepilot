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
 * The two ways a backup is meant to be deleted, and the one place it was not.
 *
 * RP-035 and RP-036. Moving storage out of the web-served uploads directory
 * fixed an exposure and created two data-retention defects, because the code
 * that deletes backups was never told the backups had moved:
 *
 *   - Uninstall removed fixed folders under uploads and nothing else, and it
 *     deleted the restorepilot_* options first -- including the one recording
 *     where storage had been moved to. So every archive survived, and the only
 *     pointer to them was gone. The privacy policy said the opposite.
 *   - Master Reset's "also delete stored backups" wiped the uploads directory.
 *     Migrated storage is not a descendant of it, so the choice did nothing
 *     while the log reported "Stored backups were deleted at the operator's
 *     request."
 *
 * Both now go through storage this plugin can prove it created: the directory
 * is named what we name ours, carries a marker file we wrote, and is not a
 * location an administrator configured themselves. That last exclusion matters
 * more than the deletions do -- these paths are outside WordPress, and a
 * recursive delete of somebody's chosen directory would be far worse than
 * leaving one behind. Half of what follows checks what is NOT deleted.
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
function rmtree(string $d): void {
    if (!is_dir($d)) { return; }
    foreach (scandir($d) ?: [] as $e) {
        if ($e === '.' || $e === '..') { continue; }
        $p = "$d/$e";
        is_dir($p) ? rmtree($p) : @unlink($p);
    }
    @rmdir($d);
}

$MARKER  = konst('STORAGE_MARKER_FILE');
$DIRNAME = konst('PRIVATE_STORAGE_DIRNAME');
$OPTION  = konst('STORAGE_PATH_OPTION');
$original_option = (string) get_option($OPTION, '');

// A sandbox to build storage-shaped directories in, so nothing here depends on
// the fixture's real backups.
$sandbox = sys_get_temp_dir() . '/rp-storage-lifecycle-' . getmypid();
rmtree($sandbox);
mkdir($sandbox, 0755, true);
register_shutdown_function(function () use ($sandbox, $OPTION, $original_option) {
    rmtree($sandbox);
    if ($original_option !== '') { update_option($OPTION, $original_option, false); }
});

/**
 * Point the plugin at a storage directory.
 *
 * Always through here, never update_option() directly. The uninstall runs
 * below delete the option row from a child process, and this process does not
 * hear about it -- so update_option() reads its cached old value, decides the
 * row exists, issues an UPDATE that matches nothing, and returns false without
 * writing. Every later read then gets the stale path. That cost an hour and
 * three tests that appeared to prove the backfill was broken when it was not.
 */
function set_storage_option(string $path): void {
    global $OPTION;
    wp_cache_flush();
    update_option($OPTION, $path, false);
    wp_cache_flush();
    if ((string) get_option($OPTION, '') !== $path) {
        check('The fixture could point storage at ' . basename($path), false,
            'option reads back as ' . var_export(get_option($OPTION, ''), true));
    }
}

/** A directory shaped like one the plugin created. */
function make_store(string $path, bool $marked = true, array $files = ['backups/archive.zip']): string {
    global $MARKER;
    foreach ($files as $rel) {
        @mkdir(dirname("$path/$rel"), 0755, true);
        file_put_contents("$path/$rel", 'PRETEND-BACKUP-CONTENT');
    }
    @mkdir($path, 0755, true);
    if ($marked) { file_put_contents($path . '/' . $MARKER, "created by RestorePilot\n"); }
    return $path;
}

// ── The marker the whole thing rests on ────────────────────────────────────
echo "=== the plugin marks the storage it creates ===\n";
priv('ensure_storage');
$live = priv('storage_dir');
check('ensure_storage() writes an ownership marker',
    is_file($live . '/' . $MARKER), $live . '/' . $MARKER);

// ── What counts as ours ────────────────────────────────────────────────────
echo "\n=== which directories the plugin will claim as its own ===\n";

$ours = make_store($sandbox . '/' . $DIRNAME);
check('A marked directory with our name is ours',
    priv('is_plugin_created_private_storage', [$ours]) === true);

$unmarked = make_store($sandbox . '/unmarked/' . $DIRNAME, false);
check('THE LIMIT: our name but no marker is not ours',
    priv('is_plugin_created_private_storage', [$unmarked]) === false,
    'a directory we did not create can still be called this');

$misnamed = make_store($sandbox . '/somebody-elses-backups');
check('THE LIMIT: a marker in a directory with another name is not ours',
    priv('is_plugin_created_private_storage', [$misnamed]) === false);

check('THE LIMIT: a directory that does not exist is not ours',
    priv('is_plugin_created_private_storage', [$sandbox . '/absent']) === false);

// ── Master Reset, both ways round (RP-036) ─────────────────────────────────
echo "\n=== Master Reset with \"also delete stored backups\" ===\n";

$private  = make_store($sandbox . '/purge/' . $DIRNAME);
$bystander = $sandbox . '/purge/somebody-elses-data';
@mkdir($bystander, 0755, true);
file_put_contents($bystander . '/important.txt', 'NOT OURS');

set_storage_option($private);
check('The private store is listed as ours to remove',
    in_array($private, priv('plugin_owned_storage_dirs'), true),
    implode(', ', array_map('basename', priv('plugin_owned_storage_dirs'))));

$failed = priv('purge_plugin_storage');
check('THE FIX: purging removes the migrated backups', !is_dir($private),
    $failed ? 'reported failures: ' . implode(', ', $failed) : 'gone');
check('And it reported no failures', $failed === []);
check('THE LIMIT: the sibling directory beside it is untouched',
    is_file($bystander . '/important.txt'),
    'a purge that reaches outside its own tree is worse than one that misses');

// purge_backups=false must leave everything alone. The handler is what decides,
// so this checks the decision rather than the helper.
$src = file_get_contents(dirname(__DIR__) . '/includes/trait-request-handlers.php');
check('Storage is purged only when the operator asked for it',
    preg_match('/if \(\$purge_backups\) \{\s*\n\s*\$failed_storage = self::purge_plugin_storage\(\$storage_targets\);/', $src) === 1);

// The ordering this file did not check, and the bug it therefore missed.
// Master Reset wipes wp_options at step 3, and the option recording where
// storage was moved to is one of the ones it deletes. Resolving the locations
// after that finds only the uploads directory, so every migrated backup
// survived while the reset reported deleting them. Component tests could not
// see it: purge_plugin_storage() works perfectly when called on its own.
$resolve_at = strpos($src, '$storage_targets = $purge_backups ? self::plugin_owned_storage_dirs() : [];');
$wipe_at    = strpos($src, 'DELETE FROM %i WHERE option_name NOT IN');
$purge_at   = strpos($src, 'self::purge_plugin_storage($storage_targets);');
check('THE FIX: the storage locations are resolved before wp_options is wiped',
    $resolve_at !== false && $wipe_at !== false && $resolve_at < $wipe_at,
    'after the wipe there is nothing left that knows where the backups went');
check('And the purge itself still runs after the wipe, on what was resolved',
    $purge_at !== false && $wipe_at !== false && $wipe_at < $purge_at);
check('And a failure is named rather than reported as success',
    strpos($src, "'stored backups could not be deleted from ' . \$failed_dir") !== false
    && strpos($src, "only partly deleted; see the problems above.") !== false);

// ── Uninstall (RP-035) ─────────────────────────────────────────────────────
echo "\n=== uninstall ===\n";

// Order is the defect: the option naming the private store is itself a
// restorepilot_* option, so deleting options first loses the pointer.
$uninstall_src = file_get_contents(dirname(__DIR__) . '/uninstall.php');
$storage_at = strpos($uninstall_src, 'restorepilot_backup_migration_uninstall_delete_private_storage();');
$options_at = strpos($uninstall_src, 'restorepilot_backup_migration_uninstall_delete_options();');
check('THE FIX: storage is deleted before the option that locates it',
    $storage_at !== false && $options_at !== false && $storage_at < $options_at);

// The constants are repeated in uninstall.php because the plugin class is not
// loaded there. If they drift, uninstall silently stops finding anything.
check('uninstall.php still agrees with the class about the directory name',
    strpos($uninstall_src, "RESTOREPILOT_UNINSTALL_PRIVATE_DIRNAME = '" . $DIRNAME . "'") !== false,
    $DIRNAME);
check('uninstall.php still agrees with the class about the marker file',
    strpos($uninstall_src, "RESTOREPILOT_UNINSTALL_STORAGE_MARKER  = '" . $MARKER . "'") !== false,
    $MARKER);

// And the behaviour, run the way WordPress runs it.
$store     = make_store($sandbox . '/uninstall/' . $DIRNAME,  true,
                        ['backups/site.zip', 'restore-rollbacks/rollback.zip', 'restore-status-abc.json']);
$neighbour = $sandbox . '/uninstall/unrelated';
@mkdir($neighbour, 0755, true);
file_put_contents($neighbour . '/keep-me.txt', 'NOT OURS');

set_storage_option($store);

$runner = $sandbox . '/run-uninstall.php';
file_put_contents($runner, "<?php\ndefine('WP_UNINSTALL_PLUGIN', 'restorepilot-backup-migration/restorepilot-backup-migration.php');\n"
    . "require " . var_export(rp_test_site() . '/wp-load.php', true) . ";\n"
    . "require " . var_export(dirname(__DIR__) . '/uninstall.php', true) . ";\n");
$out = [];
$code = 0;
exec(rp_test_php_command($runner) . ' 2>&1', $out, $code);

check('The uninstall routine ran', $code === 0, implode(' | ', array_slice($out, -2)));
check('THE FIX: the migrated backups are gone after uninstall', !is_dir($store),
    is_dir($store) ? 'still present: ' . $store : 'removed');
check('THE LIMIT: an unrelated directory beside it survives',
    is_file($neighbour . '/keep-me.txt'));
// The uninstall ran in a child process, so this one is still holding the value
// it read before. Without the flush this reads the cache, not the database --
// which is how it first reported a failure that had not happened.
wp_cache_flush();
check('And the option was cleared too',
    get_option($OPTION, '') === '' || get_option($OPTION, '') === false);

// A directory the plugin never recorded must survive, marker or not. This is
// the boundary that matters now that uninstall accepts equivalent evidence for
// a path its own option names.
$foreign = make_store($sandbox . '/foreign/somebody-elses-store', false);
set_storage_option($foreign);
$out = [];
exec(rp_test_php_command($runner) . ' 2>&1', $out, $code);
check('THE LIMIT: a directory that is not named like ours survives uninstall',
    is_dir($foreign) && is_file($foreign . '/backups/archive.zip'),
    'the name is part of the evidence, and this one does not have it');

// ── Sites that migrated before there was a marker ──────────────────────────
// The fix above requires proof that a directory is ours, and every site that
// migrated under 0.5.7 has private storage without any. Requiring the marker
// and stopping there would have left the original defect in place for exactly
// the people who already have backups outside the web root -- a fix that only
// works on installations created after it shipped.
echo "\n=== a site that migrated under an earlier release ===\n";

$legacy = make_store($sandbox . '/upgraded/' . $DIRNAME, false,
                     ['backups/customer-site.zip']);
check('It starts with no ownership marker, as 0.5.7 left it',
    !is_file($legacy . '/' . $MARKER));

set_storage_option($legacy);
$admins = get_users(['role' => 'administrator', 'number' => 1]);
if ($admins) { wp_set_current_user($admins[0]->ID); }

RestorePilot_Backup_Migration::maybe_migrate_storage();   // the admin_init hook
check('THE FIX: loading an admin page backfills the marker',
    is_file($legacy . '/' . $MARKER),
    'an upgrade repairs itself rather than silently keeping the defect');
check('And the directory is now recognised as ours to remove',
    priv('is_plugin_created_private_storage', [$legacy]) === true);

// The backfill applies the same ownership test, so it must refuse the same
// directories the deletion would.
$not_ours = make_store($sandbox . '/upgraded/some-other-folder', false);
set_storage_option($not_ours);
RestorePilot_Backup_Migration::maybe_migrate_storage();
check('THE LIMIT: it will not mark a directory with another name',
    !is_file($not_ours . '/' . $MARKER),
    'writing a marker into somebody else\'s directory would make it deletable');

// And the path that never loads an admin page at all: wp plugin delete runs
// uninstall.php without firing admin_init, so no backfill has happened.
echo "\n=== the same site deleted from the command line ===\n";
$cli = make_store($sandbox . '/wpcli/' . $DIRNAME, false, ['backups/site.zip']);
set_storage_option($cli);
check('It still has no marker, because admin_init never ran',
    !is_file($cli . '/' . $MARKER));

$out = [];
exec(rp_test_php_command($runner) . ' 2>&1', $out, $code);
check('THE FIX: uninstall removes it anyway, on equivalent evidence',
    !is_dir($cli),
    'the path came from an option only this plugin writes, and carries our directory name');

// That relaxation must not extend to a directory we did not record.
$stranger = make_store($sandbox . '/wpcli/' . $DIRNAME . '-lookalike', false, ['backups/site.zip']);
set_storage_option($stranger);
$out = [];
exec(rp_test_php_command($runner) . ' 2>&1', $out, $code);
check('THE LIMIT: a directory whose name is merely similar survives',
    is_dir($stranger),
    basename($stranger));

// Put the fixture back together for whatever runs next.
//
// The flush is load-bearing, and its absence cost a green suite. The child
// processes above deleted the option from the database, but delete_option()
// here returns early without clearing the cache when the row is already gone,
// so this process still believed storage lived in the sandbox. ensure_storage()
// dutifully created it there, the shutdown handler deleted the sandbox, and the
// next test in the suite found no storage directory at all -- failing in a way
// that had nothing to do with what it was testing.
wp_cache_flush();
delete_option($OPTION);
wp_cache_flush();
priv('ensure_storage');
if (!is_dir(priv('backup_dir')) || !is_dir(priv('rollback_dir'))) {
    check('The fixture storage was restored for the tests that follow', false,
        priv('storage_dir') . ' is missing after cleanup');
}

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
