<?php
/**
 * Master Reset now drops tables other plugins created. This decides what gets
 * dropped, so the thing that matters is not that it finds them -- it is that
 * it never names something it must not touch.
 *
 * Nothing here drops anything. It only inspects what the helper selects.
 */

define('WP_USE_THEMES', false);
require_once '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public/wp-load.php';

$failures = [];
function check(string $label, bool $ok) {
    global $failures;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if (!$ok) { $failures[] = $label; }
}
function call_private($name, $args = []) {
    $m = new ReflectionMethod('RestorePilot_Backup_Migration', $name);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
}

global $wpdb;
$foreign = call_private('foreign_plugin_tables');
$all = $wpdb->get_col("SHOW TABLES");
printf("Tables on this site: %d | selected as foreign: %d\n\n", count($all), count($foreign));

// --- The absolute rule: no WordPress core table may ever be selected -------
$core = [];
foreach (['posts','postmeta','comments','commentmeta','terms','termmeta','term_taxonomy',
          'term_relationships','links','options','users','usermeta','blogs','blogmeta',
          'site','sitemeta','signups','registration_log','blog_versions'] as $t) {
    if (!empty($wpdb->$t)) { $core[] = $wpdb->$t; }
}
$core_hit = array_intersect($core, $foreign);
check('No WordPress core table is selected for dropping', empty($core_hit));
if ($core_hit) { echo '  WOULD HAVE DROPPED: ' . implode(', ', $core_hit) . "\n"; }

check('wp_users is never selected (can be shared between installs)',
    !in_array($wpdb->users, $foreign, true));
check('wp_usermeta is never selected', !in_array($wpdb->usermeta, $foreign, true));
check('wp_options is never selected', !in_array($wpdb->options, $foreign, true));

// --- Everything selected must genuinely belong to this site ---------------
$prefix = $wpdb->prefix;
$wrong_prefix = array_filter($foreign, function ($t) use ($prefix) {
    return strpos($t, $prefix) !== 0;
});
check('Every selected table carries this site\'s prefix', empty($wrong_prefix));
if ($wrong_prefix) { echo '  outside prefix: ' . implode(', ', $wrong_prefix) . "\n"; }

// --- Restore scratch tables belong to the restore, not to this ------------
$scratch = array_filter($foreign, function ($t) {
    return strpos($t, RestorePilot_Backup_Migration::RESTORE_TMP_TABLE_MARKER) !== false
        || strpos($t, RestorePilot_Backup_Migration::RESTORE_OLD_TABLE_MARKER) !== false;
});
check('No in-flight restore scratch table is selected', empty($scratch));

// --- It should actually find the plugin tables that prompted this ---------
$expected = ['wp_cf7_vdata_entry', 'wp_cf7dbplugin_submits', 'wp_db7_forms'];
$present = array_values(array_intersect($expected, $all));
if ($present) {
    $found = array_intersect($present, $foreign);
    check('Finds the plugin tables it exists to remove (' . implode(', ', $present) . ')',
        count($found) === count($present));
} else {
    echo "note: the example plugin tables are not on this site to check against\n";
}

// --- Size of what a reset would now reclaim -------------------------------
if ($foreign) {
    $in = implode(',', array_fill(0, count($foreign), '%s'));
    $mb = $wpdb->get_var($wpdb->prepare(
        "SELECT ROUND(SUM(data_length+index_length)/1024/1024,1) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name IN ($in)", ...$foreign));
    printf("\nA reset would now also reclaim %s MB across %d table(s).\n", $mb, count($foreign));
    echo "First few: " . implode(', ', array_slice($foreign, 0, 6)) . "\n";
}

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
