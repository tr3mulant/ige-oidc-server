#!/usr/bin/env bash
#
#############################################
# Jenkins - Build and push the production image
# Runs on the Jenkins agent
#############################################
#
# Pre-deploy. Runs after the tests have passed and before the deploy. Nothing
# here touches IronGate01 -- the only output is a tagged image sitting in the
# registry for the deploy to pull.
#
# The image is tagged twice: with the git sha, which is the immutable thing the
# deploy actually pulls, and with a moving `production` alias for humans running
# compose by hand. Tagging by sha is what makes rollback possible -- every
# deploy that ever ran is still addressable by its own tag.
#
# Required environment (the Jenkinsfile sets all of these):
#   DOCKER_REGISTRY        registry.irongateenterprises.com
#   DOCKER_REGISTRY_PATH   ige-oidc
#   DOCKER_IMAGE_NAME      ige-oidc-server
#   DOCKER_TAG             the short git sha of the commit under test
#   DOCKER_REGISTRY_USER   from the 'ige-registry' credential
#   DOCKER_REGISTRY_PASS   from the 'ige-registry' credential
# Optional:
#   WWWUSER / WWWGROUP     uid/gid that owns the storage volume (default 1000)
#   APP_USER               app user inside the image (default ige-oidc).
#                          Named APP_USER rather than USERNAME because zsh sets
#                          USERNAME to the login name of whoever is running it.

set -euo pipefail

echo "================================"
echo "Pre-deploy: building production image"
echo "================================"

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

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

IMAGE="${DOCKER_REGISTRY}/${DOCKER_REGISTRY_PATH}/${DOCKER_IMAGE_NAME}"
APP_USER="${APP_USER:-ige-oidc}"

echo "Image:  ${IMAGE}"
echo "Tag:    ${DOCKER_TAG}"
echo "User:   ${APP_USER}"
echo "Commit: ${GIT_COMMIT:-unknown}"
echo "Branch: ${GIT_BRANCH:-unknown}"
echo ""

# Plain `docker build`, not `docker compose build`. Compose would parse
# production.compose.yaml, whose `${DB_PASSWORD:?}` guards would demand the
# production secrets be present on the build agent -- which the build does not
# need and should not have.
#
# Nothing here constrains the manifest shape on purpose. Whether this build
# emits a single image manifest or an OCI index with a provenance attestation is
# the agent's Docker configuration to decide, and the deploy pulls the tag
# rather than interrogating its media type.

# --pull so a stale local ubuntu:24.04 cannot silently pin the base image.
echo "Building..."
docker build \
    --pull \
    --file docker/8.5/production.Dockerfile \
    --build-arg "WWWUSER=${WWWUSER:-1000}" \
    --build-arg "WWWGROUP=${WWWGROUP:-1000}" \
    --build-arg "USERNAME=${APP_USER}" \
    --label "org.opencontainers.image.revision=${GIT_COMMIT:-unknown}" \
    --label "org.opencontainers.image.source=ige-oidc-server" \
    --label "org.opencontainers.image.created=$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    --tag "${IMAGE}:${DOCKER_TAG}" \
    --tag "${IMAGE}:production" \
    .

echo ""
echo "Built:"
docker image inspect "${IMAGE}:${DOCKER_TAG}" --format '  {{.RepoTags}}  {{.Size}} bytes'
echo ""

# Smoke-check the image before it goes anywhere. A broken config or a missing
# PHP extension is far cheaper to find here than on the server after the old
# container is already down.
echo "Verifying image..."

docker run --rm --entrypoint /usr/bin/php "${IMAGE}:${DOCKER_TAG}" --version

docker run --rm --entrypoint /usr/bin/test "${IMAGE}:${DOCKER_TAG}" -f /var/www/html/public/index.php \
    || { echo "ERROR: /var/www/html/public/index.php missing." >&2; exit 1; }
docker run --rm --entrypoint /usr/bin/test "${IMAGE}:${DOCKER_TAG}" -f /var/www/html/public/build/manifest.json \
    || { echo "ERROR: Vite manifest missing -- npm run build did not produce assets." >&2; exit 1; }

# The config cache is checked HERE, at build time, because it is the one thing
# in this repo that has already broken a boot. production.start-container runs
# `artisan optimize`, and config:cache serializes with var_export -- so a
# closure anywhere under config/ raises `Call to undefined method
# Closure::__set_state()` and the container never reaches Apache.
#
# The package ships two such closures in its default config/oidc-server.php
# (`email_verified` and `updated_at` in default_claims_map); they were replaced
# by User::resolveOidcClaim(). A `composer update` that republishes that config,
# or anyone adding `fn ($user) => ...` to a claims map, reintroduces it. Failing
# on the agent costs a red build. Failing on the box costs an outage of every
# login in the company.
#
# Deliberately not `artisan optimize`: route:cache and view:cache are not at
# risk here and would only add ways for this check to fail for reasons that are
# not the one it exists for.
docker run --rm --entrypoint /usr/bin/php "${IMAGE}:${DOCKER_TAG}" \
    /var/www/html/artisan config:cache >/dev/null \
    || { echo "ERROR: config:cache failed -- a non-serializable value (usually a closure) is in config/." >&2; exit 1; }
echo "  config caches cleanly (no closures under config/)"

# supervisord has no --configtest. Parse the file with the library supervisor
# itself uses. An image whose php-fpm program silently vanished deploys green
# and 503s every page.
docker run --rm --entrypoint /usr/bin/python3 "${IMAGE}:${DOCKER_TAG}" -c "
import configparser, sys
c = configparser.ConfigParser(interpolation=None)
c.read('/etc/supervisor/conf.d/supervisord.conf')
missing = {'program:php-fpm', 'program:apache2'} - set(c.sections())
if missing:
    sys.exit('supervisord.conf is missing: ' + ', '.join(sorted(missing)))
print('  supervisord.conf defines php-fpm and apache2')
"

docker run --rm --entrypoint /usr/sbin/apache2ctl "${IMAGE}:${DOCKER_TAG}" -t

echo "  image is sane"
echo ""

# --password-stdin, not -p: -p puts the password in the process list, which is
# readable by anything else running on the agent.
echo "Logging in to ${DOCKER_REGISTRY}..."
echo "$DOCKER_REGISTRY_PASS" | docker login --username "$DOCKER_REGISTRY_USER" --password-stdin "$DOCKER_REGISTRY"

echo "Pushing ${IMAGE}:${DOCKER_TAG}..."
docker push "${IMAGE}:${DOCKER_TAG}"

echo "Pushing ${IMAGE}:production..."
docker push "${IMAGE}:production"

echo ""
echo "================================"
echo "Pre-deploy complete"
echo "  ${IMAGE}:${DOCKER_TAG}"
echo "  ${IMAGE}:production"
echo "================================"
