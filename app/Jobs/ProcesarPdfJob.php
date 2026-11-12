<?php

namespace App\Jobs;

use App\Models\Actividad;
use App\Models\Archivos;
use App\Models\TareaDocumental;
use App\Services\Documentos\AccesoDocumental;
use App\Services\Documentos\AlmacenDocumental;
use App\Services\Documentos\Pdf\HerramientasPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Ejecuta una herramienta PDF en segundo plano. Las entradas se copian a una carpeta temporal privada,
 * el resultado queda en storage/app/tareas/{uuid} y las entradas se borran al terminar. El original nunca se toca.
 */
class ProcesarPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $tareaId)
    {
        $this->timeout = (int) config('documentos.pdf.timeout') + 60;
    }

    public function handle(HerramientasPdf $pdf, AlmacenDocumental $almacen, AccesoDocumental $acceso): void
    {
        $tarea = TareaDocumental::find($this->tareaId);
        if (! $tarea || $tarea->estado !== 'pendiente') {
            return; // ya procesada: reintentar no duplica resultados
        }

        $tarea->update(['estado' => 'procesando']);
        $dir = Storage::disk('local')->path($tarea->directorio());
        File::ensureDirectoryExists($dir.'/entradas', 0700);

        try {
            $p = $tarea->parametros;
            $entradas = $tarea->tipo === 'imagenes'
                ? $this->prepararImagenes($tarea, $dir.'/entradas')
                : $this->prepararEntradas($tarea, $dir.'/entradas', $almacen, $acceso);
            $base = pathinfo($p['nombre_base'] ?? 'documento', PATHINFO_FILENAME);
            $originalBytes = array_sum(array_map('filesize', $entradas));
            $clave = filled($p['clave'] ?? null) ? Crypt::decryptString($p['clave']) : null;

            switch ($tarea->tipo) {
                case 'eliminar':
                    $nombre = "$base (sin páginas {$p['rango']}).pdf";
                    $pdf->eliminarPaginas($entradas[0], $p['rango'], "$dir/$nombre");
                    break;
                case 'numerar':
                    $nombre = "$base (numerado).pdf";
                    $pdf->numerar($entradas[0], "$dir/$nombre", $p['posicion'] ?? 'abajo-centro', (bool) ($p['con_total'] ?? true));
                    break;
                case 'marca_agua':
                    $nombre = "$base (marca de agua).pdf";
                    $pdf->marcaAgua($entradas[0], (string) ($p['texto_marca'] ?? ''), "$dir/$nombre", (int) ($p['opacidad'] ?? 20));
                    break;
                case 'proteger':
                    $nombre = "$base (protegido).pdf";
                    $pdf->proteger($entradas[0], (string) $clave, "$dir/$nombre");
                    break;
                case 'desbloquear':
                    $nombre = "$base (sin contraseña).pdf";
                    try {
                        $pdf->desbloquear($entradas[0], (string) $clave, "$dir/$nombre");
                    } catch (RuntimeException $e) {
                        throw new RuntimeException(str_contains($e->getMessage(), 'password') ? 'La contraseña no es correcta.' : $e->getMessage());
                    }
                    break;
                case 'imagenes':
                    $nombre = "$base.pdf";
                    $pdf->imagenesAPdf($entradas, "$dir/$nombre", $p['tamano_pagina'] ?? 'a4');
                    break;
                case 'comprimir':
                    $nombre = "$base (comprimido).pdf";
                    $pdf->comprimir($entradas[0], "$dir/$nombre", $p['nivel'] ?? 'media');
                    break;
                case 'unir':
                    $nombre = "$base (unido).pdf";
                    $pdf->unir($entradas, "$dir/$nombre");
                    break;
                case 'extraer':
                    $nombre = "$base (páginas {$p['rango']}).pdf";
                    $pdf->extraer($entradas[0], $p['rango'], "$dir/$nombre");
                    break;
                case 'rotar':
                    $nombre = "$base (rotado).pdf";
                    $pdf->rotar($entradas[0], (int) $p['grados'], $p['rango'] ?? null, "$dir/$nombre");
                    break;
                case 'dividir':
                    $nombre = "$base (partes).zip";
                    $partes = $pdf->dividir($entradas[0], (int) $p['paginas_por_parte'], "$dir/$nombre");
                    break;
                default:
                    throw new RuntimeException('Herramienta desconocida.');
            }

            $finalBytes = filesize("$dir/$nombre");
            $tarea->update([
                'estado' => 'completada',
                'resultado' => [
                    'ruta' => $tarea->directorio().'/'.$nombre,
                    'nombre' => $nombre,
                    'tamano_original' => $originalBytes,
                    'tamano_final' => $finalBytes,
                    'reduccion' => $originalBytes > 0 ? round((1 - $finalBytes / $originalBytes) * 100, 1) : 0,
                    // Un PDF protegido no se puede abrir sin su contraseña para contar páginas
                    'paginas' => str_ends_with($nombre, '.pdf') && $tarea->tipo !== 'proteger' && $pdf->disponibles()['qpdf'] ? $pdf->paginas("$dir/$nombre") : null,
                    'partes' => $partes ?? null,
                ],
            ]);

            Actividad::registrar('pdf.'.$tarea->tipo, $tarea, $nombre, ['tamano_original' => $originalBytes, 'tamano_final' => $finalBytes]);
        } catch (Throwable $e) {
            $tarea->update(['estado' => 'error', 'error' => mb_substr($e->getMessage(), 0, 500)]);
            File::deleteDirectory($dir);
        } finally {
            File::deleteDirectory($dir.'/entradas');
            File::deleteDirectory($dir.'/partes');
            // La contraseña solo hacía falta durante el proceso
            if (isset($tarea->parametros['clave'])) {
                $tarea->update(['parametros' => array_diff_key($tarea->parametros, ['clave' => true])]);
            }
        }
    }

    /** @return list<string> */
    private function prepararImagenes(TareaDocumental $tarea, string $dir): array
    {
        $entradas = [];
        foreach ($tarea->parametros['imagenes'] ?? [] as $i => $rutaRelativa) {
            $origen = Storage::disk('local')->path($rutaRelativa);
            if (! str_starts_with($rutaRelativa, $tarea->directorio().'/') || ! is_file($origen)) {
                throw new RuntimeException('Una de las imágenes ya no está disponible.');
            }
            $destino = sprintf('%s/%03d.%s', $dir, $i, pathinfo($origen, PATHINFO_EXTENSION));
            rename($origen, $destino);
            $entradas[] = $destino;
        }

        return $entradas ?: throw new RuntimeException('Sube al menos una imagen.');
    }

    /** @return list<string> */
    private function prepararEntradas(TareaDocumental $tarea, string $dir, AlmacenDocumental $almacen, AccesoDocumental $acceso): array
    {
        $user = $tarea->user;
        $entradas = [];
        $limite = config('documentos.pdf.max_kb') * 1024;

        foreach ($tarea->parametros['archivos'] ?? [] as $i => $archivoId) {
            $archivo = Archivos::find($archivoId);
            // Se vuelve a autorizar: los permisos pudieron cambiar desde que se encoló la tarea
            if (! $archivo || ! $user || ! $acceso->puedeDescargar($user, $archivo) || $archivo->extension !== 'pdf') {
                throw new RuntimeException('Uno de los documentos ya no está disponible para ti.');
            }
            if ($archivo->tamano > $limite) {
                throw new RuntimeException("«{$archivo->nombre}» supera el tamaño máximo para herramientas PDF.");
            }

            $destino = sprintf('%s/%02d.pdf', $dir, $i);
            $almacen->copiarATemporal($archivo->versionActual, $destino);
            $entradas[] = $destino;
        }

        foreach ($tarea->parametros['subidas'] ?? [] as $i => $rutaRelativa) {
            $origen = Storage::disk('local')->path($rutaRelativa);
            if (! str_starts_with($rutaRelativa, $tarea->directorio().'/') || ! is_file($origen)) {
                throw new RuntimeException('El archivo subido no está disponible.');
            }
            $destino = sprintf('%s/s%02d.pdf', $dir, $i);
            rename($origen, $destino);
            $entradas[] = $destino;
        }

        if (! $entradas) {
            throw new RuntimeException('No se seleccionó ningún PDF.');
        }

        return $entradas;
    }
}
