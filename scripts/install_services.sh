#!/usr/bin/env bash
################################################################################
# scripts/install_services.sh - THE SERVICES, THE WEB SERVER AND THE SYSTEM PIECES
#
# PURPOSE:
#   Installs what the station runs: the systemd units from templates/units, the web server (Caddy) and its
#   Caddyfile, the cron jobs, the sudoers rules, the helpers and the zram service; run at the install and the
#   update.
#
# DEPENDENCIES:
#   - scripts/install_helpers.sh
#   - scripts/core.sh
#   - scripts/update_caddyfile.sh
#   - scripts/mic_hotplug.sh
#   - scripts/settings_history.py
#   - scripts/web_password_hash.py
#   - scripts/install_hardening.sh
#   - scripts/createdb.sh
#
# USAGE:
#   bash scripts/install_services.sh
#   Referenced by scripts/dump_logs.sh, scripts/install_birdnet.sh, scripts/install_hardening.sh
################################################################################

set -x # Uncomment to enable debugging
trap 'rm -f ${tmpfile}' EXIT
trap 'exit 1' SIGINT SIGHUP
tmpfile=$(mktemp)

# the folder layout, service names and ports (core.env); the program folder is the one this script is in
source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/core.sh"
config_file=$BS_CONF  # the station's settings
export USER=$USER
export HOME=$HOME

install_depends() {
	apt install -y debian-keyring debian-archive-keyring apt-transport-https
	curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --yes --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
	curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
	apt -qqq update && apt -qqy upgrade
	echo "icecast2 icecast2/icecast-setup boolean false" | debconf-set-selections
	apt install --no-install-recommends -qqy caddy sqlite3 php-sqlite3 php-fpm php-curl php-xml php-zip php-mbstring php icecast2 \
		pulseaudio avahi-utils sox libsox-fmt-mp3 alsa-utils ffmpeg \
		wget curl unzip bc \
		python3-pip python3-venv lsof net-tools inotify-tools \
		network-manager python3-dbus python3-gi iw bluez mc
}

# the station's host name: birdsuite, lower case (owner 2026-11-03), for the stock raspberrypi and for any spelling
# of birdsuite (BirdSuite, BIRDSUITE); a name of the owner's own stays
set_hostname() {
	local h
	h=$(hostname)
	if [ "$h" == "raspberrypi" ] || { [ "${h,,}" == "birdsuite" ] && [ "$h" != "birdsuite" ]; }; then
		hostnamectl set-hostname birdsuite
		sed -i "s/\b$h\b/birdsuite/g" /etc/hosts
	fi
}

