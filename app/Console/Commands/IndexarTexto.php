<?php

namespace App\Console\Commands;

use App\Services\Documentos\Busqueda\AlmacenamientoNoDisponible;
use App\Services\Documentos\Busqueda\IndexadorTexto;
use Illuminate\Console\Command;

/** Indexa por lotes los PDF que aún no tienen texto (los existentes y los recuperados del bucket). */
class IndexarTexto extends Command
{
    protected $signature = 'documentos:indexar-texto {--limite=40 : Máximo de PDF por ejecución} {--segundos=240 : Tiempo máximo}';

    protected $description = 'Extrae el texto de los PDF pendientes para la búsqueda por contenido';

    public function handle(IndexadorTexto $indexador): int
    {
        $inicio = microtime(true);
        $hechos = 0;
        $pendientes = $indexador->pendientes()->count();

        $lote = $indexador->pendientes()->with('versionActual')->orderBy('id')->limit((int) $this->option('limite'))->get();
        $fallosSeguidos = 0;
        foreach ($lote as $archivo) {
            try {
                $resultado = $indexador->indexar($archivo);
                $fallosSeguidos = 0;
            } catch (AlmacenamientoNoDisponible $e) {
                $this->warn(sprintf('%5d  sin acceso  %s', $archivo->id, $archivo->nombre));
                if (++$fallosSeguidos >= 3) {
                    $this->error('El almacenamiento no responde; se reintentará en la próxima ejecución. '.mb_substr($e->getMessage(), 0, 200));

                    return self::FAILURE;
                }

                continue;
            }
            $hechos++;
            $this->line(sprintf('%5d  %-10s %s', $archivo->id, $resultado?->estado ?? '-', $archivo->nombre));

            if (microtime(true) - $inicio > (int) $this->option('segundos')) {
                break;
            }
        }

        $this->info("Indexados: $hechos. Pendientes antes de esta ejecución: $pendientes.");

        return self::SUCCESS;
    }
}
