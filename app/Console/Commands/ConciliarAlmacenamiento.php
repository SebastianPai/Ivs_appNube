<?php

namespace App\Console\Commands;

use App\Models\ArchivoVersion;
use App\Models\Archivos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Detecta inconsistencias entre la base de datos y el almacenamiento. Solo informa; no corrige ni borra.
 */
class ConciliarAlmacenamiento extends Command
{
    protected $signature = 'documentos:conciliar {--huerfanos : Lista también objetos de la aplicación sin ninguna versión que los referencie}';

    protected $description = 'Verifica que cada versión registrada exista en el almacenamiento y reporta huérfanos';

    public function handle(): int
    {
        $faltantes = 0;
        $revisadas = 0;

        ArchivoVersion::query()->select(['id', 'archivo_id', 'numero', 'disco', 'objeto', 'tamano'])->chunkById(500, function ($versiones) use (&$faltantes, &$revisadas) {
            foreach ($versiones as $v) {
                $revisadas++;
                try {
                    $existe = Storage::disk($v->disco)->exists($v->objeto);
                } catch (Throwable) {
                    $existe = false;
                }
                if (! $existe) {
                    $faltantes++;
                    $this->warn("Falta el objeto de la versión {$v->numero} del archivo #{$v->archivo_id}: {$v->objeto}");
                }
            }
        });

        $sinVersion = Archivos::withTrashed()->whereNull('version_actual_id')->count();
        $this->info("Versiones revisadas: $revisadas · objetos faltantes: $faltantes · archivos sin versión actual: $sinVersion");

        if ($this->option('huerfanos')) {
            $disco = config('documentos.disco');
            $prefijo = trim((string) config('documentos.prefijo_objetos'), '/');
            $huerfanos = 0;
            foreach (Storage::disk($disco)->getDriver()->listContents($prefijo, true) as $item) {
                if ($item->isFile() && ! ArchivoVersion::where('disco', $disco)->where('objeto', $item->path())->exists()) {
                    $huerfanos++;
                    $this->line("Huérfano: {$item->path()}");
                }
            }
            $this->info("Objetos huérfanos en «{$disco}/{$prefijo}»: $huerfanos (no se borró ninguno).");
        }

        return $faltantes > 0 ? self::FAILURE : self::SUCCESS;
    }
}
