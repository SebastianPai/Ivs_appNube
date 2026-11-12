<?php

namespace App\Services\Documentos\Recuperacion;

use App\Models\Actividad;
use App\Models\ArchivoVersion;
use App\Models\InventarioEjecucion;
use App\Models\InventarioObjeto;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

/**
 * Inventario de SOLO LECTURA de un disco/bucket: lista objetos y guarda sus metadatos.
 * No escribe, renombra, mueve ni borra nada en el almacenamiento.
 * Es idempotente (upsert por disco+objeto) y reanudable: una ejecución interrumpida continúa con --reanudar,
 * saltándose los objetos que ya registró sin volver a pedir su checksum.
 */
class InventarioAlmacenamiento
{
    public function ejecutar(string $disco, ?string $prefijo = null, ?User $user = null, bool $reanudar = false, bool $checksums = true, ?callable $progreso = null): InventarioEjecucion
    {
        $prefijo = trim((string) $prefijo, '/');

        $ejecucion = $reanudar
            ? InventarioEjecucion::where('disco', $disco)->where('prefijo', $prefijo ?: null)->where('estado', '!=', 'completado')->latest('id')->first()
            : null;

        $ejecucion ??= InventarioEjecucion::create([
            'disco' => $disco,
            'prefijo' => $prefijo ?: null,
            'iniciado_por' => $user?->id,
            'procesados' => 0,
            'nuevos' => 0,
            'actualizados' => 0,
        ]);
        // Al reanudar se cuentan solo los objetos de esta pasada
        $ejecucion->update(['estado' => 'en_curso', 'error' => null, 'procesados' => 0, 'nuevos' => 0, 'actualizados' => 0]);

        Actividad::registrar('recuperacion.inventario_iniciado', $ejecucion, "Disco $disco", ['prefijo' => $prefijo, 'reanudada' => $reanudar]);

        try {
            $listado = Storage::disk($disco)->getDriver()->listContents($prefijo, true);

            foreach ($listado as $item) {
                // Los respaldos de la base de datos viven en el mismo bucket pero no son documentos
                if (! $item instanceof FileAttributes || str_starts_with($item->path(), trim(config('documentos.respaldos.prefijo'), '/').'/')) {
                    continue;
                }

                $this->registrar($disco, $item, $ejecucion, $checksums);

                if ($ejecucion->procesados % 50 === 0) {
                    $ejecucion->save();
                    if ($progreso) {
                        $progreso($ejecucion);
                    }
                }
            }

            $ejecucion->fill(['estado' => 'completado', 'finalizado_en' => now()])->save();
            Actividad::registrar('recuperacion.inventario_completado', $ejecucion, "Disco $disco", $ejecucion->only(['procesados', 'nuevos', 'actualizados']));
        } catch (Throwable $e) {
            // Mensaje sin credenciales: solo la clase y el texto de la excepción
            $ejecucion->fill(['estado' => 'error', 'error' => class_basename($e).': '.mb_substr($e->getMessage(), 0, 1000)])->save();
            Actividad::registrar('recuperacion.inventario_error', $ejecucion, "Disco $disco", [], 'error');
            throw $e;
        }

        return $ejecucion;
    }

    private function registrar(string $disco, FileAttributes $item, InventarioEjecucion $ejecucion, bool $checksums): void
    {
        $ruta = $item->path();

        // Objetos que la aplicación ya gestiona como versiones no son material de recuperación
        if (ArchivoVersion::where('disco', $disco)->where('objeto', $ruta)->exists()) {
            return;
        }

        $objeto = InventarioObjeto::firstOrNew(['disco' => $disco, 'objeto' => $ruta]);
        $modificado = $item->lastModified() ? now()->setTimestamp($item->lastModified()) : null;

        $sinCambios = $objeto->exists
            && (int) $objeto->tamano === (int) $item->fileSize()
            && $objeto->modificado_en?->getTimestamp() === $modificado?->getTimestamp();

        $ejecucion->ultimo_objeto = mb_substr($ruta, 0, 700);
        $ejecucion->procesados++;

        if ($sinCambios && $objeto->hash_md5) {
            if ($objeto->ejecucion_id !== $ejecucion->id) {
                $objeto->update(['ejecucion_id' => $ejecucion->id]);
            }

            return;
        }

        $extra = $item->extraMetadata();
        $nombre = basename($ruta);
        $directorio = trim(dirname($ruta), './');

        $objeto->fill([
            'directorio' => $directorio !== '' ? mb_substr($directorio, 0, 500) : null,
            'nombre' => mb_substr($nombre, 0, 255),
            'extension' => ($ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION))) !== '' ? mb_substr($ext, 0, 20) : null,
            'tamano' => (int) $item->fileSize(),
            'mime' => $item->mimeType() ?? $this->mimeSeguro($disco, $ruta),
            'hash_md5' => $this->md5($disco, $ruta, $extra, $checksums) ?? $objeto->hash_md5,
            'modificado_en' => $modificado,
            'metadatos' => $this->metadatosUtiles($extra),
            'ejecucion_id' => $ejecucion->id,
        ]);

        $objeto->exists ? $ejecucion->actualizados++ : $ejecucion->nuevos++;
        $objeto->save();
    }

    private function md5(string $disco, string $ruta, array $extra, bool $checksums): ?string
    {
        if (! empty($extra['md5Hash'])) {
            return bin2hex(base64_decode($extra['md5Hash']));
        }

        // En la nube cada consulta individual es una operación cobrada: el listado ya trae el md5 cuando existe
        // (los objetos compuestos no lo tienen) y no se hace una petición extra por objeto.
        if (! $checksums || $this->esNube($disco)) {
            return null;
        }

        try {
            $valor = Storage::disk($disco)->checksum($ruta, ['checksum_algo' => 'md5']);

            // GCS devuelve el md5 en base64; el disco local, en hexadecimal
            return ctype_xdigit((string) $valor) && strlen((string) $valor) === 32 ? $valor : bin2hex(base64_decode((string) $valor));
        } catch (Throwable) {
            return null;
        }
    }

    private function mimeSeguro(string $disco, string $ruta): ?string
    {
        if ($this->esNube($disco)) {
            // Se deduce de la extensión, sin consultar el bucket
            $ext = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));

            return $ext !== '' ? ((new MimeTypes())->getMimeTypes($ext)[0] ?? null) : null;
        }

        try {
            return Storage::disk($disco)->mimeType($ruta) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function esNube(string $disco): bool
    {
        return config("filesystems.disks.$disco.driver") !== 'local';
    }

    private function metadatosUtiles(array $extra): ?array
    {
        $utiles = array_intersect_key($extra, array_flip(['contentType', 'timeCreated', 'updated', 'generation', 'metageneration', 'crc32c', 'etag', 'metadata', 'storageClass', 'contentDisposition']));

        return $utiles ?: null;
    }
}
