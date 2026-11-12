<?php

namespace App\Services\Documentos\Recuperacion;

use App\Models\Actividad;
use App\Models\ArchivoVersion;
use App\Models\Archivos;
use App\Models\Carpeta;
use App\Models\InventarioObjeto;
use App\Models\RecuperacionGrupo;
use App\Models\User;
use App\Services\Documentos\AccesoDocumental;
use App\Services\Documentos\AlmacenDocumental;
use App\Services\Documentos\GestorArchivos;
use App\Services\Documentos\GestorCarpetas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Aplica una propuesta aprobada creando registros que REFERENCIAN los objetos originales.
 * No mueve ni copia binarios. Se puede revertir: revertir solo borra registros de la BD.
 */
class ImportadorRecuperacion
{
    public function __construct(
        private AccesoDocumental $acceso,
        private AlmacenDocumental $almacen,
        private GestorArchivos $archivos,
        private GestorCarpetas $carpetas,
    ) {}

    /** @return array{importados: int, inaccesibles: int, ya_vinculados: int, carpeta: ?Carpeta} */
    public function importarGrupo(User $user, RecuperacionGrupo $grupo, ?Carpeta $padre, bool $crearSubcarpeta, ?string $nombreSubcarpeta = null, string $unidad = Carpeta::UNIDAD_COMPARTIDA): array
    {
        $this->acceso->autorizar($this->acceso->esAdmin($user), 'Solo un administrador puede importar archivos recuperados.');

        if ($grupo->estado === 'descartado') {
            throw new \InvalidArgumentException('El grupo está descartado.');
        }

        $grupo->lote ??= (string) Str::uuid();

        $destino = $padre;
        if ($crearSubcarpeta) {
            $destino = $this->carpetas->crear($user, $padre, $nombreSubcarpeta ?: basename($grupo->clave), $unidad);
            $grupo->carpeta_creada = true;
        }

        $resultado = ['importados' => 0, 'inaccesibles' => 0, 'ya_vinculados' => 0, 'carpeta' => $destino];

        foreach ($grupo->objetos()->where('estado', '!=', 'descartado')->get() as $objeto) {
            if ($objeto->archivo_id) {
                $resultado['ya_vinculados']++;
                continue;
            }

            // Solo se da por recuperado lo que realmente se puede leer en el almacenamiento
            if (! $this->almacen->existe($objeto->disco, $objeto->objeto)) {
                $objeto->update(['estado' => 'inaccesible']);
                $resultado['inaccesibles']++;
                continue;
            }

            $this->archivos->vincularObjeto($user, $objeto, $destino, $grupo->lote);
            $resultado['importados']++;
        }

        $grupo->fill([
            'estado' => 'importado',
            'carpeta_destino_id' => $destino?->id,
            'revisado_por' => $user->id,
            'revisado_en' => now(),
        ])->save();

        Actividad::registrar('recuperacion.grupo_importado', $grupo, $grupo->clave, array_merge(
            array_diff_key($resultado, ['carpeta' => true]),
            ['carpeta_id' => $destino?->id, 'lote' => $grupo->lote]
        ));

        return $resultado;
    }

    /** Asigna a una carpeta un archivo que quedó pendiente de revisión. */
    public function asignarObjeto(User $user, InventarioObjeto $objeto, Carpeta $carpeta): Archivos
    {
        $this->acceso->autorizar($this->acceso->esAdmin($user));

        if (! $this->almacen->existe($objeto->disco, $objeto->objeto)) {
            $objeto->update(['estado' => 'inaccesible']);
            throw new \RuntimeException('El objeto no es accesible en el almacenamiento; no se puede dar por recuperado.');
        }

        if ($objeto->archivo_id && ($archivo = Archivos::withTrashed()->find($objeto->archivo_id))) {
            return $this->archivos->mover($user, $archivo, $carpeta);
        }

        return $this->archivos->vincularObjeto($user, $objeto, $carpeta, $objeto->grupo?->lote);
    }

    public function descartar(User $user, RecuperacionGrupo $grupo): void
    {
        $this->acceso->autorizar($this->acceso->esAdmin($user));

        $grupo->objetos()->whereNull('archivo_id')->update(['estado' => 'descartado']);
        $grupo->update(['estado' => 'descartado', 'revisado_por' => $user->id, 'revisado_en' => now()]);

        Actividad::registrar('recuperacion.grupo_descartado', $grupo, $grupo->clave);
    }

    /**
     * Deshace una importación borrando solo los registros creados. Si un usuario ya subió versiones nuevas
     * de un archivo, ese archivo se conserva para no perder trabajo.
     *
     * @return array{revertidos: int, conservados: int}
     */
    public function revertir(User $user, RecuperacionGrupo $grupo): array
    {
        $this->acceso->autorizar($this->acceso->esAdmin($user));

        $resultado = ['revertidos' => 0, 'conservados' => 0];

        DB::transaction(function () use ($grupo, &$resultado) {
            foreach ($grupo->objetos()->whereNotNull('archivo_id')->get() as $objeto) {
                $archivo = Archivos::withTrashed()->find($objeto->archivo_id);
                if (! $archivo) {
                    $objeto->update(['archivo_id' => null, 'estado' => 'agrupado']);
                    continue;
                }

                if ($archivo->versiones()->count() > 1 || $archivo->origen !== 'recuperacion') {
                    $resultado['conservados']++;
                    continue;
                }

                $archivo->forceFill(['version_actual_id' => null])->saveQuietly();
                ArchivoVersion::where('archivo_id', $archivo->id)->delete();
                $archivo->forceDelete();
                $objeto->update(['archivo_id' => null, 'estado' => 'agrupado']);
                $resultado['revertidos']++;
            }

            $carpeta = $grupo->carpeta_destino_id ? Carpeta::withTrashed()->find($grupo->carpeta_destino_id) : null;
            if ($grupo->carpeta_creada && $carpeta
                && ! Archivos::withTrashed()->where('carpeta_id', $carpeta->id)->exists()
                && ! Carpeta::withTrashed()->where('carpeta_padre_id', $carpeta->id)->exists()) {
                $carpeta->forceDelete();
            }

            $grupo->update([
                'estado' => $resultado['conservados'] > 0 ? 'importado' : 'propuesto',
                'carpeta_destino_id' => $resultado['conservados'] > 0 ? $grupo->carpeta_destino_id : null,
                'carpeta_creada' => $resultado['conservados'] > 0 && $grupo->carpeta_creada,
            ]);
        });

        Actividad::registrar('recuperacion.grupo_revertido', $grupo, $grupo->clave, $resultado);

        return $resultado;
    }
}
