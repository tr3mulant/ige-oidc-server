#!/usr/bin/env bash
#
#############################################
# Jenkins - Build and start test containers
# Runs on the Jenkins agent
#############################################
#
# Brings the Sail stack up from the committed compose file, installs everything
# inside it, and leaves it running for jenkins-run-tests.sh to test against.
#
# The agent carries no language runtime, only a Docker client, so the suite runs
# on the same PHP 8.5 developers use rather than on whatever the node happens to
# have. Don't add one: it is a shared node, and a PHP baked into it would pin
# every job on it to that version.
#
# SSO plan §0.6 pins this suite to PostgreSQL: this service authenticates every
# app in the company, and a green build on an engine you do not deploy is worth
# very little. So the database setup below is load-bearing, not cosmetic.
#
# Required environment:
#   ENV_TEST_FILE   path to the .env.testing secret file staged by Jenkins
# Optional:
#   COMPOSE_PROJECT_NAME   defaults to ige-oidc-ci

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-ige-oidc-ci}"

# The agent's own uid/gid. These are build args: they control the uid the
# in-container `sail` account is created with, and start-container usermods to
# match at boot. On their own they do NOT affect `docker compose exec`, which
# ignores the entrypoint and runs as the image's configured user -- and this
# image sets no USER, so that is root. The thing that makes bind-mounted writes
# land as the agent rather than root is the `-u "${WWWUSER}"` in appexec()
# below. Both halves are required; see the comment there.
export WWWUSER="${WWWUSER:-$(id -u)}"
export WWWGROUP="${WWWGROUP:-$(id -g)}"

# Every compose call goes through here so --env-file is never forgotten; without
# it docker compose falls back to reading .env, which on any machine that is not
# a fresh Jenkins workspace is a developer's real environment.
compose() {
    docker compose --env-file .env.testing "$@"
}

# Every exec into the app container goes through here, so no call site can
# forget the -u and silently start writing as root.
#
# `docker compose exec` bypasses the entrypoint and runs as the image's
# configured user; this image sets no USER, so without -u every payload command
# below (composer install, npm ci, npm run build, artisan) writes root-owned
# files into the bind-mounted Jenkins workspace. cleanWs() then cannot remove
# them, the build fails in post{cleanup}, and the next checkout inherits the
# debris. Worse, a later non-root run cannot write into the residue, which
# surfaces as dozens of unrelated-looking test failures.
#
# The numeric uid resolves against the image's /etc/passwd to the `sail`
# account (the image is built with WWWUSER=<agent uid>), so HOME is still
# /home/sail and the composer/npm caches behave.
appexec() {
    compose exec -T -u "${WWWUSER}" laravel.test "$@"
}

# Reads one key out of an env file without sourcing it. Sourcing would execute a
# stray backtick or $(...) in an unrelated secret in this shell.
envval() {
    grep -m1 -E "^$1=" "$2" 2>/dev/null | cut -d= -f2- | tr -d "\"'" || true
}

echo "================================"
echo "Building and starting test containers"
echo "================================"

[ -n "${ENV_TEST_FILE:-}" ] || { echo "ERROR: ENV_TEST_FILE is not set" >&2; exit 1; }
[ -f "$ENV_TEST_FILE" ] || { echo "ERROR: $ENV_TEST_FILE does not exist" >&2; exit 1; }

# .env.testing is not committed (.gitignore excludes .env*), so it arrives as a
# Jenkins Secret file. Copy it, never print or source it.
#
# Tolerate the source already being the destination, which happens when this is
# run by hand on a machine that has its own .env.testing -- cp errors out on
# that ("are the same file") and would fail the build for no reason.
#
# install, not cp, because of the mode. withCredentials writes its secret file
# read-only (0400), and cp gives a file it creates the SOURCE's bits -- so a
# second run in a workspace that was not cleaned finds a 0400 .env.testing and
# cp fails on it with "Permission denied".
if [ "$(readlink -f "$ENV_TEST_FILE")" = "$(readlink -f .env.testing)" ]; then
    echo "  .env.testing is already the source file, leaving it"
else
    install -m 600 "$ENV_TEST_FILE" .env.testing
    echo "  .env.testing staged"
fi

# The credential is the configuration. Nothing here supplies a value it omits:
# compose reads every one of these through --env-file, and a missing one stops
# the build instead of resolving to something only this file knows.
for key in DB_CONNECTION DB_HOST DB_DATABASE DB_USERNAME DB_PASSWORD \
           APP_PORT VITE_PORT FORWARD_DB_PORT \
           FORWARD_MAILPIT_PORT FORWARD_MAILPIT_DASHBOARD_PORT; do
    [ -n "$(envval "$key" .env.testing)" ] || {
        echo "ERROR: $key is not set in the .env.testing credential." >&2
        echo "  compose.yaml interpolates it, and nothing here defaults it." >&2
        echo "  Add it to the 'ige-oidc-server.env.testing' Secret file in Jenkins." >&2
        exit 1
    }
