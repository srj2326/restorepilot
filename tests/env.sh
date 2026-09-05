#!/usr/bin/env bash
# Shared test environment for the shell runners. Sourced, not executed.
#
# RP-008. The runners used to hard-code one machine's PHP binary, MySQL socket,
# plugin directory and fixture site. They now ask tests/env.php, which is the
# single place that resolves those -- environment variable, then
# tests/config.local.php, then a default -- so the shell and the PHP tests can
# never disagree about which site is about to be restored over.

# A sourced file cannot portably locate itself: zsh spells it ${(%):-%x} and
# bash ${BASH_SOURCE[0]}, and the zsh form is not even valid bash. Both callers
# work out their own directory before sourcing this, so take theirs.
RP_TESTS_DIR="${RP_TESTS_DIR:-$S}"
if [[ -z "$RP_TESTS_DIR" || ! -f "$RP_TESTS_DIR/env_dump.php" ]]; then
  echo "env.sh: source this from a script that sets S to the tests directory" >&2
  exit 2
fi

# env.php refuses an unmarked or missing fixture and explains how to set one up;
# let that message reach the user rather than failing later and less clearly.
if ! RP_ENV_JSON="$(php "$RP_TESTS_DIR/env_dump.php")"; then
  exit 2
fi

PLUGIN_DIR="$(printf '%s' "$RP_ENV_JSON" | sed -n '1p')"
SITE_ROOT="$(printf '%s' "$RP_ENV_JSON" | sed -n '2p')"
PHP_BIN="$(printf '%s' "$RP_ENV_JSON" | sed -n '3p')"
SOCK="$(printf '%s' "$RP_ENV_JSON" | sed -n '4p')"

# Where the working copy gets staged so the fixture runs the code under test.
SITE_DIR="$SITE_ROOT/wp-content/plugins/$(basename "$PLUGIN_DIR")"

php_run() {
  "$PHP_BIN" -d "mysqli.default_socket=$SOCK" -d "pdo_mysql.default_socket=$SOCK" -d "memory_limit=1024M" "$@"
}
