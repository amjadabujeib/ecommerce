<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('read_fence', 32)->nullable());
        Schema::create('catalog_versions', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('version');
        });
        DB::table('catalog_versions')->insert(['id' => 1, 'version' => 1]);
        // Database triggers make version changes atomic with catalog writes,
        // including bulk updates and commits made outside the HTTP process.
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

    public function down(): void
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
        Schema::dropIfExists('catalog_versions');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('read_fence'));
    }
};
