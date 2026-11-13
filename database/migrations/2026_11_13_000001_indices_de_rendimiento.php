<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * - FULLTEXT en el texto de los PDF: la búsqueda por contenido deja de recorrer todo el texto con LIKE '%…%'.
     * - (deleted_at, updated_at) en archivos: «Recientes» ordena por fecha sin recorrer la tabla.
     * SQLite (pruebas) no tiene FULLTEXT: ahí se sigue usando LIKE.
     */
    public function up(): void
    {
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('archivo_textos', fn (Blueprint $table) => $table->fullText('texto', 'archivo_textos_texto_fulltext'));
        }

        Schema::table('archivos', fn (Blueprint $table) => $table->index(['deleted_at', 'updated_at'], 'archivos_recientes_index'));
    }

    public function down(): void
    {
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('archivo_textos', fn (Blueprint $table) => $table->dropFullText('archivo_textos_texto_fulltext'));
        }

        Schema::table('archivos', fn (Blueprint $table) => $table->dropIndex('archivos_recientes_index'));
    }
};
