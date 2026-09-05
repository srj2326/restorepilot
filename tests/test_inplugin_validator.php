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

// Exercise the REAL plugin methods without booting WordPress, by extracting
// the class from the plugin file with WP-dependent bootstrap code skipped.
define('ABSPATH', '/tmp/fake-abspath/');

// Minimal stubs for the WP functions the class body touches at parse/call time.
function __($s, $d = null) { return $s; }
function esc_html__($s, $d = null) { return $s; }
function _n($a, $b, $n, $d = null) { return $n === 1 ? $a : $b; }
function plugin_dir_path($f) { return dirname($f) . '/'; }
function plugin_dir_url($f) { return 'http://example.test/'; }
function add_action() {}
function add_filter() {}
function register_activation_hook() {}
function register_deactivation_hook() {}
function plugin_basename($f) { return basename(dirname($f)) . '/' . basename($f); }

$src = file_get_contents(rp_test_plugin_file());

// Keep only the class declaration itself.
$start = strpos($src, 'final class RestorePilot_Backup_Migration');
$end = strpos($src, "\nfunction restorepilot_backup_migration_", $start);
if ($end === false) {
  // Class is followed by other class declarations; take to the last closing brace instead.
  $end = strlen($src);
}
$class_src = substr($src, $start, $end - $start);

// Trim to the balanced end of the class.
$depth = 0; $i = strpos($class_src, '{'); $len = strlen($class_src); $classEnd = null;
for ($j = $i; $j < $len; $j++) {
  if ($class_src[$j] === '{') $depth++;
  elseif ($class_src[$j] === '}') { $depth--; if ($depth === 0) { $classEnd = $j; break; } }
}
$class_src = substr($class_src, 0, $classEnd + 1);

eval($class_src);

$tmp = 'wp_restorepilot_rtmp_abc123_0';
$m = new ReflectionMethod('RestorePilot_Backup_Migration', 'assert_create_table_is_safe');
$m->setAccessible(true);

function check(ReflectionMethod $m, string $sql, string $tmp): string {
  try { $m->invoke(null, $sql, $tmp, 'wp_source'); return '*** ACCEPTED ***'; }
  catch (Throwable $e) { return 'REJECTED'; }
}

$must_reject = [
  'CREATE ... SELECT'             => "CREATE TABLE `$tmp` (`x` int) SELECT * FROM wp_users",
  'CREATE ... AS SELECT'          => "CREATE TABLE `$tmp` (`x` int) AS SELECT user_pass FROM wp_users",
  'CREATE ... SELECT w/ subquery' => "CREATE TABLE `$tmp` (`x` int) SELECT * FROM (SELECT user_pass FROM wp_users) t",
  'ENGINE then SELECT'            => "CREATE TABLE `$tmp` (`x` int) ENGINE=InnoDB SELECT * FROM wp_users",
  'stacked statement'             => "CREATE TABLE `$tmp` (`x` int); DROP TABLE wp_users",
  'executable comment'            => "CREATE TABLE `$tmp` (`x` int) /*!50100 SELECT * FROM wp_users */",
  'FEDERATED CONNECTION'          => "CREATE TABLE `$tmp` (`x` int) ENGINE=FEDERATED CONNECTION='mysql://evil/db/t'",
  'DATA DIRECTORY'                => "CREATE TABLE `$tmp` (`x` int) DATA DIRECTORY='/etc'",
  'wrong table name'              => "CREATE TABLE `wp_users` (`x` int)",
  'CREATE ... LIKE'               => "CREATE TABLE `$tmp` LIKE wp_users",
  'unbalanced parens'             => "CREATE TABLE `$tmp` (`x` int",
  'UNION after block'             => "CREATE TABLE `$tmp` (`x` int COMMENT 'ok') UNION SELECT 1",
];

$must_accept = [
  'plain InnoDB'            => "CREATE TABLE `$tmp` (\n  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
  'full option set'         => "CREATE TABLE `$tmp` (\n  `id` bigint(20) NOT NULL AUTO_INCREMENT,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB AUTO_INCREMENT=181 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC",
  'paren in default value'  => "CREATE TABLE `$tmp` (\n  `a` varchar(50) NOT NULL DEFAULT 'has ( paren',\n  PRIMARY KEY (`a`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
  'COMMENT w/ paren+quote'  => "CREATE TABLE `$tmp` (`a` int) ENGINE=InnoDB COMMENT='a ) tricky '' comment'",
  'composite PK'            => "CREATE TABLE `$tmp` (\n  `object_id` bigint(20) NOT NULL,\n  `term_taxonomy_id` bigint(20) NOT NULL,\n  PRIMARY KEY (`object_id`,`term_taxonomy_id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
  'MEMORY engine'           => "CREATE TABLE `$tmp` (`a` int) ENGINE=MEMORY DEFAULT CHARSET=utf8mb4",
];

$fail = 0;
echo "--- MUST REJECT ---\n";
foreach ($must_reject as $l => $s) {
  $r = check($m, $s, $tmp); $ok = $r === 'REJECTED'; $fail += $ok ? 0 : 1;
  printf("%-30s %-8s %s\n", $l, $ok ? 'PASS' : 'FAIL!!', $r);
}
echo "\n--- MUST ACCEPT ---\n";
foreach ($must_accept as $l => $s) {
  $r = check($m, $s, $tmp); $ok = $r !== 'REJECTED'; $fail += $ok ? 0 : 1;
  printf("%-30s %-8s %s\n", $l, $ok ? 'PASS' : 'FAIL!!', $r);
}
echo "\n" . ($fail === 0 ? "ALL PASS (in-plugin code)\n" : "$fail FAILURE(S)\n");
