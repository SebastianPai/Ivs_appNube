<?php

namespace App\Http\Middleware;

use App\Filament\Pages\CambiarContrasena;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Mientras el usuario tenga la contraseña inicial, solo puede ver la página para cambiarla o cerrar sesión. */
class ExigirCambioPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->debe_cambiar_password
            && ! $request->routeIs(CambiarContrasena::getRouteName(), 'filament.*.auth.logout')) {
            return redirect()->to(CambiarContrasena::getUrl());
        }

        return $next($request);
    }
}
