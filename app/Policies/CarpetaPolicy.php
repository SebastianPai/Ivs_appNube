<?php

namespace App\Policies;

use App\Models\Carpeta;
use App\Models\User;
use App\Services\Documentos\AccesoDocumental;

class CarpetaPolicy
{
    public function __construct(private AccesoDocumental $acceso) {}

    public function view(User $user, Carpeta $carpeta): bool
    {
        return $this->acceso->puedeVerCarpeta($user, $carpeta);
    }

    public function update(User $user, Carpeta $carpeta): bool
    {
        return $this->acceso->puedeEditarEn($user, $carpeta);
    }

    public function delete(User $user, Carpeta $carpeta): bool
    {
        return $this->acceso->puedeEliminar($user);
    }

    public function restore(User $user, Carpeta $carpeta): bool
    {
        return $this->acceso->puedeEliminar($user);
    }

    public function forceDelete(User $user, Carpeta $carpeta): bool
    {
        return $this->acceso->puedeEliminar($user);
    }

    public function gestionarPermisos(User $user, Carpeta $carpeta): bool
    {
        return $this->acceso->puedeGestionarPermisos($user);
    }
}
