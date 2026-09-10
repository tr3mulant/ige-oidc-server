#!/usr/bin/env bash
#
#############################################
# Jenkins - Deploy to IronGate01
# Runs ON THE SERVER via ssh, from the deploy directory
#############################################
#
# Pre-flight everything, verify the image is really pullable, pull, swap, wait,
# smoke test. It never builds and never pulls source -- the image already exists
# in the registry, tagged with the sha that passed CI, and this pulls that exact
# tag.
#
# Two things here exist only because this is an authorization server:
#
#   `docker compose down -v` APPEARS NOWHERE, and must not be added. The -v
#   would delete the named storage volume, and Passport's signing keys live on
#   it. Losing them does not error: it silently invalidates every token at every
#   client, signing out every user of every app in the company, on an ordinary
#   push. SSO plan §1.13 / §1.8.
#
#   The private key is fingerprinted before and after the swap. If a change to
#   the compose file, the volume name or the image ever breaks that persistence,
#   this says so in one line instead of leaving it to be discovered by users.
#
# Required environment:
#   DOCKER_REGISTRY DOCKER_REGISTRY_PATH DOCKER_IMAGE_NAME DOCKER_TAG
#   DOCKER_REGISTRY_USER DOCKER_REGISTRY_PASS
# Optional:
#   DEPLOY_DIR      where the compose file and .env.production live
#                   (default /var/www/ige-oidc)
#   APP_USER        the account php-fpm runs as (default ige-oidc)
#
# APP_PORT is deliberately not here. It is read from .env.production, and the
# deploy stops if that file does not set it.

set -euo pipefail

DEPLOY_DIR="${DEPLOY_DIR:-/var/www/ige-oidc}"
APP_USER="${APP_USER:-ige-oidc}"
# Under the deploy directory, which the deploy user owns. NOT /var/backups:
# that is root-owned drwxr-xr-x on a stock Ubuntu box, so `mkdir -p` there fails
# for the deploy user -- and with `set -e`, that aborts the whole deploy at a
# particularly bad moment, just after the new .env has been moved into place but
# before the container is swapped.
BACKUP_DIR="${BACKUP_DIR:-$DEPLOY_DIR/backups}"
COMPOSE_FILE="production.compose.yaml"

echo "================================================================"
echo "Deploying the identity provider"
echo "================================================================"

require() {
    local name="$1"
    [ -n "${!name:-}" ] || { echo "ERROR: $name is not set" >&2; exit 1; }
}
require DOCKER_REGISTRY
require DOCKER_REGISTRY_PATH
require DOCKER_IMAGE_NAME
require DOCKER_TAG
require DOCKER_REGISTRY_USER
require DOCKER_REGISTRY_PASS

IMAGE="${DOCKER_REGISTRY}/${DOCKER_REGISTRY_PATH}/${DOCKER_IMAGE_NAME}"
cd "$DEPLOY_DIR" || { echo "ERROR: $DEPLOY_DIR does not exist" >&2; exit 1; }

compose() {
    docker compose --env-file .env.production -f "$COMPOSE_FILE" "$@"
}

# Reads one key out of an env file without sourcing it. Sourcing would execute a
# stray backtick or $(...) in an unrelated secret in this shell.
envval() {
    grep -m1 -E "^$1=" "$2" 2>/dev/null | cut -d= -f2- | tr -d "\"'" || true
}

echo "Directory: $DEPLOY_DIR"
echo "Image:     ${IMAGE}:${DOCKER_TAG}"
echo ""

# --- Pre-flight --------------------------------------------------------------
# Every check that can fail happens before anything is stopped or moved, so a
# bad deploy leaves the running site untouched rather than half-swapped.
echo "=== Pre-flight ==="

[ -f "$COMPOSE_FILE" ] || { echo "ERROR: $COMPOSE_FILE missing in $DEPLOY_DIR" >&2; exit 1; }
echo "  compose file present"

