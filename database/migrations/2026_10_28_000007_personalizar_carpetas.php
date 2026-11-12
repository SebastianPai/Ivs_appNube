<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Color e ícono elegidos por los usuarios (valores de una lista cerrada en config/documentos.php)
    public function up(): void
    {
        Schema::table('carpetas', function (Blueprint $table) {
            $table->string('color', 20)->nullable()->after('descripcion');
            $table->string('icono', 40)->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('carpetas', function (Blueprint $table) {
            $table->dropColumn(['color', 'icono']);
        });
    }
};
