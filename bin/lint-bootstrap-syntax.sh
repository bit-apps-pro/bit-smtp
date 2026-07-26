#!/usr/bin/env bash
# The bootstrap chain is require'd on every request and must parse on PHP 8.0.
# Fail if any 8.1-only syntax leaks into it.
set -euo pipefail
files=("bit_smtp.php" "backend/bootstrap.php")
pattern='(^|[^[:alnum:]_])(enum[[:space:]]|readonly[[:space:]]|:[[:space:]]*never\b)'
bad=0
for f in "${files[@]}"; do
  if grep -nEq "$pattern" "$f"; then
    echo "FORBIDDEN 8.1 syntax in bootstrap-chain file: $f"
    grep -nE "$pattern" "$f"
    bad=1
  fi
done
[ "$bad" -eq 0 ] && echo "bootstrap chain is 8.0-parseable" || exit 1
