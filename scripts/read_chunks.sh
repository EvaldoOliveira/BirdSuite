#!/usr/bin/env bash
################################################################################
# scripts/read_chunks.sh - READ FROM A PIPE A SET NUMBER OF TIMES
#
# PURPOSE:
#   Read from pipe a set number of times
#
# DEPENDENCIES:
#   - (none beyond the system)
#
# USAGE:
#   bash scripts/read_chunks.sh
#   Referenced by scripts/restore.php
################################################################################

usage() { echo "Usage: $0 -n <chunks> -f <pipe>" 1>&2; exit 1; }

unset -v ACTION
unset -v ARCHIVE
unset -v QUIET
while getopts "n:f:" o; do
	case "${o}" in
		n)
			COUNT=${OPTARG}
			;;
		f)
			PIPE=${OPTARG}
			;;
		*)
			usage
			;;
	esac
done

[ -z "$COUNT" ] && usage && exit 1
[ -z "$PIPE" ] && usage && exit 1
! [ -p $PIPE ] && echo "Not a pipe" && exit 1

while [ $COUNT -gt 0 ]
do
	cat $PIPE
	COUNT=$(( $COUNT - 1 ))
done
