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

require __DIR__ . '/bootstrap.php';

$_REQUEST['_ajax_nonce'] = wp_create_nonce(RestorePilot_Backup_Migration::NONCE);
$_POST['confirm_word'] = 'RESET';

echo "=== pick_master_reset_theme() ===\n";
$ref = new ReflectionMethod('RestorePilot_Backup_Migration', 'pick_master_reset_theme');
$ref->setAccessible(true);
echo "Picked theme: " . $ref->invoke(null) . "\n";

echo "=== running handle_master_reset() ===\n";
RestorePilot_Backup_Migration::handle_master_reset();
