#!/usr/bin/env bash
# this should only contain functions and assignments, ie source install.sh should not have side effects.
# The caller has read the core definitions first (core.sh / paths.sh: BS_* and bs_render); USER and HOME are the
# station user's.

# A rendered unit is checked before root installs it (security 2026-11-02): it must not make systemd run anything as
# root or hand the station user more than it has. A service runs as the station user (User=
# that user, no + or ! before a command but the radio unblock of the Bluetooth service), with only the directives
# BirdSuite writes; a mount is the tmpfs of StreamData; a timer or a path unit starts a BirdSuite unit.
unit_template_ok () {
  local f=$1 k v line kind=${1##*.} users=0
  [ -f "$f" ] && [ ! -L "$f" ] || return 1
  while IFS= read -r line || [ -n "$line" ]; do
    line=${line%$'\r'}
    case "$line" in ''|'#'*|';'*|'['*']') continue ;; esac
    k=${line%%=*}; v=${line#*=}
    [ "$k" != "$line" ] || { echo "unit template refused, $(basename "$f"): $line"; return 1; }
    case "$kind:$k" in
      *:Description|*:Documentation|*:After|*:Before|*:Wants|*:Requires|*:BindsTo|*:PartOf|*:StartLimitIntervalSec|*:StartLimitBurst|*:RequiresMountsFor|*:ConditionPathExists|*:WantedBy|*:RequiredBy) ;;
      service:Type|service:Restart|service:RestartSec|service:TimeoutSec|service:TimeoutStopSec|service:TimeoutStartSec|service:RuntimeMaxSec|service:KillMode|service:KillSignal|service:StandardOutput|service:StandardError|service:SyslogIdentifier|service:Nice|service:WorkingDirectory|service:Environment|service:RuntimeDirectory|service:RuntimeDirectoryMode|service:RuntimeDirectoryPreserve) ;;
      # the sandboxing directives only take away
      service:Protect*|service:Private*|service:Restrict*|service:NoNewPrivileges|service:LockPersonality|service:SystemCallArchitectures|service:MemoryDenyWriteExecute) ;;
      # an empty bounding set takes every capability away; any other value could give one
      service:CapabilityBoundingSet) [ -z "$v" ] || { echo "unit template refused, $(basename "$f"): $line"; return 1; } ;;
      service:User) [ "$v" = "$USER" ] || { echo "unit template refused, $(basename "$f"): $line"; return 1; }; users=1 ;;
      service:Group) [ "$v" = "$(id -gn "$USER")" ] || { echo "unit template refused, $(basename "$f"): $line"; return 1; } ;;
      service:SupplementaryGroups)
        for g in $v; do case "$g" in audio|video|netdev|bluetooth|plugdev|gpio|i2c|spi) ;; *) echo "unit template refused, $(basename "$f"): $line"; return 1 ;; esac; done ;;
      service:ExecStart|service:ExecStartPre|service:ExecStartPost|service:ExecStop|service:ExecStopPost|service:ExecReload)
        case "$v" in [-@:+!]*) [ "$k=$v" = "ExecStartPre=+/usr/sbin/rfkill unblock bluetooth" ] || { echo "unit template refused, $(basename "$f"): $line"; return 1; } ;; esac ;;
      mount:What) [ "$v" = tmpfs ] || return 1 ;;
      mount:Type) [ "$v" = tmpfs ] || return 1 ;;
      mount:Where) case "$v" in "$BS_RECS"/*) ;; *) echo "unit template refused, $(basename "$f"): $line"; return 1 ;; esac ;;
      mount:Options) [[ $v =~ ^[A-Za-z0-9=,]+$ ]] && [[ ,$v, == *,nosuid,* ]] && [[ ,$v, == *,nodev,* ]] || { echo "unit template refused, $(basename "$f"): $line"; return 1; } ;;
      timer:OnCalendar|timer:OnBootSec|timer:OnUnitActiveSec|timer:OnUnitInactiveSec|timer:OnActiveSec|timer:Persistent|timer:RandomizedDelaySec|timer:AccuracySec) ;;
      timer:Unit|path:Unit) case " $(bs_unit_names) " in *" $v "*) ;; *) echo "unit template refused, $(basename "$f"): $line"; return 1 ;; esac ;;
      path:PathChanged|path:PathModified|path:PathExists|path:MakeDirectory) ;;
      *) echo "unit template refused, $(basename "$f"): $line"; return 1 ;;
    esac
  done < "$f"
  [ "$kind" != service ] || [ "$users" = 1 ] || { echo "unit template refused, $(basename "$f"): no User=$USER"; return 1; }
  return 0
}

# the address root fetches its own copy of the program from (birdsuite-root update, security 2026-11-02): recorded once,
# root-owned, from the station's clone when it is an https address without a user or token, else the public repository
record_origin () {
  local f=$BS_LIB/origin u
  [ -s "$f" ] && return 0
  u=$(sudo -u "$USER" git -C "$BS_PROG" config --get remote.origin.url 2>/dev/null)
  [[ $u =~ ^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?/[A-Za-z0-9._/~-]+$ ]] || u=https://github.com/EvaldoOliveira/BirdSuite.git
  install -d -o root -g root -m 0755 "$BS_LIB"
  printf '%s\n' "$u" > "$f" && chown root:root "$f" && chmod 0644 "$f"
}

get_tf_whl () {
  # the wheels come from the birdsuite-assets repository (scripts/fetch_asset.sh, git/ssh); their
  # SHA256 sums ship with this edition (scripts/tflite_wheels.sha256)

  ARCH=$(uname -m)
  PY_VERSION=$(python3 -c "import sys; print(f'{sys.version_info[0]}{sys.version_info[1]}')")
  case "${ARCH}-${PY_VERSION}" in
    aarch64-311)
      WHL=tflite_runtime-2.17.1-cp311-cp311-linux_aarch64.whl
      ;;
    aarch64-312)
      WHL=tflite_runtime-2.17.1-cp312-cp312-linux_aarch64.whl
      ;;
    aarch64-313)
      WHL=tflite_runtime-2.17.1-cp313-cp313-linux_aarch64.whl
      ;;
    x86_64-311)
      WHL=tflite_runtime-2.17.1-cp311-cp311-linux_x86_64.whl
      ;;
    x86_64-312)
      WHL=tflite_runtime-2.17.1-cp312-cp312-linux_x86_64.whl
      ;;
    x86_64-313)
      WHL=tflite_runtime-2.17.1-cp313-cp313-linux_x86_64.whl
      ;;
    *)
      echo "No tflite version found for ${ARCH}-${PY_VERSION}"
      WHL=''
      ;;
  esac
  if [ -n "$WHL" ]; then
    # the downloader and the checksums from the code that runs (root's own copy during an update), not the station user's
    if ! "$BS_HELPERS_DIR/fetch_asset.sh" wheels/$WHL "$BS_PROG"; then
      echo "Could not download $WHL"
      return 1
    fi
    SUMS=$(grep " ${WHL}\$" "$BS_HELPERS_DIR/tflite_wheels.sha256" 2>/dev/null || true)
    if [ -n "$SUMS" ] && ! (cd "$BS_PROG" && echo "$SUMS" | sha256sum -c --quiet -); then
      echo "Checksum mismatch for $WHL"
      return 1
    fi
    sed "s/tensorflow.*/$WHL/" "$BS_PROG/requirements.txt" > requirements_custom.txt
  else
    # no prebuilt tflite_runtime for this platform: fall back to the full tensorflow package
    cp "$BS_PROG/requirements.txt" requirements_custom.txt
  fi
}

