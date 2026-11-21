#!/bin/bash
# Builds module_wailo-<version>.zip with the module folder "wailo/" at the root (DoliStore packaging rule).
set -euo pipefail
cd "$(dirname "$0")"
V=$(sed -n "s/.*->version = '\([0-9.]*\)'.*/\1/p" wailo/core/modules/modWailo.class.php)
Z="module_wailo-$V.zip"
[ -f "$Z" ] && trash "$Z"
zip -q -r -X "$Z" wailo -x '*.DS_Store' -x '*/.git*'
echo "$Z $(wc -c < "$Z") octets, sha256 $(shasum -a 256 "$Z" | cut -c1-16)"
unzip -l "$Z" | tail -1
