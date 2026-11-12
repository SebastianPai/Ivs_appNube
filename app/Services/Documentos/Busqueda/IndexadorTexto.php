<?php

namespace App\Services\Documentos\Busqueda;

use App\Models\ArchivoTexto;
use App\Models\Archivos;
use App\Services\Documentos\AlmacenDocumental;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Config as ConfigPdf;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Extrae el texto de los PDF (versión actual) para la búsqueda por contenido.
 * Los PDF escaneados no tienen capa de texto: quedan como «sin_texto» (no hay OCR).
 */
class IndexadorTexto
{
    public function __construct(private AlmacenDocumental $almacen) {}

    /** PDF cuyo texto falta o corresponde a una versión anterior. */
    public function pendientes(): Builder
    {
        return Archivos::query()
            ->where('extension', 'pdf')
            ->whereNotNull('version_actual_id')
            ->where('tamano', '<=', config('documentos.busqueda.max_kb') * 1024)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('archivo_textos')
                ->whereColumn('archivo_textos.archivo_id', 'archivos.id')
                ->whereColumn('archivo_textos.version_id', 'archivos.version_actual_id'));
    }

    /**
     * @throws AlmacenamientoNoDisponible si no se pudo leer el archivo (no se marca: se reintenta después)
     */
    public function indexar(Archivos $archivo): ?ArchivoTexto
    {
        $version = $archivo->versionActual;
        if ($archivo->extension !== 'pdf' || ! $version) {
            return null;
        }

        $dir = storage_path('app/tmp/indexar');
        File::ensureDirectoryExists($dir, 0700);
        $local = $dir.'/'.$archivo->uuid.'.pdf';

        try {
            $this->almacen->copiarATemporal($version, $local);
        } catch (Throwable $e) {
            @unlink($local);
            throw new AlmacenamientoNoDisponible($e->getMessage(), 0, $e);
        }

        try {
            $texto = $this->limpiar($this->extraer($local));
            $estado = $texto === '' ? ArchivoTexto::SIN_TEXTO : ArchivoTexto::INDEXADO;
        } catch (Throwable $e) {
            Log::warning('No se pudo extraer el texto del PDF', ['archivo' => $archivo->id, 'error' => $e->getMessage()]);
            $texto = '';
            $estado = ArchivoTexto::ERROR;
        } finally {
            @unlink($local);
        }

        return ArchivoTexto::updateOrCreate(
            ['archivo_id' => $archivo->id],
            ['version_id' => $version->id, 'estado' => $estado, 'texto' => $texto !== '' ? $texto : null],
        );
    }

    private function extraer(string $ruta): string
    {
        $config = new ConfigPdf();
        $config->setRetainImageContent(false); // las imágenes no aportan texto y gastan memoria
        $config->setDecodeMemoryLimit(64 * 1024 * 1024);

        $pdf = (new Parser([], $config))->parseFile($ruta);
        $maxPaginas = (int) config('documentos.busqueda.max_paginas');

        $partes = [];
        foreach (array_slice($pdf->getPages(), 0, $maxPaginas) as $pagina) {
            $partes[] = $pagina->getText();
        }

        return implode("\n", $partes);
    }

    /** Espacios normalizados, UTF-8 válido y tope de tamaño. */
    private function limpiar(string $texto): string
    {
        $texto = mb_convert_encoding($texto, 'UTF-8', 'UTF-8');
        $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $texto) ?? '';
        $texto = preg_replace('/[ \t\x{00A0}]+/u', ' ', $texto) ?? '';
        $texto = trim(preg_replace('/\s*\n\s*/u', "\n", $texto) ?? '');

        return mb_substr($texto, 0, (int) config('documentos.busqueda.max_caracteres'));
    }
}