# ---- what BirdSuite installs into the system, from templates/ (owner 2026-11-02) ----
# The templates of the code that runs: root's own copy of the program during an update, the clone during the installation.
# templates/units/<KEY>.<type> is a unit: SVC_ANALYSIS.service installs as $BS_SVC_ANALYSIS.service, STREAMDATA.mount as
# the mount unit of the data folder's RAM drive; @BS_KEY@, @USER@ and @GROUP@ are filled by bs_render (core.sh).
# this file's own folder, taken when it is sourced: the installer sources it by a relative name ("source
# install_helpers.sh" in scripts/) and then works in the program folder, where "$(dirname "${BASH_SOURCE[0]}")" is "."
# and fetch_asset.sh is not found (2026-11-03: every new installation since 2026-11-02 stopped at the tflite wheel)
BS_HELPERS_DIR=$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")
BS_TPL=$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../templates" && pwd)
BS_UNIT_MARK='# BirdSuite unit'

# the unit file a template installs as (nothing for a key core.env does not have)
bs_unit_name () {
  local b k
  b=$(basename "$1"); k=BS_${b%.*}
  case "$b" in
    STREAMDATA.mount) systemd-escape -p --suffix=mount "$BS_STREAMDATA" ;;
    *) [ -n "${!k:-}" ] && echo "${!k}.${b##*.}" ;;
  esac
}

