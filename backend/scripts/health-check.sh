#!/usr/bin/env bash
#
# Minimal uptime/alerting probe for GET /api/v1/health
# (backend/app/Http/Controllers/HealthController.php).
#
# NOT installed anywhere by default — docs/DEPLOYMENT.md §9 explicitly notes
# nothing calls this endpoint today. This script plus scripts/sanad-backup.cron
# (or a systemd timer, or an external uptime service pointed at the same URL)
# is what actually wires it up.
#
# On failure (non-200, or curl itself fails — timeout, connection refused)
# this posts a short JSON payload to ALERT_WEBHOOK_URL if set (works as-is
# with Slack/Discord/Mattermost incoming webhooks, which all accept a plain
# {"text": "..."} body) and always exits non-zero, so it also works as a
# systemd-timer OnFailure= trigger or a cron job whose stderr your MTA mails.
#
# Usage:
#   HEALTH_URL=https://api.example.com/api/v1/health \
#   ALERT_WEBHOOK_URL=https://hooks.slack.com/services/... \
#   ./scripts/health-check.sh

set -euo pipefail

HEALTH_URL="${HEALTH_URL:-http://127.0.0.1/api/v1/health}"
TIMEOUT_SECONDS="${TIMEOUT_SECONDS:-5}"

response=""
http_code="000"

if response="$(curl -s -m "$TIMEOUT_SECONDS" -w '\n%{http_code}' "$HEALTH_URL" 2>&1)"; then
    http_code="$(echo "$response" | tail -n1)"
    body="$(echo "$response" | sed '$d')"
else
    body="curl failed to reach $HEALTH_URL (timeout or connection error)"
fi

if [ "$http_code" = "200" ]; then
    echo "OK: $HEALTH_URL -> 200"
    exit 0
fi

message="SANAD health check FAILED: $HEALTH_URL -> HTTP ${http_code}. Body: ${body}"
echo "$message" >&2

if [ -n "${ALERT_WEBHOOK_URL:-}" ]; then
    # Best-effort — a failed alert must not mask the health failure itself,
    # so its own exit code is deliberately ignored.
    curl -s -m "$TIMEOUT_SECONDS" -X POST -H 'Content-Type: application/json' \
        -d "{\"text\": $(printf '%s' "$message" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))' 2>/dev/null || printf '"%s"' "$message")}" \
        "$ALERT_WEBHOOK_URL" >/dev/null 2>&1 || true
fi

exit 1
