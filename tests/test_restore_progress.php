<?php
/**
 * The restore's database phase reported one fixed number for its whole
 * duration, so a healthy restore was indistinguishable from a dead one.
 * These check the interpolation moves, stays inside its band, and never
 * announces a step that has not started.
 */

define('WP_USE_THEMES', false);
require_once '/Users/surajitroy/Local Sites/sunhsine-bkp/app/public/wp-load.php';

$pass = 0; $fail = 0; $failures = [];
function check(string $label, bool $ok) {
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "PASS  $label\n"; }
    else { $fail++; $failures[] = $label; echo "FAIL  $label\n"; }
}

$ref = new ReflectionClass('RestorePilot_Backup_Migration');
$p = $ref->getMethod('restore_database_phase_progress'); $p->setAccessible(true);
$l = $ref->getMethod('restore_database_phase_label');    $l->setAccessible(true);
$prog  = function ($d, $t) use ($p) { return $p->invoke(null, $d, $t); };
$label = function ($d, $t) use ($l) { return $l->invoke(null, $d, $t); };

// Band: validating 12, rollback 24, maintenance 36, database 48, swap 64.
check('Starts at the phase floor (48)', $prog(0, 149) === 48);
check('Never reaches the swap step figure (64)', $prog(149, 149) < 64);
check('Ends one short of the ceiling (63)', $prog(149, 149) === 63);
check('Midpoint lands mid-band', $prog(75, 149) > 53 && $prog(75, 149) < 59);

// The real reported case: user saw a frozen 48% while on table 123 of 149.
$at_123 = $prog(122, 149);
check("Table 123 of 149 now reports $at_123%, not a frozen 48", $at_123 > 48 && $at_123 < 64);
echo "  (the value the user would have seen instead of a stuck 48%: {$at_123}%)\n";

// Monotonic and in-band across the whole phase.
$monotonic = true; $in_band = true; $distinct = [];
$prev = -1;
for ($i = 0; $i <= 149; $i++) {
    $v = $prog($i, 149);
    if ($v < $prev) { $monotonic = false; }
    if ($v < 48 || $v > 63) { $in_band = false; }
    $distinct[$v] = true;
    $prev = $v;
}
check('Never moves backwards across the whole phase', $monotonic);
check('Every value stays inside the 48-63 band', $in_band);
check('Actually moves (more than one distinct value)', count($distinct) > 10);
echo '  (' . count($distinct) . " distinct values across 149 tables)\n";

// Degenerate inputs must not produce nonsense or divide by zero.
check('Zero tables falls back to the floor', $prog(0, 0) === 48);
check('Negative done clamps to the floor', $prog(-5, 149) === 48);
check('done > total clamps to the ceiling', $prog(500, 149) === 63);

// Labels.
check('Label names the position and total', $label(123, 149) === 'Restoring database (table 123 of 149)');
check('Label falls back to the generic phase name when total is unknown',
    strpos($label(1, 0), 'table') === false);

echo "\n";
if ($fail === 0) { echo "ALL $pass CHECKS PASSED\n"; }
else { echo "$fail FAILURE(S): " . implode('; ', $failures) . "\n"; }
exit($fail === 0 ? 0 : 1);