# The single most expensive line in this repo to get wrong. Without this mapping
# the storage directory is container-local, so every image swap starts with an
# empty storage/ and start-container's warning about the missing key is the only
# trace. Checked before anything stops, because after the swap it is too late:
# the old keys are already gone.
grep -q ':/var/www/html/storage' "$COMPOSE_FILE" || {
    echo "ERROR: $COMPOSE_FILE does not mount a volume at /var/www/html/storage." >&2
    echo "  Passport's signing keys live there. Without the volume this deploy" >&2
    echo "  would discard them and sign out every user of every client app." >&2
    echo "  Refusing to continue. See SSO plan 1.13." >&2
    exit 1
}
echo "  storage volume is mapped"

# The incoming env arrives as .env.incoming (scp'd by the Jenkinsfile before
# this runs) and is only moved into place once the image is confirmed pullable.
# Not through /tmp, which is world readable, and this file is all secrets.
if [ -f .env.incoming ]; then
    ENV_UNDER_TEST=.env.incoming
    echo "  new .env.production staged"
elif [ -f .env.production ]; then
    ENV_UNDER_TEST=.env.production
    echo "  no new env staged, keeping the existing .env.production"
else
    echo "ERROR: no .env.incoming and no existing .env.production" >&2
    exit 1
fi

# The drift check. docker compose resolves ${APP_USER} from the SHELL
# environment before it reads --env-file, so the value the Jenkinsfile passed
# over ssh wins over anything the env file says -- silently. If somebody renames
# the app user in .env.production and not in the Jenkinsfile, the container
# builds and boots as one account while the env file claims another, and the
# first symptom is that nothing can read oauth-private.key.
#
# An env file that does not mention APP_USER at all is the normal case and fine:
# it is a deployment coordinate, not app config.
ENV_APP_USER="$(envval APP_USER "$ENV_UNDER_TEST")"
if [ -n "$ENV_APP_USER" ] && [ "$ENV_APP_USER" != "$APP_USER" ]; then
    echo "ERROR: APP_USER disagrees between the pipeline and the env file." >&2
    echo "  Jenkinsfile:        $APP_USER" >&2
    echo "  $ENV_UNDER_TEST:    $ENV_APP_USER" >&2
    echo "" >&2
    echo "  The shell value wins over --env-file, so this would deploy as" >&2
    echo "  '$APP_USER' while the env file claims '$ENV_APP_USER'. Make them" >&2
    echo "  agree, or drop APP_USER from the env file -- it belongs to the" >&2
    echo "  pipeline." >&2
    exit 1
fi

echo "$DOCKER_REGISTRY_PASS" | docker login --username "$DOCKER_REGISTRY_USER" --password-stdin "$DOCKER_REGISTRY" >/dev/null
echo "  registry login ok"

# Remember what is running now, so the failure message at the end can name the
# tag to roll back to.
# Deliberately not `... | head -1 | sed ...`. Under `set -e` with pipefail, a
# producer killed by SIGPIPE when head exits early fails the whole command
# substitution -- and this is an assignment, so it would abort the deploy here,
# during pre-flight, over nothing more than a cosmetic rollback hint.
PREVIOUS_IMAGE="$(compose ps --format '{{.Image}}' app 2>/dev/null || true)"
PREVIOUS_IMAGE="${PREVIOUS_IMAGE%%$'\n'*}"
PREVIOUS_TAG="${PREVIOUS_IMAGE##*:}"
[ -n "$PREVIOUS_TAG" ] && echo "  currently running: $PREVIOUS_TAG"

# Fingerprint the signing key before anything moves. A hash, not the key: this
# runs in a log Jenkins keeps for thirty builds.
KEY_BEFORE=""
if [ -n "$(compose ps --status running --quiet app 2>/dev/null)" ]; then
    KEY_BEFORE="$(compose exec -T app sha256sum /var/www/html/storage/oauth-private.key 2>/dev/null | cut -c1-16 || true)"
    if [ -n "$KEY_BEFORE" ]; then
        echo "  signing key fingerprint: ${KEY_BEFORE}"
    else
        echo "  no signing key yet (first deploy, or passport:keys has not been run)"
    fi
