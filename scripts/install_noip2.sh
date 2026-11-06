#!/usr/bin/env bash
################################################################################
# scripts/install_noip2.sh - THE NOIP DYNAMIC UPDATE CLIENT
#
# PURPOSE:
#   This scripts installs NoIP's Dynamic Update Client (DUC)
#
# DEPENDENCIES:
#   - (none beyond the system)
#
# USAGE:
#   bash scripts/install_noip2.sh
################################################################################

cd /usr/local/src && wget https://www.noip.com/client/linux/noip-duc-linux.tar.gz
tar -vzxf noip-duc-linux.tar.gz
cd noip-2*
make
make install
chmod a+wr /usr/local/etc/no-ip2.conf
sed -i '/^exit 0$/i \/usr\/local\/bin\/noip2' /etc/rc.local
noip2 -S
