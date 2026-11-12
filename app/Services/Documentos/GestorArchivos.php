<?php

namespace App\Services\Documentos;

use App\Jobs\IndexarTextoJob;
use App\Jobs\ReubicarObjetosJob;
use App\Models\Actividad;
use App\Models\ArchivoVersion;
use App\Models\Archivos;
use App\Models\Carpeta;
use App\Models\InventarioObjeto;
use App\Models\User;
use App\Services\Documentos\Recuperacion\AnalizadorRecuperacion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class GestorArchivos
{
    public function __construct(
        private AccesoDocumental $acceso,
        private AlmacenDocumental $almacen,
        private CompresorSubidas $compresor,
    ) {}

    /**
     * Sube un archivo nuevo. Orden: validar → detectar duplicado → comprimir (PDF) → escribir el objeto → registrar en BD.
     * Si falla el registro se borra el objeto recién escrito (compensación), así nunca queda un registro
     * apuntando a algo que no existe. Subir dos veces el mismo contenido con el mismo nombre no duplica
     * (ni comprime ni escribe en el almacenamiento otra vez).
     *
     * @return array{0: Archivos, 1: bool} [archivo, creado]
     */
    public function subir(User $user, ?Carpeta $carpeta, string $rutaLocal, string $nombreCliente, ?string $comentario = null): array
    {
        $this->acceso->autorizar($carpeta !== null && $this->acceso->puedeEditarEn($user, $carpeta), 'No tienes permiso para subir archivos a esta carpeta.');

        $nombre = NombresDocumentales::limpiar($nombreCliente);
        [$extension, $mime] = $this->validar($rutaLocal, $nombre);
        $md5Original = md5_file($rutaLocal);

        $existente = Archivos::where('carpeta_id', $carpeta->id)->where('nombre', $nombre)
            ->where(fn ($q) => $q->where('hash_md5', $md5Original)->orWhere('hash_original', $md5Original))
            ->first();
        if ($existente) {
            return [$existente, false];
        }

        $contenido = $this->compresor->preparar($rutaLocal, $extension);

        try {
            $tamano = (int) filesize($contenido['ruta']);
            $archivo = new Archivos([
                'nombre_original' => $nombre,
                'extension' => $extension,
                'mime' => $mime,
                'tamano' => $tamano,
                'hash_md5' => $contenido['temporal'] ? md5_file($contenido['ruta']) : $md5Original,
                'hash_original' => $md5Original,
                'identificador_documental' => $this->identificadorDocumental($nombre),
                'estado' => Archivos::ESTADO_ACTIVO,
                'origen' => 'subida',
                'subido_por' => $user->id,
            ]);
            $archivo->uuid = (string) Str::uuid();

            $objeto = $this->almacen->guardar($contenido['ruta'], $archivo->uuid, 1, $extension, $mime, $carpeta->nombresRuta(), $nombre);
        } finally {
            if ($contenido['temporal']) {
                @unlink($contenido['temporal']);
            }
        }

        try {
            Cache::lock('documentos:nombres:a:'.$carpeta->id, 15)->block(10, function () use ($archivo, $carpeta, $nombre, $objeto, $user, $comentario, $contenido) {
                DB::transaction(function () use ($archivo, $carpeta, $nombre, $objeto, $user, $comentario, $contenido) {
                    $archivo->carpeta_id = $carpeta->id;
                    $archivo->nombre = NombresDocumentales::unicoArchivo($carpeta->id, $nombre);
                    $archivo->save();

                    $version = $archivo->versiones()->create([
                        'numero' => 1,
                        'disco' => $objeto['disco'],
                        'objeto' => $objeto['objeto'],
                        'tamano' => $archivo->tamano,
                        'hash_md5' => $archivo->hash_md5,
                        'hash_original' => $archivo->hash_original,
                        'mime' => $archivo->mime,
                        'creado_por' => $user->id,
                        'comentario' => $comentario,
                        'metadatos' => $contenido['metadatos'],
                    ]);

                    $archivo->version_actual_id = $version->id;
                    $archivo->save();
                });
            });
        } catch (Throwable $e) {
            $this->almacen->eliminarSiHuerfano($objeto['disco'], $objeto['objeto']);
            throw $e;
        }

        $this->indexarTexto($archivo);

        Actividad::registrar('archivo.subido', $archivo, $archivo->nombre, array_filter([
            'carpeta_id' => $carpeta->id,
            'tamano' => $tamano,
            'compresion' => $contenido['metadatos']['compresion'] ?? null,
        ]));

        return [$archivo, true];
    }

    /** Nueva versión: la anterior se conserva intacta. Mismo contenido que la versión actual = no se crea nada. */
    public function nuevaVersion(User $user, Archivos $archivo, string $rutaLocal, string $nombreCliente, ?string $comentario = null): ArchivoVersion
    {
        $this->acceso->autorizar($this->acceso->puedeEditarArchivo($user, $archivo), 'No tienes permiso para crear versiones de este archivo.');

        [$extension, $mime] = $this->validar($rutaLocal, NombresDocumentales::limpiar($nombreCliente));
        if ($archivo->extension && $extension !== $archivo->extension) {
            throw ValidationException::withMessages(['archivo' => "La nueva versión debe ser un archivo .{$archivo->extension}."]);
        }

        $md5Original = md5_file($rutaLocal);
        $actual = $archivo->versionActual;
        if ($actual && in_array($md5Original, array_filter([$actual->hash_md5, $actual->hash_original]), true)) {
            return $actual;
        }

        $contenido = $this->compresor->preparar($rutaLocal, $extension);
        try {
            $tamano = (int) filesize($contenido['ruta']);
            $md5 = $contenido['temporal'] ? md5_file($contenido['ruta']) : $md5Original;
            $siguiente = (int) $archivo->versiones()->max('numero') + 1;
            $objeto = $this->almacen->guardar($contenido['ruta'], $archivo->uuid, $siguiente, $extension, $mime, $archivo->carpeta?->nombresRuta() ?? [], $archivo->nombre);
        } finally {
            if ($contenido['temporal']) {
                @unlink($contenido['temporal']);
            }
        }

        try {
            $version = $this->registrarVersion($user, $archivo, [
                'disco' => $objeto['disco'],
                'objeto' => $objeto['objeto'],
                'tamano' => $tamano,
                'hash_md5' => $md5,
                'hash_original' => $md5Original,
                'mime' => $mime,
                'comentario' => $comentario,
                'metadatos' => $contenido['metadatos'],
            ]);
        } catch (Throwable $e) {
            $this->almacen->eliminarSiHuerfano($objeto['disco'], $objeto['objeto']);
            throw $e;
        }

        Actividad::registrar('archivo.version', $archivo, "{$archivo->nombre} v{$version->numero}", ['version_id' => $version->id]);

        return $version;
    }

    /** Restaurar crea una versión nueva que apunta al mismo objeto inmutable; el historial no se altera. */
    public function restaurarVersion(User $user, Archivos $archivo, ArchivoVersion $version, ?string $comentario = null): ArchivoVersion
    {
        $this->acceso->autorizar($this->acceso->puedeEditarArchivo($user, $archivo), 'No tienes permiso para restaurar versiones de este archivo.');

        if ($version->archivo_id !== $archivo->id) {
            throw new InvalidArgumentException('La versión no pertenece a este archivo.');
        }

        $nueva = $this->registrarVersion($user, $archivo, [
            'disco' => $version->disco,
            'objeto' => $version->objeto,
            'tamano' => $version->tamano,
            'hash_md5' => $version->hash_md5,
            'hash_original' => $version->hash_original,
            'mime' => $version->mime,
            'metadatos' => $version->metadatos,
            'comentario' => $comentario ?: "Restaurada desde la versión {$version->numero}",
            'restaurada_desde_id' => $version->id,
        ]);

        Actividad::registrar('archivo.version_restaurada', $archivo, "{$archivo->nombre}: v{$version->numero} → v{$nueva->numero}");

        return $nueva;
    }

    public function renombrar(User $user, Archivos $archivo, string $nombre): Archivos
    {
        $this->acceso->autorizar($this->acceso->puedeEditarArchivo($user, $archivo));

        $nombre = NombresDocumentales::limpiar($nombre);
        // Se conserva la extensión real para que el tipo no cambie por un renombrado
        if ($archivo->extension && NombresDocumentales::extension($nombre) !== $archivo->extension) {
            $nombre .= '.'.$archivo->extension;
        }

        $anterior = $archivo->nombre;

        Cache::lock('documentos:nombres:a:'.($archivo->carpeta_id ?? 0), 15)->block(10, function () use ($archivo, $nombre) {
            $archivo->nombre = NombresDocumentales::unicoArchivo($archivo->carpeta_id, $nombre, $archivo->id);
            $archivo->save();
        });

        Actividad::registrar('archivo.renombrado', $archivo, "$anterior → {$archivo->nombre}");
        ReubicarObjetosJob::dispatch([$archivo->id])->afterCommit();

        return $archivo;
    }

    public function mover(User $user, Archivos $archivo, Carpeta $destino): Archivos
    {
        $origen = $archivo->carpeta;
        $this->acceso->autorizar(
            $this->acceso->puedeEditarArchivo($user, $archivo)
                && $this->acceso->puedeMover($user, $origen, $destino, $origen?->unidad ?? $destino->unidad, $destino->unidad),
            'No tienes permiso para mover este archivo a ese destino.'
        );

        Cache::lock('documentos:nombres:a:'.$destino->id, 15)->block(10, function () use ($archivo, $destino) {
            $archivo->carpeta_id = $destino->id;
            $archivo->nombre = NombresDocumentales::unicoArchivo($destino->id, $archivo->nombre, $archivo->id);
            if ($archivo->estado === Archivos::ESTADO_PENDIENTE) {
                $archivo->estado = Archivos::ESTADO_ACTIVO;
            }
            $archivo->save();
        });

        Actividad::registrar('archivo.movido', $archivo, $archivo->nombre, ['desde' => $origen?->id, 'hacia' => $destino->id]);
        ReubicarObjetosJob::dispatch([$archivo->id])->afterCommit();

        return $archivo;
    }

    /** Copia lógica: el nuevo archivo apunta al mismo objeto inmutable (no se duplica el binario). */
    public function copiar(User $user, Archivos $archivo, Carpeta $destino): Archivos
    {
        $this->acceso->autorizar(
            $this->acceso->puedeVerArchivo($user, $archivo) && $this->acceso->puedeEditarEn($user, $destino),
            'No tienes permiso para copiar este archivo a ese destino.'
        );

        $actual = $archivo->versionActual;
        if (! $actual) {
            throw new InvalidArgumentException('El archivo no tiene contenido para copiar.');
        }

        $copia = Cache::lock('documentos:nombres:a:'.$destino->id, 15)->block(10, function () use ($user, $archivo, $destino, $actual) {
            return DB::transaction(function () use ($user, $archivo, $destino, $actual) {
                $copia = Archivos::create([
                    'nombre' => NombresDocumentales::unicoArchivo($destino->id, $archivo->nombre),
                    'carpeta_id' => $destino->id,
                    'nombre_original' => $archivo->nombre_original,
                    'extension' => $archivo->extension,
                    'mime' => $actual->mime,
                    'tamano' => $actual->tamano,
                    'hash_md5' => $actual->hash_md5,
                    'hash_original' => $actual->hash_original,
                    'identificador_documental' => $archivo->identificador_documental,
                    'estado' => Archivos::ESTADO_ACTIVO,
                    'origen' => 'copia',
                    'subido_por' => $user->id,
                ]);

                $version = $copia->versiones()->create([
                    'numero' => 1,
                    'disco' => $actual->disco,
                    'objeto' => $actual->objeto,
                    'tamano' => $actual->tamano,
                    'hash_md5' => $actual->hash_md5,
                    'hash_original' => $actual->hash_original,
                    'mime' => $actual->mime,
                    'creado_por' => $user->id,
                    'comentario' => "Copia de «{$archivo->nombre}» v{$actual->numero}",
                ]);

                $copia->version_actual_id = $version->id;
                $copia->save();

                return $copia;
            });
        });

        Actividad::registrar('archivo.copiado', $copia, $copia->nombre, ['origen_id' => $archivo->id]);

        return $copia;
    }

    public function enviarPapelera(User $user, Archivos $archivo): void
    {
        $this->acceso->autorizar($this->acceso->puedeEliminar($user), 'Solo un administrador puede enviar archivos a la papelera.');

        $archivo->forceFill([
            'papelera_lote' => (string) Str::uuid(),
            'papelera_raiz' => true,
            'eliminado_por' => $user->id,
        ])->save();
        $archivo->delete();

        Actividad::registrar('archivo.papelera', $archivo, $archivo->nombre);
    }

    public function restaurar(User $user, Archivos $archivo): Archivos
    {
        $this->acceso->autorizar($this->acceso->puedeEliminar($user), 'Solo un administrador puede restaurar archivos.');

        if (! $archivo->trashed()) {
            throw new InvalidArgumentException('Este archivo no está en la papelera.');
        }

        if ($archivo->carpeta?->trashed()) {
            throw new InvalidArgumentException("Primero restaura la carpeta «{$archivo->carpeta->nombre}».");
        }

        Cache::lock('documentos:nombres:a:'.($archivo->carpeta_id ?? 0), 15)->block(10, function () use ($archivo) {
            $archivo->restore();
            $archivo->forceFill([
                'papelera_lote' => null,
                'papelera_raiz' => false,
                'eliminado_por' => null,
                'nombre' => NombresDocumentales::unicoArchivo($archivo->carpeta_id, $archivo->nombre, $archivo->id),
            ])->save();
        });

        Actividad::registrar('archivo.restaurado', $archivo, $archivo->nombre);

        return $archivo;
    }

    public function purgar(User $user, Archivos $archivo): void
    {
        $this->acceso->autorizar($this->acceso->puedeEliminar($user), 'Solo un administrador puede eliminar definitivamente.');

        if (! $archivo->trashed()) {
            throw new InvalidArgumentException('Solo se eliminan definitivamente archivos que están en la papelera.');
        }

        $this->purgarSinAutorizar($archivo);
    }

    /** Uso interno (ya autorizado). Primero se borra la BD; después, los objetos que hayan quedado sin referencias. */
    public function purgarSinAutorizar(Archivos $archivo): void
    {
        $objetos = $archivo->versiones()->get(['disco', 'objeto'])
            ->map(fn ($v) => $v->disco."\n".$v->objeto)->unique()->values();
        $nombre = $archivo->nombre;
        $id = $archivo->id;

        DB::transaction(function () use ($archivo) {
            $archivo->forceFill(['version_actual_id' => null])->saveQuietly();
            ArchivoVersion::where('archivo_id', $archivo->id)->update(['restaurada_desde_id' => null]);
            ArchivoVersion::where('archivo_id', $archivo->id)->delete();
            $archivo->forceDelete();
        });

        $borrados = 0;
        foreach ($objetos as $par) {
            [$disco, $objeto] = explode("\n", $par, 2);
            $borrados += $this->almacen->eliminarSiHuerfano($disco, $objeto) ? 1 : 0;
        }

        Actividad::registrar('archivo.eliminado_definitivamente', null, $nombre, ['archivo_id' => $id, 'objetos_borrados' => $borrados]);
    }

    /**
     * Recuperación: crea el registro documental de un objeto que ya existe en el bucket, sin volver a subirlo.
     * Idempotente: si el objeto ya está vinculado devuelve el archivo existente.
     */
    public function vincularObjeto(User $user, InventarioObjeto $objeto, ?Carpeta $carpeta, ?string $lote = null): Archivos
    {
        $this->acceso->autorizar($this->acceso->esAdmin($user), 'Solo un administrador puede importar archivos recuperados.');

        return Cache::lock('documentos:vincular:'.$objeto->id, 30)->block(10, function () use ($user, $objeto, $carpeta, $lote) {
            $objeto->refresh();
            if ($objeto->archivo_id && ($existente = Archivos::withTrashed()->find($objeto->archivo_id))) {
                return $existente;
            }

            $archivo = DB::transaction(function () use ($user, $objeto, $carpeta, $lote) {
                $archivo = Archivos::create([
                    // "1750447964-5.3 G DISEÑO.pdf" → "5.3 G DISEÑO.pdf": la hora la puso la app anterior, no el usuario
                    'nombre' => NombresDocumentales::unicoArchivo($carpeta?->id, NombresDocumentales::limpiar(
                        preg_match(AnalizadorRecuperacion::SUBIDA, $objeto->nombre, $m) ? $m[2] : $objeto->nombre
                    )),
                    'carpeta_id' => $carpeta?->id,
                    'nombre_original' => $objeto->nombre,
                    'extension' => $objeto->extension,
                    'mime' => $objeto->mime,
                    'tamano' => $objeto->tamano,
                    'hash_md5' => $objeto->hash_md5,
                    'hash_original' => $objeto->hash_md5,
                    'identificador_documental' => $objeto->clave_patron ?? $this->identificadorDocumental($objeto->nombre),
                    'estado' => $carpeta ? Archivos::ESTADO_ACTIVO : Archivos::ESTADO_PENDIENTE,
                    'origen' => 'recuperacion',
                    'subido_por' => $user->id,
                ]);

                $version = $archivo->versiones()->create([
                    'numero' => 1,
                    'disco' => $objeto->disco,
                    'objeto' => $objeto->objeto,
                    'tamano' => $objeto->tamano,
                    'hash_md5' => $objeto->hash_md5,
                    'mime' => $objeto->mime,
                    'creado_por' => $user->id,
                    'comentario' => 'Original recuperado del almacenamiento',
                    'metadatos' => ['recuperacion_lote' => $lote, 'modificado_en' => $objeto->modificado_en?->toIso8601String()],
                ]);

                $archivo->version_actual_id = $version->id;
                $archivo->save();

                $objeto->update(['archivo_id' => $archivo->id, 'estado' => 'importado']);

                return $archivo;
            });

            Actividad::registrar('recuperacion.vinculado', $archivo, $archivo->nombre, ['objeto' => $objeto->objeto, 'lote' => $lote]);

            return $archivo;
        });
    }

    private function registrarVersion(User $user, Archivos $archivo, array $datos): ArchivoVersion
    {
        // Bloqueo de fila: dos versiones simultáneas no pueden obtener el mismo número
        return DB::transaction(function () use ($user, $archivo, $datos) {
            $bloqueado = Archivos::whereKey($archivo->id)->lockForUpdate()->firstOrFail();
            $numero = (int) ArchivoVersion::where('archivo_id', $bloqueado->id)->max('numero') + 1;

            $version = ArchivoVersion::create($datos + [
                'archivo_id' => $bloqueado->id,
                'numero' => $numero,
                'creado_por' => $user->id,
            ]);

            $archivo->forceFill([
                'version_actual_id' => $version->id,
                'tamano' => $version->tamano,
                'hash_md5' => $version->hash_md5,
                'hash_original' => $version->hash_original,
                'mime' => $version->mime,
            ])->save();

            DB::afterCommit(fn () => $this->indexarTexto($archivo));

            return $version;
        });
    }

    /** El texto de los PDF se extrae en segundo plano para la búsqueda por contenido. */
    private function indexarTexto(Archivos $archivo): void
    {
        if ($archivo->extension === 'pdf') {
            IndexarTextoJob::dispatch($archivo->id);
        }
    }

    /** @return array{0: ?string, 1: string} [extensión, mime real] */
    private function validar(string $rutaLocal, string $nombre): array
    {
        if (! is_file($rutaLocal)) {
            throw ValidationException::withMessages(['archivo' => 'La subida no se completó. Inténtalo de nuevo.']);
        }

        $extension = NombresDocumentales::extension($nombre);
        if (! in_array($extension, config('documentos.subida.extensiones'), true)) {
            throw ValidationException::withMessages(['archivo' => "El tipo de archivo «.{$extension}» no está permitido."]);
        }

        $tamano = (int) filesize($rutaLocal);
        if ($tamano > config('documentos.subida.max_kb') * 1024) {
            throw ValidationException::withMessages(['archivo' => 'El archivo supera el tamaño máximo permitido.']);
        }

        // Se usa el MIME detectado en el contenido, no el que declara el navegador
        $mime = (string) (mime_content_type($rutaLocal) ?: 'application/octet-stream');
        $esperado = Archivos::tipoPorExtension($extension);
        $coincide = match ($esperado) {
            'pdf' => $mime === 'application/pdf',
            'imagen' => str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml',
            'video' => str_starts_with($mime, 'video/') || $mime === 'application/octet-stream' && $extension === 'mov',
            'audio' => str_starts_with($mime, 'audio/') || in_array($mime, ['application/octet-stream', 'video/mp4'], true) && in_array($extension, ['mp3', 'm4a'], true),
            default => ! in_array($mime, ['text/html', 'application/x-dosexec', 'application/x-msdownload', 'image/svg+xml'], true),
        };

        if (! $coincide) {
            throw ValidationException::withMessages(['archivo' => "El contenido de «{$nombre}» no corresponde a su extensión."]);
        }

        return [$extension, $mime];
    }

    private function identificadorDocumental(string $nombre): ?string
    {
        return preg_match('/^(\d{3,})(?:[-_ ]\d{1,3})?\.[a-z0-9]+$/i', $nombre, $m) ? $m[1] : null;
    }
}
