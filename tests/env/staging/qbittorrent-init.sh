#!/bin/bash
# Staging qBittorrent: a known Web UI login (tm / tm-test-pass, a throwaway value) and
# torrents are added stopped, so that testing does not download anything by itself.
[ -f /config/.tm-staging ] && exit 0
mkdir -p /config/qBittorrent /downloads
cat > /config/qBittorrent/qBittorrent.conf <<'CONF'
[BitTorrent]
Session\DefaultSavePath=/downloads
Session\AddTorrentStopped=true
Session\AddTorrentPaused=true

[LegalNotice]
Accepted=true

[Preferences]
WebUI\Username=tm
WebUI\Password_PBKDF2="@ByteArray(18Eoy2501lFJUjsne2IyYQ==:tlVY+1wNk8PIEjOWF3jpMBsnD+de6ctoP9JVlrPUKojrtrMHq6UrNG390KNwtx6bGqw7tm3wcVs3BC8+MNtVQA==)"
WebUI\HostHeaderValidation=false
WebUI\CSRFProtection=false
WebUI\LocalHostAuth=true
CONF
touch /config/.tm-staging
chown -R abc:abc /config/qBittorrent /downloads 2>/dev/null || true
