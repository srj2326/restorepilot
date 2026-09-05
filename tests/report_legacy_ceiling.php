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
 * Prints legacy_json_ceiling() for this process's memory_limit.
 *
 * A separate process because the value has to come from a real ini setting:
 * changing memory_limit at runtime inside the test would not exercise the same
 * path, and reading it back would prove nothing about what a host with that
 * limit actually gets.
 */

require_once __DIR__ . '/env.php';
rp_test_boot();

$m = new ReflectionMethod('RestorePilot_Backup_Migration', 'legacy_json_ceiling');
$m->setAccessible(true);
echo (int) $m->invoke(null);
