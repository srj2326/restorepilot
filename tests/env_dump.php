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
 * Reports the resolved test environment to the shell runners, one value a line.
 *
 * Kept deliberately dumb: the resolution and the refusal both live in env.php,
 * so the shell cannot end up pointed at a different site than the PHP tests.
 */

require_once __DIR__ . '/env.php';

$cfg = rp_test_config();
rp_test_site();  // Refuses an unmarked or missing fixture, with instructions.

echo $cfg['plugin'], "\n", $cfg['site'], "\n", $cfg['php'], "\n", $cfg['socket'], "\n";
