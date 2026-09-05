#!/bin/zsh
# The fast checks, meant to run before every commit rather than every release.
#
# Each one encodes something that must never be wrong, and each was written
# after that thing went wrong. They cost a few seconds together, which is the
# point: a gate nobody waits for is a gate nobody runs.
#
#   ./gate.sh            run the checks
#   ./gate.sh --install  also install it as the repo's pre-commit hook
#
# Deliberately excludes the long restore tests. Those take 45 minutes and
# belong before a release, not before a commit.

S="$(cd "$(dirname "$0")" && pwd)"
# PLUGIN_DIR, SITE_DIR, PHP_BIN, SOCK and php_run() come from the resolved
# environment, which refuses a fixture site that is not marked disposable.
source "$S/env.sh" || exit 2

# The release package is checked on every commit, not only at release time.
# An allowlist that is never exercised is a list of good intentions, and the
# thing it keeps out of the artifact is a directory of scripts that run Master
# Reset without asking.
package_check() {
  if [[ ! -x "$PLUGIN_DIR/build.sh" ]]; then
    echo "SKIP  build.sh not found"
    return 0
  fi
  local out
  out=$(PHP_BIN="$PHP_BIN" "$PLUGIN_DIR/build.sh" 2>&1)
  if [[ $? -ne 0 ]]; then
    echo "FAIL  release package"
    echo "$out" | grep '  FAIL  ' | head -10
    return 1
  fi
  echo "PASS  release package contains only what may ship"
  return 0
}

# Coding standards, when the tooling is present. Installed with
# `composer install`, which is optional for a contributor who only wants to run
# the tests -- so a missing vendor/ is a skip, not a failure. The ruleset in
# phpcs.xml.dist is tuned to this codebase and its baseline is zero, which is
# what makes it worth gating on: anything it reports is new.
standards_check() {
  local bin="$PLUGIN_DIR/vendor/bin/phpcs"
  if [[ ! -x "$bin" ]]; then
    echo "SKIP  coding standards (run 'composer install' to enable)"
    return 0
  fi
  local out
  if ! out=$("$bin" -q --report=summary --no-colors --basepath="$PLUGIN_DIR" 2>&1); then
    echo "FAIL  coding standards"
    echo "$out" | tail -15
    return 1
  fi
  echo "PASS  coding standards (WordPress-Extra, project ruleset)"
  return 0
}

FAST_TESTS=(
  test_plugin_paths        # the plugin knows which directory is its own
  test_fresh_job_read      # the post-lock re-read reaches the database
  test_journal_scoping     # one restore's sweep spares another's live tables
  test_post_bool           # a submitted "0" is false
  test_keyset_cursor       # export pagination never picks an unsafe cursor
  test_foreign_tables      # Master Reset never selects a core table
  test_custom_admin        # no working credential is ever handed back
  test_restore_progress    # progress stays inside its phase and moves
  test_abandon_stuck_restore
  test_job_lost_update    # a progress tick never resurrects a cancelled job
  test_restore_source_precedence  # the right file is restored, and Master Reset's modal is reachable
  test_restore_row_idempotence    # a repeated row is not a failure; every other db error still is
  test_storage_not_web_readable   # a backup cannot be fetched by asking the web server for it
  test_snapshot_and_sweep         # consistency is checked, and long prefixes do not orphan tables
  test_restore_row_schema         # a bad row is refused while the restore is still only a plan
  test_documented_claims          # what the readme promises is what the code does
  test_no_dead_guards             # no guard or PHPCS exemption that only looks like one
  test_dialog_focus               # every modal goes through the one focus controller
)

if [[ "$1" == "--install" ]]; then
  HOOK="$PLUGIN_DIR/.git/hooks/pre-commit"
  cat > "$HOOK" <<HOOKEOF
#!/bin/zsh
# Installed by gate.sh. Skip once with: git commit --no-verify
exec "$S/gate.sh"
HOOKEOF
  chmod +x "$HOOK"
  echo "installed: $HOOK"
  echo "(bypass a single commit with: git commit --no-verify)"
  echo ""
fi

fail=0
start=$(date +%s)

# --- 1. Syntax. A parse error is worth catching before anything slower. ----
for f in "$PLUGIN_DIR"/*.php "$PLUGIN_DIR"/includes/*.php; do
  [ -f "$f" ] || continue
  if ! "$PHP_BIN" -l "$f" >/dev/null 2>&1; then
    echo "FAIL  php syntax: ${f##*/}"
    "$PHP_BIN" -l "$f" 2>&1 | head -2 | sed 's/^/        /'
    fail=1
  fi
done
command -v node >/dev/null 2>&1 && for f in "$PLUGIN_DIR"/assets/js/*.js; do
  [ -f "$f" ] || continue
  if ! node --check "$f" >/dev/null 2>&1; then
    echo "FAIL  js syntax: ${f##*/}"
    fail=1
  fi
done
[ $fail -eq 0 ] && echo "PASS  syntax (php + js)"

