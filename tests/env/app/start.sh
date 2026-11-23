#!/bin/sh
set -e
/src/tests/env/app/sync.sh
# trust the certificate authority of the fake trackers
cat /certs/ca.crt >> /etc/ssl/certs/ca-certificates.crt
/src/tests/env/app/reset-data.sh
# e-mail sent with mail() is saved to /tmp/tm-mail instead of being sent
echo 'sendmail_path="/src/tests/env/app/sendmail.sh"' > "$PHP_INI_DIR/conf.d/mail.ini"
export PHP_CLI_SERVER_WORKERS=4
exec php -S 0.0.0.0:80 -t /app/public /app/public/index.php
