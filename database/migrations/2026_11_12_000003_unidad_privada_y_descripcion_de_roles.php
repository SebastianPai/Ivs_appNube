<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Rol con acceso a las dos unidades: permiso «unidad_privada.acceder» para Admin y Gerencia,
     * y una descripción en lenguaje sencillo para cada rol. No toca los demás permisos ya configurados.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('descripcion', 500)->nullable()->after('name');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permiso = Permission::findOrCreate('unidad_privada.acceder', 'web');

        foreach ([User::ROL_ADMIN, User::ROL_GERENCIA] as $nombre) {
            Role::where('name', $nombre)->where('guard_name', 'web')->first()?->givePermissionTo($permiso);
        }

        foreach (RolesYPermisosSeeder::DESCRIPCIONES_ROLES as $nombre => $descripcion) {
            Role::where('name', $nombre)->whereNull('descripcion')->update(['descripcion' => $descripcion]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'unidad_privada.acceder')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('descripcion');
        });
    }
};
