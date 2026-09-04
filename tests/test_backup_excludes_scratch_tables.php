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

// Regression test: a backup must never include this plugin's own restore
// scratch tables (RESTORE_TMP_TABLE_MARKER / RESTORE_OLD_TABLE_MARKER
// patterns). These only exist if an earlier restore was interrupted before
// its swap — found for real today: an interrupted test restore left ~265
// of them, which a later backup silently exported as if they were genuine
// site tables, ballooning that backup's own restore plan and cascading
// slow-restore failures into every test that ran after it.

$site_root = '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public';
$plugin_file = '/Users/surajitroy/Local Sites/morecalculators-dev/app/public/wp-content/plugins/restorepilot-backup-migration/restorepilot-backup-migration.php';
require $site_root . '/wp-load.php';
if (!class_exists('RestorePilot_Backup_Migration')) {
  require_once $plugin_file;
}

function call_private($method, array $args = []) {
  $ref = new ReflectionMethod('RestorePilot_Backup_Migration', $method);
  $ref->setAccessible(true);
  return $ref->invokeArgs(null, $args);
}

$failures = [];
function check($label, $cond) {
  global $failures;
  echo ($cond ? 'PASS' : 'FAIL') . "  $label\n";
  if (!$cond) $failures[] = $label;
}

global $wpdb;

// Hand-crafted fake scratch tables, matching the real naming pattern —
// deliberately not created via a real interrupted restore, since forcing
// one for real is exactly the expensive, slow scenario this test exists to
// avoid needing.
$fake_rtmp = $wpdb->prefix . 'restorepilot_rtmp_faketest_0';
$fake_rold = $wpdb->prefix . 'restorepilot_rold_faketest_0';
$wpdb->query("DROP TABLE IF EXISTS `$fake_rtmp`");
$wpdb->query("DROP TABLE IF EXISTS `$fake_rold`");
$wpdb->query("CREATE TABLE `$fake_rtmp` (id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB");
$wpdb->query("CREATE TABLE `$fake_rold` (id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB");
check('Fixture: fake rtmp scratch table exists', (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $fake_rtmp)));
check('Fixture: fake rold scratch table exists', (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $fake_rold)));

$backup_result = call_private('create_backup_package', [false, '', [], false, false, ['triggered_by' => 'scratch-table-exclusion-test']]);
check('Backup created successfully', !empty($backup_result['file']));
$backup_path = call_private('backup_dir') . '/' . $backup_result['file'];

$zip = call_private('open_backup_archive', [$backup_path]);
$manifest = json_decode($zip->get_from_name('manifest.json'), true);
check('Manifest reports a real table count (sanity check, not zero)', ($manifest['table_count'] ?? 0) > 0);

// Read every table name straight out of the raw NDJSON export — the most
// direct possible check that the exclusion actually took effect in the
// real export path, not just in some assumption about it.
$found_rtmp = false;
$found_rold = false;
$all_table_names = [];
for ($i = 0, $n = $zip->num_files(); $i < $n; $i++) {
  $name = $zip->get_name_index($i);
  if (!is_string($name) || strpos($name, 'database/database-') !== 0) {
    continue;
  }
  $content = $zip->get_from_name($name);
  foreach (explode("\n", $content) as $line) {
    if ($line === '') { continue; }
    $record = json_decode($line, true);
    if (($record['t'] ?? '') === 'table') {
      $all_table_names[] = $record['name'];
      if ($record['name'] === $fake_rtmp) { $found_rtmp = true; }
      if ($record['name'] === $fake_rold) { $found_rold = true; }
    }
  }
}
check('Backup includes a plausible number of real tables (not empty)', count($all_table_names) > 5);
check('The fake rtmp scratch table is NOT in the export', !$found_rtmp);
check('The fake rold scratch table is NOT in the export', !$found_rold);

// --- Cleanup -----------------------------------------------------------------
$zip->close();
$wpdb->query("DROP TABLE IF EXISTS `$fake_rtmp`");
$wpdb->query("DROP TABLE IF EXISTS `$fake_rold`");
foreach (call_private('discover_volumes', [$backup_path])['paths'] as $p) { @unlink($p); }

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
