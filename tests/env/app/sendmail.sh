#!/bin/sh
# Stands in for sendmail in the test stack: saves each message as a file.
mkdir -p /tmp/tm-mail
chmod 777 /tmp/tm-mail 2>/dev/null || true
cat > "/tmp/tm-mail/$(date +%s)-$$.eml"
