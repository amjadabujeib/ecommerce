#!/bin/sh
set -eu
# Use the runtime's own DNS (Docker and Podman use different resolver addresses).
resolvers="$(awk '$1 == "nameserver" && $2 ~ /^[0-9a-fA-F:.]+$/ { if (index($2, ":")) printf "[%s] ", $2; else printf "%s ", $2 }' /etc/resolv.conf)"
if [ -z "$resolvers" ]; then
    echo 'No container DNS resolver found for the PHP upstream.' >&2
    exit 1
fi
cat > /etc/nginx/conf.d/00-novacart-resolver.conf <<CONFIG
resolver ${resolvers} valid=5s ipv6=off;
resolver_timeout 2s;
CONFIG