fi
echo ""

# --- Install the new env -----------------------------------------------------
if [ -f .env.incoming ]; then
    echo "=== Installing .env.production ==="
    # Keep the outgoing one. APP_KEY lives only in this file, and the OIDC
    # client secrets from §1.6a arrive the same way -- so a bad env shipped by
    # Jenkins (wrong APP_KEY, truncated Secret file) is NOT recoverable from the
    # database dump alone.
    if [ -f .env.production ]; then
        cp -a .env.production .env.production.prev
        chmod 600 .env.production.prev
        echo "  previous env kept as .env.production.prev"
    fi
    mv .env.incoming .env.production
    chmod 600 .env.production
    echo "  installed"
    echo ""
fi

APP_PORT="$(envval APP_PORT .env.production)"
[ -n "$APP_PORT" ] || {
    echo "ERROR: APP_PORT is not set in .env.production." >&2
    echo "  It is the loopback port host Apache proxies to, and the vhost expects" >&2
    echo "  a specific one. Add it to the 'ige-oidc-server.env.production' Secret" >&2
    echo "  file in Jenkins." >&2
    exit 1
}
echo "  publishing on 127.0.0.1:${APP_PORT}"

export DOCKER_TAG APP_USER APP_PORT

DB_USERNAME="$(envval DB_USERNAME .env.production)"
DB_DATABASE="$(envval DB_DATABASE .env.production)"

# The pre-swap dump runs with these. A guess here backs up the wrong database,
# or nothing, immediately before the container is replaced.
for key in DB_USERNAME DB_DATABASE; do
    [ -n "${!key}" ] || {
        echo "ERROR: $key is not set in .env.production." >&2
        echo "  The pre-swap database backup runs with it. Add it to the" >&2
        echo "  'ige-oidc-server.env.production' Secret file in Jenkins." >&2
        exit 1
    }
done

# --- Pull --------------------------------------------------------------------
# Before stopping anything: pulling can be slow, and there is no reason for the
# site to be down while it happens.
#
# This is also the gate that proves the image exists. If the tag is not in the
# registry, or the registry is unreachable, the deploy stops HERE with docker's
# own error and the old container still serving.
echo "=== Pulling ${DOCKER_TAG} ==="
if ! compose pull; then
    echo "ERROR: could not pull ${IMAGE}:${DOCKER_TAG} -- see docker's error above." >&2
    echo "  Nothing was stopped; the running site is untouched." >&2
    exit 1
fi
echo ""

