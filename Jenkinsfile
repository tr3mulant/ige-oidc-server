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
    environment {
        DOCKER_REGISTRY      = 'registry.irongateenterprises.com'
        DOCKER_REGISTRY_PATH = 'ige-oidc'
        DOCKER_IMAGE_NAME    = 'ige-oidc-server'

        COMPOSE_PROJECT_NAME = 'ige-oidc-ci'

        // Holds production.compose.yaml, .env.production and scripts/. Not a git
        // checkout -- no source is deployed.
        DEPLOY_TARGET = 'deploy@198.199.109.91'
        DEPLOY_PATH   = '/var/www/ige-oidc'

        // APP_USER, not USERNAME: compose reads ${USERNAME} from the shell, and
        // zsh sets it to the operator's login name. This is the account php-fpm
        // runs as and therefore the one that must own oauth-private.key; the
        // deploy script aborts if .env.production disagrees.
        APP_USER = 'ige-oidc'

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
                withCredentials([usernamePassword(
                    credentialsId: 'ige-registry',
                    usernameVariable: 'DOCKER_REGISTRY_USER',
                    passwordVariable: 'DOCKER_REGISTRY_PASS'
                )]) {
                    sh 'bash scripts/ci/jenkins-build-push.sh'
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

                        # The key path is deliberately not in SSH_OPTS: every use
                        # below relies on word-splitting, which would break a path
                        # containing a space -- and Jenkins stages the key under
                        # the job's @tmp directory.
                        SSH_OPTS="-o BatchMode=yes -o ConnectTimeout=15 -o StrictHostKeyChecking=accept-new \
                                  -o ControlMaster=auto -o ControlPath=/tmp/ige-oidc-ssh-%r@%h:%p -o ControlPersist=120"

                        ssh -i "$SSH_KEY" $SSH_OPTS "$DEPLOY_TARGET" \
                            "test -d '$DEPLOY_PATH' && test -w '$DEPLOY_PATH'" || {
                            echo "Deploy directory missing or not writable by the deploy user."
                            echo "  $DEPLOY_TARGET:$DEPLOY_PATH"
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
                        # may contain a single quote.
                        ssh -i "$SSH_KEY" $SSH_OPTS "$DEPLOY_TARGET" \
                            "DEPLOY_DIR='$DEPLOY_PATH' \
                             DOCKER_REGISTRY='$DOCKER_REGISTRY' \
                             DOCKER_REGISTRY_PATH='$DOCKER_REGISTRY_PATH' \
                             DOCKER_IMAGE_NAME='$DOCKER_IMAGE_NAME' \
                             DOCKER_TAG='$DOCKER_TAG' \
                             APP_USER='$APP_USER' \
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

            sh 'docker logout $DOCKER_REGISTRY || true'
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
