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

require_once __DIR__ . '/env.php';

// Full end-to-end test: create a real table shaped exactly like Wordfence's
// wp_wflivetraffichuman (composite BINARY primary key), populate it with
// enough random binary rows that the OLD bug would reliably produce
// collisions, run it through a REAL create_backup_package() -> restore
// cycle (not just the encode/decode functions in isolation), and confirm
// every single row survives byte-for-byte with no duplicate-key failure.

$site_root = rp_test_site();
require $site_root . '/wp-load.php';

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
$test_table = $wpdb->prefix . 'rp_binary_pk_test';

$wpdb->query("DROP TABLE IF EXISTS `$test_table`");
$wpdb->query("CREATE TABLE `$test_table` (
  `IP` binary(16) NOT NULL DEFAULT '\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0',
  `identifier` binary(32) NOT NULL DEFAULT '\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0',
  `expiration` int unsigned NOT NULL,
  PRIMARY KEY (`IP`,`identifier`),
  KEY `expiration` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3");

$rows_inserted = [];
for ($i = 0; $i < 500; $i++) {
  $ip = random_bytes(16);
  $identifier = random_bytes(32);
  $expiration = time() + $i;
  $wpdb->query($wpdb->prepare(
    "INSERT INTO `$test_table` (IP, identifier, expiration) VALUES (%s, %s, %d)",
    $ip, $identifier, $expiration
  ));
  $rows_inserted[] = ['IP' => $ip, 'identifier' => $identifier, 'expiration' => $expiration];
}
$actual_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$test_table`");
check('Fixture: 500 rows with random binary composite keys inserted', $actual_count === 500);

// --- Real backup ------------------------------------------------------------
$backup_result = call_private('create_backup_package', [true, '', [], false, false, ['triggered_by' => 'binary-pk-restore-test']]);
check('Backup created successfully', !empty($backup_result['file']));
$base_path = call_private('backup_dir') . '/' . $backup_result['file'];

// --- Drop the source table, so restore must recreate it from the backup ----
$wpdb->query("DROP TABLE IF EXISTS `$test_table`");
$exists_after_drop = (int) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $test_table));
check('Source table dropped before restore (proves restore recreates it, not just verifies existing data)', $exists_after_drop === 0);

// --- Real restore -------------------------------------------------------------
$threw = false;
$error_message = '';
try {
  call_private('perform_restore', [$base_path, false, false, '', home_url(), home_url()]);
} catch (Throwable $e) {
  $threw = true;
  $error_message = $e->getMessage();
}
check('Restore completed without throwing (no duplicate-key failure)', !$threw);
if ($threw) {
  echo "  Restore error: $error_message\n";
}

// --- Verify every row survived byte-for-byte --------------------------------
$restored_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$test_table`");
check("Restored table has all 500 rows (got $restored_count)", $restored_count === 500);

$mismatches = 0;
foreach ($rows_inserted as $original) {
  $found = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM `$test_table` WHERE IP = %s AND identifier = %s AND expiration = %d",
    $original['IP'], $original['identifier'], $original['expiration']
  ));
  if ((int) $found !== 1) {
    $mismatches++;
  }
}
check('Every one of the 500 original rows is found, byte-for-byte, in the restored table', $mismatches === 0);
if ($mismatches > 0) {
  echo "  $mismatches rows failed to match after restore\n";
}

// --- Cleanup -------------------------------------------------------------------
$wpdb->query("DROP TABLE IF EXISTS `$test_table`");
@unlink($base_path);
foreach (call_private('discover_volumes', [$base_path])['paths'] ?? [] as $p) {
  @unlink($p);
}

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
