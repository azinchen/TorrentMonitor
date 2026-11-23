#!/usr/bin/env bash
# Local test runner. Everything runs in Docker on this machine; nothing is sent
# to GitHub and no CI is involved. See tests/README.md.
#
#   tests/run.sh lint          syntax check of every PHP file on PHP 8.0 and 8.5
#   tests/run.sh unit          unit tests on PHP 8.0 and 8.5
#   tests/run.sh integration   integration tests against the local stack (no internet needed)
#   tests/run.sh all           lint + unit + integration: must pass before a merge
#   tests/run.sh live          tests against real trackers (needs tests/env/secrets/trackers.env)
#   tests/run.sh staging up|down|reset|restart|logs   long-running copy for testing by hand, through the VPN
#   tests/run.sh reach         which trackers answer from here / through the VPN
#   tests/run.sh record <tracker> <path> <name>   save a scrubbed tracker page as a fixture
#   tests/run.sh up            start the local stack and leave it running (http://127.0.0.1:8088)
#   tests/run.sh down          remove the stack and its data
#   tests/run.sh sonarr        the whole Sonarr flow against a real Sonarr, once per torrent client
#   tests/run.sh screenshots   retake the interface screenshots in wiki/images (made-up data)
#   tests/run.sh hooks         install the pre-push hook that blocks pushing secrets
#
# Environment: TM_PHP=8.0|8.5 (PHP for the stack, default 8.5), TM_PORT (default 8088),
#              TM_SOLVER (live: solver address, default Byparr), TM_VPN_COUNTRY (default Portugal),
#              TM_VPN=0 (live, reach: do not use the VPN even if its secrets exist),
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

# the VPN is used when its secrets exist, unless TM_VPN=0
use_vpn() { [[ "${TM_VPN:-1}" == 1 && -f "$env_dir/secrets/vpn.env" ]]; }

live() {
    local files=(-f "$env_dir/compose.yml" -f "$env_dir/compose.live.yml")
    use_vpn && files+=(-f "$env_dir/compose.vpn.yml")
    docker compose --project-directory "$env_dir" "${files[@]}" "$@"
}

# starts services of the live stack; shows what went wrong if they do not come up
live_up() {
    # the VPN only becomes healthy after the MTU keeper has lowered the tunnel MTU
    use_vpn && live up -d --quiet-pull vpn mtu > /dev/null 2>&1
    local log
    if ! log=$(live up -d --wait --quiet-pull "$@" 2>&1); then
        echo "$log" | tail -15
        echo "the live stack did not start$(use_vpn && echo ' (TM_VPN=0 runs it without the VPN)')"
        live down -v --remove-orphans > /dev/null 2>&1 || true
        exit 1
    fi
}

remove_stack() {
    offline down -v --remove-orphans > /dev/null 2>&1 || true
    docker compose --project-directory "$env_dir" -f "$env_dir/compose.yml" -f "$env_dir/compose.offline.yml" -f "$env_dir/compose.sonarr.yml" down -v --remove-orphans > /dev/null 2>&1 || true
}

phpunit_in_app() { "$1" exec -T -e TM_ROOT=/app -e TM_SONARR="${TM_SONARR:-0}" -e TM_LIVE_WRONG_PASSWORD="${TM_LIVE_WRONG_PASSWORD:-0}" -e TM_LIVE_AUTO="${TM_LIVE_AUTO:-0}" -e TM_LIVE_GUEST="${TM_LIVE_GUEST:-0}" app phpunit -c /src/tests/phpunit.xml --testsuite "$2" "${@:3}"; }

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
    say "live tests, PHP $TM_PHP$(use_vpn && echo ', through the VPN' || echo ', without VPN')"
    remove_stack
    live_up fake app byparr flaresolverr qbittorrent
    local status=0
    phpunit_in_app live live "$@" || status=$?
    if [[ "${TM_KEEP:-0}" == 1 ]]; then
        echo "live stack left running (TM_KEEP=1): tests/run.sh down removes it"
    else
        live down -v --remove-orphans > /dev/null 2>&1 || true
    fi
    return $status
}

# The offline stack plus a real Sonarr. The tracker and the indexer are fake; Sonarr itself
# needs the internet to load the description of a series.
sonarr_stack() { docker compose --project-directory "$env_dir" -f "$env_dir/compose.yml" -f "$env_dir/compose.offline.yml" -f "$env_dir/compose.sonarr.yml" "$@"; }

