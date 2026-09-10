#!/usr/bin/env bash
#
#############################################
# Jenkins - read one value out of an env-file credential
# Runs on the Jenkins agent
#############################################
#
#   bash scripts/ci/envval.sh KEY /path/to/env-file
#
# Why this exists. The deployment coordinates -- which box, which directory,
# which registry, which account -- are CONFIGURATION, not code, and this
# repository is public. They live in the 'ige-oidc-server.env.production' Secret
# file, so moving the deployment is an edit to that credential rather than a
# commit. This is what the Jenkinsfile reads them with, inside the stage where
# withCredentials has staged the file on the agent.
#
# grep, not source. Sourcing an env file executes a stray backtick or $(...)
# sitting in an unrelated secret, in this shell. The same helper exists inside
# jenkins-build-test-containers.sh and jenkins-deploy.sh; those keep their own
# copies on purpose, because jenkins-deploy.sh is scp'd to the server alone and
# this file is not beside it there.
#
# Nothing here defaults. A deployment coordinate that quietly falls back to a
# value is how a pipeline deploys the wrong box while reporting success -- the
# failure mode this whole arrangement exists to remove.
#
# Note for anyone adding a caller: values read out of a Secret FILE are not
# masked in the build log. Jenkins masks bound secret-text and password values;
# a file binding is a path, and its contents are plain text to everything
# downstream. Do not echo what this prints, and do not add `set -x` to a block
# that calls it.

set -euo pipefail

KEY="${1:-}"
FILE="${2:-}"

if [ -z "$KEY" ] || [ -z "$FILE" ]; then
    echo "usage: $0 KEY ENV_FILE" >&2
    exit 1
fi

[ -f "$FILE" ] || {
    echo "ERROR: env file '$FILE' does not exist." >&2
    echo "  This reads a Jenkins Secret file, which only exists inside the" >&2
    echo "  withCredentials block that binds it." >&2
    exit 1
}

# -m1: the first assignment wins, which is how docker compose and Laravel both
# read these files. grep gives one line, so a value can never contain a newline.
RAW="$(grep -m1 -E "^${KEY}=" "$FILE" | cut -d= -f2-)" || true

[ -n "$RAW" ] || {
    echo "ERROR: $KEY is not set in the env file this stage was given." >&2
    echo "  It is a deployment coordinate and nothing defaults it. Add $KEY to" >&2
    echo "  the matching 'ige-oidc-server.env.*' Secret file in Jenkins." >&2
    exit 1
}

# Strip ONE matched pair of surrounding quotes -- the env-file convention -- and
# nothing else.
#
# Deliberately not `tr -d "\"'"`, which is what the in-script copies of this
# helper use. Deleting every quote anywhere in the value would also delete an
# EMBEDDED one, which both corrupts the value silently and defuses the check
# below by destroying the evidence: `foo'; rm -rf /; echo '` would come back out
# as inert text and be reported as a valid deployment coordinate.
case "$RAW" in
    \"*\") VALUE="${RAW#\"}"; VALUE="${VALUE%\"}" ;;
    \'*\') VALUE="${RAW#\'}"; VALUE="${VALUE%\'}" ;;
    *)     VALUE="$RAW" ;;
esac

# The Deploy stage interpolates these into a single-quoted command for the
# REMOTE shell. A quote left in the value would close that quoting and hand the
# remainder to the server as code -- the injection the Jenkins pipeline
# documentation warns about, one hop further out. Rejected here rather than
# discovered there.
case "$VALUE" in
    *"'"* | *'"'*)
        echo "ERROR: $KEY contains a quote character." >&2
        echo "  It is passed to the server inside a single-quoted remote command," >&2
        echo "  which that quote would terminate." >&2
        exit 1
        ;;
esac

# A value starting with '-' is read as an OPTION, not an argument. DEPLOY_TARGET
# becomes ssh's host argument, and a target of '-oProxyCommand=...' would run
# that command on the AGENT before any connection is attempted.
case "$VALUE" in
    -*)
        echo "ERROR: $KEY starts with '-'." >&2
        echo "  ssh and scp would parse it as an option rather than a host or" >&2
        echo "  path. Nothing legitimate here begins with a dash." >&2
        exit 1
        ;;
esac

printf '%s\n' "$VALUE"
