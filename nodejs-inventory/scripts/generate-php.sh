#!/bin/sh
# Regenerate the PHP message classes from the shared contract after changing
# proto/inventory/v1/inventory.proto. Can be run from any folder:
#
#   sh nodejs-inventory/scripts/generate-php.sh
#
# Uses `protoc` when it is installed, otherwise the copy shipped with the
# grpc-tools npm package (so Node.js alone is enough). The client class
# InventoryServiceClient.php is written by hand and is left untouched: it is a
# thin wrapper that names the four methods of the contract.
set -e

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
OUT="$ROOT/laravel-app/app/Modules/Inventory/Grpc/Proto"
TMP=$(mktemp -d)

if command -v protoc >/dev/null 2>&1; then
  PROTOC="protoc"
else
  PROTOC="npx --yes -p grpc-tools@1.13.1 grpc_tools_node_protoc"
fi

$PROTOC --proto_path="$ROOT/proto" --php_out="$TMP" "$ROOT/proto/inventory/v1/inventory.proto"

# protoc writes App/Modules/Inventory/Grpc/Proto/...; keep only that folder.
find "$OUT" -name '*.php' ! -name 'InventoryServiceClient.php' -delete
cp -R "$TMP/App/Modules/Inventory/Grpc/Proto/." "$OUT/"
rm -rf "$TMP"

echo "PHP classes written to $OUT"
