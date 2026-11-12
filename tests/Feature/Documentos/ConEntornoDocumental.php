<?php

namespace Tests\Feature\Documentos;

use App\Models\Archivos;
use App\Models\Carpeta;
use App\Models\User;
use App\Services\Documentos\GestorArchivos;
use App\Services\Documentos\GestorCarpetas;
use Database\Seeders\RolesYPermisosSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

trait ConEntornoDocumental
{
    use RefreshDatabase;

    protected User $admin;
    protected User $gerencia;
    protected User $usuario;
    protected User $otroUsuario;

    protected function prepararEntorno(): void
    {
        Storage::fake('documentos_local');
        Storage::fake('local');
        config(['documentos.disco' => 'documentos_local', 'documentos.disco_recuperacion' => 'documentos_local']);

        $this->seed(RolesYPermisosSeeder::class);

        $this->admin = User::factory()->create(['name' => 'Admin'])->assignRole(User::ROL_ADMIN);
        $this->gerencia = User::factory()->create(['name' => 'Gerente'])->assignRole(User::ROL_GERENCIA);
        $this->usuario = User::factory()->create(['name' => 'Usuario'])->assignRole(User::ROL_USUARIO);
        $this->otroUsuario = User::factory()->create(['name' => 'Otro'])->assignRole(User::ROL_USUARIO);

        Filament::setCurrentPanel(Filament::getPanel('inicio'));
    }

    protected function carpetas(): GestorCarpetas
    {
        return app(GestorCarpetas::class);
    }

    protected function archivos(): GestorArchivos
    {
        return app(GestorArchivos::class);
    }

    protected function crearCarpeta(?Carpeta $padre, string $nombre, string $unidad = Carpeta::UNIDAD_COMPARTIDA, ?User $por = null): Carpeta
    {
        return $this->carpetas()->crear($por ?? $this->admin, $padre, $nombre, $unidad);
    }

    protected function subirPdf(Carpeta $carpeta, string $nombre = 'Informe.pdf', ?User $por = null, int $paginas = 2, string $texto = 'Contenido'): Archivos
    {
        [$archivo] = $this->archivos()->subir($por ?? $this->admin, $carpeta, $this->pdfTemporal($paginas, $texto), $nombre);

        return $archivo;
    }

    /** PDF mínimo válido, sin comprimir (así la compresión tiene margen real). */
    protected function pdfTemporal(int $paginas = 2, string $texto = 'Contenido', bool $relleno = true): string
    {
        $objetos = [];
        $kids = [];
        $n = 3;
        foreach (range(1, $paginas) as $p) {
            $contenido = "BT /F1 18 Tf 72 720 Td ($texto pagina $p) Tj ET\n";
            if ($relleno) {
                $contenido .= str_repeat("BT /F1 10 Tf 72 100 Td (".str_repeat('relleno ', 8).") Tj ET\n", 60);
            }
            $paginaId = $n++;
            $contenidoId = $n++;
            $kids[] = "$paginaId 0 R";
            $objetos[$paginaId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents $contenidoId 0 R /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> >>";
            $objetos[$contenidoId] = '<< /Length '.strlen($contenido)." >>\nstream\n".$contenido."endstream";
        }
        $objetos[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objetos[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$paginas.' >>';
        ksort($objetos);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objetos as $id => $cuerpo) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$cuerpo\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objetos) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }
        $pdf .= 'trailer << /Size '.(count($objetos) + 1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";

        $ruta = tempnam(sys_get_temp_dir(), 'pdftest');
        file_put_contents($ruta, $pdf);

        return $ruta;
    }

    protected function pngTemporal(): string
    {
        $img = imagecreatetruecolor(20, 20);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        $ruta = tempnam(sys_get_temp_dir(), 'pngtest');
        imagepng($img, $ruta);
        imagedestroy($img);

        return $ruta;
    }
}