# --- 2. The structural rule that caused the self-deletion ------------------
# __FILE__ answers for the file it sits in, so anything under includes/ that
# uses it is pointing one level too deep. trait-support.php is the exception:
# its fallbacks are what everything else asks instead.
offenders=$(grep -l "__FILE__\|__DIR__" "$PLUGIN_DIR"/includes/*.php 2>/dev/null | grep -v "trait-support.php" || true)
if [ -n "$offenders" ]; then
  echo "FAIL  __FILE__/__DIR__ under includes/ (they resolve to includes/, not the plugin root):"
  echo "$offenders" | sed 's|.*/|        |'
  echo "        use self::plugin_root_dir() / plugin_root_file() instead"
  fail=1
else
  echo "PASS  no file-relative constants under includes/"
fi

# --- 3. Version consistency ------------------------------------------------
hdr=$("$PHP_BIN" -r 'preg_match("/^ \* Version:\s*(\S+)/m", file_get_contents($argv[1]), $m); echo $m[1] ?? "";' "$PLUGIN_DIR/restorepilot-backup-migration.php")
konst=$("$PHP_BIN" -r 'preg_match("/const VERSION = .([0-9.]+)./", file_get_contents($argv[1]), $m); echo $m[1] ?? "";' "$PLUGIN_DIR/includes/class-restorepilot-backup-migration.php")
stable=$("$PHP_BIN" -r 'preg_match("/^Stable tag:\s*(\S+)/m", file_get_contents($argv[1]), $m); echo $m[1] ?? "";' "$PLUGIN_DIR/readme.txt")
if [ "$hdr" = "$konst" ] && [ "$hdr" = "$stable" ]; then
  echo "PASS  version consistent everywhere ($hdr)"
else
  echo "FAIL  version mismatch: header=$hdr constant=$konst stable-tag=$stable"
  fail=1
fi

# The plugin header and the readme disagreed on this for six days, which is
# the likelier reason the listing kept showing an older figure than the readme.
h_tested=$("$PHP_BIN" -r 'preg_match("/^ \* Tested up to:\s*(\S+)/m", file_get_contents($argv[1]), $m); echo $m[1] ?? "";' "$PLUGIN_DIR/restorepilot-backup-migration.php")
r_tested=$("$PHP_BIN" -r 'preg_match("/^Tested up to:\s*(\S+)/m", file_get_contents($argv[1]), $m); echo $m[1] ?? "";' "$PLUGIN_DIR/readme.txt")
if [ "$h_tested" = "$r_tested" ]; then
  echo "PASS  'Tested up to' agrees between header and readme ($h_tested)"
else
  echo "FAIL  'Tested up to' mismatch: header=$h_tested readme=$r_tested"
  fail=1
fi

# --- 4. Every require actually resolves, case included ---------------------
"$PHP_BIN" -r '
$root = $argv[1];
$src = file_get_contents($root . "/restorepilot-backup-migration.php");
preg_match_all("~require_once __DIR__ \. \x27(/[^\x27]+)\x27~", $src, $m);
$bad = [];
$real = scandir($root . "/includes");
foreach ($m[1] as $rel) {
  $name = basename($rel);
  if (!is_file($root . $rel)) { $bad[] = "$name (missing)"; continue; }
  // macOS is case-insensitive; every real host is not.
  if (!in_array($name, $real, true)) { $bad[] = "$name (case differs on disk)"; }
}
if ($bad) { echo "FAIL  require paths: " . implode(", ", $bad) . "\n"; exit(1); }
printf("PASS  all %d require paths resolve (case-sensitive)\n", count($m[1]));
' "$PLUGIN_DIR" || fail=1

# --- 5. The release package -----------------------------------------------
package_check || fail=1
standards_check || fail=1

# --- 6. The fast behavioural tests ----------------------------------------
# Run against the test site, so it gets the code being committed first.
if [ -d "$SITE_DIR/includes" ]; then
  cp "$PLUGIN_DIR"/*.php "$SITE_DIR/" 2>/dev/null
  cp "$PLUGIN_DIR"/includes/*.php "$SITE_DIR/includes/" 2>/dev/null
  cp "$PLUGIN_DIR"/assets/js/*.js "$SITE_DIR/assets/js/" 2>/dev/null
  cp "$PLUGIN_DIR"/assets/css/*.css "$SITE_DIR/assets/css/" 2>/dev/null
fi

"$PHP_BIN" -d "mysqli.default_socket=$SOCK" "$S/reset_site_state.php" >/dev/null 2>&1

for t in $FAST_TESTS; do
  [ -f "$S/$t.php" ] || continue
  out=$("$PHP_BIN" -d "mysqli.default_socket=$SOCK" -d "memory_limit=512M" "$S/$t.php" 2>&1)
  code=$?
  if [ $code -eq 0 ] && echo "$out" | grep -qE 'ALL [0-9]* ?CHECKS PASSED'; then
    echo "PASS  $t"
  else
    echo "FAIL  $t"
    echo "$out" | grep -E '^FAIL |FAILURE\(S\)|Fatal error' | head -5 | sed 's/^/        /'
    fail=1
  fi
done

echo ""
if [ $fail -eq 0 ]; then
  echo "GATE PASSED in $(( $(date +%s) - start ))s"
else
  echo "GATE FAILED in $(( $(date +%s) - start ))s -- commit blocked."
  echo "Run the full suite before releasing; bypass this one commit with --no-verify."
fi
exit $fail
