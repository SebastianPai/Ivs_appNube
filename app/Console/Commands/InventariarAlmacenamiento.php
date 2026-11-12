<?php

namespace App\Console\Commands;

use App\Services\Documentos\Recuperacion\AnalizadorRecuperacion;
use App\Services\Documentos\Recuperacion\InformeRecuperacion;
use App\Services\Documentos\Recuperacion\InventarioAlmacenamiento;
use Illuminate\Console\Command;

class InventariarAlmacenamiento extends Command
{
    protected $signature = 'documentos:inventario
        {--disco= : Disco a inventariar (por defecto DOCUMENTOS_DISCO_RECUPERACION)}
        {--prefijo= : Solo objetos bajo esta ruta}
        {--reanudar : Continúa la última ejecución interrumpida}
        {--sin-checksums : No consulta el md5 de cada objeto (más rápido, sin detección de duplicados)}
        {--analizar : Al terminar, propone agrupaciones y genera el informe}';

    protected $description = 'Inventario de solo lectura del almacenamiento (no modifica ningún objeto)';

    public function handle(InventarioAlmacenamiento $inventario, AnalizadorRecuperacion $analizador, InformeRecuperacion $informe): int
    {
        $disco = $this->option('disco') ?: config('documentos.disco_recuperacion');
        $this->info("Inventariando «{$disco}» en modo solo lectura…");

        $ejecucion = $inventario->ejecutar(
            $disco,
            $this->option('prefijo'),
            null,
            (bool) $this->option('reanudar'),
            ! $this->option('sin-checksums'),
            fn ($e) => $this->line("  {$e->procesados} objetos procesados…")
        );

        $this->table(['Procesados', 'Nuevos', 'Actualizados', 'Estado'], [[$ejecucion->procesados, $ejecucion->nuevos, $ejecucion->actualizados, $ejecucion->estado]]);

        if ($this->option('analizar')) {
            $resumen = $analizador->analizar();
            $this->table(array_keys($resumen), [array_values($resumen)]);
            $rutas = $informe->generar();
            $this->info('Informe: '.storage_path('app/'.$rutas['markdown']));
            $this->info('Inventario CSV: '.storage_path('app/'.$rutas['csv']));
        }

        return self::SUCCESS;
    }
}
