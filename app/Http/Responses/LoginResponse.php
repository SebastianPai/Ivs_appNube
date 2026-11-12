<?php

namespace App\Http\Responses;

use App\Filament\Pages\Unidad;
use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LoginResponse as Responsable;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * Tras iniciar sesión se entra siempre al gestor documental.
 * Solo se respeta la URL pendiente cuando era un enlace a un documento (visor o descarga),
 * para que un enlace compartido siga funcionando después del login.
 */
class LoginResponse implements Responsable
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        $pendiente = session()->pull('url.intended');

        if (is_string($pendiente) && str_starts_with((string) parse_url($pendiente, PHP_URL_PATH), '/documentos/')) {
            return redirect()->to($pendiente);
        }

        return redirect()->to(Unidad::canAccess() ? Unidad::getUrl() : Filament::getUrl());
    }
}
