<?php
/**
 * A backup and restore of a real WooCommerce store, checked the way a store
 * owner would care about: are the orders still there, and are they still
 * correct?
 *
 * Counting tables is not enough here. An order is spread across wp_wc_orders,
 * wp_wc_order_addresses, wp_wc_order_operational_data, wp_wc_orders_meta,
 * wp_woocommerce_order_items and wp_woocommerce_order_itemmeta. Lose one row
 * from any of them and the order is quietly wrong rather than obviously
 * missing -- a total that no longer matches its line items, an address that
 * belongs to nobody. So this fingerprints every order before the backup and
 * compares fingerprints afterwards.
 *
 * It also matters that these tables are ones RestorePilot has no special
 * knowledge of. To it they are simply another plugin's tables, which is
 * exactly the case worth proving.
 */

define('WP_USE_THEMES', false);
require_once '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public/wp-load.php';

$failures = [];
function check(string $label, bool $ok, string $detail = '') {
    global $failures;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if ($detail !== '') { echo '        ' . $detail . "\n"; }
    if (!$ok) { $failures[] = $label; }
}
function call_private($name, $args = []) {
    $m = new ReflectionMethod('RestorePilot_Backup_Migration', $name);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
}

if (!class_exists('WooCommerce')) {
    // Deliberately does NOT print a passing line. This test skipping is not a
    // neutral event: it means the store this suite relies on is not loaded,
    // usually because an earlier restore test put back a backup that predates
    // it. Announcing a pass here let the suite report green while its largest
    // test checked nothing.
    echo "SKIP  WooCommerce is not active on this site, so nothing was verified\n";
    exit(0);
}

global $wpdb;

/**
 * One string per order capturing everything that must survive: its own row,
 * its addresses, its line items and their meta. Any lost or altered row
 * changes the fingerprint.
 */
