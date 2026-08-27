<?php
define('CUSTOM_USER_TABLE', 'wp_shared_users');
require __DIR__ . '/bootstrap.php';

echo "=== uses_custom_user_tables() ===\n";
$ref = new ReflectionMethod('RestorePilot_Backup_Migration', 'uses_custom_user_tables');
$ref->setAccessible(true);
var_dump($ref->invoke(null));

echo "=== create_backup_package() with CUSTOM_USER_TABLE defined ===\n";
$backupRef = new ReflectionMethod('RestorePilot_Backup_Migration', 'create_backup_package');
$backupRef->setAccessible(true);
$result = $backupRef->invoke(null, false, '', [], false, false, ['triggered_by' => 'manual', 'filename' => 'live-test-custom-user-table.zip']);
echo "Backup result: " . json_encode($result) . "\n";

// Inspect the manifest of that backup.
$dirRef = new ReflectionMethod('RestorePilot_Backup_Migration', 'backup_dir');
$dirRef->setAccessible(true);
$dir = $dirRef->invoke(null);
$zip = new ZipArchive();
$zip->open($dir . '/live-test-custom-user-table.zip');
$manifest = json_decode($zip->getFromName('manifest.json'), true);
$zip->close();
echo "Manifest backup_type: " . $manifest['backup_type'] . "\n";
echo "Manifest restorable: " . var_export($manifest['restorable'], true) . "\n";
echo "Manifest custom_user_tables: " . var_export($manifest['custom_user_tables'], true) . "\n";

echo "=== handle_master_reset() refusal with CUSTOM_USER_TABLE defined ===\n";
$_REQUEST['_ajax_nonce'] = wp_create_nonce(RestorePilot_Backup_Migration::NONCE);
$_POST['confirm_word'] = 'RESET';

// Sanity: confirm the site was NOT touched — read a marker before.
global $wpdb;
$users_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");
echo "users before attempted reset: {$users_before}\n";

RestorePilot_Backup_Migration::handle_master_reset();
