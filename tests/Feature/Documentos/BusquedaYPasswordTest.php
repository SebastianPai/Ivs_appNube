<?php

namespace Tests\Feature\Documentos;

use App\Filament\Pages\CambiarContrasena;
use App\Filament\Pages\Unidad;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Models\ArchivoTexto;
use App\Models\Carpeta;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class BusquedaYPasswordTest extends TestCase
{
    use ConEntornoDocumental;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEntorno();
    }

    public function test_el_texto_del_pdf_se_indexa_al_subir_y_se_puede_buscar(): void
    {
        $carpeta = $this->crearCarpeta(null, 'Clientes');
        $archivo = $this->subirPdf($carpeta, 'Informe final.pdf', texto: 'Estacion Primax NIT 900123456');
        $this->subirPdf($carpeta, 'Otro.pdf', texto: 'Nada que ver');

        $texto = ArchivoTexto::find($archivo->id);
        $this->assertSame(ArchivoTexto::INDEXADO, $texto->estado);
        $this->assertStringContainsString('900123456', $texto->texto);

        $this->actingAs($this->usuario);
        Livewire::test(Unidad::class)
            ->set('busqueda', '900123456')
            ->assertSee('Informe final.pdf')
            ->assertDontSee('Otro.pdf');
    }

    public function test_la_busqueda_por_contenido_respeta_permisos(): void
    {
        $privada = $this->crearCarpeta(null, 'Gerencia', Carpeta::UNIDAD_PRIVADA);
        $this->subirPdf($privada, 'Secreto.pdf', texto: 'Palabraclave');

        $this->actingAs($this->usuario);
        Livewire::test(Unidad::class)->set('busqueda', 'Palabraclave')->assertDontSee('Secreto.pdf');

        $this->actingAs($this->admin);
        Livewire::test(Unidad::class)->set('busqueda', 'Palabraclave')->assertSee('Secreto.pdf');
    }

    public function test_nueva_version_reindexa_y_el_comando_completa_pendientes(): void
    {
        $carpeta = $this->crearCarpeta(null, 'Clientes');
        $archivo = $this->subirPdf($carpeta, 'Acta.pdf', texto: 'Primera');
        $this->archivos()->nuevaVersion($this->admin, $archivo, $this->pdfTemporal(2, 'Segunda'), 'Acta.pdf');

        $this->assertStringContainsString('Segunda', ArchivoTexto::find($archivo->id)->texto);

        ArchivoTexto::query()->delete();
        $this->artisan('documentos:indexar-texto')->assertSuccessful();
        $this->assertSame(ArchivoTexto::INDEXADO, ArchivoTexto::find($archivo->id)->estado);
    }

    public function test_si_el_almacenamiento_falla_no_se_marca_y_se_reintenta(): void
    {
        $carpeta = $this->crearCarpeta(null, 'Clientes');
        $archivo = $this->subirPdf($carpeta, 'Acta.pdf', texto: 'Algo');
        ArchivoTexto::query()->delete();

        // El objeto desaparece del almacenamiento (como un bucket sin facturación)
        \Illuminate\Support\Facades\Storage::disk('documentos_local')->delete($archivo->versionActual->objeto);

        $this->artisan('documentos:indexar-texto')->assertSuccessful();
        $this->assertNull(ArchivoTexto::find($archivo->id));
        $this->assertSame(1, app(\App\Services\Documentos\Busqueda\IndexadorTexto::class)->pendientes()->count());

        // El job de una subida tampoco revienta
        \App\Jobs\IndexarTextoJob::dispatchSync($archivo->id);
        $this->assertNull(ArchivoTexto::find($archivo->id));
    }

    public function test_la_pantalla_de_ingreso_propia_permite_entrar(): void
    {
        $this->usuario->forceFill(['password' => 'ClaveSegura2026'])->save();

        $this->get('/inicio/login')->assertOk()->assertSee('Ingresa a la nube');

        Livewire::test(\App\Filament\Pages\Auth\Login::class)
            ->fillForm(['email' => $this->usuario->email, 'password' => 'ClaveSegura2026'])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect(Unidad::getUrl());

        $this->assertAuthenticatedAs($this->usuario);
    }

    public function test_usuario_nuevo_recibe_la_contrasena_inicial_y_debe_cambiarla(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(CreateUser::class)
            ->assertFormSet(['password' => 'Ivscerti2026'])
            ->fillForm(['name' => 'Nuevo', 'email' => 'nuevo@ivs.test'])
            ->call('create')
            ->assertHasNoFormErrors();

        $nuevo = User::where('email', 'nuevo@ivs.test')->first();
        $this->assertTrue(Hash::check('Ivscerti2026', $nuevo->password));
        $this->assertTrue($nuevo->debe_cambiar_password);
    }

    public function test_primer_ingreso_obliga_a_cambiar_la_contrasena(): void
    {
        $this->usuario->forceFill(['password' => 'Ivscerti2026', 'debe_cambiar_password' => true])->save();
        $this->actingAs($this->usuario);

        $this->get(Unidad::getUrl())->assertRedirect(CambiarContrasena::getUrl());
        $this->get(CambiarContrasena::getUrl())->assertOk();

        // No puede dejar la inicial
        Livewire::test(CambiarContrasena::class)
            ->set('data.password', 'Ivscerti2026')->set('data.password_confirmation', 'Ivscerti2026')
            ->call('guardar')->assertHasErrors(['data.password']);

        Livewire::test(CambiarContrasena::class)
            ->set('data.password', 'MiClaveNueva2026')->set('data.password_confirmation', 'MiClaveNueva2026')
            ->call('guardar')->assertHasNoErrors()->assertRedirect(Unidad::getUrl());

        $this->usuario->refresh();
        $this->assertFalse($this->usuario->debe_cambiar_password);
        $this->assertTrue(Hash::check('MiClaveNueva2026', $this->usuario->password));
        $this->actingAs($this->usuario)->get(Unidad::getUrl())->assertOk();
    }
}
