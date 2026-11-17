<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropVersionTriggers();
        Schema::dropIfExists('catalog_versions');
        Schema::create('catalog_cache_state', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->boolean('needs_invalidation')->default(true);
        });
        DB::table('catalog_cache_state')->insert(['id' => 1, 'needs_invalidation' => true]);
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE FUNCTION dirty_catalog_cache() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_advisory_xact_lock(734210617::bigint); UPDATE catalog_cache_state SET needs_invalidation=true WHERE id=1; RETURN NULL; END; $$');
            foreach (['products', 'categories'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_catalog_dirty BEFORE INSERT OR UPDATE OR DELETE OR TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION dirty_catalog_cache()");
            }
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (['products', 'categories'] as $table) {
                foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
                    $name = strtolower($table.'_catalog_dirty_'.$event);
                    DB::unprepared("CREATE TRIGGER {$name} AFTER {$event} ON {$table} BEGIN UPDATE catalog_cache_state SET needs_invalidation=1 WHERE id=1; END");
                }
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['products', 'categories'] as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_catalog_dirty ON {$table}");
            }
            DB::unprepared('DROP FUNCTION IF EXISTS dirty_catalog_cache()');
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (['products', 'categories'] as $table) {
                foreach (['insert', 'update', 'delete'] as $event) {
                    DB::unprepared("DROP TRIGGER IF EXISTS {$table}_catalog_dirty_{$event}");
                }
            }
        }
        Schema::dropIfExists('catalog_cache_state');
        Schema::create('catalog_versions', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('version');
        });
        // Avoid reusing any keys left by a previously running versioned deployment.
        DB::table('catalog_versions')->insert(['id' => 1, 'version' => (int) floor(microtime(true) * 1000000)]);
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE FUNCTION bump_catalog_version() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN UPDATE catalog_versions SET version=version+1 WHERE id=1; RETURN NULL; END; $$');
            foreach (['products', 'categories'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_catalog_version AFTER INSERT OR UPDATE OR DELETE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION bump_catalog_version()");
            }
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (['products', 'categories'] as $table) {
                foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
                    $name = strtolower($table.'_catalog_'.$event);
                    DB::unprepared("CREATE TRIGGER {$name} AFTER {$event} ON {$table} BEGIN UPDATE catalog_versions SET version=version+1 WHERE id=1; END");
                }
            }
        }
    }

    private function dropVersionTriggers(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['products', 'categories'] as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_catalog_version ON {$table}");
            }
            DB::unprepared('DROP FUNCTION IF EXISTS bump_catalog_version()');
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (['products', 'categories'] as $table) {
                foreach (['insert', 'update', 'delete'] as $event) {
                    DB::unprepared("DROP TRIGGER IF EXISTS {$table}_catalog_{$event}");
                }
            }
        }
    }
};
