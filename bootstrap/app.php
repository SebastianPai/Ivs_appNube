<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // No hay ruta "login" propia: el acceso es el de Filament
        $middleware->redirectGuestsTo(fn () => route('filament.inicio.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
