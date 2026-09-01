<?php
/**
 * Verifies keyset_cursor_columns() picks a safe pagination cursor.
 *
 * The risk being guarded against is not slowness but silent data loss: choose
 * a cursor that is not genuinely unique-and-non-null and keyset pagination
 * skips or duplicates rows, producing a fast backup that is quietly wrong.
 */

define('WP_USE_THEMES', false);
require_once '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public/wp-load.php';

$pass = 0; $fail = 0; $failures = [];
function check(string $label, bool $ok) {
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "PASS  $label\n"; }
    else { $fail++; $failures[] = $label; echo "FAIL  $label\n"; }
}

$ref = new ReflectionClass('RestorePilot_Backup_Migration');
$m = $ref->getMethod('keyset_cursor_columns');
$m->setAccessible(true);
$cursor = function (string $sql) use ($m) { return $m->invoke(null, $sql); };

// ── 1. A real PRIMARY KEY still wins, unchanged behaviour ──
check('Simple PRIMARY KEY still detected', $cursor(
"CREATE TABLE `wp_posts` (\n  `ID` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `post_title` text,\n  PRIMARY KEY (`ID`)\n) ENGINE=InnoDB"
) === ['ID']);

check('Composite PRIMARY KEY still detected in order', $cursor(
"CREATE TABLE `wp_term_relationships` (\n  `object_id` bigint unsigned NOT NULL DEFAULT '0',\n  `term_taxonomy_id` bigint unsigned NOT NULL DEFAULT '0',\n  PRIMARY KEY (`object_id`,`term_taxonomy_id`)\n) ENGINE=InnoDB"
) === ['object_id', 'term_taxonomy_id']);

check('PRIMARY KEY preferred over a UNIQUE key present alongside it', $cursor(
"CREATE TABLE `t` (\n  `id` int NOT NULL AUTO_INCREMENT,\n  `slug` varchar(50) NOT NULL,\n  PRIMARY KEY (`id`),\n  UNIQUE KEY `slug` (`slug`)\n) ENGINE=InnoDB"
) === ['id']);

// ── 2. The actual bug: UNIQUE NOT NULL now usable ──
check('THE FIX: UNIQUE KEY on a NOT NULL column is used (wp_cf7_vdata_entry shape)', $cursor(
"CREATE TABLE `wp_cf7_vdata_entry` (\n  `id` int NOT NULL AUTO_INCREMENT,\n  `cf7_id` int NOT NULL,\n  `name` varchar(250) CHARACTER SET utf8mb4 DEFAULT NULL,\n  `value` text CHARACTER SET utf8mb4,\n  UNIQUE KEY `id` (`id`)\n) ENGINE=InnoDB"
) === ['id']);

check('UNIQUE INDEX spelling also recognised', $cursor(
"CREATE TABLE `t` (\n  `id` int NOT NULL,\n  UNIQUE INDEX `id` (`id`)\n) ENGINE=InnoDB"
) === ['id']);

check('Composite UNIQUE key, all columns NOT NULL', $cursor(
"CREATE TABLE `t` (\n  `a` int NOT NULL,\n  `b` int NOT NULL,\n  UNIQUE KEY `ab` (`a`,`b`)\n) ENGINE=InnoDB"
) === ['a', 'b']);

// ── 3. Safety: never pick an unsafe cursor ──
check('SAFETY: nullable UNIQUE column is REFUSED (MySQL allows repeated NULLs)', $cursor(
"CREATE TABLE `t` (\n  `id` int DEFAULT NULL,\n  UNIQUE KEY `id` (`id`)\n) ENGINE=InnoDB"
) === []);

check('SAFETY: composite UNIQUE with one nullable column is REFUSED', $cursor(
"CREATE TABLE `t` (\n  `a` int NOT NULL,\n  `b` int DEFAULT NULL,\n  UNIQUE KEY `ab` (`a`,`b`)\n) ENGINE=InnoDB"
) === []);

check('SAFETY: a plain non-unique KEY is never used as a cursor', $cursor(
"CREATE TABLE `t` (\n  `id` int NOT NULL,\n  KEY `id` (`id`)\n) ENGINE=InnoDB"
) === []);

