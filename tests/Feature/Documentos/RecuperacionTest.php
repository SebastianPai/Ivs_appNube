<?php

namespace Tests\Feature\Documentos;

use App\Models\Archivos;
use App\Models\InventarioObjeto;
use App\Models\RecuperacionGrupo;
use App\Services\Documentos\Recuperacion\AnalizadorRecuperacion;
use App\Services\Documentos\Recuperacion\ImportadorRecuperacion;
use App\Services\Documentos\Recuperacion\InformeRecuperacion;
use App\Services\Documentos\Recuperacion\InventarioAlmacenamiento;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecuperacionTest extends TestCase
{
    use ConEntornoDocumental;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEntorno();

        // Simula el bucket con los archivos originales
        $disco = Storage::disk('documentos_local');
        foreach (['5289147-1.pdf', '5289147-2.pdf', '5289147-3.pdf', '5289148-1.pdf', '5289148-2.pdf', '5289199-1.pdf'] as $i => $nombre) {
            $disco->put($nombre, file_get_contents($this->pdfTemporal(1, "doc $i")));
        }
        $disco->put('expedientes/ACME/contrato.pdf', file_get_contents($this->pdfTemporal(1, 'acme')));
        $disco->put('expedientes/ACME/anexo.pdf', file_get_contents($this->pdfTemporal(1, 'anexo')));
        $disco->put('escaneo raro.pdf', file_get_contents($this->pdfTemporal(1, 'raro')));
        $disco->put('copia-de-5289147-1.pdf', $disco->get('5289147-1.pdf'));
    }

    private function inventariarYAnalizar(): array
    {
        app(InventarioAlmacenamiento::class)->ejecutar('documentos_local', null, $this->admin);

        return app(AnalizadorRecuperacion::class)->analizar();
    }

    public function test_inventario_es_de_solo_lectura_e_idempotente(): void
    {
        $antes = collect(Storage::disk('documentos_local')->allFiles())->mapWithKeys(fn ($f) => [$f => md5(Storage::disk('documentos_local')->get($f))]);

        app(InventarioAlmacenamiento::class)->ejecutar('documentos_local', null, $this->admin);
        $segunda = app(InventarioAlmacenamiento::class)->ejecutar('documentos_local', null, $this->admin);

        $this->assertSame(10, InventarioObjeto::count());
        $this->assertSame(0, $segunda->nuevos);
        $this->assertSame('completado', $segunda->estado);
        $despues = collect(Storage::disk('documentos_local')->allFiles())->mapWithKeys(fn ($f) => [$f => md5(Storage::disk('documentos_local')->get($f))]);
        $this->assertEquals($antes, $despues);
    }

    public function test_inventario_interrumpido_se_reanuda(): void
    {
        $ejecucion = \App\Models\InventarioEjecucion::create(['disco' => 'documentos_local', 'estado' => 'error', 'procesados' => 3]);

        $reanudada = app(InventarioAlmacenamiento::class)->ejecutar('documentos_local', null, $this->admin, reanudar: true);

        $this->assertSame($ejecucion->id, $reanudada->id);
        $this->assertSame('completado', $reanudada->estado);
    }

    public function test_analisis_agrupa_con_confianza_sin_inventar_relaciones(): void
    {
        $resumen = $this->inventariarYAnalizar();

        $g147 = RecuperacionGrupo::where('tipo', 'patron_numerico')->where('clave', '5289147')->firstOrFail();
        $this->assertSame(3, $g147->total);
        $this->assertSame(80, $g147->confianza);
        $this->assertStringContainsString('hipótesis', $g147->motivo);

        $this->assertSame(85, RecuperacionGrupo::where('tipo', 'carpeta_fisica')->where('clave', 'expedientes/ACME')->value('confianza'));
        $this->assertSame(30, RecuperacionGrupo::where('tipo', 'individual')->where('clave', '5289199')->value('confianza'));

        // Escenario 15: lo que no sigue un patrón queda pendiente de revisión, no se asigna a ningún grupo inventado
        $raro = InventarioObjeto::where('objeto', 'escaneo raro.pdf')->firstOrFail();
        $this->assertSame('sin_clasificar', $raro->estado);
        $this->assertSame(0, $raro->grupo->confianza);

        // Duplicado detectado por contenido
        $this->assertNotNull(InventarioObjeto::where('objeto', 'copia-de-5289147-1.pdf')->value('duplicado_de_id'));
        $this->assertGreaterThanOrEqual(1, $resumen['duplicados']);

        // Nada se importó automáticamente
        $this->assertSame(0, Archivos::count());
    }

    /** Escenario 14 y 13 */
    public function test_importar_vincula_el_objeto_existente_sin_volver_a_subirlo_y_es_idempotente(): void
    {
        $this->inventariarYAnalizar();
        $grupo = RecuperacionGrupo::where('clave', '5289147')->firstOrFail();
        $padre = $this->crearCarpeta(null, 'Expedientes');
        $objetosAntes = count(Storage::disk('documentos_local')->allFiles());

        $r = app(ImportadorRecuperacion::class)->importarGrupo($this->admin, $grupo, $padre, true, 'Expediente 5289147');

        $this->assertSame(3, $r['importados']);
        $this->assertCount($objetosAntes, Storage::disk('documentos_local')->allFiles());
        $archivo = Archivos::where('nombre', '5289147-1.pdf')->firstOrFail();
        $this->assertSame('5289147-1.pdf', $archivo->versionActual->objeto);
        $this->assertSame('recuperacion', $archivo->origen);
        $this->assertSame('5289147', $archivo->identificador_documental);

        // Accesible de verdad: se puede descargar
        $this->actingAs($this->usuario)->get(route('documentos.descargar', $archivo))->assertOk();

        // Repetir la importación no duplica
        $grupo->update(['estado' => 'propuesto']);
        $r2 = app(ImportadorRecuperacion::class)->importarGrupo($this->admin, $grupo->fresh(), $padre, false);
        $this->assertSame(0, $r2['importados']);
        $this->assertSame(3, $r2['ya_vinculados']);
        $this->assertSame(3, Archivos::count());
    }

    public function test_revertir_borra_solo_registros_y_conserva_los_objetos(): void
    {
        $this->inventariarYAnalizar();
        $grupo = RecuperacionGrupo::where('clave', '5289148')->firstOrFail();
        $padre = $this->crearCarpeta(null, 'Expedientes');
        app(ImportadorRecuperacion::class)->importarGrupo($this->admin, $grupo, $padre, true, 'Exp 5289148');

        $r = app(ImportadorRecuperacion::class)->revertir($this->admin, $grupo->fresh());

        $this->assertSame(2, $r['revertidos']);
        $this->assertSame(0, Archivos::count());
        Storage::disk('documentos_local')->assertExists(['5289148-1.pdf', '5289148-2.pdf']);
        $this->assertSame('propuesto', $grupo->fresh()->estado);
        $this->assertNull(\App\Models\Carpeta::where('nombre', 'Exp 5289148')->first());
    }

    public function test_purgar_un_archivo_recuperado_no_borra_el_original_del_bucket(): void
    {
        $this->inventariarYAnalizar();
        $padre = $this->crearCarpeta(null, 'Expedientes');
        app(ImportadorRecuperacion::class)->importarGrupo($this->admin, RecuperacionGrupo::where('clave', '5289199')->firstOrFail(), $padre, false);
        $archivo = Archivos::firstOrFail();

        $this->archivos()->enviarPapelera($this->admin, $archivo);
        $this->archivos()->purgar($this->admin, Archivos::withTrashed()->find($archivo->id));

        Storage::disk('documentos_local')->assertExists('5289199-1.pdf');
    }

    public function test_objeto_inaccesible_no_se_da_por_recuperado(): void
    {
        $this->inventariarYAnalizar();
        Storage::disk('documentos_local')->delete('5289199-1.pdf');
        $padre = $this->crearCarpeta(null, 'Expedientes');

        $r = app(ImportadorRecuperacion::class)->importarGrupo($this->admin, RecuperacionGrupo::where('clave', '5289199')->firstOrFail(), $padre, false);

        $this->assertSame(0, $r['importados']);
        $this->assertSame(1, $r['inaccesibles']);
        $this->assertSame('inaccesible', InventarioObjeto::where('objeto', '5289199-1.pdf')->value('estado'));
    }

    public function test_solo_admin_importa(): void
    {
        $this->inventariarYAnalizar();

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(ImportadorRecuperacion::class)->importarGrupo($this->gerencia, RecuperacionGrupo::firstOrFail(), null, true, 'X');
    }

    public function test_informe_se_genera(): void
    {
        $this->inventariarYAnalizar();

        $rutas = app(InformeRecuperacion::class)->generar();

        Storage::disk('local')->assertExists([$rutas['markdown'], $rutas['csv']]);
        $this->assertStringContainsString('5289147', Storage::disk('local')->get($rutas['markdown']));
    }
}
