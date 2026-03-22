#!/bin/bash
# --------------------------------------------------------------------------
# Bootgly PHP Framework
# Developed by Rodrigo Vieira (@rodrigoslayertech)
# Copyright 2023-present
# Licensed under MIT
# --------------------------------------------------------------------------
#
# GitHub Copilot Agent Hook: "Stop"
# Path: scripts/agent.sh
# Triggered by: .github/hooks/hooks.json -> hooks.Stop
#
# Runs PHPStan static analysis (level 9) when the AI agent stops.
# Output is written to: .github/hooks/logs/agent.log
# --------------------------------------------------------------------------

cd "$(dirname "$0")/.." || exit 1

echo "=== GitHub Copilot Hook [Stop] — $(date) ==="
echo "Script: scripts/agent.sh"
echo "Running PHPStan static analysis..."

vendor/bin/phpstan analyse -c @/phpstan.neon --no-progress --error-format=table 2>&1

EXIT_CODE=$?

if [ $EXIT_CODE -eq 0 ]; then
   echo "PHPStan: No errors found."
else
   echo "PHPStan: Errors detected (exit code: $EXIT_CODE)."
fi

echo "=== Done: $(date) ==="

exit $EXIT_CODE