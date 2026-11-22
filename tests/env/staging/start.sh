#!/bin/sh
# Staging application container: installs the code from the working tree, serves it the way
# a web server would (web root = public/), and runs the check every hour like cron.
set -e
mkdir -p /app /data
cd /src
tar cf - --exclude=./.git --exclude=./.claude --exclude=./tests --exclude=./dist --exclude=./wiki \
    --exclude=./config.php --exclude=./data --exclude=./torrents . | tar xf - -C /app
(
    while sleep 3600; do
        php /app/engine.php > /dev/null 2>&1 || true
    done
) &
export PHP_CLI_SERVER_WORKERS=4
exec php -S 0.0.0.0:80 -t /app/public /app/public/index.php
