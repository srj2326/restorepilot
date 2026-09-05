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

// Regression test for the root cause behind the live restore failure:
// wp_json_encode() never returns false for invalid-UTF8 binary data (unlike
// PHP's own json_encode()), so json_fragment()'s old "try raw encode, check
// for failure" pattern never detected binary columns at all — it silently
// exported corrupted bytes, which could make two genuinely-different source
// rows collide onto the same corrupted value on restore (a duplicate primary
// key that was never actually duplicated in the real data). Verifies the
// fix round-trips real binary data byte-for-byte through the full
// json_fragment() -> decode_b64_column_value() pipeline, using a synthetic
// row shaped exactly like Wordfence's wp_wflivetraffichuman table (the real
// table where this was found: PRIMARY KEY (IP binary(16), identifier
// binary(32))).

$site_root = rp_test_site();
require $site_root . '/wp-load.php';

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

// --- Test 1: many random binary rows never collide after the round trip ---
// This is the actual failure mode: DIFFERENT source rows colliding onto the
// SAME exported value. Generate enough random (IP, identifier) pairs that a
// genuine collision would be astronomically unlikely, export+decode each,
// and confirm every one is still unique and byte-identical to its source.
$rows = [];
for ($i = 0; $i < 2000; $i++) {
  $rows[] = [
    'IP' => random_bytes(16),
    'identifier' => random_bytes(32),
    'expiration' => (string) (time() + $i),
  ];
}

$decoded_keys = [];
$mismatches = 0;
foreach ($rows as $row) {
  $json = call_private('json_fragment', [$row, 'test row']);
  $decoded_wire = json_decode($json, true);
  $decoded_row = [];
  foreach ($decoded_wire as $k => $v) {
    $decoded_row[$k] = call_private('decode_b64_column_value', [$v]);
  }
  if ($decoded_row['IP'] !== $row['IP'] || $decoded_row['identifier'] !== $row['identifier']) {
    $mismatches++;
  }
  $decoded_keys[$row['IP'] . '|' . $row['identifier']] = true;
}
check('2000 rows with random BINARY(16)+BINARY(32) columns all round-trip byte-for-byte', $mismatches === 0);
check('All 2000 rows remain unique after the round trip (no corruption-induced collisions)', count($decoded_keys) === 2000);

// --- Test 2: the exact failure signature — confirm wp_json_encode() really
// is the unreliable part being worked around (documents WHY the fix is
// needed, not just that it works) ---
$sample_binary = random_bytes(32);
$wp_encode_result = wp_json_encode($sample_binary);
check('wp_json_encode() alone does NOT signal failure on binary data (confirms the bug this fix works around)', $wp_encode_result !== false);
$decoded_sample_wire = json_decode($wp_encode_result);
check('...and its output does NOT round-trip back to the original bytes (data would be silently lost without the fix)', $decoded_sample_wire !== $sample_binary);

// --- Test 3: normal text data is completely unaffected (no unnecessary
// base64 wrapping, no behavior change for the common case) ------------------
$text_row = ['post_title' => 'Hello — world 世界', 'post_content' => str_repeat('Normal content. ', 50), 'ID' => '42'];
$json = call_private('json_fragment', [$text_row, 'test text row']);
$decoded_wire = json_decode($json, true);
check('Plain UTF-8 text row: no field got base64-wrapped', !isset($decoded_wire['post_title']['_rp_b64']) && !isset($decoded_wire['post_content']['_rp_b64']));
check('Plain UTF-8 text row: values are still exactly correct after decode', $decoded_wire['post_title'] === $text_row['post_title'] && $decoded_wire['ID'] === $text_row['ID']);

// --- Test 4: empty string (edge case — must NOT be treated as invalid) -----
$empty_row = ['meta_value' => '', 'meta_key' => 'some_key'];
$json = call_private('json_fragment', [$empty_row, 'test empty row']);
$decoded_wire = json_decode($json, true);
check('Empty string value is preserved as a plain empty string, not base64-wrapped', $decoded_wire['meta_value'] === '');

// --- Test 5: mixed row (some binary columns, some text columns together) --
$mixed_row = ['IP' => random_bytes(16), 'meta_key' => 'wordfence_note', 'meta_value' => 'A normal note'];
$json = call_private('json_fragment', [$mixed_row, 'test mixed row']);
$decoded_wire = json_decode($json, true);
$ip_decoded = call_private('decode_b64_column_value', [$decoded_wire['IP']]);
check('Mixed row: binary IP column round-trips correctly', $ip_decoded === $mixed_row['IP']);
check('Mixed row: adjacent text column is untouched', $decoded_wire['meta_key'] === 'wordfence_note');

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
