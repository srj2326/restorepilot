<?php
require __DIR__ . '/bootstrap.php';

$_REQUEST['_ajax_nonce'] = wp_create_nonce(RestorePilot_Backup_Migration::NONCE);
$_POST['confirm_word'] = 'RESET';

echo "=== pick_master_reset_theme() ===\n";
$ref = new ReflectionMethod('RestorePilot_Backup_Migration', 'pick_master_reset_theme');
$ref->setAccessible(true);
echo "Picked theme: " . $ref->invoke(null) . "\n";

echo "=== running handle_master_reset() ===\n";
RestorePilot_Backup_Migration::handle_master_reset();
