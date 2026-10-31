#!/usr/bin/env bash
source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
my_dir=$HOME/BirdNET-Pi/scripts
set -x
# CADDYFILE_OUT set (owner 2026-10-23, least privilege): only write the configuration to that file, as the station user,
# for birdsuite-root caddyfile to check and install; unset: the installer / updater (root) writes and reloads it itself
OUT=${CADDYFILE_OUT:-/etc/caddy/Caddyfile}
if [ -z "${CADDYFILE_OUT:-}" ]; then
  [ -d /etc/caddy ] || mkdir /etc/caddy
  if [ -f /etc/caddy/Caddyfile ];then
    cp /etc/caddy/Caddyfile{,.original}
  fi
fi
# Written into both variants (2026-10-23 test run; update_birdnet_snippets.sh writes the file again when it lacks them):
# - security headers: no MIME sniffing, no referrer to other sites, framed only by the station's own pages (the menu
#   frames its views), no plugins, no foreign <base>; scripts are not restricted, the pages use inline scripts
# - the files under /scripts that are not pages (shell and Python sources, databases, JSON / text data, logs, the
#   gotty binaries, the root helpers birdsuite-root / birdsuite-svc) answer 404 instead of downloading; no page fetches them (the chart JSON lives under /Charts)
# - the phones' and computers' internet checks (Android generate_204, Apple hotspot-detect, Windows connecttest, Firefox
#   canonical / success) go to the home page: on the station WiFi every name is the station, so the phone sees a login
#   page and opens the site by itself (owner 2026-10-25: Android got a 404, which reads as "no internet", and stayed put)
# - no directory listings (no page links to the /By_Date or /Charts folders; the recordings open from their pages)
read -r -d '' HARDENING << 'EOF'
  header {
    X-Content-Type-Options nosniff
    Referrer-Policy same-origin
    X-Frame-Options SAMEORIGIN
    Content-Security-Policy "frame-ancestors 'self'; object-src 'none'; base-uri 'self'"
  }
  @captive path /generate_204 /gen_204 /hotspot-detect.html /library/test/success.html /ncsi.txt /connecttest.txt /canonical.html /success.txt
  redir @captive / 302
  @internal path_regexp "^/scripts/(.*[.](sh|py|pyc|db|db-journal|db-wal|db-shm|json|txt|log|conf|sha256|csv|whl)|gotty.*|birdsuite-(root|svc)|(.*/)?__pycache__/.*)$"
  respond @internal 404
EOF
# the password of the pages, when one is set: the same paths as before behind it
PUBLIC_SCRIPTS="/scripts/gps.php /scripts/location_switch.php /scripts/stations.php /scripts/ui_lang.php /scripts/status.php /scripts/curation.php /scripts/species_tools.php /scripts/species_page.php /scripts/ebird.php /scripts/detections_dashboard.php /scripts/seasonality.php /scripts/appearance.php /scripts/email_settings.php /scripts/telegram_settings.php /scripts/network.php /scripts/models.php"
AUTH=
if [ -n "${CADDY_PWD}" ]; then
{ set +x; } 2>/dev/null # the plaintext password must not reach a trace
HASHWORD=$(caddy hash-password --plaintext "${CADDY_PWD}")
set -x
WEB_USER=${CADDY_USER:-birdsuite}
USERS="    ${WEB_USER} ${HASHWORD}"
[ "$WEB_USER" = birdnet ] || USERS="${USERS}
    birdnet ${HASHWORD}"  # the user name before 2026-10-23
for p in '/Processed*' '/stream' '/phpsysinfo*' '/terminal*' '/files*' '/log*'; do
  # the live analysis log too (2026-10-23 test run: it opened without the password)
  AUTH="${AUTH}  basicauth ${p} {
${USERS}
  }
"
done
# /scripts: everything but the endpoints of the pages of the Simple menu and of every page (owner 2026-10-25: the login
# was asked on any page, because each page polls location_switch.php); those check the password themselves before they
# change anything (ensure_authenticated), as play.php and overview.php at the root always did
AUTH="${AUTH}  @scripts_private {
    path /scripts*
    not path ${PUBLIC_SCRIPTS}
  }
  basicauth @scripts_private {
${USERS}
  }
