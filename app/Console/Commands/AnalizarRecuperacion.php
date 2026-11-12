<?php

namespace App\Console\Commands;

use App\Services\Documentos\Recuperacion\AnalizadorRecuperacion;
use App\Services\Documentos\Recuperacion\InformeRecuperacion;
use Illuminate\Console\Command;

class AnalizarRecuperacion extends Command
{
    protected $signature = 'documentos:analizar';

    protected $description = 'Propone agrupaciones del inventario y genera el informe (no aplica cambios)';

    public function handle(AnalizadorRecuperacion $analizador, InformeRecuperacion $informe): int
    {
        $resumen = $analizador->analizar();
        $this->table(array_keys($resumen), [array_values($resumen)]);

        $rutas = $informe->generar();
        $this->info('Informe: '.storage_path('app/'.$rutas['markdown']));
        $this->info('Inventario CSV: '.storage_path('app/'.$rutas['csv']));

        return self::SUCCESS;
    }
}