# the RAM drive of the data folder by the Pi's memory (owner 2026-11-03): under 2 GB none (the V3 analysis needs the
# memory), 2 to 4 GB at most 512 MB (about 80 minutes of recordings waiting), 4 GB and more at most 1 GB (about 3 hours);
# the size is the tmpfs' limit, the files only take what they use
bs_ramdisk_size () {
  local kb
  kb=$(awk '/^MemTotal:/{print $2}' /proc/meminfo 2>/dev/null)
  kb=${kb:-0}
  if [ "$kb" -lt 1900000 ]; then echo ''; elif [ "$kb" -lt 3800000 ]; then echo 512M; else echo 1G; fi
}
# on or off by the memory, unless the owner chose in System › Status (BS_RAMDISK_CHOICE); a change takes effect at the next
# start (a remount now would hide the recordings being written)
apply_ramdisk_policy () {
  local n want choice
  n=$(systemd-escape -p --suffix=mount "$BS_STREAMDATA")
  [ -f "$BS_UNIT_DIR/$n" ] || return 0
  choice=$(cat "$BS_RAMDISK_CHOICE" 2>/dev/null)
  case "$choice" in
    on|off) want=$choice ;;
    *) [ -n "$(bs_ramdisk_size)" ] && want=on || want=off ;;
  esac
  if [ "$want" = on ]; then systemctl enable "$n" > /dev/null 2>&1; else systemctl disable "$n" > /dev/null 2>&1; fi
  echo "RAM drive: $want at the next start ($([ -n "$choice" ] && echo "the owner's choice" || echo "by the memory, at most $(bs_ramdisk_size)"))"
}

