#!/bin/sh
set -eu
export PGPASSWORD="$POSTGRES_PASSWORD"
psql -h db -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
  -v replication_password="$REPLICATION_PASSWORD" -v reader_password="$DB_READ_PASSWORD" <<'SQL'
SELECT format('CREATE ROLE novacart_replicator LOGIN REPLICATION PASSWORD %L', :'replication_password') WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'novacart_replicator') \gexec
SELECT format('ALTER ROLE novacart_replicator LOGIN REPLICATION PASSWORD %L', :'replication_password') \gexec
SELECT format('CREATE ROLE novacart_reader LOGIN PASSWORD %L', :'reader_password') WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'novacart_reader') \gexec
SELECT format('ALTER ROLE novacart_reader LOGIN PASSWORD %L', :'reader_password') \gexec
ALTER ROLE novacart_reader SET default_transaction_read_only = on;
GRANT CONNECT ON DATABASE :"DBNAME" TO novacart_reader;
GRANT USAGE ON SCHEMA public TO novacart_reader;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO novacart_reader;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO novacart_reader;
SELECT pg_create_physical_replication_slot('novacart_replica') WHERE NOT EXISTS (SELECT FROM pg_replication_slots WHERE slot_name = 'novacart_replica');
SQL
