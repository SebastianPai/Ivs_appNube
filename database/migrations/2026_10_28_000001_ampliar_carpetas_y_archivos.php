<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carpetas', function (Blueprint $table) {
            // El borrado en cascada eliminaba subcarpetas sin control; ahora se purga explícitamente desde la app
            $table->dropForeign(['carpeta_padre_id']);
            $table->foreign('carpeta_padre_id')->references('id')->on('carpetas')
                ->cascadeOnUpdate()->restrictOnDelete();

            $table->string('unidad', 20)->default('compartida')->index();
            $table->boolean('restringida')->default(false);
            $table->foreignId('restriccion_id')->nullable()->constrained('carpetas')->nullOnDelete();
            $table->string('ruta', 700)->default('')->index();
            $table->text('descripcion')->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('papelera_lote')->nullable()->index();
            $table->boolean('papelera_raiz')->default(false);
            $table->foreignId('eliminado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->index(['carpeta_padre_id', 'nombre']);
        });

        Schema::table('archivos', function (Blueprint $table) {
            $table->dropForeign(['carpeta_id']);
        });

        Schema::table('archivos', function (Blueprint $table) {
            // Los archivos recuperados sin clasificar todavía no tienen carpeta
            $table->unsignedBigInteger('carpeta_id')->nullable()->change();
            $table->foreign('carpeta_id')->references('id')->on('carpetas')
                ->cascadeOnUpdate()->restrictOnDelete();

            $table->uuid('uuid')->nullable()->unique();
            $table->string('nombre_original')->nullable();
            $table->string('extension', 20)->nullable()->index();
            $table->string('mime', 150)->nullable();
            $table->unsignedBigInteger('tamano')->default(0);
            $table->string('hash_md5', 64)->nullable()->index();
            $table->string('identificador_documental', 100)->nullable()->index();
            $table->string('estado', 30)->default('activo')->index();
            $table->string('origen', 20)->default('subida');
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('version_actual_id')->nullable();
            $table->uuid('papelera_lote')->nullable()->index();
            $table->boolean('papelera_raiz')->default(false);
            $table->foreignId('eliminado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->index(['carpeta_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::table('archivos', function (Blueprint $table) {
            $table->dropForeign(['carpeta_id']);
            $table->dropForeign(['subido_por']);
            $table->dropForeign(['eliminado_por']);
            $table->dropIndex(['carpeta_id', 'nombre']);
            $table->dropUnique(['uuid']);
            $table->dropIndex(['extension']);
            $table->dropIndex(['hash_md5']);
            $table->dropIndex(['identificador_documental']);
            $table->dropIndex(['estado']);
            $table->dropIndex(['papelera_lote']);
            $table->dropColumn([
                'uuid', 'nombre_original', 'extension', 'mime', 'tamano', 'hash_md5',
                'identificador_documental', 'estado', 'origen', 'subido_por', 'version_actual_id',
                'papelera_lote', 'papelera_raiz', 'eliminado_por', 'deleted_at',
            ]);
        });

        Schema::table('archivos', function (Blueprint $table) {
            $table->unsignedBigInteger('carpeta_id')->nullable(false)->change();
            $table->foreign('carpeta_id')->references('id')->on('carpetas')
                ->onUpdate('cascade')->onDelete('cascade');
        });

        Schema::table('carpetas', function (Blueprint $table) {
            $table->dropForeign(['carpeta_padre_id']);
            $table->dropForeign(['restriccion_id']);
            $table->dropForeign(['creado_por']);
            $table->dropForeign(['eliminado_por']);
            $table->dropIndex(['carpeta_padre_id', 'nombre']);
            $table->dropIndex(['unidad']);
            $table->dropIndex(['ruta']);
            $table->dropIndex(['papelera_lote']);
            $table->dropColumn([
                'unidad', 'restringida', 'restriccion_id', 'ruta', 'descripcion', 'creado_por',
                'papelera_lote', 'papelera_raiz', 'eliminado_por', 'deleted_at',
            ]);
            $table->foreign('carpeta_padre_id')->references('id')->on('carpetas')
                ->onUpdate('cascade')->onDelete('cascade');
        });
    }
};