# every unit this version ships, space separated
bs_unit_names () {
  local t
  for t in "$BS_TPL"/units/*.*; do bs_unit_name "$t"; done | tr '\n' ' '
}

# the units of $BS_UNIT_DIR that are the station's: written from templates/units (the mark on their first line) or, from
# before the mark, run as the station user, the RAM drive of the data folder, or a timer / path unit that starts one of these
bs_owned_units () {
  local f own=' '
  for f in "$BS_UNIT_DIR"/*.service "$BS_UNIT_DIR"/*.mount; do
    [ -f "$f" ] || continue
    if head -1 "$f" | grep -qF "$BS_UNIT_MARK" || grep -qx "User=$USER" "$f" \
      || { [ "${f##*.}" = mount ] && grep -q "^Where=$BS_RECS/" "$f"; }; then
      own="$own$(basename "$f") "
    fi
  done
  for f in "$BS_UNIT_DIR"/*.timer "$BS_UNIT_DIR"/*.path; do
    [ -f "$f" ] || continue
    if head -1 "$f" | grep -qF "$BS_UNIT_MARK" || [[ $own == *" $(sed -n 's/^Unit=//p' "$f" | head -1) "* ]]; then
      own="$own$(basename "$f") "
    fi
  done
  echo $own
}

# what a unit does, to carry the state of a unit that changed its name: the programs and scripts of its command by their
# last part (folder and venv paths left out) and the web path of a gotty page (--path); a timer or a path unit by the
# command of the unit it starts (found in DIR)
bs_unit_key () {
  local f=$1 dir=$2 t
  case "$f" in
    *.timer|*.path) t=$(sed -n 's/^Unit=//p' "$f" | head -1); [ -f "$dir/$t" ] || return 0; echo "${f##*.}:$(bs_unit_key "$dir/$t" "$dir")" ;;
    *) sed -n 's/^ExecStart=//p' "$f" | head -1 \
      | awk '{ for (i = 1; i <= NF; i++) { if ($i ~ /\// || $i ~ /\.(sh|py)$/) { n = split($i, a, "/"); printf "%s ", a[n] } else if ($i == "--path") printf "%s ", $(i + 1) } }' ;;
  esac
}

# The station's units = the templates of this version: those it no longer ships are stopped, disabled and removed
# (with their sandboxing drop-in); the shipped ones installed as root-owned copies when they changed. A new unit takes
# the state of a removed one that ran the same command (a renamed unit keeps the owner's choice: off stays off), else
# it is enabled (and started with --start); a changed one that runs is restarted (with --start). Returns 0 when
# something changed.
install_units () {
  local start=${1:-} tmp t n f key changed=1 st act
  local -A was=()
  local -a gone=() fresh=() moved=()
  local refused=' '
  tmp=$(mktemp -d)
  for t in "$BS_TPL"/units/*.*; do
    n=$(bs_unit_name "$t")
    [ -n "$n" ] || continue
    { echo "$BS_UNIT_MARK (templates/units/$(basename "$t")), written by the installer and every update: do not edit"; bs_render "$t"; } > "$tmp/$n"
    # the RAM drive gets the limit of this Pi's memory (2 GB and more; under 2 GB it stays off, apply_ramdisk_policy)
    if [ "$(basename "$t")" = STREAMDATA.mount ] && [ -n "$(bs_ramdisk_size)" ]; then sed -i "s/^Options=\(.*\)$/Options=\1,size=$(bs_ramdisk_size)/" "$tmp/$n"; fi
    # a template this version ships but the check refuses: the installed unit stays as it is (2026-11-02 release run 6:
    # a refused template was taken for a unit no longer shipped, and four running services were removed)
    unit_template_ok "$tmp/$n" || { rm -f "$tmp/$n"; refused="$refused$n "; }
  done
  for n in $(bs_owned_units); do
    [ -f "$tmp/$n" ] && continue
    [[ $refused == *" $n "* ]] && continue
    key=$(bs_unit_key "$BS_UNIT_DIR/$n" "$BS_UNIT_DIR")
    st=$(systemctl is-enabled "$n" 2>/dev/null); act=$(systemctl is-active "$n" 2>/dev/null)
    [ -n "$key" ] && was[$key]="${st:-disabled} ${act:-inactive}"
    gone+=("$n")
  done
  for n in "${gone[@]}"; do
    systemctl disable --now "$n" > /dev/null 2>&1
    rm -f "$BS_UNIT_DIR/$n"
    echo "systemd: $n removed (this version no longer ships it)"
    changed=0
  done
  # the sandboxing drop-ins are BirdSuite's by their name: one of a unit that is not shipped goes too
  for f in /etc/systemd/system/*.d/10-birdsuite-hardening.conf; do
    [ -f "$f" ] || continue
    n=$(basename "$(dirname "$f")"); n=${n%.d}
    [ -f "$tmp/$n" ] && continue
    rm -f "$f"; rmdir "$(dirname "$f")" 2> /dev/null
    changed=0
  done
  for f in "$tmp"/*; do
    [ -f "$f" ] || continue
    n=$(basename "$f")
    if [ -f "$BS_UNIT_DIR/$n" ]; then cmp -s "$f" "$BS_UNIT_DIR/$n" && continue; moved+=("$n"); else fresh+=("$n"); fi
    install -o root -g root -m 0644 "$f" "$BS_UNIT_DIR/$n" && changed=0
  done
  rm -rf "$tmp"
  [ "$changed" = 0 ] && systemctl daemon-reload
  # a changed unit that runs takes its new definition now (with --start); never the RAM drive (a remount empties it)
  if [ "$start" = --start ]; then
    for n in "${moved[@]}"; do
      case "$n" in *.mount) ;; *) systemctl try-restart "$n" ;; esac
    done
  fi
  for n in "${fresh[@]}"; do
    grep -q '^\[Install\]' "$BS_UNIT_DIR/$n" || continue
    key=$(bs_unit_key "$BS_UNIT_DIR/$n" "$BS_UNIT_DIR")
    st=${was[$key]:-}
    case "$st" in
      '') systemctl enable "$n"; [ "$start" = --start ] && systemctl start "$n" ;;
      enabled*) systemctl enable "$n"; [ "${st#* }" = active ] && systemctl start "$n" ;;
      *) [ "${st#* }" = active ] && systemctl start "$n" ;;
    esac
    echo "systemd: $n installed${st:+ (as the unit it replaces was: $st)}"
  done
  return $changed
}

# the station's lines of /etc/crontab = the templates/*.cron of this version. The station's lines are those that run
# as the station user, with the one-word comment just above each; written again only when they differ
install_cron () {
  local want cur
  want=$(for t in "$BS_TPL"/*.cron; do bs_render "$t"; done)
  cur=$(awk -v u="$USER" -v mode=mine -f <(bs_cron_awk) /etc/crontab)
  [ "$want" = "$cur" ] && return 1
  { awk -v u="$USER" -v mode=other -f <(bs_cron_awk) /etc/crontab; printf '%s\n' "$want"; } > /etc/crontab.birdsuite
  cat /etc/crontab.birdsuite > /etc/crontab && rm -f /etc/crontab.birdsuite
  echo "cron: the station's lines of /etc/crontab written again"
}
bs_cron_awk () {
  cat << 'AWK'
function owner(l,  f) { split(l, f, /[ \t]+/); return (f[1] ~ /^@/) ? f[2] : f[6] }
{
  if ($0 ~ /^#[A-Za-z]+$/) { if (held != "") { if (mode == "other") print held } held = $0; next }
  mine = ($0 !~ /^[ \t]*#/ && NF > 1 && owner($0) == u)
  if (held != "") { if (mine && mode == "mine") print held; if (!mine && mode == "other") print held; held = "" }
  if (mine == (mode == "mine")) print
}
END { if (held != "" && mode == "other") print held }
AWK
}

# the station user's sudo rule (only the two root helpers); another rule naming the helpers is an older one of BirdSuite's
install_sudo_rule () {
  local rule=$BS_SUDOERS-$USER f
  bs_render "$BS_TPL/sudoers" > "$rule.new"
  if visudo -cf "$rule.new" > /dev/null; then
    chmod 0440 "$rule.new" && mv "$rule.new" "$rule"
  else
    rm -f "$rule.new"
    echo "the BirdSuite sudo rule did not pass visudo"
    return 1
  fi
  for f in "$(dirname "$BS_SUDOERS")"/*; do
    [ "$f" = "$rule" ] || [ ! -f "$f" ] && continue
    grep -qF -e "$BS_SVC_HELPER" -e "$BS_ROOT_HELPER" "$f" && rm -f "$f" && echo "sudo: $(basename "$f") removed (an older BirdSuite rule)"
  done
  return 0
}

# the udev rules of templates/udev (first line: # BirdSuite); one of BirdSuite's that this version does not ship goes
install_udev_rules () {
  local t f changed=1
  for f in "$BS_UDEV_DIR"/*.rules; do
    [ -f "$f" ] && head -1 "$f" | grep -q '^# BirdSuite' || continue
    [ -f "$BS_TPL/udev/$(basename "$f")" ] && continue
    rm -f "$f" && changed=0 && echo "udev: $(basename "$f") removed (this version no longer ships it)"
  done
  for t in "$BS_TPL"/udev/*.rules; do
    f=$BS_UDEV_DIR/$(basename "$t")
    bs_render "$t" > "$f.new"
    if cmp -s "$f.new" "$f"; then rm -f "$f.new"; else mv -f "$f.new" "$f" && chmod 0644 "$f" && changed=0; fi
  done
  [ "$changed" = 0 ] && udevadm control --reload-rules
  return 0
}

# the pages run as the station user (owner 2026-10-23, least privilege): every PHP pool runs as that user, the socket
# stays Caddy's (it hands the requests over without any right on the station's files), and the web server has no sudo
# rule at all. Run by the installer and by every update, so a station installed before that day is brought in line
# (2026-11-02 release run: a pilot still ran its pages as caddy with sudo for everything). PHP restarts only on a change.
install_php_pool () {
  local pool changed=0
  for pool in /etc/php/*/fpm/pool.d/www.conf; do
    [ -f "$pool" ] || continue
    local before
    before=$(md5sum < "$pool")
    sed -i -e "s/^user = .*/user = $USER/" -e "s/^group = .*/group = $USER/" \
      -e 's/^listen.owner = .*/listen.owner = caddy/' -e 's/^listen.group = .*/listen.group = caddy/' \
      -e 's/^;\?listen.mode = .*/listen.mode = 0660/' \
      -e 's/^pm.max_children = .*/pm.max_children = 12/' -e 's/^pm.start_servers = .*/pm.start_servers = 3/' \
      -e 's/^pm.max_spare_servers = .*/pm.max_spare_servers = 6/' "$pool"
    [ "$(md5sum < "$pool")" = "$before" ] || changed=1
  done
  if [ -e /etc/sudoers.d/010_caddy-nopasswd ]; then
    rm -f /etc/sudoers.d/010_caddy-nopasswd
    changed=1
  fi
  if [ "$changed" = 1 ]; then
    # files the pages wrote as the web server become the station user's (the pages are that user from now on)
    find "$BS_PROG" "$BS_RECS" -user caddy -exec chown "$USER:$USER" {} + 2>/dev/null
    systemctl restart 'php*-fpm.service'
  fi
  return 0
}

