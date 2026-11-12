<?php

namespace Tests\Feature\Documentos;

use App\Filament\Pages\Unidad;
use App\Models\Actividad;
use App\Models\ArchivoVersion;
use App\Models\Archivos;
use App\Models\Carpeta;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class EliminacionYVersionesTest extends TestCase
{
    use ConEntornoDocumental;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEntorno();
    }

    /** Escenario 5 */
    public function test_usuario_estandar_no_puede_eliminar_ni_con_peticion_directa(): void
    {
        $carpeta = $this->crearCarpeta(null, 'Clientes');
        $this->carpetas()->configurarAcceso($this->admin, $carpeta, false, [['user_id' => $this->gerencia->id, 'nivel' => 'editar']]);
        $archivo = $this->subirPdf($carpeta);

        foreach ([$this->usuario, $this->gerencia] as $user) {
            try {
                $this->archivos()->enviarPapelera($user, $archivo);
                $this->fail('Un usuario no administrador pudo enviar a la papelera.');
            } catch (AuthorizationException) {
            }

            // Petición Livewire cruda a la acción, como la haría alguien manipulando el navegador
            $this->actingAs($user);
            Livewire::test(Unidad::class)
                ->assertActionHidden('papelera')
                ->call('mountAction', 'papelera', ['claves' => ['a:'.$archivo->id]])
                ->call('callMountedAction')
                ->call('mountAction', 'purgar', ['clave' => 'a:'.$archivo->id])
                ->call('callMountedAction');
        }

        $this->assertFalse($archivo->fresh()->trashed());
        $this->assertNotNull(Archivos::find($archivo->id));
        $this->assertFalse($this->gerencia->can('forceDelete', $archivo));
    }

    /** Escenario 6 */
    public function test_admin_envia_a_papelera_restaura_y_elimina_definitivamente(): void
    {
        $carpeta = $this->crearCarpeta(null, 'Clientes');
        $sub = $this->crearCarpeta($carpeta, 'Viejo');
        $archivo = $this->subirPdf($sub);
        $objeto = $archivo->versionActual->objeto;

        $this->carpetas()->enviarPapelera($this->admin, $carpeta);
        $this->assertTrue($sub->fresh()?->trashed() ?? Carpeta::withTrashed()->find($sub->id)->trashed());
        $this->assertTrue(Archivos::withTrashed()->find($archivo->id)->trashed());

        $this->carpetas()->restaurar($this->admin, Carpeta::withTrashed()->find($carpeta->id));
        $this->assertFalse(Archivos::find($archivo->id)->trashed());

        $this->carpetas()->enviarPapelera($this->admin, $carpeta->fresh());
        $this->carpetas()->purgar($this->admin, Carpeta::withTrashed()->find($carpeta->id));

        $this->assertNull(Carpeta::withTrashed()->find($sub->id));
        $this->assertNull(Archivos::withTrashed()->find($archivo->id));
        Storage::disk('documentos_local')->assertMissing($objeto);
        $this->assertTrue(Actividad::where('accion', 'carpeta.eliminada_definitivamente')->exists());
    }

    public function test_no_se_elimina_definitivamente_sin_pasar_por_la_papelera(): void
    {
        $archivo = $this->subirPdf($this->crearCarpeta(null, 'X'));

        $this->expectException(\InvalidArgumentException::class);
        $this->archivos()->purgar($this->admin, $archivo);
    }

    public function test_purgar_una_copia_no_borra_el_objeto_compartido(): void
    {
        $origen = $this->crearCarpeta(null, 'Origen');
        $destino = $this->crearCarpeta(null, 'Destino');
        $archivo = $this->subirPdf($origen);
        $copia = $this->archivos()->copiar($this->admin, $archivo, $destino);
        $objeto = $archivo->versionActual->objeto;

        $this->archivos()->enviarPapelera($this->admin, $copia);
        $this->archivos()->purgar($this->admin, Archivos::withTrashed()->find($copia->id));

        Storage::disk('documentos_local')->assertExists($objeto);
    }

    /** Escenario 7 */
    public function test_nueva_version_conserva_el_contenido_anterior(): void
    {
        $archivo = $this->subirPdf($this->crearCarpeta(null, 'X'), texto: 'primera');
        $v1 = $archivo->versionActual;
        $contenidoV1 = Storage::disk('documentos_local')->get($v1->objeto);

        $v2 = $this->archivos()->nuevaVersion($this->admin, $archivo, $this->pdfTemporal(texto: 'segunda'), 'Informe.pdf', 'Corrección');

        $this->assertSame(2, $v2->numero);
        $this->assertNotSame($v1->objeto, $v2->objeto);
        $this->assertSame($contenidoV1, Storage::disk('documentos_local')->get($v1->objeto));
        $this->assertSame($v2->id, $archivo->fresh()->version_actual_id);
    }

    public function test_nueva_version_identica_no_crea_version(): void
    {
        $ruta = $this->pdfTemporal(texto: 'igual');
        copy($ruta, $copia = $ruta.'.copia');
        $archivo = $this->subirPdf($this->crearCarpeta(null, 'X'), texto: 'igual');

        $resultado = $this->archivos()->nuevaVersion($this->admin, $archivo, $copia, 'Informe.pdf');

        $this->assertSame($archivo->version_actual_id, $resultado->id);
        $this->assertSame(1, $archivo->versiones()->count());
    }

    public function test_nueva_version_exige_la_misma_extension(): void
    {
        $archivo = $this->subirPdf($this->crearCarpeta(null, 'X'));

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->archivos()->nuevaVersion($this->admin, $archivo, $this->pngTemporal(), 'foto.png');
    }

    /** Escenario 8 */
    public function test_restaurar_version_genera_historial_coherente(): void
    {
        $archivo = $this->subirPdf($this->crearCarpeta(null, 'X'), texto: 'uno');
        $v1 = $archivo->versionActual;
        $v2 = $this->archivos()->nuevaVersion($this->admin, $archivo, $this->pdfTemporal(texto: 'dos'), 'Informe.pdf');

        $v3 = $this->archivos()->restaurarVersion($this->admin, $archivo->fresh(), $v1);

        $this->assertSame(3, $v3->numero);
        $this->assertSame($v1->objeto, $v3->objeto);
        $this->assertSame($v1->id, $v3->restaurada_desde_id);
        $this->assertSame([3, 2, 1], $archivo->versiones()->pluck('numero')->all());
        $this->assertNotNull(ArchivoVersion::find($v2->id));
        $this->assertSame($v3->id, $archivo->fresh()->version_actual_id);
    }

    public function test_descarga_de_version_anterior(): void
    {
        $archivo = $this->subirPdf($this->crearCarpeta(null, 'X'), texto: 'uno');
        $v1 = $archivo->versionActual;
        $this->archivos()->nuevaVersion($this->admin, $archivo, $this->pdfTemporal(texto: 'dos'), 'Informe.pdf');

        $respuesta = $this->actingAs($this->admin)->get(route('documentos.descargar', ['archivo' => $archivo, 'version' => $v1->id]));

        $respuesta->assertOk();
        $this->assertSame(Storage::disk('documentos_local')->get($v1->objeto), file_get_contents($respuesta->baseResponse->getFile()->getPathname()));
        $this->assertStringContainsString('(v1)', $respuesta->headers->get('Content-Disposition'));
    }

    public function test_el_historial_de_actividad_no_se_puede_modificar(): void
    {
        $this->crearCarpeta(null, 'X');
        $actividad = Actividad::firstOrFail();

        $this->expectException(LogicException::class);
        $actividad->update(['accion' => 'manipulado']);
    }
}
