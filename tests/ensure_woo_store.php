<?php
/**
 * Makes sure the WooCommerce store the restore test needs is actually there.
 *
 * The store is not durable, and cannot be: several tests in this suite restore
 * a fixture backup that predates it, which replaces wp_posts and takes the
 * products with it. The order tables survive that, because the old backup has
 * no idea they exist -- so the site is left holding 399 orders for 0 products,
 * which looks enough like a store to fool a precondition check that only counts
 * orders. That is exactly what happened: test_woocommerce_restore ran after
 * test_create_new_admin for the first time and failed four checks in, on a
 * serialized-meta assertion, rather than saying the store was gone.
 *
 * So the test's requirement is stated here and re-established when it is
 * missing, and the test no longer depends on running before anything that
 * restores an old backup. In the usual order this finds the store intact and
 * costs a single query.
 */

define('WP_USE_THEMES', false);
require_once '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public/wp-load.php';

if (!class_exists('WooCommerce')) {
    fwrite(STDERR, "  ensure-store: WooCommerce is not active; nothing to do\n");
    exit(0);
}

global $wpdb;
$products  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product'");
$orders    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders");
$customers = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");

// Judged on products, because that is the part a restore of an older backup
// destroys while leaving the order tables standing.
if ($products > 0 && $orders > 0) {
    exit(0);
}

echo "  ensure-store: store incomplete ($products products, $orders orders, $customers users) — reseeding\n";

// Orders reference products by id, so a half-store cannot be topped up: the
// surviving orders point at products that no longer exist. Clear it out and
// build a whole one, which is also what makes the fingerprints in the test
// mean anything.
$wpdb->query("DELETE FROM {$wpdb->prefix}wc_order_addresses");
$wpdb->query("DELETE FROM {$wpdb->prefix}wc_order_operational_data");
$wpdb->query("DELETE FROM {$wpdb->prefix}wc_orders_meta");
$wpdb->query("DELETE FROM {$wpdb->prefix}wc_orders");
$wpdb->query("DELETE FROM {$wpdb->prefix}woocommerce_order_itemmeta");
$wpdb->query("DELETE FROM {$wpdb->prefix}woocommerce_order_items");

$seed = __DIR__ . '/seed_woocommerce.php';
if (!is_file($seed)) {
    fwrite(STDERR, "  ensure-store: seed_woocommerce.php is missing\n");
    exit(1);
}

$php  = PHP_BINARY;
$sock = ini_get('mysqli.default_socket');
$cmd  = escapeshellarg($php)
      . ' -d ' . escapeshellarg('mysqli.default_socket=' . $sock)
      . ' -d ' . escapeshellarg('pdo_mysql.default_socket=' . $sock)
      . ' -d memory_limit=1024M '
      . escapeshellarg($seed) . ' 60 120 400 2>&1';

$output = [];
$code = 0;
exec($cmd, $output, $code);
if ($code !== 0) {
    fwrite(STDERR, "  ensure-store: reseeding failed\n" . implode("\n", array_slice($output, -12)) . "\n");
    exit(1);
}

// Read the result back rather than trusting the exit code.
$wpdb->flush();
$products = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product'");
$orders   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders");
echo "  ensure-store: rebuilt — $products products, $orders orders\n";

exit(($products > 0 && $orders > 0) ? 0 : 1);
