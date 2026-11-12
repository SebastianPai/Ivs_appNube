<?php

namespace App\Services\Documentos;

use App\Jobs\ReubicarObjetosJob;
use App\Models\Actividad;
use App\Models\Archivos;
use App\Models\Carpeta;
use App\Models\CarpetaPermiso;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class GestorCarpetas
{
    public function __construct(
        private AccesoDocumental $acceso,
        private GestorArchivos $archivos,
    ) {}

    public function crear(User $user, ?Carpeta $padre, string $nombre, string $unidad = Carpeta::UNIDAD_COMPARTIDA): Carpeta
    {
        $this->acceso->autorizar($this->acceso->puedeCrearCarpetaEn($user, $padre), 'No tienes permiso para crear carpetas aquí.');

        $unidad = $padre?->unidad ?? $unidad;
        if (! in_array($unidad, [Carpeta::UNIDAD_COMPARTIDA, Carpeta::UNIDAD_PRIVADA], true)) {
            throw new InvalidArgumentException('Unidad no válida.');
        }

        $carpeta = $this->bloquearNombres($padre?->id, $unidad, fn () => $this->insertar($user, $padre, NombresDocumentales::limpiar($nombre), $unidad));

        Actividad::registrar('carpeta.creada', $carpeta, $carpeta->nombre, ['padre_id' => $padre?->id, 'unidad' => $unidad]);

        return $carpeta;
    }

    /**
     * Recorre (o crea) una ruta de subcarpetas bajo $base, p. ej. ["Clientes", "ACME", "2026"].
     * Reutiliza las carpetas que ya existen con ese nombre: subir dos veces la misma carpeta no la duplica.
     */
    public function asegurarRuta(User $user, Carpeta $base, array $segmentos): Carpeta
    {
        $actual = $base;

        foreach ($segmentos as $segmento) {
            $nombre = NombresDocumentales::limpiar((string) $segmento);
            $padre = $actual;

            [$actual, $creada] = $this->bloquearNombres($padre->id, $padre->unidad, function () use ($user, $padre, $nombre) {
                $existente = Carpeta::where('carpeta_padre_id', $padre->id)
                    ->whereRaw('lower(nombre) = ?', [mb_strtolower($nombre)])
                    ->first();

                if ($existente && $this->acceso->puedeVerCarpeta($user, $existente)) {
                    return [$existente, false];
                }

                $this->acceso->autorizar($this->acceso->puedeCrearCarpetaEn($user, $padre), "No tienes permiso para crear carpetas en «{$padre->nombre}».");

                return [$this->insertar($user, $padre, $nombre, $padre->unidad), true];
            });

            if ($creada) {
                Actividad::registrar('carpeta.creada', $actual, $actual->nombre, ['padre_id' => $padre->id, 'subida_de_carpeta' => true]);
            }
        }

        return $actual;
    }

    /** Inserta la carpeta con su ruta y zona de restricción. Debe llamarse dentro del bloqueo de nombres. */
    private function insertar(User $user, ?Carpeta $padre, string $nombre, string $unidad): Carpeta
    {
        return DB::transaction(function () use ($user, $padre, $nombre, $unidad) {
            $carpeta = Carpeta::create([
                'nombre' => NombresDocumentales::unicoCarpeta($padre?->id, $unidad, $nombre),
                'carpeta_padre_id' => $padre?->id,
                'unidad' => $unidad,
                // Las carpetas raíz de la unidad privada siempre son restringidas
                'restringida' => $padre === null && $unidad === Carpeta::UNIDAD_PRIVADA,
                'creado_por' => $user->id,
            ]);

            $carpeta->ruta = ($padre?->ruta ?: '/').$carpeta->id.'/';
            $carpeta->restriccion_id = $carpeta->restringida ? $carpeta->id : $padre?->restriccion_id;
            $carpeta->save();

            return $carpeta;
        });
    }

    public function renombrar(User $user, Carpeta $carpeta, string $nombre): Carpeta
    {
        $this->acceso->autorizar($this->acceso->puedeEditarEn($user, $carpeta->padre) && $this->acceso->puedeEditarEn($user, $carpeta));

        $anterior = $carpeta->nombre;

        $this->bloquearNombres($carpeta->carpeta_padre_id, $carpeta->unidad, function () use ($carpeta, $nombre) {
            $carpeta->nombre = NombresDocumentales::unicoCarpeta($carpeta->carpeta_padre_id, $carpeta->unidad, NombresDocumentales::limpiar($nombre), $carpeta->id);
            $carpeta->save();
        });

        Actividad::registrar('carpeta.renombrada', $carpeta, "$anterior → {$carpeta->nombre}");
        $this->reubicarObjetos($carpeta);

        return $carpeta;
    }

    /** Color e ícono: lo puede cambiar quien puede editar la carpeta. Solo valores de la paleta configurada. */
    public function personalizar(User $user, Carpeta $carpeta, ?string $color, ?string $icono): Carpeta
    {
        $this->acceso->autorizar($this->acceso->puedeEditarEn($user, $carpeta), 'No tienes permiso para personalizar esta carpeta.');

        if (($color !== null && ! array_key_exists($color, config('documentos.colores')))
            || ($icono !== null && ! array_key_exists($icono, config('documentos.iconos')))) {
            throw new InvalidArgumentException('Color o ícono no válido.');
        }

        $carpeta->forceFill(['color' => $color, 'icono' => $icono === 'folder' ? null : $icono])->save();

        Actividad::registrar('carpeta.personalizada', $carpeta, $carpeta->nombre, ['color' => $color, 'icono' => $icono]);

        return $carpeta;
    }

    public function mover(User $user, Carpeta $carpeta, ?Carpeta $destino, ?string $unidadDestino = null): Carpeta
    {
        $unidadDestino = $destino?->unidad ?? $unidadDestino ?? $carpeta->unidad;

        $this->acceso->autorizar(
            $this->acceso->puedeMover($user, $carpeta->padre, $destino, $carpeta->unidad, $unidadDestino)
                && $this->acceso->puedeEditarEn($user, $carpeta),
            'No tienes permiso para mover esta carpeta a ese destino.'
        );

        if ($destino && ($destino->id === $carpeta->id || $destino->esDescendienteDe($carpeta))) {
            throw new InvalidArgumentException('No se puede mover una carpeta dentro de sí misma.');
        }

        $origenId = $carpeta->carpeta_padre_id;

        // Un solo movimiento a la vez: recalcular rutas en paralelo podría dejar el árbol inconsistente
        Cache::lock('documentos:arbol', 60)->block(10, function () use ($carpeta, $destino, $unidadDestino) {
            DB::transaction(function () use ($carpeta, $destino, $unidadDestino) {
                $carpeta->refresh();
                $carpeta->carpeta_padre_id = $destino?->id;
                $carpeta->unidad = $unidadDestino;
                $carpeta->nombre = NombresDocumentales::unicoCarpeta($destino?->id, $unidadDestino, $carpeta->nombre, $carpeta->id);
                $carpeta->save();

                $this->recalcularSubarbol($carpeta);
            });
        });

        Actividad::registrar('carpeta.movida', $carpeta, $carpeta->nombre, ['desde' => $origenId, 'hacia' => $destino?->id, 'unidad' => $unidadDestino]);
        $this->reubicarObjetos($carpeta->refresh());

        return $carpeta;
    }

    /** Lleva en el bucket los archivos de la carpeta y sus subcarpetas a su nueva ruta (solo los de ruta legible). */
    private function reubicarObjetos(Carpeta $carpeta): void
    {
        $carpetas = Carpeta::withTrashed()->where('ruta', 'like', addcslashes((string) $carpeta->ruta, '%_\\').'%')->pluck('id');

        Archivos::withTrashed()->whereIn('carpeta_id', $carpetas)
            // Filtro amplio ("_" es comodín en LIKE); el trabajo comprueba el formato exacto con esReubicable()
            ->whereHas('versiones', fn ($q) => $q->where('objeto', 'like', trim((string) config('documentos.prefijo_objetos'), '/').'/%__v%'))
            ->pluck('id')
            ->chunk(200)
            ->each(fn ($ids) => ReubicarObjetosJob::dispatch($ids->values()->all())->afterCommit());
    }

    /** Admin: marca la carpeta como restringida (o no) y reemplaza sus permisos explícitos. */
    public function configurarAcceso(User $user, Carpeta $carpeta, bool $restringida, array $permisos): Carpeta
    {
        $this->acceso->autorizar($this->acceso->puedeGestionarPermisos($user));

        if ($carpeta->carpeta_padre_id === null && $carpeta->unidad === Carpeta::UNIDAD_PRIVADA) {
            $restringida = true;
        }

        $antes = $carpeta->permisos()->get(['user_id', 'role_id', 'nivel'])->toArray();

        Cache::lock('documentos:arbol', 60)->block(10, function () use ($user, $carpeta, $restringida, $permisos) {
            DB::transaction(function () use ($user, $carpeta, $restringida, $permisos) {
                $carpeta->restringida = $restringida;
                $carpeta->save();
                $this->recalcularSubarbol($carpeta);

                $carpeta->permisos()->delete();
                foreach ($permisos as $permiso) {
                    $userId = $permiso['user_id'] ?? null;
                    $roleId = $permiso['role_id'] ?? null;
                    if (! $userId && ! $roleId) {
                        continue;
                    }

                    $carpeta->permisos()->create([
                        'user_id' => $userId ?: null,
                        'role_id' => $userId ? null : $roleId,
                        'nivel' => ($permiso['nivel'] ?? '') === CarpetaPermiso::NIVEL_EDITAR ? CarpetaPermiso::NIVEL_EDITAR : CarpetaPermiso::NIVEL_VER,
                        'otorgado_por' => $user->id,
                    ]);
                }
            });
        });

        Actividad::registrar('carpeta.permisos', $carpeta, $carpeta->nombre, [
            'restringida' => $restringida,
            'antes' => $antes,
            'despues' => $carpeta->permisos()->get(['user_id', 'role_id', 'nivel'])->toArray(),
        ]);

        return $carpeta->refresh();
    }

    public function enviarPapelera(User $user, Carpeta $carpeta): void
    {
        $this->acceso->autorizar($this->acceso->puedeEliminar($user), 'Solo un administrador puede enviar elementos a la papelera.');

        $lote = (string) Str::uuid();

        DB::transaction(function () use ($user, $carpeta, $lote) {
            $ids = Carpeta::where('ruta', 'like', $carpeta->ruta.'%')->pluck('id');
            $marca = ['papelera_lote' => $lote, 'eliminado_por' => $user->id, 'deleted_at' => now()];

            Archivos::whereIn('carpeta_id', $ids)->update($marca);
            Carpeta::whereIn('id', $ids)->update($marca);
            Carpeta::withTrashed()->whereKey($carpeta->id)->update(['papelera_raiz' => true]);
        });

        Actividad::registrar('carpeta.papelera', $carpeta, $carpeta->nombre, ['lote' => $lote]);
    }

    public function restaurar(User $user, Carpeta $carpeta): Carpeta
    {
        $this->acceso->autorizar($this->acceso->puedeEliminar($user), 'Solo un administrador puede restaurar elementos.');

        if (! $carpeta->trashed() || ! $carpeta->papelera_raiz) {
            throw new InvalidArgumentException('Esta carpeta no está en la papelera.');
        }

        $padre = $carpeta->carpeta_padre_id ? Carpeta::withTrashed()->find($carpeta->carpeta_padre_id) : null;
        if ($padre?->trashed()) {
            throw new InvalidArgumentException("Primero restaura la carpeta contenedora «{$padre->nombre}».");
        }

        $lote = $carpeta->papelera_lote;

        $this->bloquearNombres($carpeta->carpeta_padre_id, $carpeta->unidad, function () use ($carpeta, $lote) {
            DB::transaction(function () use ($carpeta, $lote) {
                $limpio = ['papelera_lote' => null, 'papelera_raiz' => false, 'eliminado_por' => null, 'deleted_at' => null];
                Carpeta::withTrashed()->where('papelera_lote', $lote)->update($limpio);
                Archivos::withTrashed()->where('papelera_lote', $lote)->update($limpio);

                $carpeta->refresh();
                $carpeta->nombre = NombresDocumentales::unicoCarpeta($carpeta->carpeta_padre_id, $carpeta->unidad, $carpeta->nombre, $carpeta->id);
                $carpeta->save();
            });
        });

        Actividad::registrar('carpeta.restaurada', $carpeta, $carpeta->nombre, ['lote' => $lote]);

        return $carpeta;
    }

    /** Eliminación definitiva de una carpeta que ya está en la papelera, con todo su contenido. */
    public function purgar(User $user, Carpeta $carpeta): void
    {
        $this->acceso->autorizar($this->acceso->puedeEliminar($user), 'Solo un administrador puede eliminar definitivamente.');

        if (! $carpeta->trashed()) {
            throw new InvalidArgumentException('Solo se eliminan definitivamente elementos que están en la papelera.');
        }

        $nombre = $carpeta->nombre;
        $subarbol = Carpeta::withTrashed()->where('ruta', 'like', $carpeta->ruta.'%')->get();

        Archivos::withTrashed()->whereIn('carpeta_id', $subarbol->pluck('id'))->get()
            ->each(fn (Archivos $archivo) => $this->archivos->purgarSinAutorizar($archivo));

        DB::transaction(function () use ($subarbol) {
            // De las hojas hacia la raíz para respetar las claves foráneas
            $subarbol->sortByDesc(fn (Carpeta $c) => strlen($c->ruta))
                ->each(fn (Carpeta $c) => $c->forceDelete());
        });

        Actividad::registrar('carpeta.eliminada_definitivamente', null, $nombre, ['carpeta_id' => $carpeta->id, 'subcarpetas' => $subarbol->count()]);
    }

    /** Recalcula ruta, unidad y zona de restricción de una carpeta y todo su subárbol. */
    public function recalcularSubarbol(Carpeta $raiz): void
    {
        $padre = $raiz->carpeta_padre_id ? Carpeta::withTrashed()->find($raiz->carpeta_padre_id) : null;
        $rutaAnterior = (string) $raiz->ruta;

        $nodos = $rutaAnterior !== ''
            ? Carpeta::withTrashed()->where('ruta', 'like', $rutaAnterior.'%')->get()
            : collect();
        if (! $nodos->contains('id', $raiz->id)) {
            $nodos->push($raiz);
        }

        $porId = $nodos->keyBy('id');
        $porId[$raiz->id] = $raiz;
        $procesados = [];

        // Por profundidad: un padre siempre se procesa antes que sus hijos
        foreach ($nodos->sortBy(fn (Carpeta $c) => $c->id === $raiz->id ? -1 : strlen((string) $c->ruta)) as $nodo) {
            $nodo = $porId[$nodo->id];
            $p = $nodo->id === $raiz->id ? $padre : ($procesados[$nodo->carpeta_padre_id] ?? $porId[$nodo->carpeta_padre_id] ?? null);

            $nodo->unidad = $p ? $p->unidad : $nodo->unidad;
            if ($p === null && $nodo->unidad === Carpeta::UNIDAD_PRIVADA) {
                $nodo->restringida = true;
            }
            $nodo->ruta = ($p ? $p->ruta : '/').$nodo->id.'/';
            $nodo->restriccion_id = $nodo->restringida ? $nodo->id : $p?->restriccion_id;

            if ($nodo->isDirty()) {
                $nodo->saveQuietly();
            }
            $procesados[$nodo->id] = $nodo;
        }
    }

    private function bloquearNombres(?int $padreId, string $unidad, callable $operacion): mixed
    {
        return Cache::lock("documentos:nombres:c:$unidad:".($padreId ?? 0), 15)->block(10, $operacion);
    }
}
