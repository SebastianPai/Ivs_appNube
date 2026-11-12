<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventario_ejecuciones', function (Blueprint $table) {
            $table->id();
            $table->string('disco', 50);
            $table->string('prefijo', 500)->nullable();
            $table->string('estado', 20)->default('en_curso'); // en_curso | completado | error
            $table->unsignedInteger('procesados')->default(0);
            $table->unsignedInteger('nuevos')->default(0);
            $table->unsignedInteger('actualizados')->default(0);
            // Último objeto procesado: permite reanudar un inventario interrumpido
            $table->string('ultimo_objeto', 700)->nullable();
            $table->text('error')->nullable();
            $table->foreignId('iniciado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalizado_en')->nullable();
            $table->timestamps();
        });

        Schema::create('recuperacion_grupos', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 30); // carpeta_fisica | patron_numerico | individual | sin_clasificar
            $table->string('clave', 300);
            $table->unsignedTinyInteger('confianza')->default(0); // 0-100
            $table->string('motivo', 500)->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->string('estado', 20)->default('propuesto'); // propuesto | importado | descartado
            $table->foreignId('carpeta_destino_id')->nullable()->constrained('carpetas')->nullOnDelete();
            // true si la importación creó la carpeta destino: al revertir se elimina solo si quedó vacía
            $table->boolean('carpeta_creada')->default(false);
            $table->uuid('lote')->nullable()->index();
            $table->foreignId('revisado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revisado_en')->nullable();
            $table->timestamps();

            $table->unique(['tipo', 'clave']);
        });

        Schema::create('inventario_objetos', function (Blueprint $table) {
            $table->id();
            $table->string('disco', 50);
            $table->string('objeto', 700);
            $table->string('directorio', 500)->nullable();
            $table->string('nombre');
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('tamano')->default(0);
            $table->string('mime', 150)->nullable();
            $table->string('hash_md5', 64)->nullable()->index();
            $table->timestamp('modificado_en')->nullable();
            $table->json('metadatos')->nullable();
            $table->string('clave_patron', 100)->nullable()->index();
            $table->unsignedInteger('sufijo')->nullable();
            $table->foreignId('grupo_id')->nullable()->constrained('recuperacion_grupos')->nullOnDelete();
            $table->foreignId('duplicado_de_id')->nullable()->constrained('inventario_objetos')->nullOnDelete();
            $table->foreignId('archivo_id')->nullable()->constrained('archivos')->nullOnDelete();
            $table->string('estado', 20)->default('pendiente')->index();
            $table->foreignId('ejecucion_id')->nullable()->constrained('inventario_ejecuciones')->nullOnDelete();
            $table->timestamps();

            $table->unique(['disco', 'objeto']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario_objetos');
        Schema::dropIfExists('recuperacion_grupos');
        Schema::dropIfExists('inventario_ejecuciones');
    }
};
