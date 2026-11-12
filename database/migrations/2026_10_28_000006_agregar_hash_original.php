<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // md5 del archivo tal como lo subió el usuario (antes de la compresión automática), para detectar duplicados
    public function up(): void
    {
        Schema::table('archivos', function (Blueprint $table) {
            $table->string('hash_original', 64)->nullable()->index()->after('hash_md5');
        });

        Schema::table('archivo_versiones', function (Blueprint $table) {
            $table->string('hash_original', 64)->nullable()->after('hash_md5');
        });
    }

    public function down(): void
    {
        Schema::table('archivo_versiones', function (Blueprint $table) {
            $table->dropColumn('hash_original');
        });

        Schema::table('archivos', function (Blueprint $table) {
            $table->dropIndex(['hash_original']);
            $table->dropColumn('hash_original');
        });
    }
};
