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

// Smoke-tests build_partial_zip(), which uses RestorePilot_Backup_Zip_Writer
// directly (not through Volume_Writer) — confirms the create() rename there
// didn't break it.

$site_root = '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public';
$plugin_file = '/Users/surajitroy/Local Sites/morecalculators-dev/app/public/wp-content/plugins/restorepilot-backup-migration/restorepilot-backup-migration.php';

require $site_root . '/wp-load.php';
if (!class_exists('RestorePilot_Backup_Migration')) {
  require_once $plugin_file;
}

function call_private($method, array $args = []) {
  $ref = new ReflectionMethod('RestorePilot_Backup_Migration', $method);
  $ref->setAccessible(true);
  return $ref->invokeArgs(null, $args);
}

$failures = [];
function check($label, $cond) {
  global $failures;
  echo ($cond ? 'PASS' : 'FAIL') . "  $label\n";
  if (!$cond) $failures[] = $label;
}

$result = call_private('create_backup_package', [true, '', [], false, false, ['triggered_by' => 'partial-zip-test']]);
$final_zip_path = call_private('backup_dir') . '/' . $result['file'];
check('Fixture backup created', is_file($final_zip_path));

$tmp_partial = call_private('build_partial_zip', [$final_zip_path, 'plugins']);
check('build_partial_zip() returns a path', is_string($tmp_partial) && $tmp_partial !== '');
check('Partial zip file exists', is_file($tmp_partial));

$za = new ZipArchive();
check('Partial zip opens', $za->open($tmp_partial) === true);
check('Partial zip has a manifest', $za->locateName('manifest.json') !== false);
echo 'Partial zip entries: ' . $za->numFiles . "\n";
$za->close();

@unlink($tmp_partial);
foreach (call_private('discover_volumes', [$final_zip_path])['paths'] as $p) {
  @unlink($p);
}

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
