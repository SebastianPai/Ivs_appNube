<?php

namespace Tests\Feature\Documentos;

use App\Filament\Pages\HerramientasPdf;
use App\Filament\Pages\Unidad;
use App\Filament\Resources\ActividadResource;
use App\Filament\Resources\RecuperacionGrupoResource;
use App\Models\ArchivoVersion;
use App\Models\Archivos;
use App\Models\Carpeta;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class InterfazTest extends TestCase
{
    use ConEntornoDocumental;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEntorno();
    }

    public function test_las_paginas_cargan_segun_el_rol(): void
    {
        $this->actingAs($this->admin);
        $this->get(Unidad::getUrl())->assertOk();
        $this->get(HerramientasPdf::getUrl())->assertOk();
        $this->get(RecuperacionGrupoResource::getUrl())->assertOk();
        $this->get(ActividadResource::getUrl())->assertOk();

        $this->actingAs($this->usuario);
        $this->get(Unidad::getUrl())->assertOk();
        $this->get(RecuperacionGrupoResource::getUrl())->assertForbidden();
        $this->get(ActividadResource::getUrl())->assertForbidden();
    }

    public function test_al_iniciar_sesion_se_entra_directo_al_drive(): void
    {
        $respuesta = fn () => app(\App\Http\Responses\LoginResponse::class)->toResponse(request());

        // Aunque el admin hubiera intentado abrir otra página antes del login
        $this->actingAs($this->admin);
        session()->put('url.intended', ActividadResource::getUrl());
        $this->assertSame(Unidad::getUrl(), $respuesta()->getTargetUrl());

        // Un enlace a un documento sí se respeta
        session()->put('url.intended', url('/documentos/archivos/5/visor'));
        $this->assertSame(url('/documentos/archivos/5/visor'), $respuesta()->getTargetUrl());
    }

    public function test_usuario_sin_rol_no_entra_al_panel(): void
    {
        $sinRol = \App\Models\User::factory()->create();

        $this->actingAs($sinRol)->get(Unidad::getUrl())->assertForbidden();
    }

    public function test_crear_carpeta_y_renombrar_desde_la_interfaz(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(Unidad::class)
            ->callAction('crearCarpeta', ['nombre' => 'Certificaciones'])
            ->assertHasNoActionErrors()
            ->assertSee('Certificaciones');

        $carpeta = Carpeta::where('nombre', 'Certificaciones')->firstOrFail();
        Livewire::test(Unidad::class)
            ->callAction('renombrar', ['nombre' => 'Certificados 2026'], ['clave' => 'c:'.$carpeta->id])
            ->assertSee('Certificados 2026');
    }

    public function test_subir_un_nivel_y_volver_desde_el_historial(): void
    {
        $this->actingAs($this->admin);
        $padre = $this->crearCarpeta(null, 'Inspecciones');
        $hija = $this->crearCarpeta($padre, 'EDS San Diego');

        Livewire::test(Unidad::class, ['carpetaId' => $hija->id])
            ->call('subirNivel')->assertSet('carpetaId', $padre->id)
            ->call('subirNivel')->assertSet('carpetaId', null)->assertSet('seccion', 'compartida')
            // El botón "atrás" del navegador cambia la propiedad desde la URL
            ->set('carpetaId', $hija->id)->assertSee('EDS San Diego')
            ->set('carpetaId', 999999)->assertSet('carpetaId', null);
    }

    public function test_subir_solo_aparece_dentro_de_una_carpeta(): void
    {
        $carpeta = $this->crearCarpeta(null, 'Clientes');
        $this->actingAs($this->admin);

        Livewire::test(Unidad::class)->assertActionHidden('subir')->assertActionVisible('crearCarpeta')
            ->assertDontSeeHtml("mountAction('subir')")->assertSeeHtml("mountAction('crearCarpeta')");
        Livewire::test(Unidad::class, ['carpetaId' => $carpeta->id])->assertActionVisible('subir')->assertSeeHtml("mountAction('subir')");

        $this->actingAs($this->usuario);
        Livewire::test(Unidad::class, ['carpetaId' => $carpeta->id])
            ->assertDontSeeHtml("mountAction('subir')")
            ->assertDontSeeHtml("mountAction('crearCarpeta')");
    }

    public function test_subir_una_carpeta_recrea_su_estructura_sin_duplicar(): void
    {
        $destino = $this->crearCarpeta(null, 'Clientes');
        $this->actingAs($this->admin);

        $subirCarpeta = function () use ($destino) {
            Livewire::test(Unidad::class, ['carpetaId' => $destino->id])
                ->call('crearEstructura', ['Expediente ACME', 'Expediente ACME/Anexos', 'Expediente ACME/Vacía', '../../fuera'])
                ->set('rutasSoltadas', ['Expediente ACME/acta.pdf', 'Expediente ACME/Anexos/anexo.pdf'])
                ->set('soltados', [
                    \Illuminate\Http\UploadedFile::fake()->createWithContent('acta.pdf', file_get_contents($this->pdfTemporal(1, 'acta'))),
                    \Illuminate\Http\UploadedFile::fake()->createWithContent('anexo.pdf', file_get_contents($this->pdfTemporal(1, 'anexo'))),
                ])
                ->call('finalizarSubidaCarpeta');
        };

        $subirCarpeta();

        $expediente = Carpeta::where('carpeta_padre_id', $destino->id)->where('nombre', 'Expediente ACME')->firstOrFail();
        $anexos = Carpeta::where('carpeta_padre_id', $expediente->id)->where('nombre', 'Anexos')->firstOrFail();
        $this->assertTrue(Carpeta::where('carpeta_padre_id', $expediente->id)->where('nombre', 'Vacía')->exists());
        $this->assertTrue(Archivos::where('carpeta_id', $expediente->id)->where('nombre', 'acta.pdf')->exists());
        $this->assertTrue(Archivos::where('carpeta_id', $anexos->id)->where('nombre', 'anexo.pdf')->exists());
        // "../../fuera" no escapa de la carpeta de destino
        $this->assertSame("/{$destino->id}/", Carpeta::where('nombre', 'fuera')->firstOrFail()->padre->ruta);

        // Subir de nuevo la misma carpeta reutiliza las carpetas y no duplica archivos
        $subirCarpeta();
        $this->assertSame(1, Carpeta::where('nombre', 'like', 'Expediente ACME%')->count());
        $this->assertSame(2, Archivos::count());
    }

    public function test_personalizar_carpeta_con_color_e_icono(): void
    {
        $carpeta = $this->crearCarpeta(null, 'Certificaciones');
        $this->actingAs($this->admin);

        Livewire::test(Unidad::class)
            ->callAction('personalizar', ['color' => 'verde', 'icono' => 'academic-cap'], ['clave' => 'c:'.$carpeta->id])
            ->assertHasNoActionErrors()
            ->assertSeeHtml('color: #16a34a');

        $this->assertSame(['verde', 'academic-cap'], [$carpeta->fresh()->color, $carpeta->fresh()->icono]);

        // Solo valores de la paleta: nada de CSS ni íconos arbitrarios
        $this->expectException(\InvalidArgumentException::class);
        $this->carpetas()->personalizar($this->admin, $carpeta, 'red;background:url(x)', null);
    }

    public function test_usuario_sin_edicion_no_personaliza(): void
    {
        $carpeta = $this->crearCarpeta(null, 'Certificaciones');

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->carpetas()->personalizar($this->usuario, $carpeta, 'azul', 'star');
    }

    public function test_panel_de_almacenamiento_solo_para_admin(): void
    {
        $carpeta = $this->crearCarpeta(null, 'Datos');
        $this->subirPdf($carpeta, 'a.pdf', texto: 'a');
        $this->subirPdf($carpeta, 'b.pdf', texto: 'b');

        $r = app(\App\Services\Documentos\EstadisticasDocumentales::class)->resumen(true);
        $this->assertSame(2, $r['archivos']);
        $this->assertGreaterThan(0, $r['bytes_ocupados']);
        $this->assertSame('Datos', $r['carpetas_pesadas'][0]['nombre']);

        $this->actingAs($this->admin)->get(\App\Filament\Pages\Almacenamiento::getUrl())->assertOk()->assertSee('Espacio ocupado');
        $this->actingAs($this->usuario)->get(\App\Filament\Pages\Almacenamiento::getUrl())->assertForbidden();
    }

    public function test_usuario_estandar_no_ve_acciones_de_edicion(): void
    {
        $this->crearCarpeta(null, 'Clientes');
        $this->actingAs($this->usuario);

        Livewire::test(Unidad::class)
            ->assertActionHidden('crearCarpeta')
            ->assertActionHidden('papelera')
            ->assertActionHidden('permisos');
    }

    public function test_mover_por_arrastre_valida_permisos(): void
    {
        $a = $this->crearCarpeta(null, 'A');
        $b = $this->crearCarpeta(null, 'B');
        $archivo = $this->subirPdf($a);

        $this->actingAs($this->usuario);
        Livewire::test(Unidad::class, ['carpetaId' => $a->id])->call('moverA', ['a:'.$archivo->id], $b->id);
        $this->assertSame($a->id, $archivo->fresh()->carpeta_id);

        $this->actingAs($this->admin);
        Livewire::test(Unidad::class, ['carpetaId' => $a->id])->call('moverA', ['a:'.$archivo->id], $b->id);
        $this->assertSame($b->id, $archivo->fresh()->carpeta_id);
    }

    /** Escenario 18 */
    public function test_carpeta_con_muchos_archivos_pagina_y_no_dispara_consultas(): void
    {
        $carpeta = $this->crearCarpeta(null, 'Masiva');
        $ahora = now();
        $filas = [];
        foreach (range(1, 1500) as $i) {
            $filas[] = ['nombre' => sprintf('Documento %04d.pdf', $i), 'carpeta_id' => $carpeta->id, 'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'extension' => 'pdf', 'mime' => 'application/pdf', 'tamano' => 1000 + $i, 'estado' => 'activo', 'origen' => 'subida',
                'subido_por' => $this->admin->id, 'created_at' => $ahora, 'updated_at' => $ahora];
        }
        foreach (array_chunk($filas, 300) as $lote) {
            Archivos::insert($lote);
        }

        $this->actingAs($this->usuario);
        DB::enableQueryLog();
        $inicio = microtime(true);
        $componente = Livewire::test(Unidad::class, ['carpetaId' => $carpeta->id]);
        $duracion = microtime(true) - $inicio;
        $consultas = count(DB::getQueryLog());

        $componente->assertSee('Documento 0060.pdf')->assertDontSee('Documento 0061.pdf')->assertSee('Cargar más');
        $this->assertLessThan(60, $consultas, "Demasiadas consultas: $consultas");
        $this->assertLessThan(5, $duracion);

        $componente->call('cargarMas')->assertSee('Documento 0120.pdf');
    }
}
