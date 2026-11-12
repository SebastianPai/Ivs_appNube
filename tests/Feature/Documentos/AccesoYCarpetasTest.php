<?php

namespace Tests\Feature\Documentos;

use App\Filament\Pages\Unidad;
use App\Models\Archivos;
use App\Models\Carpeta;
use App\Services\Documentos\AccesoDocumental;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Tests\TestCase;

class AccesoYCarpetasTest extends TestCase
{
    use ConEntornoDocumental;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEntorno();
    }

    /** Escenario 1 */
    public function test_carpeta_creada_por_admin_es_visible_para_otro_usuario(): void
    {
        $carpeta = $this->crearCarpeta(null, 'Documentos institucionales');

        $this->assertTrue(Carpeta::visiblePara($this->usuario)->whereKey($carpeta->id)->exists());

        $this->actingAs($this->usuario);
        Livewire::test(Unidad::class)->assertSee('Documentos institucionales');
    }

    /** Escenario 2 */
    public function test_subcarpeta_creada_por_usuario_autorizado_aparece_en_el_arbol_compartido(): void
    {
        $raiz = $this->crearCarpeta(null, 'Clientes');
        $sub = $this->crearCarpeta($raiz, 'Cliente ACME', por: $this->gerencia);

        $this->assertSame($this->gerencia->id, $sub->creado_por);
        $this->assertSame("/{$raiz->id}/{$sub->id}/", $sub->ruta);

        $this->actingAs($this->usuario);
        Livewire::test(Unidad::class)
            ->call('alternarRama', $raiz->id)
            ->assertSee('Cliente ACME');
    }

    public function test_usuario_sin_permiso_no_puede_crear_carpetas(): void
    {
        $raiz = $this->crearCarpeta(null, 'Clientes');

        $this->expectException(AuthorizationException::class);
        $this->crearCarpeta($raiz, 'Intento', por: $this->usuario);
    }

    /** Escenario 3 */
    public function test_usuario_sin_permisos_no_accede_a_carpeta_privada(): void
    {
        $privada = $this->crearCarpeta(null, 'Gerencia', Carpeta::UNIDAD_PRIVADA);
        $this->subirPdf($privada, 'Salarios.pdf');

        $this->assertTrue($privada->restringida);
        $this->assertFalse(app(AccesoDocumental::class)->puedeVerCarpeta($this->usuario, $privada));
        $this->assertFalse(Carpeta::visiblePara($this->usuario)->whereKey($privada->id)->exists());

        $this->actingAs($this->usuario);
        Livewire::test(Unidad::class, ['carpetaId' => $privada->id])
            ->assertSet('carpetaId', null)
            ->assertDontSee('Salarios.pdf');
    }

    public function test_permiso_explicito_da_acceso_a_la_carpeta_privada_y_se_hereda(): void
    {
        $privada = $this->crearCarpeta(null, 'Gerencia', Carpeta::UNIDAD_PRIVADA);
        $sub = $this->crearCarpeta($privada, 'Contratos');
        $this->carpetas()->configurarAcceso($this->admin, $privada, true, [['user_id' => $this->gerencia->id, 'nivel' => 'editar']]);

        $acceso = app(AccesoDocumental::class);
        $this->assertTrue($acceso->puedeVerCarpeta($this->gerencia, $sub->fresh()));
        $this->assertTrue($acceso->puedeEditarEn($this->gerencia, $sub->fresh()));
        $this->assertFalse($acceso->puedeVerCarpeta($this->usuario, $sub->fresh()));
    }

    public function test_subcarpeta_restringida_corta_la_herencia(): void
    {
        $privada = $this->crearCarpeta(null, 'Gerencia', Carpeta::UNIDAD_PRIVADA);
        $confidencial = $this->crearCarpeta($privada, 'Confidencial');
        $this->carpetas()->configurarAcceso($this->admin, $privada, true, [['user_id' => $this->gerencia->id, 'nivel' => 'ver']]);
        $this->carpetas()->configurarAcceso($this->admin, $confidencial, true, []);

        $this->assertTrue(app(AccesoDocumental::class)->puedeVerCarpeta($this->gerencia, $privada->fresh()));
        $this->assertFalse(app(AccesoDocumental::class)->puedeVerCarpeta($this->gerencia, $confidencial->fresh()));
    }

    /** Escenario 4 */
    public function test_usuario_no_autorizado_no_descarga_archivo_privado_por_id(): void
    {
        $privada = $this->crearCarpeta(null, 'Gerencia', Carpeta::UNIDAD_PRIVADA);
        $archivo = $this->subirPdf($privada, 'Salarios.pdf');

        $this->actingAs($this->usuario)->get(route('documentos.descargar', $archivo))->assertForbidden();
        $this->actingAs($this->usuario)->get(route('documentos.contenido', $archivo))->assertForbidden();
        $this->actingAs($this->usuario)->get(route('documentos.visor', $archivo))->assertForbidden();
        $this->actingAs($this->usuario)->get(route('documentos.descargar', ['archivo' => $archivo, 'version' => $archivo->version_actual_id]))->assertForbidden();

        $this->actingAs($this->admin)->get(route('documentos.descargar', $archivo))->assertOk();
    }

    public function test_invitado_es_redirigido_al_login(): void
    {
        $archivo = $this->subirPdf($this->crearCarpeta(null, 'Pública'));

        $this->get(route('documentos.descargar', $archivo))->assertRedirect(route('filament.inicio.auth.login'));
    }

    /** Escenario 16 */
    public function test_busqueda_respeta_permisos(): void
    {
        $privada = $this->crearCarpeta(null, 'Gerencia', Carpeta::UNIDAD_PRIVADA);
        $compartida = $this->crearCarpeta(null, 'Clientes');
        $this->subirPdf($privada, 'Presupuesto secreto.pdf', texto: 'a');
        $this->subirPdf($compartida, 'Presupuesto publico.pdf', texto: 'b');

        $this->actingAs($this->usuario);
        Livewire::test(Unidad::class)
            ->set('busqueda', 'Presupuesto')
            ->assertSee('Presupuesto publico.pdf')
            ->assertDontSee('Presupuesto secreto.pdf');

        $this->assertSame(1, Archivos::visiblePara($this->usuario)->where('nombre', 'like', '%Presupuesto%')->count());
        $this->assertSame(2, Archivos::visiblePara($this->admin)->where('nombre', 'like', '%Presupuesto%')->count());
    }

    /** Escenario 17 */
    public function test_mover_carpeta_mantiene_la_integridad_del_subarbol(): void
    {
        $a = $this->crearCarpeta(null, 'A');
        $b = $this->crearCarpeta(null, 'B');
        $a1 = $this->crearCarpeta($a, 'A1');
        $a11 = $this->crearCarpeta($a1, 'A11');
        $archivo = $this->subirPdf($a11, 'Dentro.pdf');

        $this->carpetas()->mover($this->admin, $a1, $b);

        $this->assertSame("/{$b->id}/{$a1->id}/", $a1->fresh()->ruta);
        $this->assertSame("/{$b->id}/{$a1->id}/{$a11->id}/", $a11->fresh()->ruta);
        $this->assertSame($a11->id, $archivo->fresh()->carpeta_id);

        // No se puede mover una carpeta dentro de su propio descendiente
        $this->expectException(\InvalidArgumentException::class);
        $this->carpetas()->mover($this->admin, $a1->fresh(), $a11->fresh());
    }

    public function test_mover_a_la_unidad_privada_aplica_la_restriccion_al_subarbol(): void
    {
        $compartida = $this->crearCarpeta(null, 'Temporal');
        $sub = $this->crearCarpeta($compartida, 'Sub');
        $privada = $this->crearCarpeta(null, 'Gerencia', Carpeta::UNIDAD_PRIVADA);

        $this->carpetas()->mover($this->admin, $compartida, $privada);

        $this->assertSame(Carpeta::UNIDAD_PRIVADA, $sub->fresh()->unidad);
        $this->assertSame($privada->id, $sub->fresh()->restriccion_id);
        $this->assertFalse(app(AccesoDocumental::class)->puedeVerCarpeta($this->usuario, $sub->fresh()));
    }

    public function test_solo_admin_cambia_elementos_de_unidad(): void
    {
        $origen = $this->crearCarpeta(null, 'Origen');
        $this->carpetas()->configurarAcceso($this->admin, $origen, false, [['user_id' => $this->gerencia->id, 'nivel' => 'editar']]);
        $sub = $this->crearCarpeta($origen, 'Sub', por: $this->gerencia);
        $privada = $this->crearCarpeta(null, 'Gerencia', Carpeta::UNIDAD_PRIVADA);
        $this->carpetas()->configurarAcceso($this->admin, $privada, true, [['user_id' => $this->gerencia->id, 'nivel' => 'editar']]);

        $this->expectException(AuthorizationException::class);
        $this->carpetas()->mover($this->gerencia, $sub, $privada->fresh());
    }

    public function test_nombres_duplicados_se_numeran(): void
    {
        $raiz = $this->crearCarpeta(null, 'Informes');
        // La comparación ignora mayúsculas; se conserva cómo lo escribió el usuario
        $this->assertSame('informes (2)', $this->crearCarpeta(null, 'informes')->nombre);

        $this->subirPdf($raiz, 'Acta.pdf', texto: 'uno');
        $this->assertSame('Acta (2).pdf', $this->subirPdf($raiz, 'Acta.pdf', texto: 'dos')->nombre);
    }
}
