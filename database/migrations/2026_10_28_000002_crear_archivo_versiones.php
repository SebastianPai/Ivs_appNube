<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archivo_versiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archivo_id')->constrained('archivos')->restrictOnDelete();
            $table->unsignedInteger('numero');
            // Cada versión apunta a un objeto inmutable: nunca se sobrescribe ni se reutiliza su ruta
            $table->string('disco', 50);
            $table->string('objeto', 700);
            $table->unsignedBigInteger('tamano')->default(0);
            $table->string('hash_md5', 64)->nullable();
            $table->string('mime', 150)->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comentario')->nullable();
            $table->foreignId('restaurada_desde_id')->nullable()->constrained('archivo_versiones')->nullOnDelete();
            $table->json('metadatos')->nullable();
            $table->timestamps();

            $table->unique(['archivo_id', 'numero']);
            $table->index(['disco', 'objeto']);
        });

        Schema::table('archivos', function (Blueprint $table) {
            $table->foreign('version_actual_id')->references('id')->on('archivo_versiones')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('archivos', function (Blueprint $table) {
            $table->dropForeign(['version_actual_id']);
        });

        Schema::dropIfExists('archivo_versiones');
    }
};