done

# The trap from the plan's "two traps found on the first sail up": DB_HOST was
# 127.0.0.1, left over from the bare-metal era, which inside the container is
# the container itself. It fails as `connection refused` several minutes into
# the run with nothing pointing at the cause.
DB_HOST="$(envval DB_HOST .env.testing)"
[ "$DB_HOST" = "pgsql" ] || {
    echo "ERROR: DB_HOST is '$DB_HOST', but the suite runs INSIDE the container." >&2
    echo "  It must be 'pgsql' -- the compose service name. 127.0.0.1 there is the" >&2
    echo "  app container, not the database." >&2
    exit 1
}

# Clear anything a previous build left behind, volumes included, so tests never
# run against a stale database.
echo "Cleaning up existing containers..."
compose down -v --remove-orphans 2>/dev/null || true

echo "Building and starting test containers..."
compose up -d --build laravel.test

echo "Waiting for the container to accept commands..."
for i in $(seq 1 30); do
    if appexec true 2>/dev/null; then
        echo "  ready after ${i} attempt(s)"
        break
    fi
    sleep 2
done

echo "Verifying container..."
appexec php --version

echo "Waiting for postgres..."
DB_USERNAME="$(envval DB_USERNAME .env.testing)"
DB_DATABASE="$(envval DB_DATABASE .env.testing)"
PG_READY=false
for i in $(seq 1 30); do
    if compose exec -T pgsql pg_isready -q -U "$DB_USERNAME" -d "$DB_DATABASE" 2>/dev/null; then
        echo "  ready after ${i} attempt(s)"
        PG_READY=true
        break
    fi
    sleep 2
done
[ "$PG_READY" = true ] || {
    echo "ERROR: postgres did not become ready within 60s" >&2
    compose logs --tail=40 pgsql >&2 || true
    exit 1
}

# The second trap the plan records. docker/pgsql/create-testing-database.sql is
# Sail's stock init script and creates a database named literally `testing`, not
# the name phpunit.xml pins -- and being an initdb script it only runs on first
# volume creation, so editing it does nothing without wiping the volume.
#
# The name is read out of phpunit.xml rather than written here twice. phpunit's
# own <env> values win over both .env and .env.testing, so that file is the only
# thing that decides which database the suite opens; hardcoding it here would be
# a second copy that could drift, and the drift would look like every test
# failing to connect.
TEST_DB="$(sed -n 's/.*<env name="DB_DATABASE" value="\([^"]*\)".*/\1/p' phpunit.xml | head -1)"
[ -n "$TEST_DB" ] || {
    echo "ERROR: could not read DB_DATABASE out of phpunit.xml" >&2
    exit 1
}

echo "Ensuring the test database '${TEST_DB}' exists..."
# `docker compose exec pgsql` reaches postgres over the container's unix socket,
# which the official image trusts for the superuser -- so no password is needed
# and none is put on a command line.
if compose exec -T pgsql psql -U "$DB_USERNAME" -d "$DB_DATABASE" -tAc \
        "SELECT 1 FROM pg_database WHERE datname = '${TEST_DB}'" | grep -q 1; then
    echo "  already present"
else
    compose exec -T pgsql createdb -U "$DB_USERNAME" -O "$DB_USERNAME" "$TEST_DB"
    echo "  created"
fi

echo "Installing Composer dependencies..."
appexec composer install --prefer-dist --no-progress --no-interaction

echo "Installing frontend dependencies..."
# ci, not install: package-lock.json stays authoritative so a build cannot
# silently drift onto newer dependencies.
appexec npm ci

echo "Building frontend assets..."
appexec npm run build

echo "Clearing Laravel caches..."
# config/route/view only -- deliberately NOT optimize:clear, which also runs
# cache:clear and therefore has to reach the cache STORE. These three touch
# files only and cannot fail on a database.
appexec php artisan config:clear
appexec php artisan route:clear
appexec php artisan view:clear

# The suite redeems authorization codes, and league/oauth2-server reports a missing
# signing key as `LogicException: Invalid key supplied` -- which never mentions a
# file. storage/*.key is gitignored and cleanWs() empties the workspace, so every
# build starts without one.
#
# Disposable and per-build. Nothing to do with the production key on IronGate01,
# which is generated once by hand: --force there invalidates every token at every
# client. Guarded rather than --force here because the command exits FAILURE when a
# key exists, which under `set -e` would kill a hand-run in a dirty workspace.
echo "Generating the ephemeral Passport signing key..."
if appexec test -f storage/oauth-private.key; then
    echo "  already present, leaving it"
else
    appexec php artisan passport:keys --no-interaction
fi

# Reports the environment the suite will actually run under: PHP and framework
# versions, the cache/queue/session drivers, the resolved database connection.
# A bad or missing credential shows up here as Laravel's own error rather than
# as a wall of failing tests several minutes later.
echo "Environment under test:"
appexec php artisan about --env=testing

echo ""
echo "✅ Containers built and ready for testing"
