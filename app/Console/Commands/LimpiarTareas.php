<?php

namespace App\Console\Commands;

use App\Models\TareaDocumental;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class LimpiarTareas extends Command
{
    protected $signature = 'documentos:limpiar-tareas {--horas=24}';

    protected $description = 'Borra los archivos temporales de herramientas PDF con más antigüedad que la indicada';

    public function handle(): int
    {
        $limite = now()->subHours((int) $this->option('horas'));
        $n = 0;

        TareaDocumental::where('created_at', '<', $limite)->whereIn('estado', ['completada', 'error'])->each(function (TareaDocumental $tarea) use (&$n) {
            Storage::disk('local')->deleteDirectory($tarea->directorio());
            $tarea->update(['estado' => 'expirada']);
            $n++;
        });

        $this->info("Tareas expiradas: $n");

        return self::SUCCESS;
    }
}
