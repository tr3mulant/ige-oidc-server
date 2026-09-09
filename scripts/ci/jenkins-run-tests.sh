#!/usr/bin/env bash
#
#############################################
# Jenkins - Run the test suite
# Runs on the Jenkins agent, inside the containers already started
#############################################
#
# Runs everything inside the container jenkins-build-test-containers.sh started,
# then tears the stack down.
#
# The suite talks to a real PostgreSQL (SSO plan §0.6), against the database that
# script created. phpunit.xml pins array/sync drivers for cache, queue, session
# and mail, so nothing here reaches outside the CI stack.
#
# Optional:
#   COMPOSE_PROJECT_NAME   defaults to ige-oidc-ci (must match the build script)
#   SKIP_TEARDOWN          set to 1 to leave containers up for debugging

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-ige-oidc-ci}"
export WWWUSER="${WWWUSER:-$(id -u)}"
export WWWGROUP="${WWWGROUP:-$(id -g)}"
export APP_PORT="${APP_PORT:-8101}"
export VITE_PORT="${VITE_PORT:-51731}"
export FORWARD_DB_PORT="${FORWARD_DB_PORT:-54330}"
export FORWARD_MAILPIT_PORT="${FORWARD_MAILPIT_PORT:-51025}"
export FORWARD_MAILPIT_DASHBOARD_PORT="${FORWARD_MAILPIT_DASHBOARD_PORT:-58025}"

# Same helper as the build script: --env-file .env.ci so compose never falls back to
# reading .env, which is a developer's real environment on any machine that is
# not a fresh Jenkins workspace.
compose() {
    docker compose --env-file .env.ci "$@"
}

# Same appexec as the build script, and for the same reason: `docker compose exec` ignores
# the entrypoint and runs as the image's user, which is root here. Without -u,
# `artisan test --log-junit` writes a root-owned tests/junit.xml into the
# bind-mounted workspace and cleanWs() cannot remove it.
appexec() {
    compose exec -T -u "${WWWUSER}" laravel.test "$@"
}

echo "================================"
echo "Running the test suite"
echo "================================"

# Teardown runs even when a step below fails, so a red build does not leave
# containers and volumes running on a long-lived agent. The junit file is
# produced inside the bind-mounted workspace, so it survives the teardown and
# Jenkins can still collect it.
teardown() {
    local rc=$?
    if [ "${SKIP_TEARDOWN:-0}" = "1" ]; then
        echo "SKIP_TEARDOWN=1, leaving containers up"
        return $rc
    fi
    echo ""
    echo "Cleaning up test containers..."
    compose down -v --remove-orphans 2>/dev/null || true
    return $rc
}
trap teardown EXIT

echo "Linting (pint --test reports violations without rewriting files)..."
appexec vendor/bin/pint --test

echo ""
echo "Running tests..."
# --log-junit is a PHPUnit option that artisan test passes through; it feeds the
# junit step in the Jenkinsfile. Drop both together if unwanted.
appexec php artisan test --log-junit=tests/junit.xml

echo ""
echo "✅ All tests passed"
