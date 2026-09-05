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

/**
 * Builds a WooCommerce dataset worth testing a backup plugin against.
 *
 * Written through WooCommerce's own CRUD classes rather than by inserting
 * rows, so the records land wherever this version actually keeps them --
 * including the HPOS order tables, which is the interesting part: real
 * business data living outside wp_posts, in tables RestorePilot only knows as
 * "some other plugin's".
 *
 * Usage: seed_woocommerce.php [products] [customers] [orders]
 */

require_once __DIR__ . '/env.php';
rp_test_boot();

if (!class_exists('WooCommerce')) {
    fwrite(STDERR, "WooCommerce is not loaded\n");
    exit(1);
}

$n_products  = (int) ($argv[1] ?? 60);
$n_customers = (int) ($argv[2] ?? 120);
$n_orders    = (int) ($argv[3] ?? 400);

echo "Seeding: $n_products products, $n_customers customers, $n_orders orders\n";
$started = microtime(true);

// --- Products -------------------------------------------------------------
$adjectives = ['Copper', 'Linen', 'Walnut', 'Slate', 'Amber', 'Cedar', 'Ivory', 'Onyx'];
$nouns      = ['Kettle', 'Lamp', 'Chair', 'Satchel', 'Mug', 'Notebook', 'Planter', 'Clock'];
$product_ids = [];

for ($i = 0; $i < $n_products; $i++) {
    $p = new WC_Product_Simple();
    $p->set_name($adjectives[$i % count($adjectives)] . ' ' . $nouns[intdiv($i, count($adjectives)) % count($nouns)] . ' #' . ($i + 1));
    $p->set_regular_price((string) (5 + ($i * 3) % 200));
    $p->set_description('A test product. Ampersands & "quotes" and <em>markup</em>, plus a URL: http://sunhsine-bkp.local/product/' . ($i + 1));
    $p->set_short_description('Short description ' . ($i + 1));
    $p->set_sku('TEST-SKU-' . str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT));
    $p->set_manage_stock(true);
    $p->set_stock_quantity(10 + ($i % 90));
    $p->set_status('publish');
    // Serialized meta with a URL inside -- the case URL replacement has to
    // handle without corrupting the serialized length prefix.
    $p->update_meta_data('_test_serialized', [
        'source' => 'http://sunhsine-bkp.local/wp-content/uploads/img-' . $i . '.png',
        'nested' => ['deep' => ['url' => 'http://sunhsine-bkp.local/deep/' . $i]],
    ]);
    $product_ids[] = $p->save();
    if ($i % 20 === 19) { echo '  products: ' . ($i + 1) . "\n"; }
}

// --- Customers ------------------------------------------------------------
$customer_ids = [];
for ($i = 0; $i < $n_customers; $i++) {
    $email = 'testcustomer' . ($i + 1) . '@example.test';
    $existing = get_user_by('email', $email);
    if ($existing) { $customer_ids[] = $existing->ID; continue; }

    $c = new WC_Customer();
    $c->set_email($email);
    $c->set_username('testcustomer' . ($i + 1));
    $c->set_password(wp_generate_password(16, true, true));
    $c->set_first_name('Test' . ($i + 1));
    $c->set_last_name('Customer');
    $c->set_billing_email($email);
    $c->set_billing_first_name('Test' . ($i + 1));
    $c->set_billing_last_name('Customer');
    $c->set_billing_address_1(($i + 1) . ' Example Street');
    $c->set_billing_city('Testville');
    $c->set_billing_postcode('AB' . str_pad((string) $i, 3, '0', STR_PAD_LEFT));
    $c->set_billing_country('GB');
    $c->set_billing_phone('+44 7700 900' . str_pad((string) ($i % 1000), 3, '0', STR_PAD_LEFT));
    $customer_ids[] = $c->save();
    if ($i % 40 === 39) { echo '  customers: ' . ($i + 1) . "\n"; }
}

// --- Orders ---------------------------------------------------------------
$statuses = ['completed', 'processing', 'on-hold', 'refunded', 'cancelled', 'pending'];
$order_ids = [];
for ($i = 0; $i < $n_orders; $i++) {
    $order = wc_create_order(['customer_id' => $customer_ids[$i % count($customer_ids)]]);
    $lines = 1 + ($i % 4);
    for ($l = 0; $l < $lines; $l++) {
        $product = wc_get_product($product_ids[($i + $l) % count($product_ids)]);
        if ($product) { $order->add_product($product, 1 + ($l % 3)); }
    }
    $cust = new WC_Customer($customer_ids[$i % count($customer_ids)]);
    $order->set_address([
        'first_name' => $cust->get_billing_first_name(),
        'last_name'  => $cust->get_billing_last_name(),
        'email'      => $cust->get_billing_email(),
        'phone'      => $cust->get_billing_phone(),
        'address_1'  => $cust->get_billing_address_1(),
        'city'       => $cust->get_billing_city(),
        'postcode'   => $cust->get_billing_postcode(),
        'country'    => $cust->get_billing_country(),
    ], 'billing');
    $order->set_status($statuses[$i % count($statuses)]);
    $order->add_meta_data('_test_order_marker', 'order-' . ($i + 1));
    $order->calculate_totals();
    $order_ids[] = $order->save();
    if ($i % 50 === 49) { echo '  orders: ' . ($i + 1) . "\n"; }
}

printf("\nDone in %.1fs\n", microtime(true) - $started);
echo 'products: ' . count($product_ids) . ' | customers: ' . count($customer_ids) . ' | orders: ' . count($order_ids) . "\n";

// Record what was made, so a restore can be checked against it rather than
// against "looks about right".
file_put_contents(__DIR__ . '/woo_fixture.json', wp_json_encode([
    'products'  => count($product_ids),
    'customers' => count($customer_ids),
    'orders'    => count($order_ids),
    'order_ids' => $order_ids,
]));
echo "fixture recorded in woo_fixture.json\n";
