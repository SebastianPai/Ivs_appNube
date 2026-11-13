<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad en todas las respuestas (mismo criterio que Ivs_appCerti):
 * - nosniff: el navegador no adivina el tipo de un archivo (evita ejecutar un documento subido como script).
 * - SAMEORIGIN: la app no se puede incrustar en otro sitio (clickjacking); el visor propio sí funciona.
 * - Referrer-Policy: las URL de documentos no se filtran a otros sitios.
 * - Permissions-Policy: la app no usa cámara, micrófono, ubicación ni pagos.
 * - HSTS: solo cuando ya se entra por https (producción), para forzar https en adelante.
 */
class CabecerasSeguridad
{
    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);

        $respuesta->headers->set('X-Content-Type-Options', 'nosniff', false);
        $respuesta->headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $respuesta->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $respuesta->headers->set('Permissions-Policy', 'camera=(), geolocation=(), microphone=(), payment=(), usb=()', false);

        if ($request->isSecure()) {
            $respuesta->headers->set('Strict-Transport-Security', 'max-age=31536000', false);
        }

        return $respuesta;
    }
}
