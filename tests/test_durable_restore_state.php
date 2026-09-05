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
 * The two files a restore cannot finish without.
 *
 * RP-038. A restore replaces wp_options with the backup's own, which destroys
 * the job record it was running from. Two files in the plugin's storage
 * directory survive that and are the only reason a restore can continue: the
 * status mirror, which carries the checkpoint and the worker token, and the
 * poll-token file, which authenticates every later status request and the
 * post-restore password step.
 *
 * Both were written with @file_put_contents() and no check of any kind. A full
 * disk, a permission change, a short write or a job record that would not
 * encode removed the restore's only durable state at exactly the moment it
 * became irreplaceable, reported success, and left an unresumable restore whose
 * status could not even be polled.
 *
 * RP-043 is the same defect seen from the static-analysis side: the ruleset
 * excludes WordPress.PHP.NoSilencedErrors globally, so nothing flagged those
 * writes. The exclusion stays -- 112 genuinely best-effort filesystem calls do
 * not need line-level suppressions -- but the invariant it used to hide is
 * asserted here instead: no silenced write survives in the file that owns the
 * durable state.
 */

require_once __DIR__ . '/env.php';
rp_test_boot();

$failures = [];
function check(string $label, bool $ok, string $detail = '') {
    global $failures;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if ($detail !== '') { echo '        ' . $detail . "\n"; }
    if (!$ok) { $failures[] = $label; }
}
function priv($name, $args = []) {
    $m = new ReflectionMethod('RestorePilot_Backup_Migration', $name);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
}
function konst(string $name) {
    return (new ReflectionClass('RestorePilot_Backup_Migration'))->getConstant($name);
}

$root = dirname(__DIR__);
$jobs_src    = file_get_contents($root . '/includes/trait-jobs.php');
$storage_src = file_get_contents($root . '/includes/trait-storage.php');
$handler_src = file_get_contents($root . '/includes/trait-request-handlers.php');
$restore_src = file_get_contents($root . '/includes/trait-restore.php');

// ── The write itself ───────────────────────────────────────────────────────
echo "=== a write that either lands whole or does not land ===\n";

