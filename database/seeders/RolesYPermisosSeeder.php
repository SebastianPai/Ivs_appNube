<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** Idempotente: se puede ejecutar varias veces sin duplicar roles ni permisos. */
class RolesYPermisosSeeder extends Seeder
{
    public const PERMISOS = [
        'drive.acceder' => 'Entrar al gestor documental',
        'documentos.descargar' => 'Descargar documentos autorizados',
        'documentos.editar_compartido' => 'Subir y organizar en la unidad compartida',
        'carpetas.crear' => 'Crear carpetas donde pueda editar',
        'herramientas.pdf' => 'Usar las herramientas PDF',
        'documentos.eliminar' => 'Papelera, restauración y eliminación (solo administradores)',
        'permisos.gestionar' => 'Gestionar permisos de carpetas',
        'recuperacion.ejecutar' => 'Ejecutar la recuperación documental',
        'auditoria.ver' => 'Consultar el historial de actividad',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(self::PERMISOS) as $nombre) {
            Permission::findOrCreate($nombre, 'web');
        }

        Role::findOrCreate(User::ROL_ADMIN, 'web')->syncPermissions(array_keys(self::PERMISOS));

        Role::findOrCreate(User::ROL_GERENCIA, 'web')->syncPermissions([
            'drive.acceder', 'documentos.descargar', 'documentos.editar_compartido', 'carpetas.crear', 'herramientas.pdf',
        ]);

        Role::findOrCreate(User::ROL_USUARIO, 'web')->syncPermissions([
            'drive.acceder', 'documentos.descargar', 'herramientas.pdf',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
