<?php

namespace App\Services\Documentos;

use App\Models\ArchivoVersion;
use App\Models\InventarioObjeto;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Acceso a los objetos físicos. Los objetos son inmutables: cada versión se escribe en una ruta nueva
 * y nunca se sobrescribe. La base de datos es la fuente de verdad de las relaciones.
 */
class AlmacenDocumental
{
    public function nombreDisco(): string
    {
        return (string) config('documentos.disco');
    }

    public function disco(?string $nombre = null): Filesystem
    {
        return Storage::disk($nombre ?? $this->nombreDisco());
    }

    /**
     * La ruta del objeto es legible: documentos/{carpeta}/{nombre}__v{n}-{aleatorio}.ext. Si se perdiera la base de
     * datos, el bucket mostraría igualmente dónde estaba cada archivo (fue lo que faltó con la app anterior).
     * Al mover o renombrar, ReubicarObjetosJob lleva el objeto a su nueva ruta.
     *
     * @param  string[]  $carpeta  Nombres de las carpetas desde la raíz
     * @return array{disco: string, objeto: string}
     */
    public function guardar(string $rutaLocal, string $uuidArchivo, int $numero, ?string $extension, ?string $mime, array $carpeta = [], ?string $nombre = null): array
    {
        $disco = $this->nombreDisco();
        $objeto = $this->rutaObjeto($carpeta, $nombre ?? $uuidArchivo, $numero, $extension);

        // Sin exists() previo: en GCS sería una operación cobrada por cada subida. 12 caracteres aleatorios
        // en el nombre hacen una colisión prácticamente imposible.
        $stream = fopen($rutaLocal, 'rb');

        try {
            $ok = $this->disco($disco)->writeStream($objeto, $stream, array_filter([
                'visibility' => 'private',
                'mimetype' => $mime,
                'ContentType' => $mime,
                // GCS: metadatos propios del objeto, visibles en la consola de Firebase
                'metadata' => array_filter([
                    'contentType' => $mime,
                    'metadata' => array_filter([
                        'archivo_uuid' => $uuidArchivo,
                        'version' => (string) $numero,
                        'carpeta' => implode('/', $carpeta) ?: null,
                        'nombre' => $nombre,
                    ]),
                ]),
            ]));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($ok === false) {
            throw new RuntimeException('No se pudo escribir el archivo en el almacenamiento.');
        }

        return ['disco' => $disco, 'objeto' => $objeto];
    }

    /** Ruta sin el sufijo aleatorio: si un objeto ya empieza así, está en su sitio y no hay que moverlo */
    public function prefijoObjeto(array $carpeta, string $nombre, int $numero): string
    {
        $segmentos = array_filter(array_map(fn ($s) => $this->segmento($s, 80), $carpeta));

        return implode('/', array_filter([
            trim((string) config('documentos.prefijo_objetos'), '/'),
            mb_substr(implode('/', $segmentos), 0, 600) ?: null,
            sprintf('%s__v%d-', $this->segmento(pathinfo($nombre, PATHINFO_FILENAME), 120), $numero),
        ]));
    }

    public function rutaObjeto(array $carpeta, string $nombre, int $numero, ?string $extension): string
    {
        return $this->prefijoObjeto($carpeta, $nombre, $numero).Str::lower(Str::random(12)).($extension ? '.'.$extension : '');
    }

    /**
     * Solo los objetos con ruta legible (subidos desde que existe este formato) se reubican. Los recuperados de
     * la app anterior (uploads/…) y los antiguos (documentos/{uuid}/v1-…) se quedan donde están.
     */
    public function esReubicable(string $objeto): bool
    {
        return str_starts_with($objeto, trim((string) config('documentos.prefijo_objetos'), '/').'/')
            && (bool) preg_match('#__v\d+-[a-z0-9]{12}(\.[a-z0-9]+)?$#', $objeto);
    }

    /** Copia del lado del servidor (en GCS no hay descarga ni subida) */
    public function copiarObjeto(string $disco, string $origen, string $destino): void
    {
        $this->disco($disco)->copy($origen, $destino);
    }

    /** Segmento de ruta seguro en cualquier almacenamiento: sin "/", "\", controles ni caracteres reservados */
    private function segmento(string $texto, int $maximo): string
    {
        $limpio = preg_replace('/[\x00-\x1F\x7F\/\\\\#?*\[\]<>:"|]+/u', ' ', $texto) ?? '';

        return trim(mb_substr(preg_replace('/\s+/u', ' ', $limpio) ?? '', 0, $maximo), ' .');
    }

    public function existe(string $disco, string $objeto): bool
    {
        try {
            return $this->disco($disco)->exists($objeto);
        } catch (\Throwable $e) {
            Log::warning('No se pudo comprobar un objeto del almacenamiento', ['disco' => $disco, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** Copia el objeto a un archivo temporal local (para herramientas PDF). */
    public function copiarATemporal(ArchivoVersion $version, string $destino): void
    {
        $origen = $this->disco($version->disco)->readStream($version->objeto);
        if (! is_resource($origen)) {
            throw new RuntimeException('No se pudo leer el archivo del almacenamiento.');
        }

        $salida = fopen($destino, 'wb');
        stream_copy_to_stream($origen, $salida);
        fclose($salida);
        fclose($origen);
    }

    /**
     * Descarga o visualización. En la nube redirige a una URL firmada de pocos minutos (generada tras autorizar);
     * en disco local, o para el visor, se transmite a través de Laravel.
     */
    public function respuesta(ArchivoVersion $version, string $nombre, bool $enLinea): Response
    {
        $disk = $this->disco($version->disco);
        $disposicion = HeaderUtils::makeDisposition(
            $enLinea ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
            $nombre,
            Str::ascii($nombre) ?: 'documento'
        );

        // Las versiones nunca cambian: si el navegador ya la tiene, se responde 304 sin leer el almacenamiento
        $etag = '"v'.$version->id.'-'.($version->hash_md5 ?: $version->tamano).'"';
        if ($enLinea && in_array($etag, request()->getETags(), true)) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => 'private, max-age='.(int) config('documentos.cache_visor_segundos').', immutable',
            ]);
        }

        // URL firmada solo en GCS: en disco local se transmite (las URL firmadas locales de Laravel 11 tienen un aviso de seguridad abierto)
        $esNube = config("filesystems.disks.{$version->disco}.driver") === 'gcs';
        if (! $enLinea && $esNube && $disk instanceof FilesystemAdapter && $disk->providesTemporaryUrls()) {
            $url = $disk->temporaryUrl($version->objeto, now()->addMinutes((int) config('documentos.minutos_url_firmada')), [
                'gcp_signing_options' => ['responseDisposition' => $disposicion],
            ]);

            return redirect()->away($url);
        }

        $cabeceras = array_filter([
            // Lo no visualizable se entrega como binario genérico para que el navegador nunca lo interprete
            'Content-Type' => $enLinea ? ($version->mime ?: 'application/octet-stream') : 'application/octet-stream',
            'Content-Disposition' => $disposicion,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            // Caché solo en el navegador del usuario (private): nunca en proxies compartidos
            'Cache-Control' => $enLinea ? 'private, max-age='.(int) config('documentos.cache_visor_segundos').', immutable' : 'private, no-store',
            'ETag' => $enLinea ? $etag : null,
        ]);

        // Video y audio en la nube: URL firmada corta; GCS atiende los rangos (adelantar/retroceder) sin pasar por el servidor
        $esMultimedia = str_starts_with((string) $version->mime, 'video/') || str_starts_with((string) $version->mime, 'audio/');
        if ($enLinea && $esMultimedia && $esNube && $disk instanceof FilesystemAdapter && $disk->providesTemporaryUrls()) {
            return redirect()->away($disk->temporaryUrl($version->objeto, now()->addMinutes(60), [
                'gcp_signing_options' => ['responseDisposition' => $disposicion, 'responseType' => $version->mime],
            ]));
        }

        // Disco local: respuesta de archivo con soporte de rangos (necesario para adelantar videos)
        if (config("filesystems.disks.{$version->disco}.driver") === 'local') {
            $ruta = $disk->path($version->objeto);
            abort_unless(is_file($ruta), 404, 'El archivo no está disponible en el almacenamiento.');

            return response()->file($ruta, $cabeceras);
        }

        $stream = $disk->readStream($version->objeto);
        if (! is_resource($stream)) {
            abort(404, 'El archivo no está disponible en el almacenamiento.');
        }

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        }, 200, $cabeceras + array_filter(['Content-Length' => $version->tamano ?: null]));
    }

    /**
     * Borra el objeto físico solo si ya ninguna versión lo referencia y no es un original inventariado
     * (los originales recuperados nunca se borran desde la aplicación).
     */
    public function eliminarSiHuerfano(string $disco, string $objeto): bool
    {
        $referenciado = ArchivoVersion::where('disco', $disco)->where('objeto', $objeto)->exists()
            || InventarioObjeto::where('disco', $disco)->where('objeto', $objeto)->exists();

        if ($referenciado) {
            return false;
        }

        try {
            return $this->disco($disco)->delete($objeto);
        } catch (\Throwable $e) {
            // Queda como huérfano; documentos:conciliar lo reporta
            Log::warning('No se pudo borrar un objeto huérfano', ['disco' => $disco, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