check('SAFETY: no key at all still returns empty (OFFSET fallback)', $cursor(
"CREATE TABLE `t` (\n  `a` int NOT NULL,\n  `b` text\n) ENGINE=InnoDB"
) === []);

check('SAFETY: FULLTEXT KEY is not mistaken for a unique cursor', $cursor(
"CREATE TABLE `t` (\n  `body` text NOT NULL,\n  FULLTEXT KEY `body` (`body`)\n) ENGINE=InnoDB"
) === []);

// ── 4. Parsing edge cases that could produce a wrong column name ──
check('Key-length specifier stripped from the column name', $cursor(
"CREATE TABLE `t` (\n  `slug` varchar(255) NOT NULL,\n  UNIQUE KEY `slug` (`slug`(191))\n) ENGINE=InnoDB"
) === ['slug']);

check('A nullable column whose name contains "id" does not confuse detection', $cursor(
"CREATE TABLE `t` (\n  `valid` int DEFAULT NULL,\n  `id` int NOT NULL,\n  UNIQUE KEY `k` (`id`)\n) ENGINE=InnoDB"
) === ['id']);

check('Unusable first UNIQUE key falls through to a usable second one', $cursor(
"CREATE TABLE `t` (\n  `a` int DEFAULT NULL,\n  `b` int NOT NULL,\n  UNIQUE KEY `ka` (`a`),\n  UNIQUE KEY `kb` (`b`)\n) ENGINE=InnoDB"
) === ['b']);

check('DEFAULT NULL on a later column does not un-NOT-NULL an earlier one', $cursor(
"CREATE TABLE `t` (\n  `id` int NOT NULL AUTO_INCREMENT,\n  `note` text DEFAULT NULL,\n  UNIQUE KEY `id` (`id`)\n) ENGINE=InnoDB"
) === ['id']);

// ── 5. Against a real table in this database, read back from MySQL ──
// It used to read wp_cf7_vdata_entry, which happened to be on the test site
// because an old backup had put it there -- so the check evaporated the moment
// the site was cleaned up. A test that needs a table of a particular shape
// should make one; borrowing whatever is lying around is how a check quietly
// stops existing.
global $wpdb;
$live_table = $wpdb->prefix . 'rp_keyset_live';
$wpdb->query("DROP TABLE IF EXISTS `$live_table`");
$wpdb->query(
    "CREATE TABLE `$live_table` (\n"
  . "  `id` int NOT NULL AUTO_INCREMENT,\n"
  . "  `cf7_id` int NOT NULL,\n"
  . "  `name` varchar(250) DEFAULT NULL,\n"
  . "  `value` text,\n"
  . "  UNIQUE KEY `id` (`id`)\n"
  . ") ENGINE=InnoDB"
);
for ($i = 1; $i <= 25; $i++) {
    $wpdb->insert($live_table, ['cf7_id' => $i, 'name' => "n$i", 'value' => "v$i"]);
}
register_shutdown_function(function () use ($wpdb, $live_table) {
    $wpdb->query("DROP TABLE IF EXISTS `$live_table`");
});
$row = $wpdb->get_row("SHOW CREATE TABLE `$live_table`", ARRAY_N);
if ($row && isset($row[1])) {
    $live = $cursor($row[1]);
    check('A live UNIQUE-KEY table resolves to cursor column `id`', $live === ['id']);

    // Prove the chosen cursor really is unique and non-null in the actual data.
    $total  = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$live_table`");
    $unique = (int) $wpdb->get_var("SELECT COUNT(DISTINCT `id`) FROM `$live_table`");
    $nulls  = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$live_table` WHERE `id` IS NULL");
    echo "  (live table: $total rows, $unique distinct ids, $nulls nulls)\n";
    check('LIVE cursor is genuinely unique across every row', $total > 0 && $total === $unique);
    check('LIVE cursor has no NULLs', $nulls === 0);
} else {
    check('LIVE wp_cf7_vdata_entry readable', false);
}

echo "\n";
if ($fail === 0) {
    echo "ALL $pass CHECKS PASSED\n";
} else {
    echo "$fail FAILURE(S): " . implode('; ', $failures) . "\n";
}
exit($fail === 0 ? 0 : 1);
