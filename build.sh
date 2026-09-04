#!/bin/zsh
#
# Builds the release package and then checks what it actually contains.
#
# A .distignore is a list of things to leave out, and a list is only as good as
# the person maintaining it: add a file to the repository root and it ships
# unless somebody remembers. So this works the other way round -- an allowlist
# of what may be in the package, and assertions afterwards about what is really
# there. Anything new has to be added deliberately or the build fails.
#
# That matters more here than for most plugins. tests/ contains scripts that
# boot WordPress as user 1 and run Master Reset, delete users and set
# passwords, and a plugin directory sits under the web root on a live site.
# Shipping one would hand every installation a way to be wiped by a request.
#
#   ./build.sh            build, verify, and report
#   ./build.sh --zip      also write dist/<slug>.<version>.zip
#
set -u

ROOT="$(cd "$(dirname "$0")" && pwd)"
SLUG="restorepilot-backup-migration"
STAGE="$ROOT/dist/$SLUG"
FAILURES=0

fail() { print -r -- "  FAIL  $1"; FAILURES=$((FAILURES + 1)); }
ok()   { print -r -- "  ok    $1"; }

# Everything permitted in the package. Nothing else is copied, and the checks
# below confirm nothing else arrived by another route.
ALLOW=(
  "$SLUG.php"
  readme.txt
  uninstall.php
  assets
  includes
)

print -r -- "Building $SLUG"
rm -rf "$ROOT/dist"
mkdir -p "$STAGE"

for entry in $ALLOW; do
  if [[ ! -e "$ROOT/$entry" ]]; then
    fail "allowlisted entry is missing from the source tree: $entry"
    continue
  fi
  cp -R "$ROOT/$entry" "$STAGE/"
done

# macOS leaves these in any directory that has been opened in Finder.
find "$STAGE" -name '.DS_Store' -delete 2>/dev/null
find "$STAGE" -name '._*' -delete 2>/dev/null

printf "\nChecking the package\n"

# --- Nothing that must never ship -------------------------------------------
banned_found=0
while IFS= read -r found; do
  fail "development material in the package: ${found#$STAGE/}"
  banned_found=1
done < <(find "$STAGE" \( \
      -name 'tests' -o -name 'test_*' -o -name 'CODEX*' -o -name 'README.md' \
   -o -name '.git*' -o -name '.distignore' -o -name 'build.sh' -o -name '.claude' \
   -o -name 'node_modules' -o -name 'vendor' -o -name '*.zip' -o -name '.wordpress-org' \) -print)
[[ $banned_found -eq 0 ]] && ok "no tests, review notes, build tooling or VCS files"

# --- Only allowlisted entries at the top level ------------------------------
unexpected=0
for entry in "$STAGE"/*(N) "$STAGE"/.*(N); do
  name="${entry:t}"
  [[ "$name" == "." || "$name" == ".." ]] && continue
  if [[ ! " ${ALLOW[*]} " == *" $name "* ]]; then
    fail "unexpected top-level entry: $name"
    unexpected=1
  fi
done
[[ $unexpected -eq 0 ]] && ok "top level contains only the allowlisted entries"

# --- Every runtime require must resolve inside the package ------------------
# The README once told people to copy the plugin without includes/, which the
# main file requires eighteen times. A package with the same gap would fatal on
# activation, and would do it on somebody else's site.
missing_requires=$(
  grep -oE "require_once __DIR__ \. '[^']+'" "$STAGE/$SLUG.php" \
  | sed "s|require_once __DIR__ \. '||; s|'$||" \
  | while IFS= read -r rel; do [[ -f "$STAGE$rel" ]] || print -r -- "$rel"; done
)
if [[ -n "$missing_requires" ]]; then
  print -r -- "$missing_requires" | while IFS= read -r m; do fail "require does not resolve in the package: $m"; done
else
  ok "all $(grep -c 'require_once __DIR__' "$STAGE/$SLUG.php") runtime requires resolve"
fi

# --- Version agreement, the way WordPress.org reads it ----------------------
hdr=$(grep -m1 -E '^\s*\*\s*Version:' "$STAGE/$SLUG.php" | sed -E 's/.*Version:[[:space:]]*//')
tag=$(grep -m1 -E '^Stable tag:' "$STAGE/readme.txt" | sed -E 's/.*Stable tag:[[:space:]]*//')
const=$(grep -m1 "const VERSION" "$STAGE/includes/class-$SLUG.php" | sed -E "s/.*'([^']+)'.*/\1/")
if [[ "$hdr" == "$tag" && "$tag" == "$const" ]]; then
  ok "version is $hdr in the header, readme.txt and VERSION"
else
  fail "version disagrees: header=$hdr readme=$tag const=$const"
fi

# --- The changelog has to mention the version being shipped -----------------
if grep -q "^= $hdr =" "$STAGE/readme.txt"; then
  ok "readme.txt has a changelog entry for $hdr"
else
  fail "no changelog entry for $hdr in readme.txt"
fi

# --- Syntax, against the copy that would actually be installed --------------
PHP_BIN="${PHP_BIN:-php}"
if command -v "$PHP_BIN" >/dev/null 2>&1; then
  syntax_bad=0
  while IFS= read -r f; do
    "$PHP_BIN" -l "$f" >/dev/null 2>&1 || { fail "syntax error in the package: ${f#$STAGE/}"; syntax_bad=1; }
  done < <(find "$STAGE" -name '*.php')
  [[ $syntax_bad -eq 0 ]] && ok "every PHP file in the package parses"
else
  print -r -- "  skip  no PHP binary on PATH (set PHP_BIN to check syntax)"
fi

printf "\n%s files, %s\n" "$(find "$STAGE" -type f | wc -l | tr -d ' ')" "$(du -sh "$STAGE" | cut -f1)"

if [[ $FAILURES -gt 0 ]]; then
  printf "\nBUILD FAILED -- %s problem(s). Nothing here should be published.\n" "$FAILURES"
  exit 1
fi

if [[ "${1:-}" == "--zip" ]]; then
  ZIP="$ROOT/dist/$SLUG.$hdr.zip"
  ( cd "$ROOT/dist" && zip -qr "$ZIP" "$SLUG" -x '*.DS_Store' )
  print -r -- "wrote ${ZIP#$ROOT/}"
fi

printf "\nBUILD OK -- dist/%s is what may be published.\n" "$SLUG"
