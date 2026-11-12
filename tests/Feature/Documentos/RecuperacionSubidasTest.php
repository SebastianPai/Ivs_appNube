<?php

namespace Tests\Feature\Documentos;

use App\Models\InventarioObjeto;
use App\Models\RecuperacionGrupo;
use App\Services\Documentos\Recuperacion\AnalizadorRecuperacion;
use App\Services\Documentos\Recuperacion\IdentificadorExpedientes;
use App\Services\Documentos\Recuperacion\InventarioAlmacenamiento;
use App\Services\Documentos\Recuperacion\LectorFichaInspeccion;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/** Bucket plano de la app anterior: uploads/{hora unix}-{nombre original} */
class RecuperacionSubidasTest extends TestCase
{
    use ConEntornoDocumental;

    private const T = 1760000000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEntorno();
    }

    /** xlsx mínimo con la cabecera "INFORMACION GENERAL" del Informe Final */
    private function informeFinal(array $filas): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        $textos = [];
        $xmlFilas = '';
        foreach ($filas as $r => $celdas) {
            $xmlFilas .= '<row r="'.($r + 1).'">';
            foreach (array_values($celdas) as $c => $texto) {
                $textos[] = htmlspecialchars($texto);
                $xmlFilas .= '<c r="'.chr(65 + $c).($r + 1).'" t="s"><v>'.(count($textos) - 1).'</v></c>';
            }
            $xmlFilas .= '</row>';
        }

        $zip = new ZipArchive();
        $zip->open($ruta, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .implode('', array_map(fn ($t) => "<si><t>$t</t></si>", $textos)).'</sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$xmlFilas.'</sheetData></worksheet>');
        $zip->close();

        return file_get_contents($ruta);
    }

    private function subida(int $segundos, string $nombre, string $contenido = ''): void
    {
        Storage::disk('documentos_local')->put('uploads/'.(self::T + $segundos)."-$nombre", $contenido ?: "contenido $nombre $segundos");
    }

    private function inventariarIdentificarYAnalizar(): void
    {
        app(InventarioAlmacenamiento::class)->ejecutar('documentos_local', null, $this->admin);
        app(IdentificadorExpedientes::class)->ejecutar('documentos_local');
        app(AnalizadorRecuperacion::class)->analizar();
    }

    public function test_lector_extrae_la_ficha_y_descarta_plantillas_vacias(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'ficha');
        file_put_contents($ruta, $this->informeFinal([
            ['INFORME FINAL DE INSPECCION'],
            ['NOMBRE COMERCIAL DE LA INSTALACION A INSPECCIONAR:', 'ESTACION DE SERVICIO GASOLINERA ANDERSON'],
            ['NIT:', '36812081-2'],
            ['Código SICOM: 636739.0'],
            ['Fecha de la inspección: 12/08/2025', 'NOMBRE DEL INSPECTOR:'],
            ['Razón social', 'Nombre:'], // plantilla sin llenar: la celda siguiente es otra etiqueta
        ]));

        $ficha = app(LectorFichaInspeccion::class)->leer($ruta);

        $this->assertSame('EDS GASOLINERA ANDERSON', $ficha['nombre_carpeta']);
        $this->assertSame('36812081-2', $ficha['nit']);
        $this->assertSame('636739', $ficha['sicom']);
        $this->assertSame('12/08/2025', $ficha['fecha_inspeccion']);
        $this->assertArrayNotHasKey('razon_social', $ficha);
    }

    public function test_separa_expedientes_del_mismo_lote_y_los_nombra_con_su_ficha(): void
    {
        $informe = $this->informeFinal([['NOMBRE COMERCIAL DE LA INSTALACION:', 'ESTACION DE SERVICIO SAN DIEGO'], ['NIT:', '900-1']]);

        // Primera estación, en orden alfabético y con su Informe Final
        foreach (['4.1.B - INFRAESTRUCTURA.pdf', '5.1.C - EQUIPO ANCLADO.pdf', '5.3.C - ROTULADO.pdf', '5.5.A - EXTINTORES.pdf', '6.2.D - AREA.pdf'] as $i => $n) {
            $this->subida($i, $n);
        }
        $this->subida(6, 'OIN-FO-OP-006 - Informe Final de Inspeccion.xlsx', $informe);
        // Segunda estación en el mismo lote: el orden vuelve a 4.1
        foreach (['4.1.B - INFRAESTRUCTURA.pdf', '5.1.C - EQUIPO.pdf', '5.3.C - ROTULADO EDS LAS BRISAS.pdf', '5.5.A - EXTINTORES EDS LAS BRISAS.pdf', '6.2.D - AREA.pdf'] as $i => $n) {
            $this->subida(10 + $i, $n);
        }
        // Documentos sueltos horas después
        $this->subida(9000, 'HOJA DE VIDA JUAN PEREZ.pdf');
        $this->subida(9001, 'OIN-PR-AD-002 Procedimiento de compras.docx');
        $this->subida(9002, 'cosa sin pistas.pdf');

        $antes = Storage::disk('documentos_local')->allFiles();
        $this->inventariarIdentificarYAnalizar();

        $sanDiego = RecuperacionGrupo::where('tipo', 'expediente_inspeccion')->where('clave', 'like', 'EDS SAN DIEGO (%')->firstOrFail();
        $this->assertSame(6, $sanDiego->total);
        $this->assertSame(85, $sanDiego->confianza);
        $this->assertStringContainsString('NIT 900-1', $sanDiego->motivo);

        $brisas = RecuperacionGrupo::where('tipo', 'expediente_inspeccion')->where('clave', 'like', 'EDS LAS BRISAS (%')->firstOrFail();
        $this->assertSame(5, $brisas->total);
        $this->assertSame(70, $brisas->confianza);

        $this->assertSame(1, RecuperacionGrupo::where(['tipo' => 'categoria', 'clave' => 'Personal'])->value('total'));
        $this->assertSame(1, RecuperacionGrupo::where(['tipo' => 'categoria', 'clave' => 'Sistema de gestión'])->value('total'));
        $this->assertSame('lote_subida', InventarioObjeto::where('nombre', 'like', '%cosa sin pistas.pdf')->first()->grupo->tipo);

        // Solo lectura: el bucket queda igual
        $this->assertEquals($antes, Storage::disk('documentos_local')->allFiles());
    }
}