# ssh of a distributed station (owner 2026-11-02, release run 4): the owner's own keys are never on it, so password
# login stays, made harder to abuse: no root login, few tries per connection, a short login window, unauthenticated
# connections throttled, no empty password, no X11. 2026-11-03 (lynis SSH-7408, US-49): 3 tries, 2 sessions per
# connection, no agent forwarding, port forwarding only to the station itself (an ssh tunnel to its own pages, as the
# tests use, keeps working; nothing can be relayed through it), idle connections closed after 10 minutes, the logins
# logged with their key. A drop-in (Debian reads sshd_config.d first, so it wins); checked
# with sshd -t before ssh is reloaded, removed again when the check fails. Nothing happens when it is already in place.
install_ssh_hardening () {
  local f=/etc/ssh/sshd_config.d/50-birdsuite.conf tmp
  [ -d /etc/ssh/sshd_config.d ] || return 0
  tmp=$(mktemp)
  cat > "$tmp" << 'EOF2'
# Managed by BirdSuite (install_helpers.sh install_ssh_hardening)
PermitRootLogin no
PermitEmptyPasswords no
MaxAuthTries 3
MaxSessions 2
LoginGraceTime 30
MaxStartups 10:30:60
X11Forwarding no
AllowAgentForwarding no
AllowTcpForwarding local
PermitOpen 127.0.0.1:* [::1]:* localhost:*
ClientAliveInterval 300
ClientAliveCountMax 2
TCPKeepAlive no
LogLevel VERBOSE
EOF2
  if cmp -s "$tmp" "$f"; then rm -f "$tmp"; return 0; fi
  install -o root -g root -m 0644 "$tmp" "$f"
  rm -f "$tmp"
  if sshd -t 2>/dev/null; then
    systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true
  else
    rm -f "$f"
    echo "ssh hardening not applied: sshd -t refused it"
  fi
  return 0
}

