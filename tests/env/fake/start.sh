#!/bin/sh
# Fake internet for the test stack: a PHP router on :8080 and nginx on :443 that
# terminates TLS for the tracker host names aliased to this container.
set -e

HOSTS="rutracker.org kinozal.me kinozal.tv kinozal.guru rutor.is nnmclub.to ya.ru"

if [ ! -f /certs/ready ]; then
    cd /certs
    openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=TorrentMonitor test CA" \
        -keyout ca.key -out ca.crt 2>/dev/null
    san=""
    for h in $HOSTS; do san="${san}DNS:$h,DNS:www.$h,"; done
    printf 'subjectAltName=%s\nbasicConstraints=CA:FALSE\n' "${san%,}" > san.cnf
    openssl req -newkey rsa:2048 -nodes -subj "/CN=fake-trackers" -keyout server.key -out server.csr 2>/dev/null
    openssl x509 -req -in server.csr -CA ca.crt -CAkey ca.key -CAcreateserial -days 3650 \
        -extfile san.cnf -out server.crt 2>/dev/null
    chmod 644 ca.crt server.crt server.key
    touch /certs/ready
fi

mkdir -p /run/nginx /tmp/fake
conf=/src/tests/env/fake/nginx.conf
if [ "${TM_FAKE_NO_HTTP:-0}" = 1 ]; then
    # port 80 belongs to the application when all containers share one network
    sed '/listen 80;/d' "$conf" > /tmp/fake/nginx.conf
    conf=/tmp/fake/nginx.conf
fi
nginx -c "$conf"
export PHP_CLI_SERVER_WORKERS=4
exec php -S 0.0.0.0:8080 -t /tmp/fake /src/tests/env/fake/router.php
