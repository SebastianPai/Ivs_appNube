<?php

namespace App\Jobs;

use App\Models\Archivos;
use App\Services\Documentos\Busqueda\AlmacenamientoNoDisponible;
use App\Services\Documentos\Busqueda\IndexadorTexto;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Indexa el texto de un PDF recién subido o con nueva versión. */
class IndexarTextoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** Espera antes de reintentar si el almacenamiento falla (el comando por lotes también lo recoge). */
    private array $backoff = [300, 1800];

    public int $timeout = 120;

    public function __construct(public int $archivoId) {}

    public function handle(IndexadorTexto $indexador): void
    {
        $archivo = Archivos::find($this->archivoId);
        if (! $archivo) {
            return;
        }

        try {
            $indexador->indexar($archivo);
        } catch (AlmacenamientoNoDisponible) {
            // Nunca rompe la subida: se reintenta más tarde y, si no, lo recoge el comando por lotes
            if ($this->job && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 1800);
            }
        }
    }
}
