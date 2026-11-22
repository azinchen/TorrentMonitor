#!/bin/sh
# Copies the code under test from the read-only mount into /app, so that tests
# (the updater, config.php, torrents/) never write into the repository.
set -e
mkdir -p /app /data
cd /src
tar cf - --exclude=./.git --exclude=./.claude --exclude=./tests --exclude=./dist --exclude=./wiki \
    --exclude=./config.php --exclude=./data . | tar xf - -C /app
rm -f /app/src/AddedByRelease.php
cat > /app/config.php <<CONFIG
<?php
Config::write('update.api', '${TM_UPDATE_API:-http://fake:8080/github}');
// the test network has no internet: the connectivity check asks the fake instead
Config::write('connectivity.url', 'http://fake:8080/__control/state');
CONFIG
