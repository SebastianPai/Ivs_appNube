<?php

namespace App\Services\Documentos\Recuperacion;

use App\Models\Actividad;
use App\Models\InventarioObjeto;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Descarga (solo lectura) los formatos de inspección en Excel del inventario y guarda su ficha
 * (estación, razón social, NIT, SICOM…) en metadatos['ficha']. El analizador la usa para nombrar expedientes.
 * Solo baja estos formatos —pocos MB en total—, nunca el bucket completo.
 */
class IdentificadorExpedientes
{
    // OIN-FO-OP-001 Plan de evaluación, -003 Lista de chequeo, -004 Reunión de apertura, -006 Informe final
    public const FORMATOS = '/OIN\W*FO\W*OP\W*00[1346]\b/i';

    public const TAMANO_MAXIMO = 10 * 1024 * 1024;

    public function __construct(private LectorFichaInspeccion $lector) {}

    public function ejecutar(string $disco, bool $forzar = false, ?callable $progreso = null): array
    {
        $resumen = ['revisados' => 0, 'con_ficha' => 0, 'sin_ficha' => 0, 'errores' => 0];

        $candidatos = InventarioObjeto::where('disco', $disco)
            ->where('extension', 'xlsx')
            ->where('tamano', '<=', self::TAMANO_MAXIMO)
            ->whereNull('duplicado_de_id')
            ->get()
            ->filter(fn (InventarioObjeto $o) => preg_match(self::FORMATOS, $o->nombre))
            ->filter(fn (InventarioObjeto $o) => $forzar || ! array_key_exists('ficha', $o->metadatos ?? []));

        foreach ($candidatos as $objeto) {
            $resumen['revisados']++;
            $temporal = tempnam(sys_get_temp_dir(), 'ficha');

            try {
                $origen = Storage::disk($disco)->readStream($objeto->objeto);
                $destino = fopen($temporal, 'wb');
                stream_copy_to_stream($origen, $destino);
                fclose($destino);
                is_resource($origen) && fclose($origen);

                $ficha = $this->lector->leer($temporal);
                $resumen[$ficha && isset($ficha['nombre']) ? 'con_ficha' : 'sin_ficha']++;
                $this->guardar($objeto, ['ficha' => $ficha]);
            } catch (Throwable $e) {
                $resumen['errores']++;
                $this->guardar($objeto, ['ficha' => null, 'ficha_error' => mb_substr($e->getMessage(), 0, 200)]);
            } finally {
                @unlink($temporal);
            }

            if ($progreso) {
                $progreso($resumen, $objeto);
            }
        }

        // Las copias idénticas comparten la ficha del original sin volver a descargarse
        InventarioObjeto::where('disco', $disco)->whereNotNull('duplicado_de_id')->with('duplicadoDe')->get()
            ->each(function (InventarioObjeto $copia) {
                $original = $copia->duplicadoDe?->metadatos ?? [];
                if (array_key_exists('ficha', $original)) {
                    $this->guardar($copia, ['ficha' => $original['ficha']]);
                }
            });

        Actividad::registrar('recuperacion.identificacion', null, "Fichas de inspección en $disco", $resumen);

        return $resumen;
    }

    private function guardar(InventarioObjeto $objeto, array $datos): void
    {
        $objeto->metadatos = array_merge($objeto->metadatos ?? [], $datos);
        $objeto->save();
    }
}
