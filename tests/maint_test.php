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

require_once __DIR__ . '/env.php';

/**
 * Integration test (real files, no browser/DB) for the maintenance drop-in
 * preserve/restore round-trip — validates this session's data-loss fix and the
 * new inert .txt stash location under uploads.
 */
error_reporting(E_ALL); ini_set('display_errors','1');

$base = sys_get_temp_dir() . '/rp_maint_' . getmypid();
@mkdir($base, 0777, true);
$wpContent = $base . '/wp-content';
$uploads   = $wpContent . '/uploads';
@mkdir($uploads, 0777, true);

$plugin = rp_test_plugin_file();
define('ABSPATH', $base . '/');
define('WP_CONTENT_DIR', $wpContent);
define('HOUR_IN_SECONDS', 3600); define('DAY_IN_SECONDS', 86400); define('MINUTE_IN_SECONDS', 60);

foreach (['add_action','add_filter','register_activation_hook','register_deactivation_hook','register_uninstall_hook'] as $fn) {
  if (!function_exists($fn)) eval("function $fn(){ return true; }");
}
if (!function_exists('plugin_basename')) { function plugin_basename($f){ return basename(dirname($f)).'/'.basename($f); } }
if (!function_exists('plugin_dir_path')) { function plugin_dir_path($f){ return rtrim(dirname($f),'/').'/'; } }
if (!function_exists('plugin_dir_url'))  { function plugin_dir_url($f){ return 'http://e.test/'; } }
if (!function_exists('plugins_url'))     { function plugins_url($p='',$f=''){ return 'http://e.test/'.ltrim($p,'/'); } }
if (!function_exists('__'))         { function __($s,$d=null){ return $s; } }
if (!function_exists('esc_html__')) { function esc_html__($s,$d=null){ return $s; } }
if (!function_exists('esc_attr__')) { function esc_attr__($s,$d=null){ return $s; } }
if (!function_exists('esc_html_e')) { function esc_html_e($s,$d=null){ echo $s; } }
if (!function_exists('esc_attr_e')) { function esc_attr_e($s,$d=null){ echo $s; } }
if (!function_exists('trailingslashit')) { function trailingslashit($s){ return rtrim($s,'/\\').'/'; } }
if (!function_exists('wp_mkdir_p')) { function wp_mkdir_p($d){ return is_dir($d) || mkdir($d, 0777, true); } }
$GLOBALS['__uploads'] = $uploads;
if (!function_exists('wp_upload_dir')) { function wp_upload_dir($t=null,$c=true){ return ['basedir'=>$GLOBALS['__uploads'],'baseurl'=>'http://e.test/uploads','error'=>false]; } }

require $plugin;

$rc = new ReflectionClass('RestorePilot_Backup_Migration');
function priv($rc,$m,$a=[]){ $x=$rc->getMethod($m); $x->setAccessible(true); return $x->invokeArgs(null,$a); }
$pass=0;$fail=0;
function check($l,$c){ global $pass,$fail; if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";} }

$dropin = trailingslashit(WP_CONTENT_DIR).'maintenance.php';
$stash  = priv($rc,'maintenance_dropin_backup_file');
echo "stash path: $stash\n";
check('stash lives under uploads (not wp-content root) and is .txt',
  strpos($stash, $GLOBALS['__uploads']) === 0 && substr($stash,-4) === '.txt');

echo "== Case 1: a third-party maintenance.php exists ==\n";
file_put_contents($dropin, "<?php // THIRD-PARTY MAINTENANCE PAGE v1\n");
priv($rc,'install_maintenance_dropin');
$afterInstall = file_get_contents($dropin);
check('our drop-in installed (marker present)', strpos($afterInstall, 'RestorePilot maintenance drop-in') !== false);
check('third-party contents stashed to .txt', is_file($stash) && strpos(file_get_contents($stash),'THIRD-PARTY MAINTENANCE PAGE v1') !== false);
priv($rc,'remove_maintenance_dropin');
check('third-party drop-in restored verbatim', is_file($dropin) && trim(file_get_contents($dropin)) === '<?php // THIRD-PARTY MAINTENANCE PAGE v1');
check('stash cleaned up after restore', !is_file($stash));

echo "== Case 2: NO pre-existing drop-in (common case) ==\n";
@unlink($dropin); @unlink($stash);
priv($rc,'install_maintenance_dropin');
check('our drop-in installed', is_file($dropin) && strpos(file_get_contents($dropin),'RestorePilot maintenance drop-in') !== false);
check('no stray stash created', !is_file($stash));
priv($rc,'remove_maintenance_dropin');
check('our drop-in removed on teardown', !is_file($dropin));

echo "== Case 3: our OWN stale drop-in already present ==\n";
file_put_contents($dropin, "<?php /* RestorePilot maintenance drop-in stale */\n");
priv($rc,'install_maintenance_dropin');
check('stale own drop-in overwritten, no stash of our own file', is_file($dropin) && !is_file($stash));
priv($rc,'remove_maintenance_dropin');
check('cleaned up', !is_file($dropin));

// cleanup temp tree
exec('rm -rf ' . escapeshellarg($base));
echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail===0?0:1);