# more hardening (2026-11-03, lynis, US-49): repeated failed ssh logins banned for a while (fail2ban, sshd jail,
# nftables, the station itself never banned), and the network protocols a station never uses (dccp, sctp, rds, tipc)
# kept out of the kernel. Nothing happens when it is already in place.
install_extra_hardening () {
  local f=/etc/modprobe.d/birdsuite-unused-protocols.conf tmp
  tmp=$(mktemp)
  printf '# Managed by BirdSuite (install_helpers.sh install_extra_hardening)\n' > "$tmp"
  for m in dccp sctp rds tipc; do printf 'install %s /bin/false\nblacklist %s\n' "$m" "$m" >> "$tmp"; done
  cmp -s "$tmp" "$f" || install -o root -g root -m 0644 "$tmp" "$f"
  rm -f "$tmp"
  if ! dpkg -s fail2ban > /dev/null 2>&1; then
    DEBIAN_FRONTEND=noninteractive apt-get -y -qq install fail2ban > /dev/null 2>&1 || { echo "fail2ban could not be installed"; return 0; }
  fi
  f=/etc/fail2ban/jail.d/birdsuite.local
  tmp=$(mktemp)
  cat > "$tmp" << 'EOF2'
# Managed by BirdSuite (install_helpers.sh install_extra_hardening)
[DEFAULT]
banaction = nftables-multiport
backend = systemd
ignoreip = 127.0.0.1/8 ::1
bantime = 10m
findtime = 10m
maxretry = 5

[sshd]
enabled = true
EOF2
  if ! cmp -s "$tmp" "$f"; then
    install -o root -g root -m 0644 "$tmp" "$f"
    systemctl restart fail2ban > /dev/null 2>&1 || true
  fi
  rm -f "$tmp"
  systemctl enable --now fail2ban > /dev/null 2>&1 || true
}

