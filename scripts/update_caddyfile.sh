#!/usr/bin/env bash
################################################################################
# scripts/update_caddyfile.sh - THE WEB SERVER CONFIGURATION (CADDYFILE)
#
# PURPOSE:
#   Writes the Caddyfile from the settings (the login on or off, the users, the site address, the trusted LAN)
#   and reloads Caddy; run by birdsuite-root caddyfile after a save of Station › Access.
#
# DEPENDENCIES:
#   - scripts/paths.sh
#   - scripts/update_birdnet_snippets.sh
#   - scripts/web_password_hash.py
#   - scripts/settings_history.py
#   - scripts/field_daemon.py
#   - birdsuite-root (sudo helper)
#   - birdsuite-svc (sudo helper)
#
# USAGE:
#   bash scripts/update_caddyfile.sh
#   Referenced by scripts/install_services.sh, scripts/update_birdnet_snippets.sh
################################################################################

source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
my_dir=$BS_SCRIPTS
set -x
# CADDYFILE_OUT set (owner 2026-10-23, least privilege): only write the configuration to that file, as the station user,
# for birdsuite-root caddyfile to check and install; unset: the installer / updater (root) writes and reloads it itself
OUT=${CADDYFILE_OUT:-$BS_CADDYFILE}
if [ -z "${CADDYFILE_OUT:-}" ]; then
	[ -d "$(dirname "$BS_CADDYFILE")" ] || mkdir "$(dirname "$BS_CADDYFILE")"
	if [ -f "$BS_CADDYFILE" ];then
		cp "$BS_CADDYFILE" "$BS_CADDYFILE.original"
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
# - no directory listings (no page links to the /By_Date folders; the recordings open from their pages)
# - pages, scripts and JSON compressed (zstd / gzip; Caddy leaves audio and pictures alone): a species list page went
#   from 1.6 MB to under 0.3 MB (2026-11-02 release run 4, PERF-50)
# - the live spectrogram (spectrogram.png, redrawn every few seconds) is always asked again, and answered 304 by its
#   ETag / Last-Modified while it did not change (owner 2026-11-06, Gitea #78): kept by the browser, never shown stale
read -r -d '' HARDENING << 'EOF'
  encode zstd gzip
  header {
    X-Content-Type-Options nosniff
    Referrer-Policy same-origin
    X-Frame-Options SAMEORIGIN
    Content-Security-Policy "frame-ancestors 'self'; object-src 'none'; base-uri 'self'"
  }
  @livespec path /spectrogram.png
  header @livespec Cache-Control no-cache
  @captive path /generate_204 /gen_204 /hotspot-detect.html /library/test/success.html /ncsi.txt /connecttest.txt /canonical.html /success.txt
  redir @captive / 302
  @internal path_regexp "^/scripts/(.*[.](sh|py|pyc|db|db-journal|db-wal|db-shm|json|txt|log|conf|sha256|csv|whl|env)|gotty.*|birdsuite-(root|svc)|(.*/)?__pycache__/.*)$"
  respond @internal 404
EOF
# the password of the pages, when one is set: the same paths as before behind it
# (the pages of the Simple menu used to open without the password; since 2026-11-02 everything asks for it)
PUBLIC_SCRIPTS_RETIRED="/scripts/gps.php /scripts/location_switch.php /scripts/stations.php /scripts/ui_lang.php /scripts/status.php /scripts/curation.php /scripts/species_tools.php /scripts/species_page.php /scripts/ebird.php /scripts/detections_dashboard.php /scripts/seasonality.php /scripts/appearance.php /scripts/email_settings.php /scripts/telegram_settings.php /scripts/network.php /scripts/models.php"
AUTH=
# a password on everything (owner 2026-11-02): every page, endpoint, stream, log, terminal and file view asks for the
# web login; while no password is set the factory one (BS_WEB_PASSWORD_DEFAULT) is asked, so a station is never open, and
# it opens only Station › Access, to set the station's own (bug #60, common.php login_user()).
# The station's own calls (localhost: the cache warmer, the notification pictures) do not log in.
# the stored password is its bcrypt hash (SEC-15; scripts/web_password_hash.py) and goes in as it is; the factory
# password, or a plain value left by an older station, is hashed here
{ set +x; } 2>/dev/null # neither the plaintext nor the hash must reach a trace
if [[ ${CADDY_PWD:-} =~ ^\$2[aby]\$ ]]; then
	HASHWORD=$CADDY_PWD
else
	HASHWORD=$(caddy hash-password --plaintext "${CADDY_PWD:-$BS_WEB_PASSWORD_DEFAULT}")
