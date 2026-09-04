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

// Replicate the CURRENT validation logic from build_restore_plan() exactly.
function current_checks(string $create, string $tmp_table): string {
  if (!preg_match('/^CREATE TABLE `' . preg_quote($tmp_table, '/') . '`\s*\(/i', $create)) {
    return 'REJECTED (prefix check)';
  }
  $outer = (string) preg_replace('/\(.*\)/s', '', $create);
  if (strpos($outer, ';') !== false) {
    return 'REJECTED (semicolon check)';
  }
  return '*** ACCEPTED ***';
}

$tmp = 'wp_restorepilot_rtmp_abc123_0';

$cases = [
  'legit InnoDB' =>
    "CREATE TABLE `$tmp` (\n  `id` bigint(20) NOT NULL AUTO_INCREMENT,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

  'CREATE ... SELECT (exfiltrate users)' =>
    "CREATE TABLE `$tmp` (`x` int) SELECT * FROM wp_users",

  'CREATE ... AS SELECT' =>
    "CREATE TABLE `$tmp` (`x` int) AS SELECT user_pass FROM wp_users",

  'CREATE ... SELECT with subquery parens' =>
    "CREATE TABLE `$tmp` (`x` int) SELECT * FROM (SELECT user_pass FROM wp_users) t",

  'stacked statement after paren block' =>
    "CREATE TABLE `$tmp` (`x` int); DROP TABLE wp_users",
];

foreach ($cases as $label => $sql) {
  printf("%-42s => %s\n", $label, current_checks($sql, $tmp));
}