# the operating system's security updates by themselves (owner 2026-11-02): Debian's unattended-upgrades with its own
# origins (the Debian and Debian-Security archives; no automatic reboot), the package lists refreshed daily
install_unattended_upgrades () {
  if ! dpkg -s unattended-upgrades > /dev/null 2>&1; then
    apt-get -qq update > /dev/null 2>&1
    DEBIAN_FRONTEND=noninteractive apt-get -y -qq install unattended-upgrades > /dev/null 2>&1 || { echo "unattended-upgrades could not be installed"; return 0; }
  fi
  local f=/etc/apt/apt.conf.d/20auto-upgrades want
  want=$'APT::Periodic::Update-Package-Lists "1";\nAPT::Periodic::Unattended-Upgrade "1";'
  [ "$(cat "$f" 2>/dev/null)" = "$want" ] || printf '%s\n' "$want" > "$f"
  systemctl enable --now unattended-upgrades.service > /dev/null 2>&1 || true
  return 0
}

# the root side: the two helpers (copies, root-owned, so the station user cannot change them), the core definitions
# they read (BS_LIB/core.env and core.sh), the station user's name, the address root fetches its copy of the program
# from, and the sudo rule
install_root_helpers () {
  local src
  src=$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")
  install -d -o root -g root -m 0755 "$BS_LIB"
  printf '%s\n' "$USER" > "$BS_LIB/station_user"
  chmod 0644 "$BS_LIB/station_user"
  install -o root -g root -m 0644 "$src/core.env" "$src/core.sh" "$BS_LIB/"
  install -o root -g root -m 0755 "$src/birdsuite-svc" "$BS_SVC_HELPER"
  install -o root -g root -m 0755 "$src/birdsuite-root" "$BS_ROOT_HELPER"
  record_origin
  install_sudo_rule
}

