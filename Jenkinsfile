#!/usr/bin/env groovy

// CI/CD for the identity provider (auth.irongateenterprises.com).
//
//   scripts/ci/jenkins-build-test-containers.sh   build + start test containers
//   scripts/ci/jenkins-run-tests.sh               run the suite inside them
//   scripts/ci/jenkins-build-push.sh              build + push the production image
//   scripts/ci/jenkins-deploy.sh                  deploy on IronGate01 over ssh
//
// Tests run inside containers, not against the agent's PHP. The deploy pulls the
// tag CI built; it never builds on the server.
//
// .env.testing and .env.production are not committed, so both arrive as Jenkins
// "Secret file" credentials.
pipeline {
    agent { label 'dind' }
    options {
        timestamps()
        timeout(time: 75, unit: 'MINUTES')
        buildDiscarder(logRotator(numToKeepStr: '30'))
        // COMPOSE_PROJECT_NAME is fixed, so concurrent builds would tear down
        // each other's containers and fight over the published ports.
        disableConcurrentBuilds()
    }
    // What is deliberately NOT in this block: DEPLOY_TARGET, DEPLOY_PATH,
    // APP_USER and DOCKER_REGISTRY. They name the box this deploys to, the
    // directory it deploys into, the account php-fpm runs as and the registry it
    // pulls from -- deployment CONFIGURATION, and this repository is public.
    // They live in the 'ige-oidc-server.env.production' Secret file, so moving
    // the deployment is an edit to that credential rather than a commit.
    //
    // A credentials() binding for that file would be legal here, and would reach
    // every stage and the post blocks. It is bound per-stage instead, so the
    // production secrets in it -- APP_KEY, DB_PASSWORD, the OIDC client secrets
    // -- are on the agent's disk only during the two stages that need them, and
    // not through checkout and the test run.
    //
    // What cannot be done here either way is READING a value out of that file:
    // environment{} is evaluated before any step can run, so there is no sh to
    // grep with. Each stage reads what it needs with scripts/ci/envval.sh, which
    // fails loudly on a missing key instead of defaulting to a box.
    //
    // What is left below is the image's own naming -- which is this public
    // repository's own name -- and the tag, which is the commit under test.
    environment {
        DOCKER_REGISTRY_PATH = 'ige-oidc'
        DOCKER_IMAGE_NAME    = 'ige-oidc-server'

        COMPOSE_PROJECT_NAME = 'ige-oidc-ci'

        // substring, not take(7): StringGroovyMethods.take is not in
        // script-security's whitelist and halts the script before any stage runs.
        // No fallback on purpose -- an empty GIT_COMMIT should fail, not deploy
        // a tag called 'unknown'.
        DOCKER_TAG = "${env.GIT_COMMIT.substring(0, 7)}"
    }
    stages {
        stage('Pre-build') {
            steps {
                sh '''
                    set -eu

                    command -v docker >/dev/null || {
                        echo "This agent has no docker client."
                        echo "Rebuild the node from github.com/tr3mulant/jenkins-dind-agent."
                        exit 1
                    }

                    # A client on PATH proves nothing; every stage needs a daemon.
                    docker info >/dev/null 2>&1 || {
                        echo "Docker client present, but no reachable daemon."
                        echo "  DOCKER_HOST=${DOCKER_HOST:-unset}"
                        echo ""
                        echo "Fix on the Jenkins node. The agent is a CLIENT; the daemon is the"
                        echo "'dind' service beside it. Expect DOCKER_HOST=tcp://docker:2376,"
                        echo "DOCKER_TLS_VERIFY=1, DOCKER_CERT_PATH=/certs/client."
                        echo ""
                        echo "tcp://docker, not tcp://dind: dind's cert covers the container id,"
                        echo "'docker' and 'localhost' only. An error ending in"
                        echo "  x509: certificate is valid for ..., not dind"
                        echo "means dind is healthy and the stack is missing the 'docker' alias."
                        echo ""
                        echo "Do NOT mount the host socket instead. That gives every job on this"
                        echo "node root on the Jenkins host and every credential in it."
                        echo ""
                        docker version || true
                        exit 1
                    }

                    # The test containers bind-mount this workspace. Compose
                    # resolves the path here, dockerd resolves it inside dind, and
                    # they only agree under the volume both share. Outside it,
                    # dockerd silently mounts an empty root-owned directory.
                    if [ -n "${DOCKER_HOST:-}" ]; then
                        case "${WORKSPACE:-}" in
                            /home/jenkins/agent/*) ;;
                            *)
                                echo "Workspace is outside the volume dind shares with this agent."
                                echo "  WORKSPACE=${WORKSPACE:-unset}"
                                echo "Set the node's remote root directory to /home/jenkins/agent."
                                exit 1
                                ;;
                        esac
                    fi

                    docker version
                    docker compose version
                '''
            }
        }
        stage('Build test containers') {
            steps {
                withCredentials([file(credentialsId: 'ige-oidc-server.env.testing', variable: 'ENV_TEST_FILE')]) {
                    sh 'bash scripts/ci/jenkins-build-test-containers.sh'
                }
            }
        }
        stage('Test') {
            steps {
                sh 'bash scripts/ci/jenkins-run-tests.sh'
            }
        }
        stage('Build and push image') {
            when {
                expression {
                    def branch = env.BRANCH_NAME ?: env.GIT_BRANCH ?: ''
                    return branch in ['main', 'origin/main', 'refs/remotes/origin/main']
                }
            }
            environment {
                // uid/gid of the app account inside the production image. Fixed,
                // and scoped to this stage rather than the pipeline: storage is a
                // named volume so nothing outside the container has to match, but
                // the CI containers bind-mount the workspace and need the agent's
                // real id instead.
                WWWUSER  = '1000'
                WWWGROUP = '1000'
            }
            options {
                // A cold agent compiles the whole PHP layer with no cache.
                timeout(time: 30, unit: 'MINUTES')
            }
            steps {
                withCredentials([
                    file(credentialsId: 'ige-oidc-server.env.production', variable: 'ENV_PROD'),
                    usernamePassword(
                        credentialsId: 'ige-registry',
                        usernameVariable: 'DOCKER_REGISTRY_USER',
                        passwordVariable: 'DOCKER_REGISTRY_PASS'
                    )
                ]) {
                    // Single-quoted, so the shell expands these and Groovy never
                    // does. A double-quoted step would interpolate the values on
                    // the controller and copy them into the agent's process list.
                    sh '''
                        set -eu

                        # APP_USER is read here and nowhere else in this stage:
                        # it becomes the image's --build-arg USERNAME, and the
                        # deploy reads the SAME key from the SAME file on the
                        # server. An image built as one account while the volume
                        # is owned by another is a healthy container that cannot
                        # read oauth-private.key and therefore cannot sign a
                        # token -- with no error anywhere.
                        DOCKER_REGISTRY="$(bash scripts/ci/envval.sh DOCKER_REGISTRY "$ENV_PROD")"
                        APP_USER="$(bash scripts/ci/envval.sh APP_USER "$ENV_PROD")"
                        export DOCKER_REGISTRY APP_USER

                        bash scripts/ci/jenkins-build-push.sh
                    '''
                }
            }
        }
        stage('Deploy') {
            // Not `when { branch 'main' }`. That reads BRANCH_NAME, which only
            // Multibranch jobs set -- here it is null, so the gate would never
            // match and the deploy would silently never run on a green build.
            when {
                expression {
                    def branch = env.BRANCH_NAME ?: env.GIT_BRANCH ?: ''
                    echo "Deploy gate: BRANCH_NAME=${env.BRANCH_NAME}, GIT_BRANCH=${env.GIT_BRANCH}"
                    return branch in ['main', 'origin/main', 'refs/remotes/origin/main']
                }
            }
            options {
                timeout(time: 20, unit: 'MINUTES')
            }
            steps {
                withCredentials([
                    file(credentialsId: 'ige-oidc-server.env.production', variable: 'ENV_PROD'),
                    sshUserPrivateKey(credentialsId: 'deploy-ssh-key', keyFileVariable: 'SSH_KEY'),
                    usernamePassword(
                        credentialsId: 'ige-registry',
                        usernameVariable: 'DOCKER_REGISTRY_USER',
                        passwordVariable: 'DOCKER_REGISTRY_PASS'
                    )
                ]) {
                    sh '''
                        set -eu

                        for tool in ssh scp; do
                            command -v "$tool" >/dev/null || {
                                echo "This agent has no $tool. Install openssh-client on the node."
                                exit 1
                            }
                        done

                        # The deployment coordinates, out of the credential rather
                        # than out of this file. envval.sh exits non-zero on a
                        # missing key, and `set -e` turns that into a failed
                        # stage -- deliberately, so a half-configured credential
                        # stops here instead of resolving to somebody's default.
                        #
                        # Not echoed anywhere below, and do not add `set -x`:
                        # values read out of a Secret FILE are not masked in the
                        # build log the way a secret-text binding is.
                        DEPLOY_TARGET="$(bash scripts/ci/envval.sh DEPLOY_TARGET "$ENV_PROD")"
                        DEPLOY_PATH="$(bash scripts/ci/envval.sh DEPLOY_PATH "$ENV_PROD")"
                        DOCKER_REGISTRY="$(bash scripts/ci/envval.sh DOCKER_REGISTRY "$ENV_PROD")"

                        # The key path is deliberately not in SSH_OPTS: every use
                        # below relies on word-splitting, which would break a path
                        # containing a space -- and Jenkins stages the key under
                        # the job's @tmp directory.
                        SSH_OPTS="-o BatchMode=yes -o ConnectTimeout=15 -o StrictHostKeyChecking=accept-new \
                                  -o ControlMaster=auto -o ControlPath=/tmp/ige-oidc-ssh-%r@%h:%p -o ControlPersist=120"

                        ssh -i "$SSH_KEY" $SSH_OPTS "$DEPLOY_TARGET" \
                            "test -d '$DEPLOY_PATH' && test -w '$DEPLOY_PATH'" || {
                            echo "Deploy directory missing or not writable by the deploy user."
                            echo "  $DEPLOY_PATH, on the host named by DEPLOY_TARGET in the"
                            echo "  'ige-oidc-server.env.production' credential."
                            echo ""
                            echo "Provision it once, as root:"
                            echo "      mkdir -p $DEPLOY_PATH"
                            echo "      chown deploy:deploy $DEPLOY_PATH"
                            echo "      chmod 750 $DEPLOY_PATH"
                            exit 1
                        }

                        ssh -i "$SSH_KEY" $SSH_OPTS "$DEPLOY_TARGET" \
                            "mkdir -p '$DEPLOY_PATH/scripts' '$DEPLOY_PATH/apache' && rm -f '$DEPLOY_PATH/.env.incoming'"

                        # The env lands under a temporary name; the deploy script
                        # installs it only once the image is confirmed pullable, so
                        # a failed build never leaves new config in front of the old
                        # image. Not via /tmp, which is world readable.
                        scp -i "$SSH_KEY" $SSH_OPTS "$ENV_PROD" "$DEPLOY_TARGET:$DEPLOY_PATH/.env.incoming"
                        scp -i "$SSH_KEY" $SSH_OPTS production.compose.yaml "$DEPLOY_TARGET:$DEPLOY_PATH/production.compose.yaml"
                        scp -i "$SSH_KEY" $SSH_OPTS scripts/ci/jenkins-deploy.sh "$DEPLOY_TARGET:$DEPLOY_PATH/scripts/deploy.sh"
                        scp -i "$SSH_KEY" $SSH_OPTS scripts/ci/smoke-test.sh "$DEPLOY_TARGET:$DEPLOY_PATH/scripts/smoke-test.sh"

                        # The host vhosts are SHIPPED, NOT APPLIED. Applying means
                        # writing /etc/apache2 and reloading, which needs root --
                        # and this box also serves the registry this pipeline pulls
                        # from, so a job that could write a bad vhost and reload
                        # could take down the registry and remove its own ability to
                        # deploy the fix. A human applies them with sudo.
                        scp -i "$SSH_KEY" $SSH_OPTS deploy/apache/auth_irongateenterprises_com.conf        "$DEPLOY_TARGET:$DEPLOY_PATH/apache/"
                        scp -i "$SSH_KEY" $SSH_OPTS deploy/apache/auth_irongateenterprises_com-le-ssl.conf "$DEPLOY_TARGET:$DEPLOY_PATH/apache/"

                        # Passed as environment, not interpolated into the remote
                        # command line, which is visible in the remote process list.
                        # Single-quoted for the remote shell, so none of these values
                        # may contain a single quote -- envval.sh rejects one in the
                        # values it reads, for exactly this reason.
                        #
                        # APP_USER is NOT passed. deploy.sh reads it from the
                        # .env.production it just installed, which is the same file
                        # this stage read the coordinates from and the same one the
                        # image was built against. One copy, so there is nothing
                        # left to drift.
                        ssh -i "$SSH_KEY" $SSH_OPTS "$DEPLOY_TARGET" \
                            "DEPLOY_DIR='$DEPLOY_PATH' \
                             DOCKER_REGISTRY='$DOCKER_REGISTRY' \
                             DOCKER_REGISTRY_PATH='$DOCKER_REGISTRY_PATH' \
                             DOCKER_IMAGE_NAME='$DOCKER_IMAGE_NAME' \
                             DOCKER_TAG='$DOCKER_TAG' \
                             DOCKER_REGISTRY_USER='$DOCKER_REGISTRY_USER' \
                             DOCKER_REGISTRY_PASS='$DOCKER_REGISTRY_PASS' \
                             bash $DEPLOY_PATH/scripts/deploy.sh"
                    '''
                }
            }
        }
    }
    post {
        always {
            // allowEmptyResults so a failure before the test stage reports as
            // itself, not as "no test results found".
            junit allowEmptyResults: true, testResults: 'tests/junit.xml'

            // `docker logout` used to be here. It needs DOCKER_REGISTRY, which now
            // comes out of a credential bound inside a stage, and a post block
            // cannot see one. It moved to an EXIT trap in jenkins-build-push.sh --
            // the only thing that ever logs THIS AGENT in (the login in
            // jenkins-deploy.sh runs on the server, not here). Beside the login it
            // undoes, it also covers the paths where the push fails.
        }
        failure {
            // Backstop for a teardown that could not run. -v is correct here and
            // nowhere near the deploy: this is the ephemeral CI database.
            sh 'docker compose --env-file .env.testing down -v --remove-orphans 2>/dev/null || true'
        }
        cleanup {
            cleanWs()
        }
    }
}
