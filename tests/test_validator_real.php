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

require __DIR__ . '/validator.php';

define("WP_USE_THEMES", false);
$_SERVER["HTTP_HOST"] = "morecalculators-dev.local";
$_SERVER["REQUEST_URI"] = "/";
chdir("/Users/surajitroy/Local Sites/morecalculators-dev/app/public");
require "wp-load.php";

global $wpdb;
$tables = $wpdb->get_col('SHOW TABLES');
$tmp = 'wp_restorepilot_rtmp_abc123_0';

$rejected = [];
$accepted = 0;
foreach ($tables as $table) {
  $row = $wpdb->get_row('SHOW CREATE TABLE `' . $table . '`', ARRAY_N);
  $create = $row[1] ?? '';
  // Apply the same rewrite the restore does, then validate.
  $rewritten = preg_replace('/CREATE TABLE `?' . preg_quote($table, '/') . '`?/i', 'CREATE TABLE `' . $tmp . '`', $create, 1);
  $result = validate_create($rewritten, $tmp);
  if (strpos($result, 'ACCEPTED') !== false) {
    $accepted++;
  } else {
    $rejected[$table] = $result;
  }
}

echo "Tables checked: " . count($tables) . "\n";
echo "Accepted: $accepted\n";
echo "Rejected: " . count($rejected) . "\n";
foreach ($rejected as $t => $why) {
  echo "  REJECTED $t => $why\n";
  $row = $wpdb->get_row('SHOW CREATE TABLE `' . $t . '`', ARRAY_N);
  // Show just the tail so we can see which option tripped it.
  $c = $row[1];
  $open = strpos($c, '(');
  $close = matching_paren($c, $open);
  echo "     tail: " . trim(substr($c, $close + 1)) . "\n";
}
