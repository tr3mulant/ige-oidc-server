#!/usr/bin/env bash

set -uo pipefail

fail() { echo "unhealthy: $*"; exit 1; }

curl -fsS --max-time 5 http://localhost/.well-known/openid-configuration >/dev/null 2>&1 \
    || fail "/.well-known/openid-configuration did not return 200"

status="$(supervisorctl status 2>&1)" || true

if [ -z "$status" ] || echo "$status" | grep -qiE 'refused|no such file|SHUTDOWN_STATE|not running'; then
    fail "supervisord is not reachable: ${status:-no output}"
fi

for program in php-fpm apache2; do
    line="$(echo "$status" | grep -E "^${program}[[:space:]]" || true)"
    [ -n "$line" ] || fail "${program} is not defined in supervisord"
    echo "$line" | grep -q RUNNING || fail "${program} is $(echo "$line" | awk '{print $2}')"
done

echo "healthy: php-fpm, apache2 running; discovery document 200"
