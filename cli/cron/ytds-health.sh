#!/usr/bin/env bash
#
# ytds-health.sh — periodic liveness + read probe for a AmareloTDS instance, driven by the ytds CLI.
# Runs on the operator's machine (the CLI is local-only). Exits non-zero on failure so cron/alerting
# can notice. It only reads (version + campaigns list); it never mutates.
#
# Usage:  ytds-health.sh <env>           env = a configured remote environment (e.g. stg, prod)
# Cron:   */10 * * * * /path/to/ytds/cli/cron/ytds-health.sh stg >> /var/log/ytds-health.log 2>&1
#
set -u

ENV_NAME="${1:-stg}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
YTDS="$ROOT/bin/ytds"

ts() { date -u +%Y-%m-%dT%H:%M:%SZ; }
fail() { echo "$(ts) ytds-health $ENV_NAME FAIL: $1" >&2; exit 1; }

[ -x "$YTDS" ] || fail "ytds not executable at $YTDS"

"$YTDS" version --env "$ENV_NAME" >/dev/null 2>&1 || fail "version probe"
"$YTDS" campaigns list --env "$ENV_NAME" >/dev/null 2>&1 || fail "campaigns list probe"

echo "$(ts) ytds-health $ENV_NAME ok"
