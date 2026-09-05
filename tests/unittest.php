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
 * Runtime unit tests for the plugin's PURE helpers (no WP/DB needed):
 *  - make_json_safe() / decode_b64_column_value() binary round-trip
 *  - path_is_unsafe() / zip_entry_is_unsafe() traversal guards
 * Exercised via reflection since they are private static methods.
 */
error_reporting(E_ALL); ini_set('display_errors','1');

$plugin = rp_test_plugin_file();
define('ABSPATH', '/tmp/wp-abspath/');
define('WP_CONTENT_DIR', '/tmp/wp-abspath/wp-content');
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
// WP's wp_json_encode returns false on invalid UTF-8 (that's what make_json_safe keys off).
if (!function_exists('wp_json_encode')) { function wp_json_encode($v,$o=0,$d=512){ $r=json_encode($v,$o,$d); return $r===false?false:$r; } }

require $plugin;

$rc = new ReflectionClass('RestorePilot_Backup_Migration');
function call_priv($rc, $m, $args) { $mm = $rc->getMethod($m); $mm->setAccessible(true); return $mm->invokeArgs(null, $args); }

$pass = 0; $fail = 0;
function check($label, $cond) { global $pass,$fail; if ($cond){$pass++; echo "  PASS  $label\n";} else {$fail++; echo "  FAIL  $label\n";} }

echo "== binary-safe DB value round-trip (make_json_safe / decode_b64_column_value) ==\n";
// valid UTF-8 string passes through unchanged
$plain = call_priv($rc, 'make_json_safe', ['hello world']);
check('valid UTF-8 passes through as string', $plain === 'hello world');
// invalid UTF-8 (raw bytes) becomes a base64 sentinel
$binary = "\xff\xfe\x00\x01rp\x80binary";
$wrapped = call_priv($rc, 'make_json_safe', [$binary]);
check('invalid-UTF8 becomes {_rp_b64:1,v:...} sentinel', is_array($wrapped) && ($wrapped['_rp_b64'] ?? 0) === 1 && isset($wrapped['v']));
// and decodes back to the exact original bytes
$decoded = call_priv($rc, 'decode_b64_column_value', [$wrapped]);
check('sentinel decodes back to identical bytes', $decoded === $binary);
// a plain string passed to the decoder is returned unchanged
$passthru = call_priv($rc, 'decode_b64_column_value', ['just a string']);
check('decoder leaves plain string untouched', $passthru === 'just a string');
// nested array is recursively made safe
$nested = call_priv($rc, 'make_json_safe', [['a' => $binary, 'b' => 'ok']]);
check('nested array: binary child wrapped, text child intact',
  is_array($nested) && is_array($nested['a']) && ($nested['a']['_rp_b64'] ?? 0) === 1 && $nested['b'] === 'ok');

echo "== path traversal guards (path_is_unsafe) ==\n";
check("'../etc' is unsafe",          call_priv($rc,'path_is_unsafe',['../etc']) === true);
check("'a/../../b' is unsafe",       call_priv($rc,'path_is_unsafe',['a/../../b']) === true);
check("leading '/abs' is unsafe",    call_priv($rc,'path_is_unsafe',['/abs/path']) === true);
check("NUL byte is unsafe",          call_priv($rc,'path_is_unsafe',["a\0b"]) === true);
check("'wp-content/x.txt' is safe",  call_priv($rc,'path_is_unsafe',['wp-content/x.txt']) === false);

echo "== zip entry guards (zip_entry_is_unsafe) ==\n";
check("'files/../../wp-config.php' unsafe", call_priv($rc,'zip_entry_is_unsafe',['files/../../wp-config.php']) === true);
check("absolute '/etc/passwd' unsafe",      call_priv($rc,'zip_entry_is_unsafe',['/etc/passwd']) === true);
check("windows 'C:/x' unsafe",              call_priv($rc,'zip_entry_is_unsafe',['C:/x']) === true);
check("normal 'files/wp-content/a.php' safe", call_priv($rc,'zip_entry_is_unsafe',['files/wp-content/a.php']) === false);

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