fi
set -x
WEB_USER=${CADDY_USER:-birdsuite}
{ set +x; } 2>/dev/null # the accounts and their hashes never reach a trace
# the accounts (US-56): the web login of Settings › Access, then every other named user of config/users.json (its own
# bcrypt hash; the file is read, never sourced; a name is letters and digits, a hash only bcrypt characters)
CREDS="    ${WEB_USER} ${HASHWORD}"
if [ -r "$BS_CONFIG/users.json" ]; then
	while IFS=' ' read -r u h; do
		[[ $u =~ ^[A-Za-z0-9]{1,32}$ ]] && [[ $h =~ ^\$2[aby]\$[0-9]{2}\$[./A-Za-z0-9]{53}$ ]] && [ "$u" != "$WEB_USER" ] || continue
		CREDS="$CREDS
    $u $h"
	done < <(python3 -c 'import json,sys
d=json.load(open(sys.argv[1]))
for e in d.get("users", []):
    if not e.get("main") and e.get("hash"): print(e.get("name", ""), e["hash"])' "$BS_CONFIG/users.json" 2>/dev/null)
fi
# who is asked (US-53, owner 2026-11-04):
# - WEB_LOGIN=off: an open station, no login anywhere, no dialog (the pages' own checks answer yes too, common.php)
# - LAN_TRUST=everyone (the factory value): every request that is not the station's own asks, as since 2026-11-02
# - LAN_TRUST=household: a device on a private network (the LAN, the station WiFi, the VPN) opens the pages without the
#   login, the pages themselves ask where they show something sensitive and for every change (views.php, the view
#   registry); what has no page in front (the log, terminal and files services, the live audio, the database editor,
#   the hardware page) still asks there; every other device asks for everything
# no password set yet (bug #60): the factory password is never a login, so what has no page in front (terminal, files,
# the database editor, the hardware page) sends to Station › Access, where the station's own password is set
NOPWD=
# the factory one stored as its hash counts as none (a new install writes it so); checked by PHP's bcrypt, the values
# passed in the environment, never on a command line
pwd_is_factory() {
	[ -z "${CADDY_PWD:-}" ] && return 0
	BS_H=$CADDY_PWD BS_F=$BS_WEB_PASSWORD_DEFAULT php -r 'exit(password_verify(getenv("BS_F"), getenv("BS_H")) ? 0 : 1);' 2> /dev/null
}
if [ "${WEB_LOGIN:-on}" != off ] && pwd_is_factory; then
	NOPWD="  @nopwd {
    not remote_ip 127.0.0.1 ::1
    path /terminal* /files* /scripts/adminer.php /phpsysinfo/*
  }
  redir @nopwd /views.php?view=Access
"
fi
case "${WEB_LOGIN:-on}" in
	off) AUTH= ;;
	*)
		if [ "${LAN_TRUST:-everyone}" = household ]; then
			AUTH="  @remote not remote_ip 127.0.0.1 ::1 private_ranges
  basicauth @remote {
${CREDS}
  }
  @lanadmin {
    not remote_ip 127.0.0.1 ::1
    path /log* /terminal* /files* /stream* /scripts/adminer.php /phpsysinfo/*
  }
  basicauth @lanadmin {
${CREDS}
  }
"
		else
			# the page of a forgotten password opens from a private network without the login (US-53, scripts/password_reset.php)
			AUTH="  @remote {
    not remote_ip 127.0.0.1 ::1
    not {
      remote_ip private_ranges
      path /scripts/password_reset.php
    }
  }
  basicauth @remote {
${CREDS}
  }
"
		fi
		;;
esac
set -x
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
  root * ${BS_EXTRACTED}
${HARDENING}
  file_server
  handle /By_Date/* {
    file_server
  }
${NOPWD}${AUTH}  reverse_proxy /stream localhost:${BS_PORT_ICECAST}
  php_fastcgi unix//run/php/php-fpm.sock
  reverse_proxy /log* localhost:${BS_PORT_LOG}
  reverse_proxy /terminal* localhost:${BS_PORT_TERMINAL}
  reverse_proxy /files* localhost:${BS_PORT_FILES}
EOF
# http by default (owner 2026-10-29, US-126): the pages, the links and the installer use http, no certificate warning.
# HTTPS_ALWAYS=1 (Settings › Defaults): the site over https only, http sends the browser there. Else https is switched on
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
# skip_install_trust: the station's CA is for the browsers only; without it Caddy tries to add it to the system trust
# store through sudo at the first https start, which the caddy user may not do (2026-11-03: a "caddy : user NOT in
# sudoers" alert e-mail each time the GPS https came on)
write_https() {
cat << EOF
{
  skip_install_trust
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
