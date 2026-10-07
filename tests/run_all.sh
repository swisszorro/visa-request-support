#!/bin/sh
# Runs every test file; exit code 1 if any fails.
#   docker compose exec visa sh tests/run_all.sh
cd "$(dirname "$0")/.." || exit 1
rc=0
for t in mrz verifier gemini claude rules matching excel image; do
  printf '== %s: ' "$t"
  out=$(php "tests/${t}_test.php" 2>&1) || rc=1
  echo "$out" | grep -E "PASSED|FAILED|Fatal" | tail -1
  echo "$out" | grep -E "✗|Fatal" | head -20
done
exit $rc
