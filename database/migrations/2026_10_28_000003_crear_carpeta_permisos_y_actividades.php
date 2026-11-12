<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carpeta_permisos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carpeta_id')->constrained('carpetas')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained('roles')->cascadeOnDelete();
            $table->string('nivel', 20)->default('ver'); // ver | editar
            $table->foreignId('otorgado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'carpeta_id']);
            $table->index(['role_id', 'carpeta_id']);
        });

        Schema::create('actividades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('accion', 60)->index();
            $table->string('sujeto_tipo', 60)->nullable();
            $table->unsignedBigInteger('sujeto_id')->nullable();
            $table->string('descripcion', 500)->nullable();
            $table->string('resultado', 20)->default('ok');
            $table->json('metadatos')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['sujeto_tipo', 'sujeto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actividades');
        Schema::dropIfExists('carpeta_permisos');
    }
};
