#!/bin/zsh
# A test is a pass only if it exits 0 AND prints no failure line AND actually
# reports finishing. Exit code alone let a test that printed "3 FAILURE(S)"
# and then ended normally be recorded as a pass.
#
# Between tests the site is reset. Without that, a test that dies partway
# through a restore leaves maintenance mode on and every test after it dies
# inside wp-load -- reported today as five failures when there was one, and
# the real one was the first rather than the loudest.
S="$(cd "$(dirname "$0")" && pwd)"
# PHP_BIN, SOCK, SITE_ROOT and php_run() come from the resolved environment,
# which refuses a fixture site that is not marked disposable.
source "$S/env.sh" || exit 2
OUT="$1"; shift

# A site that cannot boot fails every test for the same reason; say so once
# rather than 19 times.
health_check() {
  export RP_SITE_ROOT="$SITE_ROOT"
  php_run -r '
    define("WP_USE_THEMES", false);
    require_once getenv("RP_SITE_ROOT") . "/wp-load.php";
    if (!class_exists("RestorePilot_Backup_Migration")) { exit(2); }
    exit(0);
  ' >/dev/null 2>&1
  return $?
}

passed=0; failed=0; skipped=0
echo "started: $(date)" > "$OUT"

php_run "$S/reset_site_state.php" >> "$OUT" 2>&1
if ! health_check; then
  echo "ABORTED: the test site will not boot cleanly even after a reset." >> "$OUT"
  echo "DONE" >> "$OUT"
  exit 1
fi

# Plugins a given test needs active. Kept here rather than in the reset so the
# reset stays a general-purpose tool and each requirement is stated next to the
# test that has it -- and so no other test pays for loading them.
test_preconditions() {
  case "$1" in
    test_woocommerce_restore|test_restore_duplicate_recovery) echo "woocommerce/woocommerce.php" ;;
    *) echo "" ;;
  esac
}

for t in "$@"; do
  # Establish this test's preconditions immediately before it runs, not as a
  # side effect of the previous test's cleanup.
  pre=$(test_preconditions "$t")
  reset_out=$(php_run "$S/reset_site_state.php" ${pre} 2>&1)
  [ -n "$reset_out" ] && echo "$reset_out" >> "$OUT"

  # Data preconditions, after the plugin ones because they need the plugin
  # loaded. The store several tests destroy is rebuilt here rather than being
  # assumed to have survived whatever ran before.
  case "$t" in
    # Both need a database large enough to take several chunks: one restores a
    # real store, the other has to catch a scratch table mid-restore and cannot
    # if the whole thing finishes inside a single chunk.
    test_woocommerce_restore|test_restore_duplicate_recovery)
      store_out=$(php_run "$S/ensure_woo_store.php" 2>&1)
      [ -n "$store_out" ] && echo "$store_out" >> "$OUT"
      ;;
  esac

  s=$(date +%s)
  r=$(php_run "$S/$t.php" 2>&1)
  code=$?
  e=$(( $(date +%s) - s ))

  reason=""
  [ $code -ne 0 ] && reason="exit $code"
  echo "$r" | grep -qE 'FAILURE\(S\)|^FAIL ' && reason="${reason:+$reason, }reported failures"
  echo "$r" | grep -qE 'Fatal error|Uncaught' && reason="${reason:+$reason, }fatal error"
  echo "$r" | grep -qE 'ALL CHECKS PASSED|ALL [0-9]+ CHECKS PASSED' || reason="${reason:+$reason, }no completion line"

  # A test that could not run is not a test that passed. test_woocommerce_restore
  # skips itself when WooCommerce is inactive -- which an earlier restore test in
  # this same suite causes, by restoring a backup that predates the store -- and
  # it used to say ALL CHECKS PASSED on the way out. The suite then reported
  # green while its largest test had checked nothing at all. Skips are now their
  # own outcome, counted apart from passes and named in the summary.
  if echo "$r" | grep -qE '^SKIP '; then
    skipped=$((skipped + 1))
    echo "SKIP  (${e}s)  $t.php  -- $(echo "$r" | grep -E '^SKIP ' | head -1 | sed 's/^SKIP  *//')" >> "$OUT"
  elif [ -z "$reason" ]; then
    passed=$((passed + 1))
    echo "PASS  (${e}s)  $t.php" >> "$OUT"
  else
    failed=$((failed + 1))
    echo "=====================================" >> "$OUT"
    echo "FAIL  (${e}s)  $t.php  [$reason]" >> "$OUT"
    echo "$r" | grep -E '^FAIL |FAILURE\(S\)|Fatal error|Uncaught' | head -20 >> "$OUT"
    echo "=====================================" >> "$OUT"
  fi

  # Always, pass or fail: a test that passed can still have left maintenance
  # mode on, and the next test is not the place to discover it.
  reset_out=$(php_run "$S/reset_site_state.php" 2>&1)
  [ -n "$reset_out" ] && echo "$reset_out" >> "$OUT"

  # If the site is unbootable now, the tests after this one cannot say
  # anything about the code. Stop, and name the test that broke it.
  if ! health_check; then
    echo "=====================================" >> "$OUT"
    echo "ABORTED after $t.php: the site no longer boots, and a reset did not" >> "$OUT"
    echo "  recover it. Remaining tests were not run -- they would all have" >> "$OUT"
    echo "  failed for this reason rather than their own." >> "$OUT"
    echo "=====================================" >> "$OUT"
    break
  fi
done

echo "" >> "$OUT"
echo "$passed passed, $failed failed, $skipped skipped" >> "$OUT"
echo "finished: $(date)" >> "$OUT"
echo "DONE" >> "$OUT"
# A skip is not a pass, so it is not a green run either.
[ "$failed" -eq 0 ] && [ "$skipped" -eq 0 ]
