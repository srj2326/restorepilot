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

function single_column_primary_key(string $create_sql): string {
    if (preg_match('/PRIMARY KEY\s*\(\s*`([^`,]+)`\s*\)/i', $create_sql, $m)) {
      return $m[1];
    }
    return '';
}

$posts = "CREATE TABLE `wp_posts` (\n  `ID` bigint(20) unsigned NOT NULL auto_increment,\n  `post_author` bigint(20) unsigned NOT NULL default 0,\n  PRIMARY KEY  (`ID`),\n  KEY `post_name` (`post_name`(191))\n) ENGINE=InnoDB";
echo "posts PK: [" . single_column_primary_key($posts) . "] (expect ID)\n";

$options = "CREATE TABLE `wp_options` (\n  `option_id` bigint(20) unsigned NOT NULL auto_increment,\n  `option_name` varchar(191) NOT NULL default '',\n  PRIMARY KEY  (`option_id`),\n  UNIQUE KEY `option_name` (`option_name`)\n) ENGINE=InnoDB";
echo "options PK: [" . single_column_primary_key($options) . "] (expect option_id)\n";

$term_rel = "CREATE TABLE `wp_term_relationships` (\n  `object_id` bigint(20) unsigned NOT NULL default 0,\n  `term_taxonomy_id` bigint(20) unsigned NOT NULL default 0,\n  `term_order` int(11) NOT NULL default 0,\n  PRIMARY KEY  (`object_id`,`term_taxonomy_id`),\n  KEY `term_taxonomy_id` (`term_taxonomy_id`)\n) ENGINE=InnoDB";
echo "term_relationships PK: [" . single_column_primary_key($term_rel) . "] (expect EMPTY - composite)\n";

$no_pk = "CREATE TABLE `some_log` (\n  `created` datetime NOT NULL,\n  `message` text\n) ENGINE=InnoDB";
echo "no_pk PK: [" . single_column_primary_key($no_pk) . "] (expect EMPTY - no PK)\n";

$usermeta = "CREATE TABLE `wp_usermeta` (\n  `umeta_id` bigint(20) unsigned NOT NULL auto_increment,\n  `user_id` bigint(20) unsigned NOT NULL default 0,\n  `meta_key` varchar(255) default NULL,\n  `meta_value` longtext,\n  PRIMARY KEY  (`umeta_id`),\n  KEY `user_id` (`user_id`)\n) ENGINE=InnoDB";
echo "usermeta PK: [" . single_column_primary_key($usermeta) . "] (expect umeta_id)\n";
