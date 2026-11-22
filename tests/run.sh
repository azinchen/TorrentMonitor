#!/usr/bin/env bash
# Local test runner. Everything runs in Docker on this machine; nothing is sent
# to GitHub and no CI is involved. See tests/README.md.
#
#   tests/run.sh lint          syntax check of every PHP file on PHP 8.0 and 8.5
#   tests/run.sh unit          unit tests on PHP 8.0 and 8.5
#   tests/run.sh integration   integration tests against the local stack (no internet needed)
#   tests/run.sh all           lint + unit + integration: must pass before a merge
#   tests/run.sh live          tests against real trackers (needs tests/env/secrets/trackers.env)
#   tests/run.sh reach         which trackers answer from here / through the VPN
#   tests/run.sh record <tracker> <path> <name>   save a scrubbed tracker page as a fixture
#   tests/run.sh up            start the local stack and leave it running (http://127.0.0.1:8088)
#   tests/run.sh down          remove the stack and its data
#   tests/run.sh hooks         install the pre-push hook that blocks pushing secrets
#
# Environment: TM_PHP=8.0|8.5 (PHP for the stack, default 8.5), TM_PORT (default 8088),
#              TM_SOLVER (live: solver address, default Byparr), TM_VPN_COUNTRY (default Portugal),
#              TM_KEEP=1 (live: leave the stack running afterwards, e.g. for "record").
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
env_dir="$root/tests/env"
versions=(8.0 8.5)
export TM_PHP="${TM_PHP:-8.5}"

say() { printf '\n== %s\n' "$*"; }

build_images() {
    for v in "${versions[@]}"; do
        if [[ "${TM_REBUILD:-0}" == 1 ]] || ! docker image inspect "tm-tests:php$v" > /dev/null 2>&1; then
            say "building test image for PHP $v"
            docker build -q --build-arg "PHP_VERSION=$v" -t "tm-tests:php$v" "$root/tests/docker" > /dev/null
        fi
    done
}

offline() { docker compose --project-directory "$env_dir" -f "$env_dir/compose.yml" -f "$env_dir/compose.offline.yml" "$@"; }

live() {
    local files=(-f "$env_dir/compose.yml" -f "$env_dir/compose.live.yml")
    [[ -f "$env_dir/secrets/vpn.env" ]] && files+=(-f "$env_dir/compose.vpn.yml")
    docker compose --project-directory "$env_dir" "${files[@]}" "$@"
}

remove_stack() { offline down -v --remove-orphans > /dev/null 2>&1 || true; }

phpunit_in_app() { "$1" exec -T -e TM_ROOT=/app app phpunit -c /src/tests/phpunit.xml --testsuite "$2" "${@:3}"; }

cmd_lint() {
    build_images
    for v in "${versions[@]}"; do
        say "lint, PHP $v"
        docker run --rm -v "$root":/src:ro "tm-tests:php$v" sh -c '
            fail=0
            for f in $(find /src -name "*.php" -not -path "/src/.git/*" -not -path "/src/dist/*"); do
                php -l "$f" > /tmp/lint.out 2>&1 || { cat /tmp/lint.out; fail=1; }
            done
            [ $fail -eq 0 ] && echo "no syntax errors"
            exit $fail'
    done
}

cmd_unit() {
    build_images
    for v in "${versions[@]}"; do
        say "unit tests, PHP $v"
        docker run --rm --user 1000:1000 -v "$root":/src:ro "tm-tests:php$v" phpunit -c /src/tests/phpunit.xml --testsuite unit "$@"
    done
}

cmd_integration() {
    build_images
    say "integration tests, PHP $TM_PHP"
    # always a fresh stack: containers keep DNS and database state between runs
    remove_stack
    offline up -d --wait --quiet-pull > /dev/null 2>&1
    local status=0
    phpunit_in_app offline integration "$@" || status=$?
    if [[ $status -ne 0 ]]; then
        echo "stack left running for inspection: tests/run.sh down removes it"
        return $status
    fi
    remove_stack
}

cmd_live() {
    build_images
    [[ -f "$env_dir/secrets/trackers.env" ]] || { echo "tests/env/secrets/trackers.env is missing: copy trackers.env.example and fill it in"; exit 1; }
    say "live tests, PHP $TM_PHP$([[ -f "$env_dir/secrets/vpn.env" ]] && echo ', through the VPN' || echo ', without VPN')"
    remove_stack
    live up -d --wait --quiet-pull fake app byparr flaresolverr > /dev/null 2>&1
    local status=0
    phpunit_in_app live live "$@" || status=$?
    if [[ "${TM_KEEP:-0}" == 1 ]]; then
        echo "live stack left running (TM_KEEP=1): tests/run.sh down removes it"
    else
        live down -v --remove-orphans > /dev/null 2>&1 || true
    fi
    return $status
}

cmd_reach() {
    build_images
    say "tracker reachability$([[ -f "$env_dir/secrets/vpn.env" ]] && echo ', through the VPN' || echo ', without VPN')"
    remove_stack
    local services=(fake app)
    [[ "${TM_REACH_SOLVER:-1}" == 1 ]] && services+=(byparr)
    live up -d --wait --quiet-pull "${services[@]}" > /dev/null 2>&1
    local status=0
    live exec -T app php /src/tests/tools/reach.php || status=$?
    live down -v --remove-orphans > /dev/null 2>&1 || true
    return $status
}

cmd_record() {
    [[ $# -eq 3 ]] || { echo "usage: tests/run.sh record <tracker> <path> <name>"; exit 2; }
    local out="$root/tests/Fixtures/$1/$3.html"
    mkdir -p "$(dirname "$out")"
    # needs a running live stack in which the tracker session already exists (run the live tests with TM_KEEP=1 first)
    live exec -T app php /src/tests/tools/record.php "$1" "$2" > "$out.tmp" && mv "$out.tmp" "$out" && echo "saved $out"
}

cmd_hooks() {
    install -m 755 "$root/tests/hooks/pre-push" "$(git -C "$root" rev-parse --git-path hooks)/pre-push"
    echo "pre-push hook installed"
}

command="${1:-}"; shift || true
case "$command" in
    lint)        cmd_lint ;;
    unit)        cmd_unit "$@" ;;
    integration) cmd_integration "$@" ;;
    all)         cmd_lint; cmd_unit; cmd_integration; say "all passed" ;;
    live)        cmd_live "$@" ;;
    reach)       cmd_reach ;;
    record)      cmd_record "$@" ;;
    up)          build_images; remove_stack; offline up -d --wait; echo "application: http://127.0.0.1:${TM_PORT:-8088}" ;;
    down)        remove_stack; echo "stack removed" ;;
    hooks)       cmd_hooks ;;
    *)           sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 2 ;;
esac
