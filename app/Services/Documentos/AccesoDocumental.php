<?php

namespace App\Services\Documentos;

use App\Models\Archivos;
use App\Models\Carpeta;
use App\Models\CarpetaPermiso;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Política de acceso central del gestor documental. Toda operación pasa por aquí.
 *
 * - Carpeta abierta (restriccion_id nulo, solo en la unidad compartida): la ve todo usuario con drive.acceder;
 *   puede editarla quien tenga documentos.editar_compartido o un permiso "editar" explícito.
 * - Carpeta restringida: solo la ven quienes tengan un permiso en ella o en un ancestro dentro de la misma
 *   zona de restricción. Una subcarpeta restringida corta la herencia (excepción explícita).
 * - Unidad privada completa: quien tenga unidad_privada.acceder (rol Gerencia) ve las carpetas privadas, con el mismo
 *   nivel que tiene en la compartida (ver, o editar si además tiene documentos.editar_compartido). Las subcarpetas
 *   restringidas a propósito dentro de la privada siguen siendo excepciones y solo las ven quienes tengan permiso en ellas.
 * - Solo los administradores envían a la papelera, restauran, eliminan y gestionan permisos.
 */
class AccesoDocumental
{
    public const NIVEL_VER = 'ver';
    public const NIVEL_EDITAR = 'editar';
    public const NIVEL_ADMIN = 'admin';

    public const PERMISO_UNIDAD_PRIVADA = 'unidad_privada.acceder';

    private const PESOS = [self::NIVEL_VER => 1, self::NIVEL_EDITAR => 2, self::NIVEL_ADMIN => 3];

    public function puedeAcceder(?User $user): bool
    {
        return $user !== null && ($user->esAdmin() || $user->can('drive.acceder'));
    }

    public function esAdmin(?User $user): bool
    {
        return $user !== null && $user->esAdmin();
    }

    /** Nivel efectivo sobre una carpeta: null (sin acceso), ver, editar o admin. */
    public function nivel(User $user, Carpeta $carpeta): ?string
    {
        if (! $this->puedeAcceder($user) || $carpeta->trashed()) {
            return null;
        }

        if ($user->esAdmin()) {
            return self::NIVEL_ADMIN;
        }

        $nivel = null;

        if ($carpeta->restriccion_id === null || $this->accedeUnidadPrivada($user, $carpeta)) {
            $nivel = $user->can('documentos.editar_compartido') ? self::NIVEL_EDITAR : self::NIVEL_VER;
        }

        foreach ($this->nivelesConcedidos($user, $carpeta) as $concedido) {
            $nivel = $this->mayor($nivel, $concedido);
        }

        return $nivel;
    }

    /**
     * El rol entra a la unidad privada: a sus raíces y a todo lo que hereda de ellas.
     * Una subcarpeta restringida a propósito (su zona no empieza en una raíz) queda fuera.
     */
    public function accedeUnidadPrivada(User $user, ?Carpeta $carpeta = null): bool
    {
        if (! $user->can(self::PERMISO_UNIDAD_PRIVADA)) {
            return false;
        }
        if ($carpeta === null) {
            return true;
        }

        return $carpeta->unidad === Carpeta::UNIDAD_PRIVADA
            && $carpeta->restriccion_id !== null
            && Carpeta::whereKey($carpeta->restriccion_id)->whereNull('carpeta_padre_id')->exists();
    }

    public function puedeVerCarpeta(User $user, Carpeta $carpeta): bool
    {
        return $this->nivel($user, $carpeta) !== null;
    }

    /** Editar = subir archivos, nuevas versiones, renombrar y mover dentro de la carpeta. */
    public function puedeEditarEn(User $user, ?Carpeta $carpeta): bool
    {
        // La raíz de cada unidad solo la organiza un administrador
        if ($carpeta === null) {
            return $this->esAdmin($user) && $this->puedeAcceder($user);
        }

        return in_array($this->nivel($user, $carpeta), [self::NIVEL_EDITAR, self::NIVEL_ADMIN], true);
    }

    public function puedeCrearCarpetaEn(User $user, ?Carpeta $padre): bool
    {
        return $this->puedeEditarEn($user, $padre) && ($user->esAdmin() || $user->can('carpetas.crear'));
    }

    public function puedeVerArchivo(User $user, Archivos $archivo): bool
    {
        if (! $this->puedeAcceder($user)) {
            return false;
        }

        if ($user->esAdmin()) {
            return true;
        }

        if ($archivo->trashed() || $archivo->estado !== Archivos::ESTADO_ACTIVO || $archivo->carpeta_id === null) {
            return false;
        }

        $carpeta = $archivo->carpeta;

        return $carpeta !== null && $this->puedeVerCarpeta($user, $carpeta);
    }

