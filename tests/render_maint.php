<?php
/**
 * Renders render_maintenance_page() to a file so the real markup can be
 * inspected without putting the live site into maintenance mode.
 *
 * Stubs the WordPress functions it calls, so nothing here touches the site.
 */

function nocache_headers() {}
function status_header($c) {}
function __($s, $d = '') { return $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

$src = file_get_contents('/Users/surajitroy/Local Sites/morecalculators-dev/app/public/wp-content/plugins/restorepilot-backup-migration/restorepilot-backup-migration.php');

// Lift just the one method out of the class and run it standalone.
$start = strpos($src, 'private static function render_maintenance_page(): void {');
if ($start === false) { fwrite(STDERR, "method not found\n"); exit(1); }

$i = strpos($src, '{', $start);
$depth = 0; $end = $i;
for ($p = $i; $p < strlen($src); $p++) {
    if ($src[$p] === '{') { $depth++; }
    elseif ($src[$p] === '}') { $depth--; if ($depth === 0) { $end = $p; break; } }
}
$body = substr($src, $i + 1, $end - $i - 1);
// header() is a silent no-op under CLI, so only the exit needs rewriting.
$body = str_replace('exit;', 'return;', $body);

ob_start();
eval($body);
$html = ob_get_clean();

file_put_contents(__DIR__ . '/maintenance-preview.html', $html);
echo "Rendered " . strlen($html) . " bytes\n\n";

// Sanity checks on the real output.
$checks = [
    'Has doctype'                      => stripos($html, '<!DOCTYPE html>') === 0,
    'Has viewport meta'                => strpos($html, 'name="viewport"') !== false,
    'Has noindex (503 page)'           => strpos($html, 'noindex') !== false,
    'Defines light palette on :root'   => strpos($html, '--rp-bg:#f6f7f7') !== false,
    'Defines dark palette'             => strpos($html, 'prefers-color-scheme:dark') !== false,
    'Respects reduced motion'          => strpos($html, 'prefers-reduced-motion') !== false,
    'Has progressbar role'             => strpos($html, 'role="progressbar"') !== false,
    'No external asset requests'       => !preg_match('/(src|href)\s*=\s*["\']https?:/i', $html),
    'No plugin name in visitor output' => stripos($html, 'restorepilot') === false,
    'Body/head tags balanced'          => substr_count($html, '<body') === 1 && substr_count($html, '</html>') === 1,
];

$fail = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if (!$ok) { $fail++; }
}
echo "\n" . ($fail === 0 ? "ALL CHECKS PASSED\n" : "$fail FAILURE(S)\n");
exit($fail === 0 ? 0 : 1);

exit(empty($failures) ? 0 : 1);
