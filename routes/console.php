<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('documentos:limpiar-tareas')->hourly();

// Copia de la base de datos y de la organización de carpetas en el bucket, cada madrugada
Schedule::command('documentos:respaldar')->dailyAt('03:00')->withoutOverlapping();

// Texto de los PDF existentes para la búsqueda por contenido, por lotes pequeños
Schedule::command('documentos:indexar-texto --limite=40 --segundos=240')->everyFiveMinutes()->withoutOverlapping(10);

// En cPanel no hay supervisor: el cron de cada minuto procesa la cola y termina antes del siguiente
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->when(fn () => config('documentos.cola_por_cron'));
