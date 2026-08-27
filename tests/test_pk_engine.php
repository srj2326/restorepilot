<?php
function primary_key_columns(string $create_sql): array {
  if (!preg_match('/PRIMARY KEY\s*\(((?:[^()]|\(\d+\))*)\)/i', $create_sql, $m)) {
    return [];
  }
  $columns = [];
  foreach (explode(',', $m[1]) as $part) {
    $part = trim(preg_replace('/\(\d+\)\s*(ASC|DESC)?\s*$/i', '', trim($part)));
    $part = trim($part, "` \t\n\r\0\x0B");
    if ($part !== '' && preg_match('/^[A-Za-z0-9_]+$/', $part)) {
      $columns[] = $part;
    }
  }
  return $columns;
}

function table_engine(string $create_sql): string {
  if (preg_match('/\)\s*ENGINE\s*=\s*([A-Za-z0-9_]+)/i', $create_sql, $m)) {
    return $m[1];
  }
  return '';
}

$cases = [
  'wp_options (single col)' => "CREATE TABLE `wp_options` (\n  `option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `option_name` varchar(191) NOT NULL DEFAULT '',\n  PRIMARY KEY (`option_id`),\n  UNIQUE KEY `option_name` (`option_name`)\n) ENGINE=InnoDB AUTO_INCREMENT=181 DEFAULT CHARSET=utf8mb4",

  'wp_term_relationships (composite)' => "CREATE TABLE `wp_term_relationships` (\n  `object_id` bigint(20) unsigned NOT NULL DEFAULT 0,\n  `term_taxonomy_id` bigint(20) unsigned NOT NULL DEFAULT 0,\n  `term_order` int(11) NOT NULL DEFAULT 0,\n  PRIMARY KEY (`object_id`,`term_taxonomy_id`),\n  KEY `term_taxonomy_id` (`term_taxonomy_id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

  'no PK' => "CREATE TABLE `wp_something` (\n  `a` int(11) NOT NULL,\n  KEY `a` (`a`)\n) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4",

  'PK with key-length spec' => "CREATE TABLE `weird` (\n  `a` varchar(255) NOT NULL,\n  `b` int(11) NOT NULL,\n  PRIMARY KEY (`a`(100),`b`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

  'MyISAM single col' => "CREATE TABLE `legacy` (\n  `id` bigint(20) NOT NULL AUTO_INCREMENT,\n  PRIMARY KEY (`id`)\n) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4",
];

foreach ($cases as $label => $sql) {
  $pk = primary_key_columns($sql);
  $engine = table_engine($sql);
  printf("%-35s pk=%-30s engine=%s\n", $label, json_encode($pk), $engine);
}