function order_fingerprints(): array {
    global $wpdb;
    $out = [];
    $ids = $wpdb->get_col("SELECT id FROM {$wpdb->prefix}wc_orders ORDER BY id");
    foreach ($ids as $id) {
        $id = (int) $id;
        $core = $wpdb->get_row($wpdb->prepare(
            "SELECT status, currency, total_amount, customer_id, billing_email, date_created_gmt
             FROM {$wpdb->prefix}wc_orders WHERE id = %d", $id), ARRAY_A);
        $addr = $wpdb->get_results($wpdb->prepare(
            "SELECT address_type, first_name, last_name, address_1, city, postcode, country, email, phone
             FROM {$wpdb->prefix}wc_order_addresses WHERE order_id = %d ORDER BY address_type", $id), ARRAY_A);
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT order_item_id, order_item_name, order_item_type
             FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id = %d ORDER BY order_item_id", $id), ARRAY_A);
        $item_meta = [];
        foreach ($items as $it) {
            $item_meta[] = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta
                 WHERE order_item_id = %d ORDER BY meta_key, meta_value", (int) $it['order_item_id']), ARRAY_A);
        }
        $meta = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->prefix}wc_orders_meta
             WHERE order_id = %d ORDER BY meta_key, meta_value", $id), ARRAY_A);

        $out[$id] = sha1(wp_json_encode([$core, $addr, $items, $item_meta, $meta]));
    }
    return $out;
}

function table_counts(): array {
    global $wpdb;
    $counts = [];
    foreach ([
        'wc_orders', 'wc_order_addresses', 'wc_order_operational_data', 'wc_orders_meta',
        'woocommerce_order_items', 'woocommerce_order_itemmeta', 'wc_customer_lookup',
    ] as $t) {
        $counts[$t] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}{$t}");
    }
    $counts['products'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product'");
    $counts['customers'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");
    return $counts;
}

// --- Before -----------------------------------------------------------------
$before_counts = table_counts();
$before_orders = order_fingerprints();
printf("Before: %d orders, %d order items, %d products, %d users\n",
    $before_counts['wc_orders'], $before_counts['woocommerce_order_items'],
    $before_counts['products'], $before_counts['customers']);
// Products matter as much as orders here, and are the part that goes missing:
// a restore of a backup predating the store replaces wp_posts while leaving the
// order tables standing, so the site keeps 399 orders for 0 products. Counting
// only orders called that a real store and failed four checks later on a
// serialized-meta assertion instead.
$store_ok = $before_counts['wc_orders'] > 0
    && $before_counts['woocommerce_order_items'] > 0
    && $before_counts['products'] > 0;
check('There is a real store to test against', $store_ok,
    sprintf('%d orders, %d items, %d products', $before_counts['wc_orders'],
        $before_counts['woocommerce_order_items'], $before_counts['products']));
if (!$store_ok) {
    echo "\nSKIP  the store is not intact, so nothing below would mean anything\n";
    exit(1);
}

// A serialized value with a URL nested inside it, to prove replacement walks
// serialized structures without corrupting their length prefixes.
$probe_product = (int) $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type='product' ORDER BY ID LIMIT 1");
// Written fresh each run rather than assumed. A previous run that failed
// partway could have left this clobbered, and a fixture the test merely hopes
// is intact tells you nothing when it is not.
$probe_expected = [
    'source' => home_url('/wp-content/uploads/probe-image.png'),
    'nested' => ['deep' => ['url' => home_url('/deep/probe'), 'label' => 'Ampersand & "quoted" — unicode ✓']],
    'count'  => 42,
];
update_post_meta($probe_product, '_test_serialized', $probe_expected);
$probe_before = get_post_meta($probe_product, '_test_serialized', true);
check('Serialized probe written and readable before the backup',
    is_array($probe_before) && isset($probe_before['nested']['deep']['url']));

// --- Back up ----------------------------------------------------------------
echo "\nTaking a full backup...\n";
$t0 = microtime(true);
$backup = call_private('create_backup_package', [true]);
// create_backup_package() returns the file's basename, not its path -- the
// path is basename plus the backup directory.
$backup_path = !empty($backup['file'])
    ? rtrim(call_private('backup_dir'), '/') . '/' . $backup['file']
    : '';
printf("Backup: %s (%.0fs)\n", $backup['file'] ?? '?', microtime(true) - $t0);
check('Backup was created', $backup_path !== '' && is_file($backup_path),
    $backup_path !== '' ? $backup_path : 'no file returned');
if ($backup_path === '' || !is_file($backup_path)) {
    echo "\n" . count($failures) . " FAILURE(S)\n";
    exit(1);
}

// --- Change the store, so a restore has something to undo -------------------
$deleted_order = (int) array_key_first($before_orders);
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wc_orders WHERE id = %d", $deleted_order));
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wc_order_addresses WHERE order_id = %d", $deleted_order));
$wpdb->query("UPDATE {$wpdb->prefix}wc_orders SET total_amount = 999999 WHERE id = (SELECT id FROM (SELECT id FROM {$wpdb->prefix}wc_orders ORDER BY id LIMIT 1) x)");
update_post_meta($probe_product, '_test_serialized', ['source' => 'CLOBBERED']);
$after_damage = table_counts();
check('The store was genuinely damaged before restoring (so the restore has work to do)',
    $after_damage['wc_orders'] < $before_counts['wc_orders'],
    sprintf('orders %d -> %d', $before_counts['wc_orders'], $after_damage['wc_orders']));

// --- Restore ----------------------------------------------------------------
echo "\nRestoring...\n";
$restore_zip = call_private('storage_dir') . '/restore-upload-' . wp_generate_uuid4() . '.zip';
copy($backup_path, $restore_zip);
$job_id = 'rp-woo-' . wp_generate_uuid4();
$token  = wp_generate_password(32, false, false);
call_private('set_restore_job', [$job_id, [
    'status' => 'queued', 'phase' => 'queued', 'progress' => 0, 'message' => 'Queued',
    'restore_zip_path' => $restore_zip,
    'auto_detect_urls' => true, 'restore_files' => false, 'create_new_admin' => false,
    'token' => $token, 'poll_token' => wp_generate_password(32, false, false), 'created' => time(),
]]);

$t0 = microtime(true);
$deadline = time() + 2400;
do {
    call_private('run_restore_job', [$job_id, $token]);
    $job = call_private('get_restore_job', [$job_id, true]);
    $status = $job['status'] ?? '';
} while (!in_array($status, ['complete', 'error', 'stale'], true) && time() < $deadline);
printf("Restore: %s (%.0fs)\n", $status, microtime(true) - $t0);
check('Restore completed', $status === 'complete', $job['message'] ?? '');

// --- After ------------------------------------------------------------------
$wpdb->flush();
wp_cache_flush();
$after_counts = table_counts();
$after_orders = order_fingerprints();

echo "\n";
foreach ($before_counts as $table => $n) {
    check(sprintf('%s restored to its original count (%d)', $table, $n),
        ($after_counts[$table] ?? -1) === $n,
        sprintf('before %d, after %d', $n, $after_counts[$table] ?? -1));
}

// The point of the whole test: every order identical, field for field.
$missing = array_diff_key($before_orders, $after_orders);
$extra   = array_diff_key($after_orders, $before_orders);
$changed = [];
foreach ($before_orders as $id => $fp) {
    if (isset($after_orders[$id]) && $after_orders[$id] !== $fp) { $changed[] = $id; }
}
check('No order went missing', empty($missing), $missing ? 'missing ids: ' . implode(', ', array_slice(array_keys($missing), 0, 5)) : '');
check('No unexpected order appeared', empty($extra));
check('Every order is byte-for-byte identical (row, addresses, items, item meta, order meta)',
    empty($changed),
    $changed ? count($changed) . ' changed, e.g. ' . implode(', ', array_slice($changed, 0, 5)) : count($before_orders) . ' orders verified');

// Serialized meta survived the restore and its URL replacement.
$probe_after = get_post_meta($probe_product, '_test_serialized', true);
check('Serialized product meta was restored, not left clobbered',
    is_array($probe_after) && isset($probe_after['nested']['deep']['url']),
    is_array($probe_after) ? '' : 'came back as: ' . var_export($probe_after, true));
check('Serialized structure survived intact -- nested URL, entities and unicode all match',
    is_array($probe_after) && $probe_after == $probe_expected,
    (is_array($probe_after) && $probe_after != $probe_expected)
        ? 'differs: ' . wp_json_encode($probe_after) : '');
// A serialized string whose length prefix no longer matches its content
// unserializes to false; proving it survived means proving it still parses.
$raw = $wpdb->get_var($wpdb->prepare(
    "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_test_serialized'", $probe_product));
check('The stored serialized string still parses (length prefixes intact)',
    is_string($raw) && @unserialize($raw) !== false);

// Cleanup.
@unlink($restore_zip);
delete_option('restorepilot_restore_job_' . sanitize_key($job_id));
call_private('force_release_restore_locks', [$job_id]);

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
