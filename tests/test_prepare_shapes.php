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

// Exercise WordPress's real wpdb::prepare() (no DB connection needed for
// prepare() itself) to confirm the %i statements this plugin now builds
// render into the SQL we intend.
define('ABSPATH', '/Users/surajitroy/Local Sites/morecalculators-dev/app/public/');
define('WP_DEBUG', false);
function __($s, $d = null) { return $s; }
function _doing_it_wrong($f, $m, $v) { echo "  [doing_it_wrong] $f: $m\n"; }
function wp_load_translations_early() {}
function esc_sql($s) { return $s; }
function has_filter($t, $f = false) { return false; }
function apply_filters($t, $v) { return $v; }
function add_filter($t, $f, $p = 10, $a = 1) { return true; }
function remove_filter($t, $f, $p = 10) { return true; }
require ABSPATH . 'wp-includes/class-wpdb.php';

// prepare() needs no live connection — skip the connecting constructor.
class TestWpdb extends wpdb {
  public function __construct() {}
}
$wpdb = new TestWpdb();

echo "=== RENAME TABLE (2 tables, one with a pre-existing target) ===\n";
$pairs = ['%i TO %i', '%i TO %i', '%i TO %i'];
$args  = ['wp_options', 'wp_restorepilot_rold_x_0', 'wp_restorepilot_rtmp_x_0', 'wp_options', 'wp_restorepilot_rtmp_x_1', 'wp_posts'];
echo $wpdb->prepare('RENAME TABLE ' . implode(', ', $pairs), $args) . "\n\n";

echo "=== DROP TABLE IF EXISTS ===\n";
echo $wpdb->prepare('DROP TABLE IF EXISTS %i', 'wp_restorepilot_rtmp_x_0') . "\n\n";

echo "=== SHOW CREATE TABLE ===\n";
echo $wpdb->prepare('SHOW CREATE TABLE %i', 'wp_options') . "\n\n";

echo "=== TRUNCATE ===\n";
echo $wpdb->prepare('TRUNCATE TABLE %i', 'wp_posts') . "\n\n";

echo "=== keyset first page, single-col PK ===\n";
$pk = ['option_id'];
$order_by = implode(', ', array_fill(0, count($pk), '%i ASC'));
echo $wpdb->prepare("SELECT * FROM %i ORDER BY {$order_by} LIMIT %d", array_merge(['wp_options'], $pk, [500])) . "\n\n";

echo "=== keyset next page, COMPOSITE PK ===\n";
$pk = ['object_id', 'term_taxonomy_id'];
$colph = implode(', ', array_fill(0, count($pk), '%i'));
$order_by = implode(', ', array_fill(0, count($pk), '%i ASC'));
$valph = implode(', ', array_fill(0, count($pk), '%s'));
$tuple = '(' . $colph . ')';
$value_tuple = '(' . $valph . ')';
$last_seen = [12, 34];
echo $wpdb->prepare(
  "SELECT * FROM %i WHERE {$tuple} > {$value_tuple} ORDER BY {$order_by} LIMIT %d",
  array_merge(['wp_term_relationships'], $pk, $last_seen, $pk, [500])
) . "\n\n";

echo "=== offset fallback ===\n";
echo $wpdb->prepare('SELECT * FROM %i LIMIT %d OFFSET %d', 'wp_nokey', 500, 1000) . "\n\n";

echo "=== options NOT IN keep-list ===\n";
$keep = ['siteurl', 'home', 'blogname'];
$ph = implode(', ', array_fill(0, count($keep), '%s'));
echo $wpdb->prepare("DELETE FROM %i WHERE option_name NOT IN ({$ph})", array_merge(['wp_options'], $keep)) . "\n\n";

echo "=== master reset user deletes ===\n";
echo $wpdb->prepare('DELETE FROM %i WHERE ID != %d', 'wp_users', 3) . "\n";
echo $wpdb->prepare('SELECT COUNT(*) FROM %i', 'wp_users') . "\n\n";

echo "=== injection attempt through an identifier ===\n";
echo $wpdb->prepare('DROP TABLE IF EXISTS %i', 'wp_x`; DROP TABLE wp_users; --') . "\n";
