#!/usr/bin/env bash
# The bash reader of core.env (owner 2026-11-02): sets every BS_* key of that file as a shell variable, read as text
# (no expansion but ${KEY} of an earlier key, no command), so root can read it too. Sourced by paths.sh and, from root's
# own copies in BS_LIB, by the root helpers.
# Seeds: BS_HOME from the environment (root names the station user's home), else the program folder is the one above
# this file and BS_HOME the folder above that. BS_CORE_ENV: another core.env (root's copy).
bs_core_env=${BS_CORE_ENV:-$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/core.env}
if [ -z "${BS_HOME:-}" ]; then
  BS_PROG=$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/.." && pwd)
  BS_HOME=$(dirname "$BS_PROG")
fi
if [ -r "$bs_core_env" ]; then
  while IFS= read -r bs_line || [ -n "$bs_line" ]; do
    [[ $bs_line =~ ^(BS_[A-Z0-9_]+)=(.*)$ ]] || continue
    bs_k=${BASH_REMATCH[1]}; bs_v=${BASH_REMATCH[2]}
    [ -n "${!bs_k:-}" ] && continue
    while [[ $bs_v =~ \$\{(BS_[A-Z0-9_]+)\} ]]; do
      bs_r=${BASH_REMATCH[1]}
      bs_v=${bs_v//"\${$bs_r}"/${!bs_r:-}}
    done
    printf -v "$bs_k" '%s' "$bs_v"
  done < "$bs_core_env"
else
  echo "core.sh: $bs_core_env not found" >&2
fi
unset bs_line bs_k bs_v bs_r bs_core_env

# bs_render SRC: SRC with every @BS_KEY@ replaced by the key's value and @USER@ / @GROUP@ by the station user and its
# group (USER must be the station user), on stdout. For the templates (units, cron, sudoers) the installer and the
# update install as root.
bs_render() {
  local line out k
  while IFS= read -r line || [ -n "$line" ]; do
    out=$line
    while [[ $out =~ @(BS_[A-Z0-9_]+)@ ]]; do
      k=${BASH_REMATCH[1]}
      out=${out//"@$k@"/${!k:-}}
    done
    out=${out//@USER@/$USER}
    out=${out//@GROUP@/$(id -gn "$USER" 2>/dev/null || echo "$USER")}
    printf '%s\n' "$out"
  done < "$1"
}
