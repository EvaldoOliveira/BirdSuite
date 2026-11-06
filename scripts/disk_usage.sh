#!/usr/bin/env bash
################################################################################
# scripts/disk_usage.sh - DISK USAGE INDICATOR FOR TMUX
#
# PURPOSE:
#   Disk Usage Indicator for TMUX
#
# DEPENDENCIES:
#   - (none beyond the system)
#
# USAGE:
#   bash scripts/disk_usage.sh
#   Referenced by templates/tmux.conf
################################################################################

df -h / | awk '/\// {print "Disk Size="$2", Used="$3", Available="$4", "$5" of disk used"}'
