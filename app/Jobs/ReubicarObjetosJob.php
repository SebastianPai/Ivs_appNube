<?php

namespace App\Jobs;

use App\Models\ArchivoVersion;
use App\Models\Archivos;
use App\Services\Documentos\AlmacenDocumental;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tras mover o renombrar, lleva los objetos del bucket a la ruta que corresponde a su carpeta y nombre actuales:
 * copia (del lado del servidor) → actualiza la BD → borra el objeto anterior solo si ya nadie lo referencia
 * (una copia lógica u otra versión pueden seguir apuntando a él). Si algo falla, el archivo sigue abriéndose
 * desde su ruta anterior. Los objetos recuperados de la app anterior nunca se mueven.
 */
class ReubicarObjetosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    /** @param list<int> $archivoIds */
    public function __construct(public array $archivoIds) {}

    public function handle(AlmacenDocumental $almacen): void
    {
        foreach (Archivos::whereIn('id', $this->archivoIds)->with(['carpeta', 'versiones'])->get() as $archivo) {
            // Un archivo a la vez: dos movimientos seguidos no deben pisarse
            Cache::lock('documentos:reubicar:'.$archivo->id, $this->timeout)->block(30, fn () => $this->reubicar($archivo, $almacen));
        }
    }

    private function reubicar(Archivos $archivo, AlmacenDocumental $almacen): void
    {
        $archivo->refresh()->load(['carpeta', 'versiones']);
        $carpeta = $archivo->carpeta?->nombresRuta() ?? [];

        foreach ($archivo->versiones->groupBy(fn ($v) => $v->disco.'|'.$v->objeto) as $versiones) {
            /** @var ArchivoVersion $version */
            $version = $versiones->first();
            if (! $almacen->esReubicable($version->objeto)) {
                continue;
            }

            $numero = (int) $versiones->min('numero');
            if (str_starts_with($version->objeto, $almacen->prefijoObjeto($carpeta, $archivo->nombre, $numero))) {
                continue; // ya está en su sitio
            }

            $anterior = $version->objeto;
            $nuevo = $almacen->rutaObjeto($carpeta, $archivo->nombre, $numero, $archivo->extension);

            try {
                $almacen->copiarObjeto($version->disco, $anterior, $nuevo);
                ArchivoVersion::whereIn('id', $versiones->pluck('id'))->update(['objeto' => $nuevo]);

                if (! ArchivoVersion::where('disco', $version->disco)->where('objeto', $anterior)->exists()) {
                    $almacen->disco($version->disco)->delete($anterior);
                }
            } catch (Throwable $e) {
                Log::warning('No se pudo reubicar un objeto; sigue en su ruta anterior', ['archivo' => $archivo->id, 'objeto' => $anterior, 'error' => $e->getMessage()]);
            }
        }
    }
}
