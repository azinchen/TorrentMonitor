#!/bin/sh
# Copies the code under test from the read-only mount into /app, so that tests
# (the updater, config.php, torrents/) never write into the repository.
set -e
mkdir -p /app /data
cd /src
tar cf - --exclude=./.git --exclude=./.claude --exclude=./tests --exclude=./dist --exclude=./wiki \
    --exclude=./config.php --exclude=./data . | tar xf - -C /app
rm -f /app/class/AddedByRelease.php
cat > /app/config.php <<CONFIG
<?php
include_once dirname(__FILE__).'/class/Config.class.php';
Config::write('update.api', '${TM_UPDATE_API:-http://fake:8080/github}');
CONFIG
