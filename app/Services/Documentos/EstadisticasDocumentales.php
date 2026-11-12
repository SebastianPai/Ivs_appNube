<?php

namespace App\Services\Documentos;

use App\Models\ArchivoVersion;
use App\Models\Archivos;
use App\Models\Carpeta;
use App\Models\InventarioObjeto;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Uso del almacenamiento calculado SOLO con la base de datos (tamaños registrados de cada versión).
 * No consulta el bucket: ver el panel no genera operaciones cobradas en Firebase.
 */
class EstadisticasDocumentales
{
    public function resumen(bool $refrescar = false): array
    {
        if ($refrescar) {
            Cache::forget('documentos:estadisticas');
        }

        return Cache::remember('documentos:estadisticas', now()->addMinutes(5), fn () => $this->calcular());
    }

    /** Resumen breve para la barra lateral (usa la misma caché). */
    public function breve(): array
    {
        $r = $this->resumen();

        return ['bytes' => $r['bytes_ocupados'], 'archivos' => $r['archivos']];
    }

    private function calcular(): array
    {
        // Un objeto físico puede estar referenciado por varias versiones (copias, restauraciones): se cuenta una vez
        $objetos = DB::query()->fromSub(
            ArchivoVersion::query()->select('disco', 'objeto', DB::raw('max(tamano) as tamano'))->groupBy('disco', 'objeto'),
            'o'
        );
        $bytesGestionados = (int) (clone $objetos)->sum('tamano');
        $objetosFisicos = (int) (clone $objetos)->count();

        $bytesActuales = (int) Archivos::sum('tamano');
        $bytesPapelera = (int) Archivos::onlyTrashed()->sum('tamano');
        $originalesSinImportar = InventarioObjeto::whereNull('archivo_id')->whereNotIn('estado', ['descartado', 'inaccesible']);
        $bytesSinImportar = (int) (clone $originalesSinImportar)->sum('tamano');
        $bytesOcupados = $bytesGestionados + $bytesSinImportar;

        $porTipo = [];
        foreach (Archivos::select('extension', DB::raw('count(*) as total'), DB::raw('coalesce(sum(tamano), 0) as bytes'))->groupBy('extension')->get() as $fila) {
            $tipo = Archivos::tipoPorExtension($fila->extension);
            $porTipo[$tipo]['total'] = ($porTipo[$tipo]['total'] ?? 0) + $fila->total;
            $porTipo[$tipo]['bytes'] = ($porTipo[$tipo]['bytes'] ?? 0) + (int) $fila->bytes;
        }
        uasort($porTipo, fn ($a, $b) => $b['bytes'] <=> $a['bytes']);

        // Peso por carpeta de primer nivel (agrupando por el primer id de la ruta materializada)
        $porRaiz = [];
        $filas = Archivos::query()->join('carpetas', 'carpetas.id', '=', 'archivos.carpeta_id')
            ->select('carpetas.ruta', DB::raw('count(*) as total'), DB::raw('coalesce(sum(archivos.tamano), 0) as bytes'))
            ->groupBy('carpetas.ruta')->get();
        foreach ($filas as $fila) {
            $raiz = (int) (array_values(array_filter(explode('/', $fila->ruta)))[0] ?? 0);
            $porRaiz[$raiz]['total'] = ($porRaiz[$raiz]['total'] ?? 0) + $fila->total;
            $porRaiz[$raiz]['bytes'] = ($porRaiz[$raiz]['bytes'] ?? 0) + (int) $fila->bytes;
        }
        $nombres = Carpeta::withTrashed()->whereIn('id', array_keys($porRaiz) ?: [0])->get(['id', 'nombre', 'unidad'])->keyBy('id');
        uasort($porRaiz, fn ($a, $b) => $b['bytes'] <=> $a['bytes']);
        $carpetas = collect($porRaiz)->take(8)->map(fn ($d, $id) => $d + [
            'nombre' => $nombres[$id]->nombre ?? 'Carpeta eliminada',
            'privada' => ($nombres[$id]->unidad ?? null) === Carpeta::UNIDAD_PRIVADA,
        ])->values()->all();

        $ahorro = ['bytes' => 0, 'archivos' => 0];
        ArchivoVersion::whereNotNull('metadatos')->select('id', 'metadatos')->chunkById(1000, function ($versiones) use (&$ahorro) {
            foreach ($versiones as $v) {
                $c = $v->metadatos['compresion'] ?? null;
                if ($c && ($c['aplicada'] ?? false)) {
                    $ahorro['bytes'] += max(0, (int) $c['tamano_original'] - (int) $c['tamano_final']);
                    $ahorro['archivos']++;
                }
            }
        });

        $usuarios = Archivos::query()->join('users', 'users.id', '=', 'archivos.subido_por')
            ->select('users.name', DB::raw('count(*) as total'), DB::raw('coalesce(sum(archivos.tamano), 0) as bytes'))
            ->groupBy('users.id', 'users.name')->orderByDesc('bytes')->limit(5)->get()
            ->map(fn ($f) => ['nombre' => $f->name, 'total' => (int) $f->total, 'bytes' => (int) $f->bytes])->all();

        $meses = [];
        foreach (range(5, 0) as $hace) {
            $inicio = now()->startOfMonth()->subMonths($hace);
            $meses[] = [
                'etiqueta' => ucfirst($inicio->translatedFormat('M Y')),
                'total' => Archivos::withTrashed()->whereBetween('created_at', [$inicio, $inicio->copy()->endOfMonth()])->count(),
            ];
        }

        return [
            'calculado_en' => now(),
            'archivos' => Archivos::count(),
            'carpetas' => Carpeta::count(),
            'carpetas_privadas' => Carpeta::where('unidad', Carpeta::UNIDAD_PRIVADA)->count(),
            'versiones' => ArchivoVersion::count(),
            'objetos_fisicos' => $objetosFisicos,
            'bytes_ocupados' => $bytesOcupados,
            'bytes_actuales' => $bytesActuales,
            'bytes_historial' => max(0, $bytesGestionados - $bytesActuales - $bytesPapelera),
            'bytes_papelera' => $bytesPapelera,
            'archivos_papelera' => Archivos::onlyTrashed()->count(),
            'bytes_sin_importar' => $bytesSinImportar,
            'originales_sin_importar' => (clone $originalesSinImportar)->count(),
            'pendientes_revision' => Archivos::where('estado', Archivos::ESTADO_PENDIENTE)->count(),
            'ahorro_compresion' => $ahorro,
            'costo_mensual_usd' => round($bytesOcupados / 1024 ** 3 * (float) config('documentos.precio_gb_mes_usd'), 2),
            'por_tipo' => $porTipo,
            'carpetas_pesadas' => $carpetas,
            'usuarios' => $usuarios,
            'meses' => $meses,
        ];
    }
}
