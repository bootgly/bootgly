#!/bin/bash
# --------------------------------------------------------------------------
# Bootgly PHP Framework
# Developed by Rodrigo Vieira (@rodrigoslayertech)
# Copyright 2023-present
# Licensed under MIT
# --------------------------------------------------------------------------
#
# GitHub Copilot Hook: "UserPromptSubmit"
# Path: scripts/prompt.sh
# Triggered by: .github/hooks/hooks.json -> hooks.UserPromptSubmit
#
# Usage: bash scripts/prompt.sh <action>
# Actions:
#   check-naming  — Checks for Bootgly naming convention violations
# --------------------------------------------------------------------------

cd "$(dirname "$0")/.." || exit 1

ACTION="${1:-check-naming}"

# ==========================================================================
# Action: check-naming
# ==========================================================================
check_naming () {
   # PHP interface methods that are required by PHP itself (not violations)
   local PHP_INTERFACE_METHODS="offsetExists|offsetGet|offsetSet|offsetUnset|getIterator"

   # Find camelCase multi-word method names (violations of single-word verb convention)
   local VIOLATIONS
   VIOLATIONS=$(grep -rn --include='*.php' -oP \
      'function\s+\K[a-z]+[A-Z][a-zA-Z]*(?=\s*\()' \
      Bootgly/ \
      | grep -v '/&/' \
      | grep -v '/vs/' \
      | grep -v '/tests/' \
      | grep -v '/examples/' \
      | grep -vP ":(${PHP_INTERFACE_METHODS})$")

   if [ -z "$VIOLATIONS" ]; then
      echo "[Bootgly Naming] No violations found."
      return 0
   fi

   local COUNT
   COUNT=$(echo "$VIOLATIONS" | wc -l)

   echo "=== [Bootgly Naming Convention] $COUNT violation(s) found ==="
   echo ""
   echo "Rule: Methods should use single-word verbs (e.g., 'render', 'boot', 'purge')."
   echo "Avoid: camelCase combos (e.g., 'getConfig' → use property hook or rename)."
   echo ""
   echo "$VIOLATIONS" | while IFS=: read -r FILE LINE METHOD; do
      echo "  ⚠ $FILE:$LINE → $METHOD"
   done
   echo ""
   echo "Before renaming, consider whether the compound name signals the need for"
   echo "a new class/context. For example:"
   echo "  Process->installSignal()  → Process->Signal->install()"
   echo "  Process->handleSignal()   → Process->Signal->handle()"
   echo "  Server->getConfig()       → Server->\$config (property hook)"
   echo ""
   echo "If the compound method implies a sub-entity, extract a class first,"
   echo "then the method naturally becomes a single verb on that class."
   echo "================================================================="

   return 1
}

# ==========================================================================
# Dispatch
# ==========================================================================
case "$ACTION" in
   check-naming)
      check_naming
      exit $?
      ;;
   *)
      echo "Unknown action: $ACTION"
      echo "Usage: bash scripts/prompt.sh <action>"
      echo "Actions: check-naming"
      exit 1
      ;;
esac
