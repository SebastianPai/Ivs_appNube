<?php

namespace App\Services\Documentos\Recuperacion;

use App\Models\InventarioEjecucion;
use App\Models\InventarioObjeto;
use App\Models\RecuperacionGrupo;
use Illuminate\Support\Facades\Storage;

class InformeRecuperacion
{
    public function resumen(): array
    {
        $porEstado = InventarioObjeto::selectRaw('estado, count(*) as total, coalesce(sum(tamano), 0) as bytes')->groupBy('estado')->get()->keyBy('estado');

        return [
            'objetos' => (int) $porEstado->sum('total'),
            'bytes' => (int) $porEstado->sum('bytes'),
            'pendientes' => (int) ($porEstado['pendiente']->total ?? 0),
            'agrupados' => (int) ($porEstado['agrupado']->total ?? 0),
            'sin_clasificar' => (int) ($porEstado['sin_clasificar']->total ?? 0),
            'importados' => (int) ($porEstado['importado']->total ?? 0),
            'descartados' => (int) ($porEstado['descartado']->total ?? 0),
            'inaccesibles' => (int) ($porEstado['inaccesible']->total ?? 0),
            'duplicados' => InventarioObjeto::whereNotNull('duplicado_de_id')->count(),
            'grupos_propuestos' => RecuperacionGrupo::where('estado', 'propuesto')->count(),
            'ultima_ejecucion' => InventarioEjecucion::latest('id')->first(),
        ];
    }

    /** Genera un informe Markdown y un CSV en storage/app/recuperacion (privado). Devuelve las rutas. */
    public function generar(): array
    {
        $r = $this->resumen();
        $fecha = now()->format('Ymd-His');
        $md = [];

        $md[] = '# Informe de recuperación documental';
        $md[] = '';
        $md[] = 'Generado: '.now()->toDateTimeString();
        if ($r['ultima_ejecucion']) {
            $e = $r['ultima_ejecucion'];
            $md[] = "Último inventario: disco `{$e->disco}`, estado **{$e->estado}**, {$e->procesados} objetos procesados ({$e->created_at}).";
        }
        $md[] = '';
        $md[] = '## Totales';
        $md[] = '';
        $md[] = '| Concepto | Cantidad |';
        $md[] = '|---|---|';
        foreach (['objetos', 'agrupados', 'sin_clasificar', 'importados', 'descartados', 'inaccesibles', 'duplicados', 'grupos_propuestos'] as $k) {
            $md[] = '| '.str_replace('_', ' ', ucfirst($k)).' | '.$r[$k].' |';
        }
        $md[] = '| Tamaño total | '.round($r['bytes'] / 1048576, 2).' MB |';
        $md[] = '';
        $md[] = '> Las agrupaciones son propuestas. Ninguna se aplicó sin aprobación de un administrador, y ningún objeto del almacenamiento fue movido ni borrado.';
        $md[] = '';
        $md[] = '## Propuestas por confianza';
        $md[] = '';
        $md[] = '| Tipo | Clave | Objetos | Confianza | Estado | Motivo |';
        $md[] = '|---|---|---|---|---|---|';
        foreach (RecuperacionGrupo::where('tipo', '!=', 'sin_clasificar')->orderByDesc('confianza')->orderBy('clave')->get() as $g) {
            $md[] = "| {$g->tipo} | `{$g->clave}` | {$g->total} | {$g->confianza}% | {$g->estado} | ".str_replace('|', '/', (string) $g->motivo).' |';
        }

        $duplicados = InventarioObjeto::whereNotNull('duplicado_de_id')->with('duplicadoDe')->orderBy('objeto')->get();
        $md[] = '';
        $md[] = '## Duplicados (mismo contenido)';
        $md[] = '';
        foreach ($duplicados as $d) {
            $md[] = "- `{$d->objeto}` es idéntico a `{$d->duplicadoDe?->objeto}`";
        }
        if ($duplicados->isEmpty()) {
            $md[] = 'Ninguno detectado (requiere checksums en el inventario).';
        }

        $md[] = '';
        $md[] = '## Sin clasificar (pendientes de revisión)';
        $md[] = '';
        foreach (InventarioObjeto::where('estado', 'sin_clasificar')->orderBy('objeto')->limit(500)->get() as $o) {
            $md[] = "- `{$o->objeto}` (".round($o->tamano / 1024, 1).' KB)';
        }

        $inaccesibles = InventarioObjeto::where('estado', 'inaccesible')->orderBy('objeto')->get();
        if ($inaccesibles->isNotEmpty()) {
            $md[] = '';
            $md[] = '## Inaccesibles';
            $md[] = '';
            foreach ($inaccesibles as $o) {
                $md[] = "- `{$o->objeto}`: figuraba en el inventario pero no se pudo leer al importar.";
            }
        }

        $csv = fopen('php://temp', 'w+');
        fputcsv($csv, ['objeto', 'tamano', 'md5', 'modificado', 'clave_patron', 'sufijo', 'grupo', 'confianza', 'estado', 'duplicado_de', 'archivo_id']);
        InventarioObjeto::with(['grupo', 'duplicadoDe'])->orderBy('objeto')->chunk(500, function ($objetos) use ($csv) {
            foreach ($objetos as $o) {
                fputcsv($csv, [$o->objeto, $o->tamano, $o->hash_md5, $o->modificado_en, $o->clave_patron, $o->sufijo, $o->grupo?->clave, $o->grupo?->confianza, $o->estado, $o->duplicadoDe?->objeto, $o->archivo_id]);
            }
        });
        rewind($csv);

        $rutaMd = "recuperacion/informe-$fecha.md";
        $rutaCsv = "recuperacion/inventario-$fecha.csv";
        Storage::disk('local')->put($rutaMd, implode("\n", $md)."\n");
        Storage::disk('local')->put($rutaCsv, stream_get_contents($csv));
        fclose($csv);

        return ['markdown' => $rutaMd, 'csv' => $rutaCsv];
    }
}