# the links of the pages and tools to the program folder (the data folder's web root, phpsysinfo, the gotty settings),
# made as the station user; a dangling one there that points into the station user's home (an older place of the
# program) goes first. ~/.bashrc and ~/.gotty are refreshed only when they already are links
install_links () {
  local u="sudo -u $USER" l
  for l in "$BS_EXTRACTED"/* "$BS_EXTRACTED/scripts" "$HOME"/phpsysinfo/* "$HOME"/phpsysinfo/templates/* "$HOME/.bashrc" "$HOME/.gotty" "$BS_SCRIPTS/labels.txt"; do
    [ -L "$l" ] && [ ! -e "$l" ] && [[ $(readlink "$l") == "$BS_HOME"/* ]] && rm -f "$l"
  done
  $u ln -fs "$BS_HOMEPAGE"/* "$BS_EXTRACTED"
  $u ln -fs "$BS_MODEL/labels.txt" "$BS_SCRIPTS"
  $u ln -fs "$BS_SCRIPTS" "$BS_EXTRACTED"
  for l in play.php spectrogram.php overview.php todays_detections.php; do $u ln -fs "$BS_SCRIPTS/$l" "$BS_EXTRACTED"; done
  $u ln -fs "$BS_HOMEPAGE/images/favicon.ico" "$BS_EXTRACTED"
  if [ -d "$HOME/phpsysinfo" ]; then
    $u ln -fs "$HOME/phpsysinfo" "$BS_EXTRACTED"
    $u ln -fs "$BS_TEMPLATES/phpsysinfo.ini" "$HOME/phpsysinfo/"
    $u ln -fs "$BS_TEMPLATES/green_bootstrap.css" "$HOME/phpsysinfo/templates/"
    $u ln -fs "$BS_TEMPLATES/index_bootstrap.html" "$HOME/phpsysinfo/templates/html"
  fi
  [ -L "$HOME/.gotty" ] && $u ln -sfn "$BS_TEMPLATES/gotty" "$HOME/.gotty"
  [ -L "$HOME/.bashrc" ] && $u ln -sfn "$BS_TEMPLATES/bashrc" "$HOME/.bashrc"
  return 0
}

# NetworkManager for the station user (the Network page and the field services run as that user, least privilege)
install_polkit_rule () {
  mkdir -p /etc/polkit-1/rules.d
  cat > /etc/polkit-1/rules.d/50-birdsuite-networkmanager.rules << PKEOF
// BirdSuite (least privilege): the station user manages the network without root
polkit.addRule(function(action, subject) {
  if (action.id.indexOf("org.freedesktop.NetworkManager.") == 0 && subject.user == "$USER") {
    return polkit.Result.YES;
  }
});
PKEOF
  chmod 0644 /etc/polkit-1/rules.d/50-birdsuite-networkmanager.rules
}

install_tmp_mount() {
  STATE=$(systemctl is-enabled tmp.mount 2>&1 | grep -E '(enabled|disabled|static)')
  ! [ -f /usr/share/systemd/tmp.mount ] && echo "Warning: no /usr/share/systemd/tmp.mount found"
  if [ -z $STATE ]; then
    cp -f /usr/share/systemd/tmp.mount /etc/systemd/system/tmp.mount
    systemctl daemon-reload
    systemctl enable tmp.mount
  else
    echo "tmp.mount is $STATE, skipping"
  fi
}

# systemd sandboxing of the station's services (2026-10-23 run 3, HRD-01): one root-owned drop-in per unit, written by
# the installer and refreshed by every update. Conservative on purpose:
# - the services that never use sudo get the full set (no new privileges, read-only system, private /tmp, kernel and
#   clock protected, no set-uid, no namespaces); without a sound device when they do not record or stream
# - the ones that start services or set the clock through sudo (birdsuite-svc / birdsuite-root: microphone hot-plug,
#   field network, Bluetooth set-up) only the protections a root helper does not need
# - the web terminal is a login shell for the administrator (su, sudo): left as it is
hardening_dropin () {
  local unit=$1 level=$2 dir="/etc/systemd/system/$1.d"
  local common="ProtectKernelModules=yes
ProtectKernelLogs=yes
LockPersonality=yes
SystemCallArchitectures=native"
  local body
  case "$level" in
    full|full-devices)
      body="NoNewPrivileges=yes
RestrictSUIDSGID=yes
PrivateTmp=yes
ProtectSystem=full
ProtectKernelTunables=yes
ProtectControlGroups=yes
ProtectClock=yes
ProtectHostname=yes
RestrictNamespaces=yes
RestrictAddressFamilies=AF_UNIX AF_NETLINK AF_INET AF_INET6
$common"
      [ "$level" = full ] && body="$body
PrivateDevices=yes
RestrictRealtime=yes" ;;
    sudo)
      body="ProtectControlGroups=yes
RestrictRealtime=yes
$common" ;;
    *) return 1 ;;
  esac
  mkdir -p "$dir"
  printf '# written by BirdSuite (install_helpers.sh hardening_dropin), refreshed by every update\n[Service]\n%s\n' "$body" > "$dir/10-birdsuite-hardening.conf.new"
  chown root:root "$dir/10-birdsuite-hardening.conf.new"; chmod 0644 "$dir/10-birdsuite-hardening.conf.new"
  if cmp -s "$dir/10-birdsuite-hardening.conf.new" "$dir/10-birdsuite-hardening.conf"; then
    rm -f "$dir/10-birdsuite-hardening.conf.new"; return 1
  fi
  mv -f "$dir/10-birdsuite-hardening.conf.new" "$dir/10-birdsuite-hardening.conf"
}

# returns 0 when a drop-in changed (the caller reloads systemd and restarts what runs)
install_unit_hardening () {
  local changed=1 u
  for u in "$BS_SVC_ANALYSIS" "$BS_SVC_SPECTROGRAM" "$BS_SVC_LOG" "$BS_SVC_LOCATION_CHECK" "$BS_SVC_SOUND_UPLOAD"; do
    hardening_dropin "$u.service" full && changed=0
  done
  for u in "$BS_SVC_RECORDING" "$BS_SVC_LIVESTREAM"; do
    hardening_dropin "$u.service" full-devices && changed=0
  done
  for u in "$BS_SVC_MIC_HOTPLUG" "$BS_SVC_FIELD" "$BS_SVC_BLE" "$BS_SVC_PWRESET"; do
    hardening_dropin "$u.service" sudo && changed=0
  done
  return $changed
}