cmd_sonarr() {
    build_images
    say "Sonarr flow, PHP $TM_PHP"
    remove_stack
    sonarr_stack down -v --remove-orphans > /dev/null 2>&1 || true
    local log
    if ! log=$(sonarr_stack up -d --wait --quiet-pull fake app qbittorrent transmission deluge sonarr 2>&1); then
        echo "$log" | tail -15
        echo "the Sonarr stack did not start"
        sonarr_stack down -v --remove-orphans > /dev/null 2>&1 || true
        exit 1
    fi
    local status=0
    TM_SONARR=1 phpunit_in_app sonarr_stack sonarr "$@" || status=$?
    if [[ "${TM_KEEP:-0}" == 1 || $status -ne 0 ]]; then
        echo "stack left running: tests/run.sh down removes it"
    else
        sonarr_stack down -v --remove-orphans > /dev/null 2>&1 || true
    fi
    return $status
}

cmd_reach() {
    build_images
    say "tracker reachability$(use_vpn && echo ', through the VPN' || echo ', without VPN')"
    remove_stack
    local services=(fake app)
    [[ "${TM_REACH_SOLVER:-1}" == 1 ]] && services+=(byparr)
    live_up "${services[@]}"
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

# a long-running copy for testing by hand, through the VPN: see tests/env/staging/compose.yml
cmd_staging() {
    local file="$env_dir/staging/compose.yml"
    [[ "${2:-}" == "--no-vpn" || -f "$env_dir/staging/.no-vpn" ]] && file="$env_dir/staging/compose.novpn.yml"
    local compose=(docker compose -f "$file")
    case "${1:-}" in
        up)
            build_images
            if [[ "${2:-}" == "--no-vpn" ]]; then
                touch "$env_dir/staging/.no-vpn"
                "${compose[@]}" up -d
            else
                rm -f "$env_dir/staging/.no-vpn"
                [[ -f "$env_dir/secrets/vpn.env" ]] || { echo "tests/env/secrets/vpn.env is missing (see vpn.env.example)"; exit 2; }
                # the VPN only becomes healthy after the MTU keeper has lowered the tunnel MTU
                "${compose[@]}" up -d vpn mtu
                "${compose[@]}" up -d --wait
            fi
            echo "TorrentMonitor: http://<this host>:${TM_STAGING_PORT:-8090}   qBittorrent: http://<this host>:${TM_STAGING_QBT_PORT:-8091} (tm / tm-test-pass)"
            ;;
        down)    "${compose[@]}" down ;;
        reset)   "${compose[@]}" down -v ;;
        restart) "${compose[@]}" restart app ;;
        logs)    "${compose[@]}" logs --tail 50 "${@:2}" ;;
        *)       echo "usage: tests/run.sh staging up [--no-vpn] | down | reset | restart | logs [service]"; exit 2 ;;
    esac
}

# Retakes wiki/images/*.png from the current code: the stack is started, filled with made-up
# data and photographed with headless Chrome. Run before a release: the readme shows these files.
cmd_screenshots() {
    build_images
    remove_stack
    offline up -d --wait --quiet-pull fake app qbittorrent > /dev/null 2>&1 || { echo "the stack did not start"; exit 1; }
    offline exec -T app php /src/tests/tools/demo-data.php
    local out="$root/wiki/images" port="${TM_PORT:-8088}" status=0
    shot() {  # name, page, width,height
        rm -f "$out/$1.png"
        timeout 90 docker run --rm --network host --user "$(id -u):$(id -g)" -v "$out":/out zenika/alpine-chrome:latest \
            --no-sandbox --headless --disable-gpu --hide-scrollbars --force-device-scale-factor=1 \
            --virtual-time-budget=6000 --window-size="$3" --screenshot="/out/$1.png" "http://127.0.0.1:$port/#$2" > /dev/null 2>&1
        [[ -s "$out/$1.png" ]] && echo "saved wiki/images/$1.png" || { echo "FAILED wiki/images/$1.png"; status=1; }
    }
    shot topics topics 1280,620
    shot trackers trackers 1280,880
    shot status status 1280,1140
    remove_stack
    return $status
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
    sonarr)      cmd_sonarr "$@" ;;
    record)      cmd_record "$@" ;;
    up)          build_images; remove_stack; offline up -d --wait; echo "application: http://127.0.0.1:${TM_PORT:-8088}" ;;
    down)        remove_stack; echo "stack removed" ;;
    staging)     cmd_staging "$@" ;;
    screenshots) cmd_screenshots ;;
    hooks)       cmd_hooks ;;
    *)           sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 2 ;;
esac
