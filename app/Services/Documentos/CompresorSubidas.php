<?php

namespace App\Services\Documentos;

use App\Services\Documentos\Pdf\HerramientasPdf;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Compresión automática de PDFs al subirlos. Se hace en el servidor antes de escribir en el almacenamiento:
 * así el bucket recibe una única escritura, ya comprimida (menos almacenamiento, menos transferencia de salida).
 * Si la compresión falla, tarda demasiado o no ahorra lo suficiente, se sube el archivo original sin tocar.
 */
class CompresorSubidas
{
    private ?bool $qpdfDisponible = null;

    public function __construct(private HerramientasPdf $pdf) {}

    /**
     * @return array{ruta: string, temporal: ?string, metadatos: ?array}
     *         ruta = archivo a subir; temporal = archivo a borrar después (si se comprimió)
     */
    public function preparar(string $rutaLocal, ?string $extension): array
    {
        $nivel = (string) config('documentos.pdf.compresion_automatica');
        $sinCambios = ['ruta' => $rutaLocal, 'temporal' => null, 'metadatos' => null];

        if ($extension !== 'pdf' || $nivel === 'off' || ! array_key_exists($nivel, HerramientasPdf::NIVELES)) {
            return $sinCambios;
        }

        $original = (int) filesize($rutaLocal);
        if ($original === 0 || $original > config('documentos.pdf.max_kb') * 1024 || ! $this->disponible()) {
            return $sinCambios;
        }

        $salida = tempnam(sys_get_temp_dir(), 'ivspdf');
        try {
            $this->pdf->comprimir($rutaLocal, $salida, $nivel, (float) config('documentos.pdf.timeout_subida'));
            clearstatcache(true, $salida);
            $final = (int) filesize($salida);
            $ahorro = $original > 0 ? (1 - $final / $original) * 100 : 0;

            if ($final === 0 || $ahorro < (int) config('documentos.pdf.ahorro_minimo')) {
                @unlink($salida);

                return $sinCambios + ['metadatos' => ['compresion' => ['aplicada' => false, 'motivo' => 'ahorro insuficiente', 'ahorro' => round($ahorro, 1)]]];
            }

            return [
                'ruta' => $salida,
                'temporal' => $salida,
                'metadatos' => ['compresion' => [
                    'aplicada' => true,
                    'nivel' => $nivel,
                    'tamano_original' => $original,
                    'tamano_final' => $final,
                    'ahorro' => round($ahorro, 1),
                ]],
            ];
        } catch (Throwable $e) {
            @unlink($salida);
            Log::info('Compresión automática omitida', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return $sinCambios + ['metadatos' => ['compresion' => ['aplicada' => false, 'motivo' => 'no se pudo procesar']]];
        }
    }

    private function disponible(): bool
    {
        return $this->qpdfDisponible ??= $this->pdf->disponibles()['qpdf'];
    }
}