# --- Back up ------------------------------------------------------------------
# Two backups, and they are not interchangeable. SSO plan §1.13 rule 3: the keys
# are files on a volume, not rows, so a restore that recovers Postgres and not
# the volume brings back every account and no ability to sign anything.
#
# A failed backup does not stop the deploy -- refusing to ship because a dump
# failed is its own outage -- but it is loud about it.
if [ -n "$(compose ps --status running --quiet app 2>/dev/null)" ]; then
    if ! mkdir -p "$BACKUP_DIR" 2>/dev/null; then
        echo "  WARNING: cannot create ${BACKUP_DIR} -- skipping backups."
    else
        # 700, and note the ordering: the deploy creates this directory on the
        # very first run, so without this chmod it sits at 755 on a box that
        # also serves app.irongateenterprises.com.
        chmod 700 "$BACKUP_DIR" 2>/dev/null || true
        STAMP="$(date -u +%Y%m%dT%H%M%SZ)"

        echo "=== Backing up the signing keys ==="
        KEY_BACKUP="${BACKUP_DIR}/oauth-keys-${STAMP}-pre-${DOCKER_TAG}.tar.gz"
        # The umask matters as much as the chmod: it closes the window where the
        # archive exists world-readable while it is still streaming.
        if ( umask 077; compose exec -T app tar -cz -C /var/www/html/storage \
                oauth-private.key oauth-public.key 2>/dev/null > "$KEY_BACKUP" ); then
            chmod 600 "$KEY_BACKUP" 2>/dev/null || true
            echo "  $KEY_BACKUP"
        else
            rm -f "$KEY_BACKUP"
            echo "  WARNING: no signing keys to back up. If this is not the first"
            echo "  deploy, that is a problem -- see the fingerprint check below."
        fi
        echo ""

        if [ -n "$(compose ps --status running --quiet pgsql 2>/dev/null)" ]; then
            echo "=== Backing up the database ==="
            # Before migrations, because start-container runs `migrate --force`
            # the moment the new container boots. Re-deploying an older image
            # tag rolls back the CODE but never the SCHEMA, so this dump is what
            # makes that recoverable.
            DB_BACKUP="${BACKUP_DIR}/ige-oidc-${STAMP}-pre-${DOCKER_TAG}.sql.gz"
            if ( umask 077; compose exec -T pgsql pg_dump -U "$DB_USERNAME" \
                    -d "$DB_DATABASE" 2>/dev/null | gzip > "$DB_BACKUP" ); then
                chmod 600 "$DB_BACKUP" 2>/dev/null || true
                echo "  $DB_BACKUP ($(du -h "$DB_BACKUP" | cut -f1))"
            else
                rm -f "$DB_BACKUP"
                echo "  WARNING: pg_dump failed. Continuing, but there is no"
                echo "  pre-migration snapshot for this deploy."
            fi
            echo ""
        fi

        # Keep a month. These are small and the disk is shared with two other
        # sites.
        find "$BACKUP_DIR" -name 'ige-oidc-*.sql.gz' -mtime +30 -delete 2>/dev/null || true
        find "$BACKUP_DIR" -name 'oauth-keys-*.tar.gz' -mtime +30 -delete 2>/dev/null || true
    fi
fi

# --- Swap --------------------------------------------------------------------
# No -v. Read the header before considering adding one.
echo "=== Stopping containers ==="
compose down
echo ""

echo "=== Starting containers ==="
compose up -d --remove-orphans
echo ""

# --- Wait --------------------------------------------------------------------
# start-container migrates and caches before Apache accepts a request, so this
# is waiting on the whole boot sequence, not just the web server.
echo "=== Waiting for the app to come up ==="
READY=false
for i in $(seq 1 60); do
    if compose exec -T app curl -fsS http://localhost/up >/dev/null 2>&1; then
        echo "  ready after ${i} attempt(s)"
        READY=true
        break
    fi
    sleep 3
done

if [ "$READY" = false ]; then
    echo "ERROR: app did not answer /up within 180s" >&2
    echo "--- last 80 log lines ---" >&2
    compose logs --tail=80 app >&2 || true
    [ -n "$PREVIOUS_TAG" ] && echo "Roll back with: DOCKER_TAG=$PREVIOUS_TAG $0" >&2
    exit 1
fi
echo ""

# --- The signing keys survived the swap --------------------------------------
# The check this whole script is shaped around. Everything above can succeed and
# the deployment still be a company-wide silent logout.
echo "=== Signing keys ==="
KEY_AFTER="$(compose exec -T app sha256sum /var/www/html/storage/oauth-private.key 2>/dev/null | cut -c1-16 || true)"

