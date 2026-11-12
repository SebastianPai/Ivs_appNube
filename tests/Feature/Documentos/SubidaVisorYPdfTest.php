<?php

namespace Tests\Feature\Documentos;

use App\Jobs\ProcesarPdfJob;
use App\Models\Archivos;
use App\Models\TareaDocumental;
use App\Services\Documentos\Pdf\HerramientasPdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class SubidaVisorYPdfTest extends TestCase
{
    use ConEntornoDocumental;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEntorno();
    }

    private function requiereQpdf(): void
    {
        if (! app(HerramientasPdf::class)->disponibles()['qpdf']) {
            $this->markTestSkipped('qpdf no está instalado en este entorno.');
        }
    }

    public function test_pdf_se_comprime_automaticamente_al_subir(): void
    {
        $this->requiereQpdf();
        config(['documentos.pdf.compresion_automatica' => 'baja']);
        $original = $this->pdfTemporal(5);
        $tamanoOriginal = filesize($original);
        $md5Original = md5_file($original);

        [$archivo] = $this->archivos()->subir($this->admin, $this->crearCarpeta(null, 'X'), $original, 'Grande.pdf');
        $version = $archivo->versionActual;

        $this->assertLessThan($tamanoOriginal, $archivo->tamano);
        $this->assertSame($md5Original, $archivo->hash_original);
        $this->assertTrue($version->metadatos['compresion']['aplicada']);
        $guardado = Storage::disk('documentos_local')->path($version->objeto);
        $this->assertStringStartsWith('%PDF', file_get_contents($guardado, false, null, 0, 4));
        $this->assertSame(5, app(HerramientasPdf::class)->paginas($guardado));
    }

    /** Escenario 13: resubir el mismo archivo (aunque se haya comprimido) no duplica ni vuelve a escribir. */
    public function test_subida_repetida_no_crea_duplicados(): void
    {
        $carpeta = $this->crearCarpeta(null, 'X');
        $ruta = $this->pdfTemporal(3);
        copy($ruta, $copia = $ruta.'.copia');

        [$primero, $creado1] = $this->archivos()->subir($this->admin, $carpeta, $ruta, 'Acta.pdf');
        $objetos = count(Storage::disk('documentos_local')->allFiles());
        [$segundo, $creado2] = $this->archivos()->subir($this->admin, $carpeta, $copia, 'Acta.pdf');

        $this->assertTrue($creado1);
        $this->assertFalse($creado2);
        $this->assertSame($primero->id, $segundo->id);
        $this->assertSame(1, Archivos::count());
        $this->assertCount($objetos, Storage::disk('documentos_local')->allFiles());
    }

    /** Escenario 12 */
    public function test_fallo_al_registrar_no_deja_registros_ni_objetos_huerfanos(): void
    {
        $carpeta = $this->crearCarpeta(null, 'X');
        Archivos::creating(fn () => throw new RuntimeException('Fallo simulado de la base de datos'));

        try {
            $this->subirPdf($carpeta);
            $this->fail('Se esperaba un fallo.');
        } catch (RuntimeException $e) {
            $this->assertSame('Fallo simulado de la base de datos', $e->getMessage());
        }

        $this->assertSame(0, Archivos::withTrashed()->count());
        $this->assertSame([], Storage::disk('documentos_local')->allFiles());
    }

    public function test_rechaza_tipos_peligrosos_y_contenido_que_no_coincide(): void
    {
        $carpeta = $this->crearCarpeta(null, 'X');
        $html = tempnam(sys_get_temp_dir(), 'h');
        file_put_contents($html, '<html><script>alert(1)</script></html>');

        foreach (['malo.exe', 'pagina.html', 'falso.pdf'] as $nombre) {
            try {
                $this->archivos()->subir($this->admin, $carpeta, $html, $nombre);
                $this->fail("Se aceptó $nombre");
            } catch (ValidationException) {
            }
        }

        $this->assertSame(0, Archivos::count());
    }

    public function test_nombres_maliciosos_se_limpian(): void
    {
        $archivo = $this->subirPdf($this->crearCarpeta(null, 'X'), '..\\..\\etc/<passwd>.pdf');

        $this->assertSame('passwd .pdf', $archivo->nombre);
        // Ruta legible en el bucket, sin posibilidad de salirse del prefijo
        $objeto = $archivo->versionActual->objeto;
        $this->assertMatchesRegularExpression('#^documentos/Unidad compartida/X/passwd__v1-[a-z0-9]{12}\.pdf$#', $objeto);
        $this->assertNotContains('..', explode('/', $objeto));
    }

    public function test_el_objeto_lleva_carpeta_y_nombre_y_el_respaldo_reconstruye_la_organizacion(): void
    {
        $carpeta = $this->crearCarpeta($this->crearCarpeta(null, 'Inspecciones'), 'EDS San Diego');
        $archivo = $this->subirPdf($carpeta, '4.1.B - INFRAESTRUCTURA.pdf');
        $this->assertStringStartsWith('documentos/Unidad compartida/Inspecciones/EDS San Diego/4.1.B - INFRAESTRUCTURA__v1-', $archivo->versionActual->objeto);

        $this->artisan('documentos:respaldar')->assertSuccessful();

        $manifiesto = collect(Storage::disk('documentos_local')->allFiles('respaldos'))->first(fn ($f) => str_ends_with($f, '.manifiesto.json.gz'));
        $datos = json_decode(gzdecode(Storage::disk('documentos_local')->get($manifiesto)), true);
        $fila = collect($datos['archivos'])->firstWhere('id', $archivo->id);

        $this->assertSame('Unidad compartida/Inspecciones/EDS San Diego', $fila['carpeta']);
        $this->assertSame($archivo->versionActual->objeto, $fila['versiones'][0]['objeto']);
    }

    /** Escenario 9 */
    public function test_pdf_se_visualiza_sin_descargarlo(): void
    {
        $archivo = $this->subirPdf($this->crearCarpeta(null, 'X'));

        $this->actingAs($this->usuario)->get(route('documentos.visor', $archivo))->assertOk()->assertSee('pdf.min.js', false);
        $contenido = $this->actingAs($this->usuario)->get(route('documentos.contenido', $archivo));
        $contenido->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('inline', $contenido->headers->get('Content-Disposition'));

        // Segunda vista: el navegador ya lo tiene en caché y no se vuelve a leer el almacenamiento
        $this->actingAs($this->usuario)
            ->get(route('documentos.contenido', $archivo), ['If-None-Match' => $contenido->headers->get('ETag')])
            ->assertStatus(304);
    }

    /** Escenario 10 */
    public function test_imagen_se_visualiza(): void
    {
        [$archivo] = $this->archivos()->subir($this->admin, $this->crearCarpeta(null, 'X'), $this->pngTemporal(), 'logo.png');

        $this->actingAs($this->usuario)->get(route('documentos.visor', $archivo))->assertOk();
        $this->actingAs($this->usuario)->get(route('documentos.contenido', $archivo))->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_video_se_reproduce_con_soporte_de_rangos(): void
    {
        // Cabecera mínima de un MP4 (caja ftyp) para que se detecte como video/mp4
        $mp4 = tempnam(sys_get_temp_dir(), 'v');
        file_put_contents($mp4, pack('N', 24).'ftypisom'.pack('N', 512).'isomiso2'.str_repeat("\0", 4000));
        [$archivo] = $this->archivos()->subir($this->admin, $this->crearCarpeta(null, 'Videos'), $mp4, 'capacitacion.mp4');

        $this->assertSame('video', $archivo->tipo());
        $this->assertTrue($archivo->esVisualizable());
        $this->actingAs($this->usuario)->get(route('documentos.visor', $archivo))->assertOk()->assertSee('createElement', false);

        $parcial = $this->actingAs($this->usuario)->get(route('documentos.contenido', $archivo), ['Range' => 'bytes=0-99']);
        $parcial->assertStatus(206);
        $this->assertSame('bytes', $parcial->headers->get('Accept-Ranges'));
    }

    public function test_tipos_no_visualizables_no_se_sirven_en_linea(): void
    {
        $txt = tempnam(sys_get_temp_dir(), 't');
        file_put_contents($txt, 'texto plano');
        [$archivo] = $this->archivos()->subir($this->admin, $this->crearCarpeta(null, 'X'), $txt, 'notas.txt');

        $this->actingAs($this->admin)->get(route('documentos.contenido', $archivo))->assertStatus(415);
        $descarga = $this->actingAs($this->admin)->get(route('documentos.descargar', $archivo));
        $descarga->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
    }

    /** Escenario 11 */
    public function test_compresion_produce_un_pdf_valido_sin_alterar_el_original(): void
    {
        $this->requiereQpdf();
        config(['documentos.pdf.compresion_automatica' => 'off']);
        $archivo = $this->subirPdf($this->crearCarpeta(null, 'X'), paginas: 4);
        $hashAntes = md5(Storage::disk('documentos_local')->get($archivo->versionActual->objeto));

        $tarea = TareaDocumental::create(['user_id' => $this->usuario->id, 'tipo' => 'comprimir', 'parametros' => ['archivos' => [$archivo->id], 'nivel' => 'baja', 'nombre_base' => $archivo->nombre]]);
        ProcesarPdfJob::dispatchSync($tarea->id);
        $tarea->refresh();

        $this->assertSame('completada', $tarea->estado, (string) $tarea->error);
        $this->assertGreaterThan(0, $tarea->resultado['reduccion']);
        $this->assertSame(4, $tarea->resultado['paginas']);
        $this->assertSame($hashAntes, md5(Storage::disk('documentos_local')->get($archivo->versionActual->objeto)));
        $this->assertSame(1, $archivo->versiones()->count());

        $this->actingAs($this->usuario)->get(route('documentos.tarea.resultado', $tarea))->assertOk();
        $this->actingAs($this->otroUsuario)->get(route('documentos.tarea.resultado', $tarea))->assertNotFound();
    }

    public function test_unir_extraer_y_rotar(): void
    {
        $this->requiereQpdf();
        $pdf = app(HerramientasPdf::class);
        $dir = sys_get_temp_dir();

        $pdf->unir([$this->pdfTemporal(2), $this->pdfTemporal(3)], $unido = $dir.'/unido-'.uniqid().'.pdf');
        $this->assertSame(5, $pdf->paginas($unido));

        $pdf->extraer($unido, '5,1-2', $extraido = $dir.'/extraido-'.uniqid().'.pdf');
        $this->assertSame(3, $pdf->paginas($extraido));

        $pdf->rotar($unido, 90, '1', $rotado = $dir.'/rotado-'.uniqid().'.pdf');
        $this->assertSame(5, $pdf->paginas($rotado));

        $this->expectException(RuntimeException::class);
        $pdf->extraer($unido, '1; rm -rf /', $dir.'/x.pdf');
    }

    public function test_mover_y_renombrar_reubican_el_objeto_en_el_bucket(): void
    {
        $disco = Storage::disk('documentos_local');
        $inspecciones = $this->crearCarpeta(null, 'Inspecciones');
        $eds = $this->crearCarpeta($inspecciones, 'EDS San Diego');
        $archivo = $this->subirPdf($eds, 'Informe.pdf');
        $original = $archivo->versionActual->objeto;
        $contenido = $disco->get($original);

        // Renombrar el archivo
        $this->archivos()->renombrar($this->admin, $archivo, 'Informe final.pdf');
        $renombrado = $archivo->fresh()->versionActual->objeto;
        $this->assertStringStartsWith('documentos/Unidad compartida/Inspecciones/EDS San Diego/Informe final__v1-', $renombrado);
        $this->assertFalse($disco->exists($original));
        $this->assertSame($contenido, $disco->get($renombrado));

        // Renombrar y mover la carpeta: se reubica todo lo de adentro
        $this->carpetas()->renombrar($this->admin, $eds, 'EDS San Diego Barbacoas');
        $archivados = $this->crearCarpeta(null, 'Archivados');
        $this->carpetas()->mover($this->admin, $inspecciones, $archivados);
        $final = $archivo->fresh()->versionActual->objeto;
        $this->assertStringStartsWith('documentos/Unidad compartida/Archivados/Inspecciones/EDS San Diego Barbacoas/Informe final__v1-', $final);
        $this->assertSame($contenido, $disco->get($final));
        $this->assertSame([$final], $disco->allFiles('documentos'));
    }

    public function test_no_se_reubican_los_recuperados_ni_se_borra_un_objeto_compartido(): void
    {
        $disco = Storage::disk('documentos_local');
        $carpeta = $this->crearCarpeta(null, 'A');
        $otra = $this->crearCarpeta(null, 'B');

        // Archivo recuperado de la app anterior: su objeto no se toca
        $disco->put('uploads/1750447964-viejo.pdf', file_get_contents($this->pdfTemporal(1)));
        app(\App\Services\Documentos\Recuperacion\InventarioAlmacenamiento::class)->ejecutar('documentos_local', null, $this->admin);
        $recuperado = $this->archivos()->vincularObjeto($this->admin, \App\Models\InventarioObjeto::firstOrFail(), $carpeta);
        $this->archivos()->mover($this->admin, $recuperado, $otra);
        $this->assertSame('uploads/1750447964-viejo.pdf', $recuperado->fresh()->versionActual->objeto);
        $this->assertTrue($disco->exists('uploads/1750447964-viejo.pdf'));

        // Una copia comparte el objeto: moverla no borra el del original
        $archivo = $this->subirPdf($carpeta, 'Compartido.pdf');
        $copia = $this->archivos()->copiar($this->admin, $archivo, $otra);
        $this->archivos()->renombrar($this->admin, $copia, 'Copia renombrada.pdf');
        $this->assertTrue($disco->exists($archivo->fresh()->versionActual->objeto));
        $this->assertTrue($disco->exists($copia->fresh()->versionActual->objeto));
        $this->assertNotSame($archivo->fresh()->versionActual->objeto, $copia->fresh()->versionActual->objeto);
    }

    public function test_eliminar_numerar_marca_de_agua_y_contrasena(): void
    {
        $this->requiereQpdf();
        $pdf = app(HerramientasPdf::class);
        $dir = sys_get_temp_dir();
        $origen = $this->pdfTemporal(4);

        $pdf->eliminarPaginas($origen, '2-3', $sin = $dir.'/sin-'.uniqid().'.pdf');
        $this->assertSame(2, $pdf->paginas($sin));

        $pdf->numerar($origen, $numerado = $dir.'/num-'.uniqid().'.pdf');
        $this->assertSame(4, $pdf->paginas($numerado));
        $this->assertCount(4, $pdf->tamanosPaginas($numerado));

        $pdf->marcaAgua($origen, 'COPIA CONTROLADA', $marca = $dir.'/marca-'.uniqid().'.pdf');
        $this->assertSame(4, $pdf->paginas($marca));

        $pdf->proteger($origen, 'clave123', $protegido = $dir.'/prot-'.uniqid().'.pdf');
        $this->assertTrue($pdf->metadatos($protegido)['cifrado']);
        $pdf->desbloquear($protegido, 'clave123', $abierto = $dir.'/abierto-'.uniqid().'.pdf');
        $this->assertFalse($pdf->metadatos($abierto)['cifrado']);

        $this->expectException(RuntimeException::class);
        $pdf->desbloquear($protegido, 'incorrecta', $dir.'/x-'.uniqid().'.pdf');
    }

    public function test_imagenes_a_pdf_y_la_contrasena_no_queda_guardada(): void
    {
        $this->actingAs($this->admin);
        $dir = sys_get_temp_dir();
        $foto = $dir.'/foto-'.uniqid().'.jpg';
        $logo = $dir.'/logo-'.uniqid().'.png';
        imagejpeg(imagecreatetruecolor(800, 600), $foto);
        imagepng(imagecreatetruecolor(300, 500), $logo);

        // Sin qpdf también funciona: es PHP puro
        app(HerramientasPdf::class)->imagenesAPdf([$foto, $logo], $salida = $dir.'/img-'.uniqid().'.pdf');
        $this->assertStringStartsWith('%PDF-', file_get_contents($salida, false, null, 0, 5));
        $this->assertSame(2, preg_match_all('#/Type /Page\b#', file_get_contents($salida)));

        $this->requiereQpdf();
        $archivo = $this->subirPdf($this->crearCarpeta(null, 'Docs'));
        $tarea = TareaDocumental::create(['user_id' => $this->admin->id, 'tipo' => 'proteger', 'parametros' => [
            'archivos' => [$archivo->id], 'clave' => \Illuminate\Support\Facades\Crypt::encryptString('secreta1'), 'nombre_base' => $archivo->nombre,
        ]]);
        ProcesarPdfJob::dispatchSync($tarea->id);

        $tarea->refresh();
        $this->assertSame('completada', $tarea->estado, (string) $tarea->error);
        $this->assertArrayNotHasKey('clave', $tarea->parametros);
    }

    public function test_herramienta_pdf_vuelve_a_autorizar_al_procesar(): void
    {
        $privada = $this->crearCarpeta(null, 'Gerencia', \App\Models\Carpeta::UNIDAD_PRIVADA);
        $archivo = $this->subirPdf($privada);

        $tarea = TareaDocumental::create(['user_id' => $this->usuario->id, 'tipo' => 'comprimir', 'parametros' => ['archivos' => [$archivo->id], 'nivel' => 'baja']]);
        ProcesarPdfJob::dispatchSync($tarea->id);

        $this->assertSame('error', $tarea->fresh()->estado);
    }
}