# the Custom URL (BIRDNETPI_URL, Settings › Access) as a name of this host, once
update_etc_hosts() {
	local name=${BIRDNETPI_URL#*://}
	name=${name%%[:/]*}
	[ -n "$name" ] || return 0
	grep -qw -- "$name" /etc/hosts || sed -i -e "s/$(hostname).local/$(hostname).local ${name}/" /etc/hosts
}

install_scripts() {
	ln -sf "$BS_SCRIPTS"/* "$BS_BIN"/
}

create_necessary_dirs() {
	echo "Creating necessary directories"
	[ -d ${EXTRACTED} ] || sudo -u ${USER} mkdir -p ${EXTRACTED}
	[ -d ${EXTRACTED}/By_Date ] || sudo -u ${USER} mkdir -p ${EXTRACTED}/By_Date
	[ -d ${PROCESSED} ] || sudo -u ${USER} mkdir -p ${PROCESSED}
	[ -d "$BS_STREAMDATA" ] || sudo -u ${USER} mkdir -p "$BS_STREAMDATA"
	# the spool of the BirdDB-Br sound repository (US-18): SOUND_REPO_PATH, empty = the station's own (core.env)
	[ -d "${SOUND_REPO_PATH:-$BS_SOUND_REPO}" ] || sudo -u ${USER} mkdir -p "${SOUND_REPO_PATH:-$BS_SOUND_REPO}"
	[ -L ${EXTRACTED}/spectrogram.png ] || sudo -u ${USER} ln -sf "$BS_STREAMDATA/spectrogram.png" ${EXTRACTED}/spectrogram.png

	# the station's own files (owner 2026-10-22): config/ of the program folder, the running species lists in config/species_lists
	sudo -u ${USER} mkdir -p "$BS_CUSTOM_LISTS" "$BS_CONFIG_SCRIPTS" "$BS_NOTIFY" "$BS_DATA" "$BS_CONFIG/settings_profiles" "$BS_CUSTOM" "$BS_STATE"
	for f in exclude confirmed include whitelist; do [ -e "$BS_LISTS/${f}_species_list.txt" ] || sudo -u ${USER} touch "$BS_LISTS/${f}_species_list.txt"; done
	install_links  # the pages and tools links (install_helpers.sh)
	chmod -R g+rw "$BS_PROG"
	chmod -R g+rw ${RECS_DIR}
}

generate_BirdDB() {
	echo "Generating BirdDB.txt"
	local db_txt=$BS_DATA/BirdDB.txt
	if ! [ -f $db_txt ];then
		sudo -u ${USER} touch $db_txt
		echo "Date;Time;Sci_Name;Com_Name;Confidence;Lat;Lon;Cutoff;Week;Sens;Overlap" | sudo -u ${USER} tee -a $db_txt
	elif ! grep Date $db_txt;then
		sudo -u ${USER} sed -i '1 i\Date;Time;Sci_Name;Com_Name;Confidence;Lat;Lon;Cutoff;Week;Sens;Overlap' $db_txt
	fi
	chown $USER:$USER $db_txt && chmod g+rw $db_txt
}

set_login() {
	if ! [ -d /etc/lightdm ];then
		systemctl set-default multi-user.target
		ln -fs /lib/systemd/system/getty@.service /etc/systemd/system/getty.target.wants/getty@tty1.service
		if ! [ -d /etc/systemd/system/getty@tty1.service.d ];then
			mkdir /etc/systemd/system/getty@tty1.service.d
		fi
		cat > /etc/systemd/system/getty@tty1.service.d/autologin.conf << EOF
[Service]
ExecStart=
ExecStart=-/sbin/agetty --autologin $USER --noclear %I \$TERM
EOF
	fi
}

install_Caddyfile() {
	# one generator for the installation and the updates (2026-10-23 test run: this copy had fallen behind - no
	# protection of /log, no security headers); it keeps the plaintext password out of the trace
	$BS_SCRIPTS/update_caddyfile.sh

	systemctl enable caddy
	# the web server reads the public files of the station (clips, charts, the page files) through the station user's
	# group; it runs no command and needs no other group (least privilege, 2026-10-23)
	usermod -aG $USER caddy
	chmod g+r+x $HOME
}

install_avahi_aliases() {
	# no avahi-alias@<host>.local unit any more (2026-10-25): avahi-daemon publishes the host name itself and the field
	# service the station's own name, both with the address in use; the alias kept the address of the start-up
	# symbolic link does not work here, so just copy
	cp -f "$BS_TEMPLATES/http.service" /etc/avahi/services/
	systemctl restart avahi-daemon.service
}

install_gotty_links() {
	sudo -u ${USER} ln -sf "$BS_TEMPLATES/gotty" ${HOME}/.gotty
	sudo -u ${USER} ln -sf "$BS_TEMPLATES/bashrc" ${HOME}/.bashrc
}

configure_caddy_php() {
	echo "Configuring PHP for Caddy"
	# the pages run as the station user, no sudo for the web server (install_helpers.sh, also run by every update)
	install_php_pool
}

# least privilege (owner 2026-10-23): the only root steps of the station are the two named helpers, installed
# root-owned (copies, so the station user cannot change them); the station user's sudo rule allows only these two
install_least_privilege() {
	install_root_helpers || exit 1
}

install_phpsysinfo() {
	# from phpsysinfo's own repository (owner 2026-10-23: a customer installs from public sources, never the station
	# owner's backup); a shallow clone, BIRDNET_PHPSYSINFO_REPO overrides it
	sudo -u ${USER} git clone --depth 1 ${BIRDNET_PHPSYSINFO_REPO:-https://github.com/phpsysinfo/phpsysinfo.git} \
		${HOME}/phpsysinfo
}

config_icecast() {
	if [ -f /etc/icecast2/icecast.xml ];then
		cp /etc/icecast2/icecast.xml{,.prebirdnetpi}
	fi
	sed -i 's/>admin</>birdnet</g' /etc/icecast2/icecast.xml
	{ set +x; } 2>/dev/null # the stream password must not reach the install log
	ICE_PWD_ESC=$(printf '%s' "${ICE_PWD}" | sed -e 's/[\/&\\]/\\&/g')
	passwords=("source-" "relay-" "admin-" "master-" "")
	for i in "${passwords[@]}";do
	sed -i "s/<${i}password>.*<\/${i}password>/<${i}password>${ICE_PWD_ESC}<\/${i}password>/g" /etc/icecast2/icecast.xml
	done
	set -x
	sed -i 's|<!-- <bind-address>.*|<bind-address>127.0.0.1</bind-address>|;s|<!-- <shoutcast-mount>.*|<shoutcast-mount>/stream</shoutcast-mount>|' /etc/icecast2/icecast.xml

	systemctl enable icecast2.service
}

install_mic_hotplug() {
	# USB microphone hot-plug (US-51b): udev restarts a state-driven oneshot unit on every USB sound card add/remove;
	# mic_hotplug.sh stops or (re)configures and starts the station (templates/udev)
	install_udev_rules
}

install_settings_history() {
	# every change of the settings, whoever writes it, one line in the settings history log with the origin from the web
	# server's access log (scripts/settings_history.py, owner 2026-10-31); the station user reads that log through the
	# web server's group
	getent group caddy > /dev/null && usermod -aG caddy $USER
}

install_field_services() {
	# field access (US-120, owner 2026-10-20): the field and Bluetooth units run as the station user (owner 2026-10-23,
	# least privilege): NetworkManager through a polkit rule for that user, Bluetooth through its group, the clock and
	# the LED through the root helper
	install_polkit_rule
}

chown_things() {
	chown -R $USER:$USER "$BS_PROG" "$BS_RECS"
}

increase_caddy_timeout() {
	mkdir /etc/systemd/system/caddy.service.d
	cat << EOF > /etc/systemd/system/caddy.service.d/override.conf
[Service]
TimeoutSec=300s
EOF
	systemctl daemon-reload
}

install_services() {
	set_hostname
	update_etc_hosts
	set_login
	install_tmp_mount

	install_depends
	install_scripts
	install_Caddyfile
	install_avahi_aliases
	install_gotty_links
	install_phpsysinfo
	install_mic_hotplug
	install_field_services
	install_settings_history
	# every systemd unit of templates/units, enabled (install_helpers.sh); the cron lines of templates/*.cron
	install_units
	apply_ramdisk_policy
	install_cron
	increase_caddy_timeout

	create_necessary_dirs
	generate_BirdDB
	configure_caddy_php
	install_ssh_hardening
	install_extra_hardening
	install_unattended_upgrades
	# the first-run password, written as text before PHP was there, kept only as its hash from now on (SEC-15)
	python3 "$BS_SCRIPTS/web_password_hash.py" || true
	install_least_privilege
	# number format of the station = the BirdSuite language chosen (owner 2026-10-23; LC_NUMERIC only)
	bs_lang=$(sed -n 's/^UI_LANG=//p' "$BS_CONF" | tr -d '"'); [ -z "$bs_lang" ] && bs_lang=$(sed -n 's/^DATABASE_LANG=//p' "$BS_CONF" | tr -d '"')
	"$BS_ROOT_HELPER" numeric "${bs_lang:-en}" 2>/dev/null || "$BS_ROOT_HELPER" numeric en || true
	# the log policy (owner 2026-11-01, System › Disk Space): journal LOG_RETENTION_DAYS days and 200 MB, rotated logs compressed
	log_days=$(sed -n 's/^LOG_RETENTION_DAYS=//p' "$BS_CONF" | tr -d '"')
	"$BS_ROOT_HELPER" logs "${log_days:-30}" 2>/dev/null || "$BS_ROOT_HELPER" logs 30 || true
	install_unit_hardening; systemctl daemon-reload  # systemd sandboxing of the services (install_helpers.sh)
	# inbound firewall, automatic security updates, no root ssh login (2026-10-23; fresh installs only)
	$BS_SCRIPTS/install_hardening.sh || echo "System hardening not applied (scripts/install_hardening.sh)" >&2
	config_icecast
	USER=$USER HOME=$HOME $BS_SCRIPTS/createdb.sh
}

if [ -f ${config_file} ];then
	{ set +x; } 2>/dev/null # not traced: BirdSuite.conf carries the web / stream passwords
	source ${config_file}
	set -x
	source "$BS_SCRIPTS/install_helpers.sh"
	install_services
	chown_things
else
	echo "Unable to find a configuration file. Please make sure that $config_file exists."
	# a failure, not a silent success (2026-10-23: the installation went on and reported success with nothing installed)
	exit 1
fi
