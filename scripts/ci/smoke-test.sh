#!/usr/bin/env bash
#
#############################################
# Production Smoke Tests
# Run this ON THE SERVER, from the deploy directory
#############################################
#
# "The pipeline went green" and "the identity provider actually works" are two
# different facts. These check the second.
#
# The bias throughout is towards the failures that LOOK HEALTHY. An IdP whose
# signing key is unreadable serves every page, answers /up, and reports a
# healthy container -- while every client application is broken. A plain HTTP
# check would pass all of it.

# Both are supplied by scripts/deploy.sh, which reads APP_USER out of
# .env.production. Neither defaults: this is the deploy's only real
# verification, and a default pointing at the wrong directory or checking the
# wrong account would print PASS about something else entirely.
#
# APP_USER, not USERNAME: zsh sets USERNAME to the login name, which makes this
# check the wrong account and fail on a perfectly healthy container.
: "${DEPLOY_DIR:?set DEPLOY_DIR -- the directory holding production.compose.yaml and .env.production}"
: "${APP_USER:?set APP_USER -- the account php-fpm runs as, as set in .env.production}"

COMPOSE_FILE="production.compose.yaml"
cd "$DEPLOY_DIR" || exit 1

echo "================================"
echo "Running Production Smoke Tests"
echo "================================"

FAILURES=0

compose() {
    docker compose --env-file .env.production -f "$COMPOSE_FILE" "$@"
}

# Run a shell snippet inside the app container. One helper, so no test below has
# to nest quotes inside a docker compose invocation.
appsh() {
    compose exec -T app bash -c "$1"
}

check() {
    local label="$1" snippet="$2"
    printf '%-58s' "$label"
    if appsh "$snippet" >/dev/null 2>&1; then
        echo "PASS"
    else
        echo "FAIL"
        FAILURES=$((FAILURES + 1))
    fi
}

# --- The web server ----------------------------------------------------------
check "Test 1: health endpoint /up answers 200" \
      'curl -fsS http://localhost/up'

check "Test 2: login page renders" \
      'curl -fsS http://localhost/login | grep -qi "csrf\|password"'

# --- The supervised processes ------------------------------------------------
# In the order supervisord starts them. A dead pool is a 503 on every page.
i=3
for program in php-fpm apache2; do
    check "Test ${i}: supervisord ${program} RUNNING" \
          "supervisorctl status ${program} | grep -q RUNNING"
    i=$((i + 1))
done

# --- The container's own verdict ---------------------------------------------
# healthcheck.sh checks the processes as well as HTTP, so this is the same
# signal `docker ps` and any monitoring will see.
printf '%-58s' "Test 5: container healthcheck passes"
if compose exec -T app /usr/local/bin/healthcheck >/dev/null 2>&1; then
    echo "PASS"
else
    echo "FAIL"
    FAILURES=$((FAILURES + 1))
fi

# --- Postgres ----------------------------------------------------------------
printf '%-58s' "Test 6: postgres accepting connections"
if compose exec -T pgsql pg_isready -q >/dev/null 2>&1; then
    echo "PASS"
else
    echo "FAIL"
    FAILURES=$((FAILURES + 1))
fi

check "Test 7: database migrated" \
      'php /var/www/html/artisan migrate:status | grep -q Ran'

check "Test 8: artisan boots and reports its environment" \
      'php /var/www/html/artisan about'

# --- The OIDC surface --------------------------------------------------------
# The discovery document is the contract every client reads. It is also the one
# endpoint that answers 200 no matter how broken the signing keys are, which is
# why it is checked for CONTENT rather than status.
check "Test 9: discovery document is served and parses" \
      'curl -fsS http://localhost/.well-known/openid-configuration | php -r "exit(json_decode(stream_get_contents(STDIN)) === null ? 1 : 0);"'

# preferred_username is what mod_auth_openidc turns into REMOTE_USER on the
# legacy intranet, where it is matched against ADMIN_ML_USERS. Dropping it from
# the profile scope is not an error anywhere -- it produces an empty REMOTE_USER
# and silently removes everyone's admin rights on app.irongateenterprises.com.
check "Test 10: preferred_username is advertised as a claim" \
      'curl -fsS http://localhost/.well-known/openid-configuration | grep -q preferred_username'

