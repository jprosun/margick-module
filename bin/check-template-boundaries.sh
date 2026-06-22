#!/usr/bin/env bash
#
# check-template-boundaries.sh — enforce SCHEMA-AND-MIGRATIONS.md LAW 3.
# =====================================================================
# A template (the thin per-site config + presentation layer) must NOT own schema
# or hand-query the shared core. This gate fails the build when it does.
#
# Two invariants:
#   RULE 1 — No DDL in the template layer. `CREATE TABLE` / `ALTER TABLE` belong
#            to a capability's versioned migration (the module), never a template.
#            The capability engine dir (default: inc) is EXEMPT — its DDL is
#            pending extraction into the module, tracked separately.
#   RULE 2 — The core tables (mgk_core_*) are touched ONLY through the module's
#            OrderRepository. No template/theme file may name them in code
#            (comments are allowed). Raw $wpdb on the core is what we forbid.
#
# Portable: uses `find -name '*.php' -exec grep` (no GNU-only --include/-I), so it
# runs the same under BusyBox (containers) and GNU (CI) grep.
#
# Usage: check-template-boundaries.sh <theme-or-template-dir> [capability-dir ...]
# Exit 0 = clean, 1 = violations found, 2 = bad invocation.

set -uo pipefail

ROOT="${1:-}"
if [[ -z "$ROOT" || ! -d "$ROOT" ]]; then
  echo "usage: $0 <theme-or-template-dir> [capability-dir ...]" >&2
  exit 2
fi
shift || true
CAP_DIRS=("$@")
[[ ${#CAP_DIRS[@]} -eq 0 ]] && CAP_DIRS=("inc")

violations=0

# Directory prune expression for RULE 1: vendor/node_modules + capability dirs.
# Built as an array (paren tokens as elements) so find gets them as operators —
# NO eval (a bare '(' under eval becomes a shell subshell and breaks the command).
prune_names=(vendor node_modules "${CAP_DIRS[@]}")
prune_expr=('(')
first=1
for n in "${prune_names[@]}"; do
  if [[ $first -eq 1 ]]; then first=0; else prune_expr+=(-o); fi
  prune_expr+=(-name "$n")
done
prune_expr+=(')')

echo "── RULE 1: no CREATE/ALTER TABLE in the template layer (exempt: ${CAP_DIRS[*]}) ──"
ddl_hits="$(find "$ROOT" -type d "${prune_expr[@]}" -prune -o -type f -name '*.php' -exec grep -En '(CREATE|ALTER)[[:space:]]+TABLE' {} + 2>/dev/null || true)"
if [[ -n "$ddl_hits" ]]; then
  echo "  ✗ DDL found in template layer:"; echo "$ddl_hits" | sed 's/^/    /'
  violations=$((violations+1))
else
  echo "  ✓ none"
fi

echo "── RULE 2: core tables (mgk_core_*) only via the module Repositories (no raw access) ──"
# Known core-owned tables (add new ones here when a core capability ships a table).
# Explicit list (not a wildcard) so option names like mgk_core_schema_version don't
# false-positive. Matches the table base name as used in a query string.
CORE_TABLES='mgk_core_(orders|order_items|vouchers|voucher_redemptions)'
# Scan ALL php (prune only vendor/node_modules); drop pure comment lines (* // #).
core_hits="$(find "$ROOT" -type d \( -name vendor -o -name node_modules \) -prune -o -type f -name '*.php' -exec grep -En "$CORE_TABLES" {} + 2>/dev/null \
  | grep -vE ':[0-9]+:[[:space:]]*(\*|//|#|/\*)' || true)"
if [[ -n "$core_hits" ]]; then
  echo "  ✗ raw reference to core tables outside the module:"; echo "$core_hits" | sed 's/^/    /'
  violations=$((violations+1))
else
  echo "  ✓ none"
fi

echo
if [[ $violations -gt 0 ]]; then
  echo "BOUNDARY CHECK FAILED ($violations rule(s) violated)."
  exit 1
fi
echo "BOUNDARY CHECK PASSED."
exit 0