"
fi
# the access log (owner 2026-10-31): the origin of each settings change in state/settings_history.log
# (scripts/settings_history.py); readable by the web server's group, which the station user is in; rolled by Caddy
read -r -d '' ACCESS_LOG << 'EOF'
  log {
    output file /var/log/caddy/access.log {
      roll_size 10MiB
      roll_keep 5
      roll_keep_for 720h
      mode 0640
    }
  }
EOF
read -r -d '' BODY << EOF
  root * ${EXTRACTED}
${HARDENING}
  file_server
  handle /By_Date/* {
    file_server
  }
  handle /Charts/* {
    file_server
  }
${AUTH}  reverse_proxy /stream localhost:8000
  php_fastcgi unix//run/php/php-fpm.sock
  reverse_proxy /log* localhost:8080
  reverse_proxy /terminal* localhost:8888
  reverse_proxy /files* localhost:8889
EOF
# http by default (owner 2026-10-29, US-126): the pages, the links and the installer use http, no certificate warning.
# HTTPS_ALWAYS=1 (Tools › Defaults): the site over https only, http sends the browser there. Else https is switched on
# for a moment while a device reads its GPS for a location (browsers give the GPS only to https pages): scripts/
# https_temp.php writes the time it ends in state/https_temp and has this file written again; the page switches it off
# when the position arrived, the field service (field_daemon.py) when the time is over.
# The https side has a certificate of the station's own (Caddy's local CA, tls internal), made at the first visit for the
# name or address the browser used (birdsuite-xx.local, 192.168.x.y, 10.42.N.1 in the field); the browser warns once per
# device. On-demand certificates are only issued for names the ask endpoint approves: here any name that reaches the
# station, since the station's own CA is trusted by nobody else.
HTTPS_MODE=off
if [ "${HTTPS_ALWAYS:-0}" = 1 ]; then
  HTTPS_MODE=always
else
  until=$(cat "$BS_STATE/https_temp" 2>/dev/null)
  [[ "$until" =~ ^[0-9]+$ ]] && [ "$until" -gt "$(date +%s)" ] && HTTPS_MODE=temp
fi
HTTP_BODY=$BODY
[ "$HTTPS_MODE" = always ] && HTTP_BODY='  redir https://{host}{uri} 308'
# Caddy's check runs as the station user, who may not write the access log (2026-10-31: the check failed on it and
# the GPS https was gone): the check gets the same file without the log block, the installed file has it
write_https() {
cat << EOF
{
  on_demand_tls {
    ask http://localhost:5555/
  }
}
http://localhost:5555 {
  respond 200
}
http:// ${BIRDNETPI_URL} {
${1}${HTTP_BODY}
}
https:// {
  tls internal {
    on_demand
  }
${1}${BODY}
}
EOF
}
write_https "${ACCESS_LOG}
" > "$OUT.https"
write_https '' > "$OUT.check"

# only when Caddy accepts it; else the pages over http alone, as before (the station never loses its web server)
cat << EOF > "$OUT.http"
http:// ${BIRDNETPI_URL} {
${ACCESS_LOG}
${BODY}
}
EOF
if [ "$HTTPS_MODE" = off ]; then
  mv -f "$OUT.http" "$OUT"; rm -f "$OUT.https"
elif caddy validate --adapter caddyfile --config "$OUT.check" > /dev/null 2>&1; then
  mv -f "$OUT.https" "$OUT"; rm -f "$OUT.http"
else
  echo "update_caddyfile.sh: Caddy refused the https part - the pages stay on http only" >&2
  mv -f "$OUT.http" "$OUT"; rm -f "$OUT.https"
fi
rm -f "$OUT.check"

if [ -n "${CADDYFILE_OUT:-}" ]; then
  caddy fmt --overwrite "$OUT"
else
  sudo caddy fmt --overwrite "$OUT"
  sudo systemctl reload caddy
fi
