#!/bin/bash
# Runs inside the linuxserver/qbittorrent container before qBittorrent starts:
# sets a known Web UI login so tests can sign in. The password is a throwaway
# test value ("tm-test-pass"), not a secret.
mkdir -p /config/qBittorrent /downloads/qbt
cat > /config/qBittorrent/qBittorrent.conf <<'CONF'
[BitTorrent]
Session\DefaultSavePath=/downloads/qbt
Session\DHTEnabled=false
Session\PeXEnabled=false
Session\LSDEnabled=false

[LegalNotice]
Accepted=true

[Preferences]
WebUI\Username=tm
WebUI\Password_PBKDF2="@ByteArray(18Eoy2501lFJUjsne2IyYQ==:tlVY+1wNk8PIEjOWF3jpMBsnD+de6ctoP9JVlrPUKojrtrMHq6UrNG390KNwtx6bGqw7tm3wcVs3BC8+MNtVQA==)"
WebUI\HostHeaderValidation=false
WebUI\CSRFProtection=false
WebUI\LocalHostAuth=true
CONF
chown -R abc:abc /config/qBittorrent /downloads/qbt 2>/dev/null || true
chmod 777 /downloads /downloads/qbt
