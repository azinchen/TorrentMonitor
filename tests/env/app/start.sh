#!/bin/sh
set -e
/src/tests/env/app/sync.sh
# trust the certificate authority of the fake trackers
cat /certs/ca.crt >> /etc/ssl/certs/ca-certificates.crt
php /src/tests/env/app/reset-db.php
export PHP_CLI_SERVER_WORKERS=4
exec php -S 0.0.0.0:80 -t /app
