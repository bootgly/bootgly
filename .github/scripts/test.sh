#!/usr/bin/env bash
# Bootgly CI — run the suite in one test-matrix cell.
#
# One script for both kinds of cells: on the runner VM (setup-php cells) and
# inside the cell's container (distro cells). Every cell runs the suite
# through the same preflight, session isolation, budget and diagnostics.
#
#    usage: test.sh [bootgly test arguments]     (default: --bootgly)
#    env    BOOTGLY_CI_BUDGET  seconds before the run counts as stalled (default 600)
#           BOOTGLY_CI_PHP     the PHP minor version the cell claims (preflight checks it)
#           BOOTGLY_CI_XDEBUG  1 when the cell runs Xdebug in coverage mode (default 0: absent)
set -eo pipefail

# ? The wrapper and the diagnostics need the GNU/util-linux tools, not their
#   BusyBox forms: BusyBox setsid has no --wait, BusyBox timeout no
#   --kill-after, BusyBox ps no auxf.
for tool in setsid timeout ps ss free git; do
   if ! command -v "$tool" >/dev/null; then
      echo "::error::preflight: $tool is missing"
      exit 1
   fi
done
if ! setsid --wait true; then
   echo '::error::preflight: setsid has no --wait (util-linux setsid required)'
   exit 1
fi
if ! timeout --signal=TERM --kill-after=1 5 true; then
   echo '::error::preflight: timeout has no --kill-after (GNU coreutils or uutils required)'
   exit 1
fi
php .github/scripts/preflight.php

# !
BUDGET="${BOOTGLY_CI_BUDGET:-600}"
if [ "$#" -eq 0 ]; then
   set -- --bootgly
fi

# ---

php bootgly
set +e
started=$SECONDS
# The run gets its own session, so a process-group signal raised inside
# it cannot reach this shell: the step reports the run instead of dying
# with it. setsid does not relay a signal death (it returns the bare
# signal number), so the inner shell records the real status itself; a
# missing file means the run's own shell was killed under it.
STATUS="${RUNNER_TEMP:-${TMPDIR:-/tmp}}/bootgly-test.status"
rm -f "$STATUS"
# A test child that signals its whole process group reaches `timeout`
# when both share one; `timeout` then treats the stray SIGTERM as its
# own and SIGKILLs the run --kill-after seconds later. The runner gets
# a session of its own inside the budget, so a group signal raised by
# any descendant stops at the runner (which already survives SIGTERM).
# shellcheck disable=SC2016  # the inner shell expands $BUDGET, $STATUS and $@
STATUS="$STATUS" BUDGET="$BUDGET" setsid --wait bash -c \
   'timeout --signal=TERM --kill-after=30 "$BUDGET" setsid --wait php bootgly test "$@"; echo $? > "$STATUS"' \
   bash "$@"
code=$(cat "$STATUS" 2>/dev/null)
if [ -z "$code" ]; then
   echo '::error::the run session was killed from the outside — no status recorded'
   code=137
fi
elapsed=$(( SECONDS - started ))
set -e
if [ "$code" -ne 0 ]; then
   echo "::error::bootgly test exited ${code} after ${elapsed}s"
   if [ "$code" -eq 124 ]; then
      echo "::error::the ${BUDGET}s budget expired — the run stalled"
   elif [ "$code" -gt 128 ]; then
      echo "::error::killed by signal $(( code - 128 )) — not the budget"
   fi
   ps auxf || true
   ss -tlnp || true
   free -m || true
   # ! A container cell cannot read the kernel ring: its job prints it from the host.
   if sudo -n true 2>/dev/null; then
      sudo dmesg -T 2>/dev/null | tail -40 || true
   fi
fi
exit "$code"