    public function puedeDescargar(User $user, Archivos $archivo): bool
    {
        return $this->puedeVerArchivo($user, $archivo) && ($user->esAdmin() || $user->can('documentos.descargar'));
    }

    public function puedeEditarArchivo(User $user, Archivos $archivo): bool
    {
        if ($archivo->trashed()) {
            return false;
        }

        if ($user->esAdmin()) {
            return $this->puedeAcceder($user);
        }

        return $archivo->carpeta_id !== null
            && $this->puedeVerArchivo($user, $archivo)
            && $this->puedeEditarEn($user, $archivo->carpeta);
    }

    /** Mover exige editar en el origen y en el destino; cambiar de unidad solo lo hace un administrador. */
    public function puedeMover(User $user, ?Carpeta $origen, ?Carpeta $destino, string $unidadOrigen, string $unidadDestino): bool
    {
        if ($unidadOrigen !== $unidadDestino && ! $this->esAdmin($user)) {
            return false;
        }

        return $this->puedeEditarEn($user, $origen) && $this->puedeEditarEn($user, $destino);
    }

    /** Papelera, restauración y eliminación definitiva: exclusivamente administradores. */
    public function puedeEliminar(?User $user): bool
    {
        return $this->esAdmin($user);
    }

    public function puedeGestionarPermisos(?User $user): bool
    {
        return $this->esAdmin($user);
    }

    public function autorizar(bool $permitido, string $mensaje = 'No tienes permiso para realizar esta acción.'): void
    {
        if (! $permitido) {
            throw new AuthorizationException($mensaje);
        }
    }

    public function filtrarCarpetas(Builder $query, User $user): Builder
    {
        if (! $this->puedeAcceder($user)) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->esAdmin()) {
            return $query;
        }

        $tabla = $query->getModel()->getTable();
        $roles = $this->idsRoles($user);
        $privadaCompleta = $this->accedeUnidadPrivada($user);

        return $query->where(function (Builder $q) use ($tabla, $user, $roles, $privadaCompleta) {
            $q->whereNull("$tabla.restriccion_id")
                ->when($privadaCompleta, fn ($w) => $w->orWhere(fn ($p) => $p
                    ->where("$tabla.unidad", Carpeta::UNIDAD_PRIVADA)
                    ->whereIn("$tabla.restriccion_id", Carpeta::query()->withTrashed()->select('id')
                        ->where('unidad', Carpeta::UNIDAD_PRIVADA)->whereNull('carpeta_padre_id'))))
                ->orWhereExists(function ($sub) use ($tabla, $user, $roles) {
                    $sub->selectRaw('1')
                        ->from('carpeta_permisos as cp')
                        ->join('carpetas as g', 'g.id', '=', 'cp.carpeta_id')
                        ->whereNull('g.deleted_at')
                        ->whereColumn('g.restriccion_id', "$tabla.restriccion_id")
                        ->whereRaw("$tabla.ruta like ".$this->prefijoLike('g.ruta'))
                        ->where(fn ($w) => $w->where('cp.user_id', $user->id)->orWhereIn('cp.role_id', $roles ?: [0]));
                });
        });
    }

    public function filtrarArchivos(Builder $query, User $user): Builder
    {
        if (! $this->puedeAcceder($user)) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->esAdmin()) {
            return $query;
        }

        $tabla = $query->getModel()->getTable();

        return $query
            ->where("$tabla.estado", Archivos::ESTADO_ACTIVO)
            ->whereIn("$tabla.carpeta_id", Carpeta::query()->visiblePara($user)->select('carpetas.id'));
    }

    /** @return list<string> */
    private function nivelesConcedidos(User $user, Carpeta $carpeta): array
    {
        $roles = $this->idsRoles($user);

        return CarpetaPermiso::query()
            ->join('carpetas as g', 'g.id', '=', 'carpeta_permisos.carpeta_id')
            ->whereNull('g.deleted_at')
            ->whereIn('carpeta_permisos.carpeta_id', $carpeta->idsRuta() ?: [0])
            ->where(function ($q) use ($carpeta) {
                $carpeta->restriccion_id === null
                    ? $q->whereNull('g.restriccion_id')
                    : $q->where('g.restriccion_id', $carpeta->restriccion_id);
            })
            ->where(fn ($q) => $q->where('carpeta_permisos.user_id', $user->id)->orWhereIn('carpeta_permisos.role_id', $roles ?: [0]))
            ->pluck('carpeta_permisos.nivel')
            ->all();
    }

    private function idsRoles(User $user): array
    {
        return $user->roles->pluck('id')->all();
    }

    private function mayor(?string $a, ?string $b): ?string
    {
        return (self::PESOS[$a] ?? 0) >= (self::PESOS[$b] ?? 0) ? $a : $b;
    }

    private function prefijoLike(string $columna): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "($columna || '%')"
            : "concat($columna, '%')";
    }
}
