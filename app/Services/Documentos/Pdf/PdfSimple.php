<?php

namespace App\Services\Documentos\Pdf;

use RuntimeException;

/**
 * Escritor de PDF mínimo, sin dependencias: páginas con texto (Helvetica) e imágenes JPEG.
 * Sirve para "Imágenes a PDF" (funciona sin qpdf, también en hosting compartido) y para generar los sellos
 * de numeración y marca de agua que qpdf superpone sobre el documento original.
 */
class PdfSimple
{
    /** @var list<array{ancho: float, alto: float, contenido: string, imagenes: array<string, int>, opacidad: bool}> */
    private array $paginas = [];

    /** @var list<array{datos: string, ancho: int, alto: int, gris: bool}> */
    private array $imagenes = [];

    public function pagina(float $ancho, float $alto): static
    {
        $this->paginas[] = ['ancho' => $ancho, 'alto' => $alto, 'contenido' => '', 'imagenes' => [], 'opacidad' => false, 'opacidadValor' => 1];

        return $this;
    }

    /** Texto en (x, y) desde la esquina inferior izquierda, girado $angulo grados, gris $gris (0 negro – 1 blanco). */
    public function texto(string $texto, float $x, float $y, float $tamano, float $angulo = 0, float $gris = 0, ?float $opacidad = null): static
    {
        $rad = deg2rad($angulo);
        [$c, $s] = [cos($rad), sin($rad)];
        $p = &$this->paginas[array_key_last($this->paginas)];
        if ($opacidad !== null) {
            $p['opacidad'] = true;
        }

        $p['contenido'] .= sprintf(
            "q %s%.3F g BT /F1 %.2F Tf %.4F %.4F %.4F %.4F %.2F %.2F Tm (%s) Tj ET Q\n",
            $opacidad !== null ? '/GS1 gs ' : '', $gris, $tamano, $c, $s, -$s, $c, $x, $y, $this->escapar($texto)
        );
        $p['opacidadValor'] = $opacidad ?? ($p['opacidadValor'] ?? 1);

        return $this;
    }

    /** Ancho aproximado en puntos de un texto en Helvetica (para centrarlo). */
    public static function anchoTexto(string $texto, float $tamano): float
    {
        $ancho = 0;
        foreach (mb_str_split($texto) as $car) {
            $ancho += match (true) {
                str_contains('il.,:;|!\'', $car) => 0.28,
                str_contains('fjrt()[] -', $car) => 0.33,
                str_contains('mwMW', $car) => 0.86,
                ctype_upper($car) => 0.68,
                ctype_digit($car) => 0.556,
                default => 0.53,
            };
        }

        return $ancho * $tamano;
    }

    /** Agrega una imagen JPEG o PNG (PNG se convierte a JPEG con GD) ajustada dentro del rectángulo indicado. */
    public function imagen(string $ruta, float $x, float $y, float $anchoMax, float $altoMax): static
    {
        $info = @getimagesize($ruta);
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new RuntimeException('Solo se aceptan imágenes JPG, PNG o WEBP.');
        }

        $datos = $info[2] === IMAGETYPE_JPEG ? file_get_contents($ruta) : $this->aJpeg($ruta, $info[2]);
        $gris = ($info['channels'] ?? 3) === 1;
        if (($info['channels'] ?? 3) === 4) {
            // JPEG en CMYK: se pasa por GD para no depender de que el lector lo interprete bien
            $datos = $this->aJpeg($ruta, IMAGETYPE_JPEG);
            $gris = false;
        }

        $this->imagenes[] = ['datos' => $datos, 'ancho' => $info[0], 'alto' => $info[1], 'gris' => $gris];
        $indice = count($this->imagenes);

        $escala = min($anchoMax / $info[0], $altoMax / $info[1]);
        [$w, $h] = [$info[0] * $escala, $info[1] * $escala];
        $p = &$this->paginas[array_key_last($this->paginas)];
        $p['imagenes']["Im$indice"] = $indice;
        $p['contenido'] .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im%d Do Q\n", $w, $h, $x + ($anchoMax - $w) / 2, $y + ($altoMax - $h) / 2, $indice);

        return $this;
    }

    public function guardar(string $ruta): void
    {
        if (! $this->paginas) {
            throw new RuntimeException('El documento no tiene páginas.');
        }

        $objetos = [];
        $nuevo = function (string $cuerpo) use (&$objetos): int {
            $objetos[] = $cuerpo;

            return count($objetos);
        };

        $catalogo = $nuevo('');
        $raiz = $nuevo('');
        $fuente = $nuevo('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');

        $idsImagen = [];
        foreach ($this->imagenes as $i => $img) {
            $idsImagen[$i + 1] = $nuevo(sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
                $img['ancho'], $img['alto'], $img['gris'] ? 'DeviceGray' : 'DeviceRGB', strlen($img['datos']), $img['datos']
            ));
        }

        $kids = [];
        foreach ($this->paginas as $p) {
            $contenido = $nuevo(sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($p['contenido']), $p['contenido']));
            $xobjects = implode(' ', array_map(fn ($nombre, $i) => "/$nombre {$idsImagen[$i]} 0 R", array_keys($p['imagenes']), $p['imagenes']));
            $estados = $p['opacidad'] ? sprintf('/ExtGState << /GS1 << /Type /ExtGState /ca %.2F /CA %.2F >> >>', $p['opacidadValor'], $p['opacidadValor']) : '';
            $kids[] = $nuevo(sprintf(
                '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 %d 0 R >> %s %s >> /Contents %d 0 R >>',
                $raiz, $p['ancho'], $p['alto'], $fuente, $xobjects ? "/XObject << $xobjects >>" : '', $estados, $contenido
            ));
        }

        $objetos[$catalogo - 1] = "<< /Type /Catalog /Pages $raiz 0 R >>";
        $objetos[$raiz - 1] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', array_map(fn ($k) => "$k 0 R", $kids)), count($kids));

        $salida = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objetos as $i => $cuerpo) {
            $offsets[] = strlen($salida);
            $salida .= ($i + 1)." 0 obj\n$cuerpo\nendobj\n";
        }
        $xref = strlen($salida);
        $salida .= 'xref'."\n0 ".(count($objetos) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $salida .= sprintf("%010d 00000 n \n", $o);
        }
        $salida .= sprintf("trailer\n<< /Size %d /Root %d 0 R >>\nstartxref\n%d\n%%%%EOF\n", count($objetos) + 1, $catalogo, $xref);

        if (file_put_contents($ruta, $salida) === false) {
            throw new RuntimeException('No se pudo escribir el PDF.');
        }
    }

    private function escapar(string $texto): string
    {
        $ansi = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $texto);

        return strtr($ansi === false ? $texto : $ansi, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => ' ', "\n" => ' ']);
    }

    private function aJpeg(string $ruta, int $tipo): string
    {
        $origen = match ($tipo) {
            IMAGETYPE_PNG => @imagecreatefrompng($ruta),
            IMAGETYPE_WEBP => @imagecreatefromwebp($ruta),
            default => @imagecreatefromjpeg($ruta),
        };
        if (! $origen) {
            throw new RuntimeException('No se pudo leer la imagen.');
        }

        // Fondo blanco para las transparencias
        $lienzo = imagecreatetruecolor(imagesx($origen), imagesy($origen));
        imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));
        imagecopy($lienzo, $origen, 0, 0, 0, 0, imagesx($origen), imagesy($origen));

        ob_start();
        imagejpeg($lienzo, null, 88);

        return (string) ob_get_clean();
    }
}
