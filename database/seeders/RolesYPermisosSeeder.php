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
        'unidad_privada.acceder' => 'Acceder a toda la unidad privada',
        'carpetas.crear' => 'Crear carpetas donde pueda editar',
        'herramientas.pdf' => 'Usar las herramientas PDF',
        'documentos.eliminar' => 'Papelera, restauración y eliminación (solo administradores)',
        'permisos.gestionar' => 'Gestionar permisos de carpetas',
        'recuperacion.ejecutar' => 'Ejecutar la recuperación documental',
        'auditoria.ver' => 'Consultar el historial de actividad',
    ];

    /** Explicación de cada permiso para quien no conoce la aplicación (se muestra al editar un rol). */
    public const AYUDA_PERMISOS = [
        'drive.acceder' => 'Sin este permiso la persona no puede entrar a la aplicación.',
        'documentos.descargar' => 'Puede descargar los archivos que alcanza a ver.',
        'documentos.editar_compartido' => 'Puede subir archivos, crear versiones, renombrar y mover en las carpetas que ve.',
        'unidad_privada.acceder' => 'Ve también la unidad privada completa (documentos internos de la empresa). Sin él, solo ve la unidad compartida.',
        'carpetas.crear' => 'Puede crear carpetas nuevas donde tenga permiso de editar.',
        'herramientas.pdf' => 'Unir, dividir, comprimir, rotar y convertir PDF.',
        'documentos.eliminar' => 'Mandar a la papelera y borrar definitivamente. Solo funciona con el rol Admin.',
        'permisos.gestionar' => 'Decidir quién ve cada carpeta. Solo funciona con el rol Admin.',
        'recuperacion.ejecutar' => 'Revisar e importar los archivos recuperados de la plataforma anterior.',
        'auditoria.ver' => 'Ver quién subió, descargó, movió o borró cada cosa.',
    ];

    /** Qué hace cada rol, en lenguaje sencillo. */
    public const DESCRIPCIONES_ROLES = [
        User::ROL_ADMIN => 'Control total: ve las dos unidades, borra, recupera de la papelera, gestiona usuarios y decide quién ve cada carpeta.',
        User::ROL_GERENCIA => 'Ve la unidad compartida y la unidad privada, y puede subir, organizar y crear carpetas. No borra ni gestiona usuarios.',
        User::ROL_USUARIO => 'Solo ve la unidad compartida: consulta y descarga documentos. No sube ni ve la unidad privada.',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(self::PERMISOS) as $nombre) {
            Permission::findOrCreate($nombre, 'web');
        }

        Role::findOrCreate(User::ROL_ADMIN, 'web')->syncPermissions(array_keys(self::PERMISOS));

        Role::findOrCreate(User::ROL_GERENCIA, 'web')->syncPermissions([
            'drive.acceder', 'documentos.descargar', 'documentos.editar_compartido', 'unidad_privada.acceder', 'carpetas.crear', 'herramientas.pdf',
        ]);

        Role::findOrCreate(User::ROL_USUARIO, 'web')->syncPermissions([
            'drive.acceder', 'documentos.descargar', 'herramientas.pdf',
        ]);

        foreach (self::DESCRIPCIONES_ROLES as $rol => $descripcion) {
            Role::where('name', $rol)->whereNull('descripcion')->update(['descripcion' => $descripcion]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
