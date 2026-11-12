<?php

use App\Http\Controllers\ArchivoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/inicio');
});

Route::middleware(['auth', 'throttle:120,1'])->prefix('documentos')->name('documentos.')->group(function () {
    Route::get('/archivos/{archivo}/descargar', [ArchivoController::class, 'descargar'])->name('descargar');
    Route::get('/archivos/{archivo}/contenido', [ArchivoController::class, 'contenido'])->name('contenido');
    Route::get('/archivos/{archivo}/visor', [ArchivoController::class, 'visor'])->name('visor');
    Route::get('/tareas/{tarea}/resultado', [ArchivoController::class, 'resultadoTarea'])->name('tarea.resultado');
});
