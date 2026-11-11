#!/bin/sh
set -eu
mkdir -p "$PGDATA"
chown postgres:postgres "$PGDATA"
chmod 700 "$PGDATA"
if [ ! -s "$PGDATA/PG_VERSION" ]; then
  export PGPASSWORD="$REPLICATION_PASSWORD"
  gosu postgres pg_basebackup -h db -U novacart_replicator -D "$PGDATA" -Fp -X stream -R -S novacart_replica
  unset PGPASSWORD
fi
exec gosu postgres postgres -c hot_standby=on
