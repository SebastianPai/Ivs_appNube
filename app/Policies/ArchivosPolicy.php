<?php

namespace App\Policies;

use App\Models\Archivos;
use App\Models\User;
use App\Services\Documentos\AccesoDocumental;

class ArchivosPolicy
{
    public function __construct(private AccesoDocumental $acceso) {}

    public function view(User $user, Archivos $archivo): bool
    {
        return $this->acceso->puedeVerArchivo($user, $archivo);
    }

    public function download(User $user, Archivos $archivo): bool
    {
        return $this->acceso->puedeDescargar($user, $archivo);
    }

    public function update(User $user, Archivos $archivo): bool
    {
        return $this->acceso->puedeEditarArchivo($user, $archivo);
    }

    public function delete(User $user, Archivos $archivo): bool
    {
        return $this->acceso->puedeEliminar($user);
    }

    public function restore(User $user, Archivos $archivo): bool
    {
        return $this->acceso->puedeEliminar($user);
    }

    public function forceDelete(User $user, Archivos $archivo): bool
    {
        return $this->acceso->puedeEliminar($user);
    }
}
