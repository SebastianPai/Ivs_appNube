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

    public function test_explorador_de_destino_navega_busca_y_respeta_permisos(): void
    {
        $clientes = $this->crearCarpeta(null, 'Clientes');
        $eds = $this->crearCarpeta($clientes, 'EDS La Molienda');
        $this->crearCarpeta($eds, 'Fotos');
        $privada = $this->crearCarpeta(null, 'Gerencia', Carpeta::UNIDAD_PRIVADA);

        $this->actingAs($this->admin);
        $pagina = Livewire::test(Unidad::class)->instance();

        // Raíz de la unidad compartida: Clientes, con subcarpetas
        $raiz = $pagina->explorarDestinos(null, 'compartida');
        $this->assertSame(['Clientes'], array_column($raiz['carpetas'], 'nombre'));
        $this->assertTrue($raiz['carpetas'][0]['hijas']);

        // Dentro de Clientes, moviendo la propia «EDS La Molienda»: aparece bloqueada
        $dentro = $pagina->explorarDestinos($clientes->id, 'compartida', '', ['c:'.$eds->id]);
        $this->assertSame('Clientes', $dentro['actual']['nombre']);
        $this->assertTrue($dentro['carpetas'][0]['bloqueada']);

        // Búsqueda con la ubicación
        $busqueda = $pagina->explorarDestinos(null, 'compartida', 'Fotos');
        $this->assertSame('Clientes › EDS La Molienda', $busqueda['carpetas'][0]['ubicacion']);

        // Un usuario sin acceso a la unidad privada no la ve
        $this->actingAs($this->usuario);
        $otra = Livewire::test(Unidad::class)->instance();
        $this->assertSame([], $otra->explorarDestinos(null, 'privada')['carpetas']);
        $this->assertNull($otra->explorarDestinos($privada->id, 'privada')['actual']);
    }

    public function test_mover_con_el_explorador(): void
    {
        $origen = $this->crearCarpeta(null, 'Origen');
        $destino = $this->crearCarpeta(null, 'Destino');
        $archivo = $this->subirPdf($origen, 'Acta.pdf');

        $this->actingAs($this->admin);
        Livewire::test(Unidad::class)
            ->callAction('mover', ['destino' => 'c:'.$destino->id], ['claves' => ['a:'.$archivo->id]])
            ->assertHasNoActionErrors();

        $this->assertSame($destino->id, $archivo->fresh()->carpeta_id);
    }

    public function test_gerencia_ve_las_dos_unidades_y_usuario_solo_la_compartida(): void
    {
        $privada = $this->crearCarpeta(null, 'Contabilidad', Carpeta::UNIDAD_PRIVADA);
        $subcarpeta = $this->crearCarpeta($privada, 'Nómina 2026', Carpeta::UNIDAD_PRIVADA);
        $this->crearCarpeta(null, 'Clientes');
        $archivo = $this->subirPdf($subcarpeta, 'Balance.pdf');
        $acceso = app(\App\Services\Documentos\AccesoDocumental::class);

        // Gerencia: entra a la privada completa y puede trabajar ahí
        $this->assertTrue($acceso->puedeVerCarpeta($this->gerencia, $subcarpeta));
        $this->assertTrue($acceso->puedeEditarEn($this->gerencia, $subcarpeta));
        $this->assertTrue($acceso->puedeDescargar($this->gerencia, $archivo));
        $this->assertSame(['Clientes', 'Contabilidad', 'Nómina 2026'], Carpeta::visiblePara($this->gerencia)->orderBy('nombre')->pluck('nombre')->all());

        $this->actingAs($this->gerencia);
        $this->assertContains('privada', Livewire::test(Unidad::class)->instance()->seccionesDisponibles());

        // Usuario: solo la compartida
        $this->assertFalse($acceso->puedeVerCarpeta($this->usuario, $subcarpeta));
        $this->assertFalse($acceso->puedeVerArchivo($this->usuario, $archivo));
        $this->assertSame(['Clientes'], Carpeta::visiblePara($this->usuario)->pluck('nombre')->all());

        $this->actingAs($this->usuario);
        $this->assertNotContains('privada', Livewire::test(Unidad::class)->instance()->seccionesDisponibles());
    }

    public function test_los_formularios_de_usuarios_y_roles_explican_cada_rol(): void
    {
        $this->actingAs($this->admin);

        $this->get(\App\Filament\Resources\UserResource::getUrl('create'))
            ->assertOk()
            ->assertSee('Ve la unidad compartida y la unidad privada', false)
            ->assertSee('Solo ve la unidad compartida', false);

        $this->get(\App\Filament\Resources\RoleResource::getUrl('edit', ['record' => \App\Models\Role::where('name', 'Gerencia')->first()]))
            ->assertOk()
            ->assertSee('Acceder a toda la unidad privada')
            ->assertSee('Sin él, solo ve la unidad compartida', false);
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
            ->fillForm(['name' => 'Nuevo', 'email' => 'nuevo@ivs.test', 'roles' => [\App\Models\Role::where('name', 'Usuario')->value('id')]])
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
