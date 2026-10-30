#!/usr/bin/env bash
# System hardening of a fresh install (owner 2026-10-23): an inbound firewall of the station's own (nftables, table
# inet birdsuite), automatic security updates (Debian security archive only, never an automatic reboot) and no root
# login over ssh. Run as root by install_services.sh; the updater does not run it, existing stations keep what they have.
# Undo: systemctl disable --now birdsuite-firewall; rm /etc/apt/apt.conf.d/5{2,3}birdsuite-*; rm /etc/ssh/sshd_config.d/10-birdsuite.conf
my_dir=${my_dir:-$(realpath "$(dirname "$0")/..")}

apt-get install -y --no-install-recommends nftables unattended-upgrades || { echo "hardening: packages not installed" >&2; exit 1; }

# firewall: a root-owned copy of the rules (the station user cannot change what root loads) and a unit that loads them
# before the network is up; its own unit and not nftables.service, whose /etc/nftables.conf flushes every table
install -o root -g root -m 0644 "$my_dir/templates/birdsuite-firewall.nft" /etc/birdsuite-firewall.nft
cat > /usr/lib/systemd/system/birdsuite-firewall.service << 'UNIT'
[Unit]
Description=BirdSuite inbound firewall (nftables table inet birdsuite)
DefaultDependencies=no
Before=network-pre.target
Wants=network-pre.target
After=local-fs.target

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/sbin/nft -f /etc/birdsuite-firewall.nft
ExecReload=/usr/sbin/nft -f /etc/birdsuite-firewall.nft
ExecStop=/usr/sbin/nft delete table inet birdsuite

[Install]
WantedBy=sysinit.target
UNIT
systemctl daemon-reload
systemctl enable --now birdsuite-firewall.service || echo "hardening: firewall not started" >&2

# automatic updates: the security archive only (the BirdSuite program, Caddy and Python packages are updated by the
# station's own update), downloaded and installed daily, no reboot
cat > /etc/apt/apt.conf.d/52birdsuite-unattended << 'APT'
// BirdSuite (2026-10-23): security updates only, never an automatic reboot
#clear Unattended-Upgrade::Origins-Pattern;
Unattended-Upgrade::Origins-Pattern {
  "origin=Debian,codename=${distro_codename}-security,label=Debian-Security";
  "origin=Debian,codename=${distro_codename},label=Debian-Security";
};
Unattended-Upgrade::Automatic-Reboot "false";
Unattended-Upgrade::Remove-Unused-Dependencies "false";
APT
cat > /etc/apt/apt.conf.d/53birdsuite-periodic << 'APT'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT
systemctl enable --now unattended-upgrades.service apt-daily.timer apt-daily-upgrade.timer 2> /dev/null

# ssh: no root login (the first user and its sudo stay the way in); password login is left as the image set it.
# 10- sorts before the image's own drop-ins, and sshd keeps the first value it reads
printf '# BirdSuite (2026-10-23)\nPermitRootLogin no\n' > /etc/ssh/sshd_config.d/10-birdsuite.conf
chmod u=rw,go=r /etc/ssh/sshd_config.d/10-birdsuite.conf
if sshd -t; then
  systemctl reload ssh.service 2> /dev/null || systemctl reload sshd.service 2> /dev/null
else
  rm -f /etc/ssh/sshd_config.d/10-birdsuite.conf
  echo "hardening: sshd rejected the drop-in, left out" >&2
fi
exit 0