$tmpdir = sys_get_temp_dir() . '/rp-durable-' . getmypid();
@mkdir($tmpdir, 0755, true);
register_shutdown_function(function () use ($tmpdir) {
    foreach (glob($tmpdir . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($tmpdir);
});

$target = $tmpdir . '/state.json';
check('It writes the file and reports success',
    priv('write_file_durable', [$target, 'first']) === true && file_get_contents($target) === 'first');

check('It replaces an existing file rather than appending',
    priv('write_file_durable', [$target, 'second']) === true && file_get_contents($target) === 'second');

check('It leaves no temporary file behind',
    count(glob($tmpdir . '/*.tmp') ?: []) === 0,
    implode(', ', array_map('basename', glob($tmpdir . '/*.tmp') ?: [])));

// The rename is what makes a partial read impossible; a status poll landing
// mid-write would otherwise decode nothing and look like a vanished job.
check('It renames into place rather than writing over the live file',
    strpos($storage_src, '@rename($tmp, $path)') !== false);

check('A write into a directory that does not exist and cannot be made fails, and says so',
    priv('write_file_durable', ['/proc/definitely-not-writable/rp/state.json', 'x']) === false);

// ── The status mirror ──────────────────────────────────────────────────────
echo "\n=== the restore status mirror ===\n";

$job_id = 'rp-durable-' . getmypid();
$job = ['status' => 'running', 'poll_token' => 'tok', 'checkpoint' => ['database_done' => true]];

check('A good record is mirrored and reports success',
    priv('write_restore_status_file', [$job_id, $job]) === true);
check('And it reads back as what was written',
    priv('read_restore_status_file', [$job_id])['checkpoint']['database_done'] === true);

// wp_json_encode() returns false when a record cannot be represented. That
// used to be written anyway, producing an empty file that read back as a job
// which did not exist -- the checkpoint gone, silently.
//
// Malformed UTF-8 was the obvious candidate and is the wrong one:
// wp_json_encode() runs a sanity check that repairs bad bytes rather than
// failing, so "\xB1\x31" encodes happily as "?1". Nesting past JSON's depth
// limit does fail, and so do resources and INF.
$bad = $job;
$deep = 'x';
for ($i = 0; $i < 600; $i++) { $deep = [$deep]; }
$bad['checkpoint'] = $deep;
$before = priv('read_restore_status_file', [$job_id]);
check('THE FIX: a record that cannot be encoded is refused, not written empty',
    priv('write_restore_status_file', [$job_id, $bad]) === false);
check('And the previous good mirror is still intact',
    priv('read_restore_status_file', [$job_id]) === $before,
    'a failed write must not destroy the checkpoint that was already there');

@unlink(priv('restore_status_file', [$job_id]));

// ── Failing before the irreversible phase ──────────────────────────────────
echo "\n=== a restore that cannot store its state must not start ===\n";

check('set_restore_job() reports whether the mirror was written',
    preg_match('/private static function set_restore_job\(string \$job_id, array \$job\): bool/', $jobs_src) === 1);
check('write_poll_token_file() reports whether the token was stored',
    preg_match('/private static function write_poll_token_file\(string \$job_id, string \$poll_token\): bool/', $jobs_src) === 1);

check('THE FIX: queueing a restore refuses when either could not be written',
    strpos($handler_src, '$mirrored = $set_ok && self::write_poll_token_file($job_id, $poll_token);') !== false
    && preg_match('/if \(!\$mirrored\) \{\s*\n\s*throw new RuntimeException/', $handler_src) === 1,
    'refusing here costs a message; refusing after the swap costs an outage');

// The refusal has to happen before anything irreversible, which for this
// handler means before the worker is dispatched.
$throw_at    = strpos($handler_src, 'if (!$mirrored) {');
$dispatch_at = strpos($handler_src, 'self::dispatch_restore_worker($job_id, $token);');
check('And it refuses before the worker is dispatched',
    $throw_at !== false && $dispatch_at !== false && $throw_at < $dispatch_at);

// ── After the swap, when refusing is no longer an option ───────────────────
echo "\n=== after the database swap ===\n";
check('The poll token rewritten after the file phase is read back and verified',
    strpos($restore_src, 'self::read_poll_token_file($job_id) !== $job_after_files[\'poll_token\']') !== false);
check('And a failure there is logged as something an operator can act on',
    strpos($restore_src, 'Status polling and the post-restore password step will not authenticate') !== false);

// ── RP-043: the invariant the ruleset can no longer hide ───────────────────
echo "\n=== no silenced writes where the durable state lives ===\n";

// Reads and deletes may be best-effort: a missing file is already handled by
// the is_file() guards around them. A silenced WRITE is the defect.
if (preg_match_all('/@(file_put_contents|fwrite|rename|copy|touch)\s*\(/', $jobs_src, $m)) {
    check('THE FIX: trait-jobs.php has no silenced write', false, implode(', ', $m[0]));
} else {
    check('THE FIX: trait-jobs.php has no silenced write', true,
        'the file owning the restore\'s durable state checks every write it makes');
}

check('Its remaining silenced calls are reads and deletes only',
    preg_match_all('/@[a-z_]+\s*\(/', $jobs_src, $all) > 0
    && count(array_filter($all[0], function ($c) {
         return !preg_match('/@(file_get_contents|unlink|maybe_unserialize)\s*\(/', $c);
       })) === 0,
    implode(' ', $all[0]));

// And the ruleset says why it is excluded, so the next reader is not misled
// the way the previous justification misled me.
$phpcs = file_get_contents($root . '/phpcs.xml.dist');
check('The ruleset no longer calls the status mirror best-effort',
    stripos($phpcs, 'the status mirror') === false
    || stripos($phpcs, 'test_durable_restore_state') !== false,
    'the earlier justification named it as an example of a write that may fail silently');

echo "\n" . ($failures ? (count($failures) . ' FAILURE(S): ' . implode('; ', $failures)) : 'ALL CHECKS PASSED') . "\n";

exit(empty($failures) ? 0 : 1);
