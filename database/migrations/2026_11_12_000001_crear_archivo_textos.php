<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Texto extraído de los PDF para buscar por contenido. Tabla aparte para no cargarlo en cada listado. */
    public function up(): void
    {
        Schema::create('archivo_textos', function (Blueprint $table) {
            $table->unsignedBigInteger('archivo_id')->primary();
            $table->unsignedBigInteger('version_id')->nullable();
            // indexado | sin_texto (escaneado) | error
            $table->string('estado', 20);
            $table->longText('texto')->nullable();
            $table->timestamps();

            $table->foreign('archivo_id')->references('id')->on('archivos')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archivo_textos');
    }
};