if [ -z "$KEY_AFTER" ]; then
    if [ -n "$KEY_BEFORE" ]; then
        echo "ERROR: the signing key was present before this deploy and is gone now." >&2
        echo "  Every token at every client is now unverifiable." >&2
        echo "  Restore from ${BACKUP_DIR}/oauth-keys-*.tar.gz and check the" >&2
        echo "  storage volume in $COMPOSE_FILE." >&2
        exit 1
    fi
    # First deploy. Expected: §1.13 rule 1 says passport:keys is run once, by
    # hand, never as a repeatable step. Loud rather than fatal, because failing
    # here would leave the same unkeyed container running and give the operator
    # a red build instead of the command they need.
    echo "  WARNING: there is no signing key on the storage volume."
    echo "  Sign-in works; issuing and verifying tokens does not. Run once:"
    echo ""
    echo "      cd $DEPLOY_DIR"
    echo "      docker compose --env-file .env.production -f $COMPOSE_FILE \\"
    echo "          run --rm app php artisan passport:keys"
    echo ""
    echo "  run --rm, NOT docker exec: run goes through the entrypoint and"
    echo "  writes the key owned by $APP_USER. exec runs as root and produces a"
    echo "  0600 root-owned key the php-fpm pool cannot read -- healthy"
    echo "  container, serving site, every token operation broken."
    echo ""
    echo "  Then verify:  curl -s -o /dev/null -w '%{http_code}\\n' \\"
    echo "                     http://127.0.0.1:${APP_PORT}/.well-known/jwks.json"
    echo "  Never pass --force. It is one word from a total outage."
elif [ -n "$KEY_BEFORE" ] && [ "$KEY_BEFORE" != "$KEY_AFTER" ]; then
    echo "ERROR: the signing key CHANGED across this deploy." >&2
    echo "  before: $KEY_BEFORE" >&2
    echo "  after:  $KEY_AFTER" >&2
    echo "" >&2
    echo "  Every token issued before this deploy is now invalid, at every" >&2
    echo "  client. The storage volume is not persisting, or something ran" >&2
    echo "  passport:keys --force. Restore the previous key from" >&2
    echo "  ${BACKUP_DIR}/oauth-keys-*.tar.gz before doing anything else." >&2
    exit 1
else
    echo "  unchanged across the swap: ${KEY_AFTER}"
fi
echo ""

# --- Verify ------------------------------------------------------------------
echo "=== Container status ==="
compose ps
echo ""

echo "=== Supervised processes ==="
compose exec -T app supervisorctl status || true
echo ""

# -f, not -x. It is invoked as `bash scripts/smoke-test.sh`, so the executable
# bit was never load-bearing -- but gating on it would mean losing that bit (a
# `git apply`, an archive round-trip, a tarball restore) silently skips the
# deploy's only real verification while still printing "Deployed <tag>".
if [ -f scripts/smoke-test.sh ]; then
    echo "=== Smoke tests ==="
    if ! APP_USER="$APP_USER" DEPLOY_DIR="$DEPLOY_DIR" bash scripts/smoke-test.sh; then
        echo "ERROR: smoke tests failed" >&2
        [ -n "$PREVIOUS_TAG" ] && echo "Roll back with: DOCKER_TAG=$PREVIOUS_TAG $0" >&2
        exit 1
    fi
    echo ""
else
    echo "WARNING: scripts/smoke-test.sh is missing -- this deploy was NOT verified." >&2
    echo ""
fi

# Clears DANGLING layers only (<none>:<none>) -- that is all `docker image
# prune` does without -a. It does NOT reclaim old deploy images: every image
# here arrives via `compose pull` of IMAGE:<sha> and keeps that tag forever, so
# nothing ever becomes dangling. That is deliberate. A deploy must never delete
# a rollback target, and `-a` here would remove every image not currently
# running -- including tools.* and the registry's own, which share this box.
echo "=== Pruning dangling layers ==="
docker image prune --force --filter "until=336h" >/dev/null 2>&1 || true
echo "  done (tagged deploy images are kept)"
echo ""

echo "================================================================"
echo "Deployed ${DOCKER_TAG}"
echo "  https://auth.irongateenterprises.com"
echo "  rollback: DOCKER_TAG=<previous-sha> $0"
echo "================================================================"