# The issuer comes from OIDC_ISSUER/APP_URL, not from the request, so this
# catches an APP_URL that was never updated for production. Redirect URIs are
# built from the scheme and host and matched by EXACT STRING (SSO plan 1.6): an
# http:// issuer behind TLS-terminating Apache means every client's redirect
# fails to match, which presents as a login loop rather than as an error.
check "Test 11: the issuer is an https:// URL" \
      'curl -fsS http://localhost/.well-known/openid-configuration | grep -qE "\"issuer\"[[:space:]]*:[[:space:]]*\"https://"'

# The authorization endpoint must redirect an unauthenticated browser to login,
# not 500. A 500 here is the usual first symptom of a broken client
# registration or an unreadable key.
check "Test 12: /oauth/authorize redirects rather than erroring" \
      'test "$(curl -s -o /dev/null -w "%{http_code}" "http://localhost/oauth/authorize?client_id=smoke&response_type=code")" -lt 400'

# --- The signing keys --------------------------------------------------------
# jwks.json is the ONLY endpoint that fails on a bad key. / and the discovery
# document both answer 200 regardless, which is exactly why neither is the
# check.
printf '%-58s' "Test 13: JWKS endpoint serves the public key"
if ! appsh 'test -f /var/www/html/storage/oauth-private.key' >/dev/null 2>&1; then
    # Not counted as a failure: on a first deploy the keys are created by hand,
    # once, and the deploy script prints the command. Counting it would make the
    # very first deploy of a correct image fail.
    echo "SKIP (no key yet -- run passport:keys)"
elif appsh 'curl -fsS http://localhost/.well-known/jwks.json | grep -q "\"kty\""' >/dev/null 2>&1; then
    echo "PASS"
else
    echo "FAIL"
    FAILURES=$((FAILURES + 1))
fi

# Ownership, not just existence. `docker exec` skips the entrypoint and runs as
# root, so `docker exec ... passport:keys` produces a 0600 root-owned key. The
# pool cannot read it, and nothing says so: the site serves and every token
# operation fails.
printf '%-58s' "Test 14: the signing key is readable by ${APP_USER}"
if ! appsh 'test -f /var/www/html/storage/oauth-private.key' >/dev/null 2>&1; then
    echo "SKIP (no key yet)"
elif appsh "gosu $APP_USER test -r /var/www/html/storage/oauth-private.key" >/dev/null 2>&1; then
    echo "PASS"
else
    echo "FAIL"
    FAILURES=$((FAILURES + 1))
fi

# --- Neither key nor env is reachable over HTTP ------------------------------
# The container half of SSO plan 1.8. DocumentRoot is public/, so storage/ is
# outside it -- but a stray Alias, a symlink, or a DocumentRoot typo would
# expose the private key of the company's identity provider to the internet.
# Cheap to check, catastrophic to miss.
i=15
for path in storage/oauth-private.key .env; do
    check "Test ${i}: ${path} is not served over HTTP" \
          "test \"\$(curl -s -o /dev/null -w '%{http_code}' 'http://localhost/${path}')\" -ge 400"
    i=$((i + 1))
done

# --- The pool runs as the account that owns the signing key -------------------
# The live process, not the config file. This is what makes oauth-private.key
# readable by the process that signs tokens; get it wrong and the container is
# healthy, the site serves, and every token operation fails.
#
# Numeric uids, not names: `ps -o user=` truncates past 8 characters.
check "Test 17: the PHP pool runs as ${APP_USER}" \
      "pid=\$(pgrep -f 'php-fpm: pool www' | head -1) && test -n \"\$pid\" \
       && test \"\$(ps -o uid= -p \$pid | tr -d ' ')\" = \"\$(id -u $APP_USER)\""

# --- Errors actually reach somewhere a person can read -----------------------
# A deploy that logs nowhere looks perfectly healthy right up until the first
# 500, and then there is no trace of it anywhere. Under php-fpm the stderr half
# depends on the pool's catch_workers_output, without which FPM discards what
# its children write to stderr entirely. Both halves are configured; this checks
# the file half, and `docker compose logs app` shows the other.
check "Test 18: an error reaches storage/logs" \
      "gosu $APP_USER php /var/www/html/artisan tinker --execute='Log::error(\"idp smoke test\");' \
       && grep -rqs 'idp smoke test' /var/www/html/storage/logs/"

echo ""
echo "================================"
if [ "$FAILURES" -eq 0 ]; then
    echo "All smoke tests passed"
    echo "================================"
    exit 0
fi
echo "${FAILURES} test(s) failed"
echo "Review logs: docker compose -f ${COMPOSE_FILE} logs app"
echo "================================"
exit 1
