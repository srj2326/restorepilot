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
PHP_BIN="/Users/surajitroy/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
SOCK="/Users/surajitroy/Library/Application Support/Local/run/gKsH4-EmV/mysql/mysqld.sock"
OUT="$1"; shift

php_run() {
  "$PHP_BIN" -d "mysqli.default_socket=$SOCK" -d "pdo_mysql.default_socket=$SOCK" -d "memory_limit=1024M" "$@"
}

# A site that cannot boot fails every test for the same reason; say so once
# rather than 19 times.
health_check() {
  php_run -r '
    define("WP_USE_THEMES", false);
    require_once "/Users/surajitroy/Local Sites/sunhsine-bkp/app/public/wp-load.php";
    if (!class_exists("RestorePilot_Backup_Migration")) { exit(2); }
    exit(0);
  ' >/dev/null 2>&1
  return $?
}

echo "started: $(date)" > "$OUT"

php_run "$S/reset_site_state.php" >> "$OUT" 2>&1
if ! health_check; then
  echo "ABORTED: the test site will not boot cleanly even after a reset." >> "$OUT"
  echo "DONE" >> "$OUT"
  exit 1
fi

for t in "$@"; do
  s=$(date +%s)
  r=$(php_run "$S/$t.php" 2>&1)
  code=$?
  e=$(( $(date +%s) - s ))

  reason=""
  [ $code -ne 0 ] && reason="exit $code"
  echo "$r" | grep -qE 'FAILURE\(S\)|^FAIL ' && reason="${reason:+$reason, }reported failures"
  echo "$r" | grep -qE 'Fatal error|Uncaught' && reason="${reason:+$reason, }fatal error"
  echo "$r" | grep -qE 'ALL CHECKS PASSED|ALL [0-9]+ CHECKS PASSED' || reason="${reason:+$reason, }no completion line"

  if [ -z "$reason" ]; then
    echo "PASS  (${e}s)  $t.php" >> "$OUT"
  else
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

echo "finished: $(date)" >> "$OUT"
echo "DONE" >> "$OUT"
