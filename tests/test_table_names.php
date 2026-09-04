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

const RESTORE_TMP_TABLE_MARKER = 'restorepilot_rtmp_';
const RESTORE_OLD_TABLE_MARKER = 'restorepilot_rold_';

function restore_scratch_table_name(string $target_prefix, string $marker, string $restore_id, int $index): string {
  $prefix = preg_replace('/[^A-Za-z0-9_]/', '', $target_prefix);
  $suffix = $marker . $restore_id . '_' . $index;
  $max_prefix_len = max(0, 64 - strlen($suffix));
  return substr($prefix, 0, $max_prefix_len) . $suffix;
}

$restore_id = substr(md5('test-uuid'), 0, 12);

$cases = [
  'wp_',
  'wordpress_',
  str_repeat('a', 30),
  str_repeat('b', 45), // longer than MySQL's practical limit but should still not collide
  str_repeat('c', 60),
];

foreach ($cases as $prefix) {
  $n1 = restore_scratch_table_name($prefix, RESTORE_TMP_TABLE_MARKER, $restore_id, 0);
  $n2 = restore_scratch_table_name($prefix, RESTORE_TMP_TABLE_MARKER, $restore_id, 4999);
  $n3 = restore_scratch_table_name($prefix, RESTORE_OLD_TABLE_MARKER, $restore_id, 4999);
  printf("prefix len=%2d  len(n1)=%2d len(n2)=%2d  n1=%s\n", strlen($prefix), strlen($n1), strlen($n2), $n1);
  printf("                                          n2=%s\n", $n2);
  printf("                                          n3=%s\n", $n3);
  if ($n2 === $n3) {
    echo "COLLISION between tmp and old marker names!\n";
  }
  if (strlen($n1) > 64 || strlen($n2) > 64) {
    echo "EXCEEDS 64 CHARS\n";
  }
}
