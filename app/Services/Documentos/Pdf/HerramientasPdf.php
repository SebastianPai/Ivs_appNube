<?php

namespace App\Services\Documentos\Pdf;

use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Operaciones PDF con qpdf (unir, extraer/reordenar, dividir, rotar, compresión sin pérdida y optimización de
 * imágenes) y, si está instalado, Ghostscript para la compresión fuerte. Los procesos se lanzan con lista de
 * argumentos, sin shell, y nunca escriben sobre el archivo de entrada.
 */
class HerramientasPdf
{
    public const NIVELES = [
        'baja' => 'Sin pérdida (reestructura y recomprime, no toca imágenes)',
        'media' => 'Media (además recomprime imágenes en JPEG calidad 75)',
        'alta' => 'Alta (reduce la resolución de las imágenes; requiere Ghostscript)',
    ];

    public function disponibles(): array
    {
        return [
            'qpdf' => $this->funciona([$this->qpdf(), '--version']),
            'ghostscript' => $this->funciona([$this->ghostscript(), '--version']),
        ];
    }

    public function comprimir(string $entrada, string $salida, string $nivel, ?float $timeout = null): void
    {
        if ($nivel === 'alta' && $this->disponibles()['ghostscript']) {
            $this->ejecutar([
                $this->ghostscript(), '-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.5', '-dPDFSETTINGS=/ebook',
                '-dNOPAUSE', '-dQUIET', '-dBATCH', '-dSAFER', '-sOutputFile='.$salida, $entrada,
            ], $timeout);
        } else {
            $args = [$this->qpdf(), '--object-streams=generate', '--compress-streams=y', '--recompress-flate', '--compression-level=9'];
            if ($nivel !== 'baja') {
                $args = array_merge($args, ['--optimize-images', '--jpeg-quality=75']);
            }
            $this->ejecutar(array_merge($args, [$entrada, $salida]), $timeout);
        }

        $this->verificar($salida);
    }

    /** @param list<string> $entradas */
    public function unir(array $entradas, string $salida): void
    {
        if (count($entradas) < 2) {
            throw new RuntimeException('Selecciona al menos dos PDF para unir.');
        }

        $this->ejecutar(array_merge([$this->qpdf(), '--empty', '--pages'], $entradas, ['--', $salida]));
        $this->verificar($salida);
    }

    /** Extrae y/o reordena: "1-3,7" o "3,1,2". El orden indicado es el del resultado. */
    public function extraer(string $entrada, string $rango, string $salida): void
    {
        $this->ejecutar([$this->qpdf(), $entrada, '--pages', '.', $this->rangoValido($rango), '--', $salida]);
        $this->verificar($salida);
    }

    /** Divide en partes de N páginas y entrega un ZIP. */
    public function dividir(string $entrada, int $paginasPorParte, string $salidaZip): int
    {
        $dir = dirname($salidaZip).'/partes';
        @mkdir($dir, 0700, true);

        $this->ejecutar([$this->qpdf(), '--split-pages='.max(1, $paginasPorParte), $entrada, $dir.'/parte-%d.pdf']);

        $partes = glob($dir.'/parte-*.pdf') ?: [];
        natsort($partes);

        $zip = new ZipArchive();
        if ($zip->open($salidaZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo ZIP.');
        }
        foreach ($partes as $parte) {
            $zip->addFile($parte, basename($parte));
        }
        $zip->close();

        return count($partes);
    }

    public function rotar(string $entrada, int $grados, ?string $rango, string $salida): void
    {
        if (! in_array($grados, [90, 180, 270], true)) {
            throw new RuntimeException('La rotación debe ser de 90, 180 o 270 grados.');
        }

        $rango = trim((string) $rango) === '' ? '1-z' : $this->rangoValido($rango);
        $this->ejecutar([$this->qpdf(), $entrada, $salida, '--rotate=+'.$grados.':'.$rango]);
        $this->verificar($salida);
    }

    /** Quita las páginas indicadas y conserva el resto en su orden. */
    public function eliminarPaginas(string $entrada, string $rango, string $salida): void
    {
        $total = $this->paginas($entrada);
        $quitar = $this->expandirRango($this->rangoValido($rango), $total);
        $conservar = array_values(array_diff(range(1, $total), $quitar));
        if (! $conservar) {
            throw new RuntimeException('No se pueden eliminar todas las páginas.');
        }

        $this->ejecutar([$this->qpdf(), $entrada, '--pages', '.', implode(',', $conservar), '--', $salida]);
        $this->verificar($salida);
    }

    /** Cifrado AES-256. Sin contraseña de propietario aparte: se usa la misma. */
    public function proteger(string $entrada, string $clave, string $salida): void
    {
        if (mb_strlen($clave) < 4) {
            throw new RuntimeException('La contraseña debe tener al menos 4 caracteres.');
        }

        $this->ejecutar([$this->qpdf(), '--encrypt', $clave, $clave, '256', '--', $entrada, $salida]);
        if (! is_file($salida)) {
            throw new RuntimeException('No se pudo proteger el PDF.');
        }
    }

    public function desbloquear(string $entrada, string $clave, string $salida): void
    {
        $this->ejecutar([$this->qpdf(), '--password='.$clave, '--decrypt', $entrada, $salida]);
        $this->verificar($salida);
    }

    /** Número "n / total" (o solo "n") en cada página. Posición: abajo-centro, abajo-derecha o arriba-derecha. */
    public function numerar(string $entrada, string $salida, string $posicion = 'abajo-centro', bool $conTotal = true, int $desde = 1): void
    {
        $tamanos = $this->tamanosPaginas($entrada);
        $total = count($tamanos);
        $sello = new PdfSimple();

        foreach ($tamanos as $i => [$ancho, $alto]) {
            $texto = $conTotal ? ($i + $desde).' / '.($total + $desde - 1) : (string) ($i + $desde);
            $t = max(8, min(14, $ancho / 50));
            $w = PdfSimple::anchoTexto($texto, $t);
            [$x, $y] = match ($posicion) {
                'abajo-derecha' => [$ancho - $w - 28, 20],
                'arriba-derecha' => [$ancho - $w - 28, $alto - 20 - $t],
                default => [($ancho - $w) / 2, 20],
            };
            $sello->pagina($ancho, $alto)->texto($texto, $x, $y, $t, 0, 0.2);
        }

        $this->superponer($entrada, $sello, $salida);
    }

    /** Texto en diagonal, gris y semitransparente, centrado en cada página. */
    public function marcaAgua(string $entrada, string $texto, string $salida, int $opacidad = 20): void
    {
        $texto = trim($texto);
        if ($texto === '' || mb_strlen($texto) > 60) {
            throw new RuntimeException('Escribe un texto de marca de agua de hasta 60 caracteres.');
        }

        $sello = new PdfSimple();
        foreach ($this->tamanosPaginas($entrada) as [$ancho, $alto]) {
            $diagonal = sqrt($ancho ** 2 + $alto ** 2);
            $t = min(96, 0.75 * $diagonal / max(1, PdfSimple::anchoTexto($texto, 1)));
            $w = PdfSimple::anchoTexto($texto, $t);
            $angulo = rad2deg(atan2($alto, $ancho));
            $rad = deg2rad($angulo);
            // Se parte del centro y se retrocede media longitud del texto sobre la diagonal
            $x = $ancho / 2 - cos($rad) * $w / 2 + sin($rad) * $t / 3;
            $y = $alto / 2 - sin($rad) * $w / 2 - cos($rad) * $t / 3;
            $sello->pagina($ancho, $alto)->texto($texto, $x, $y, $t, $angulo, 0.5, max(5, min(80, $opacidad)) / 100);
        }

        $this->superponer($entrada, $sello, $salida);
    }

    /**
     * Imágenes a PDF, una por página. No usa qpdf: funciona también en hosting compartido.
     *
     * @param  list<string>  $imagenes
     */
    public function imagenesAPdf(array $imagenes, string $salida, string $tamano = 'a4'): void
    {
        if (! $imagenes) {
            throw new RuntimeException('Sube al menos una imagen.');
        }

        $pdf = new PdfSimple();
        foreach ($imagenes as $ruta) {
            $info = @getimagesize($ruta) ?: throw new RuntimeException('Uno de los archivos no es una imagen válida.');
            if ($tamano === 'original') {
                // 1 px = 0,75 pt (96 ppp), máximo un A3 para no generar páginas gigantes
                $escala = min(0.75, 1190 / max($info[0], $info[1]));
                [$ancho, $alto] = [$info[0] * $escala, $info[1] * $escala];
                $pdf->pagina($ancho, $alto)->imagen($ruta, 0, 0, $ancho, $alto);
            } else {
                // A4 en la orientación de la imagen, con margen de 1 cm
                [$ancho, $alto] = $info[0] > $info[1] ? [841.89, 595.28] : [595.28, 841.89];
                $pdf->pagina($ancho, $alto)->imagen($ruta, 28.35, 28.35, $ancho - 56.7, $alto - 56.7);
            }
        }

        $pdf->guardar($salida);
        if ($this->disponibles()['qpdf']) {
            $this->verificar($salida);
        }
    }

    /** @return list<array{0: float, 1: float}> Ancho y alto visibles de cada página (considera /Rotate) */
    public function tamanosPaginas(string $archivo): array
    {
        $json = json_decode($this->ejecutar([$this->qpdf(), '--json=2', '--json-key=pages', '--json-key=qpdf', $archivo]), true);
        $objetos = $json['qpdf'][1] ?? [];
        $valor = fn (string $ref) => $objetos["obj:$ref"]['value'] ?? [];

        $tamanos = [];
        foreach ($json['pages'] ?? [] as $pagina) {
            $nodo = $valor($pagina['object']);
            $caja = $nodo['/CropBox'] ?? $nodo['/MediaBox'] ?? null;
            $rotacion = $nodo['/Rotate'] ?? null;
            // MediaBox y Rotate se heredan del árbol de páginas
            for ($padre = $nodo['/Parent'] ?? null; ($caja === null || $rotacion === null) && is_string($padre); $padre = $valor($padre)['/Parent'] ?? null) {
                $caja ??= $valor($padre)['/MediaBox'] ?? null;
                $rotacion ??= $valor($padre)['/Rotate'] ?? null;
            }
            if (is_string($caja)) {
                $caja = $valor($caja);
            }
            $caja = is_array($caja) && count($caja) === 4 ? array_map('floatval', $caja) : [0, 0, 595.28, 841.89];
            [$w, $h] = [abs($caja[2] - $caja[0]), abs($caja[3] - $caja[1])];
            $tamanos[] = in_array(((int) $rotacion % 360 + 360) % 360, [90, 270], true) ? [$h, $w] : [$w, $h];
        }

        return $tamanos ?: throw new RuntimeException('No se pudieron leer las páginas del PDF.');
    }

    /** qpdf coloca la página i del sello sobre la página i del documento (ajusta tamaño y rotación). */
    private function superponer(string $entrada, PdfSimple $sello, string $salida): void
    {
        $rutaSello = dirname($salida).'/sello-'.bin2hex(random_bytes(4)).'.pdf';
        try {
            $sello->guardar($rutaSello);
            $this->ejecutar([$this->qpdf(), $entrada, '--overlay', $rutaSello, '--', $salida]);
        } finally {
            @unlink($rutaSello);
        }
        $this->verificar($salida);
    }

    /** @return list<int> */
    private function expandirRango(string $rango, int $total): array
    {
        $paginas = [];
        foreach (explode(',', $rango) as $parte) {
            [$a, $b] = array_pad(explode('-', $parte, 2), 2, null);
            $num = fn (string $v) => $v === 'z' ? $total : (str_starts_with($v, 'r') ? $total - (int) substr($v, 1) + 1 : (int) $v);
            [$desde, $hasta] = [$num($a), $b === null ? $num($a) : $num($b)];
            foreach (range(min($desde, $hasta), max($desde, $hasta)) as $p) {
                if ($p >= 1 && $p <= $total) {
                    $paginas[] = $p;
                }
            }
        }

        return array_values(array_unique($paginas));
    }

    public function paginas(string $archivo): int
    {
        return (int) trim($this->ejecutar([$this->qpdf(), '--show-npages', $archivo]));
    }

    public function metadatos(string $archivo): array
    {
        $cabecera = (string) file_get_contents($archivo, false, null, 0, 16);
        try {
            $paginas = $this->paginas($archivo);
        } catch (RuntimeException) {
            $paginas = null; // protegido con contraseña de apertura
        }

        $datos = [
            'paginas' => $paginas,
            'version_pdf' => preg_match('/%PDF-(\d\.\d)/', $cabecera, $m) ? $m[1] : null,
            'tamano' => filesize($archivo),
            'cifrado' => $this->codigoSalida([$this->qpdf(), '--is-encrypted', $archivo]) === 0,
            'linealizado' => $this->codigoSalida([$this->qpdf(), '--check-linearization', $archivo]) === 0,
        ];

        try {
            $trailer = json_decode($this->ejecutar([$this->qpdf(), '--json=2', '--json-key=qpdf', '--json-object=trailer', $archivo]), true);
            $info = $trailer['qpdf'][1]['trailer']['value']['/Info'] ?? null;
            if (is_string($info) && preg_match('/^(\d+) (\d+) R$/', $info, $ref)) {
                $obj = json_decode($this->ejecutar([$this->qpdf(), '--json=2', '--json-key=qpdf', '--json-object='.$ref[1].','.$ref[2], $archivo]), true);
                foreach ($obj['qpdf'][1]["obj:{$info}"]['value'] ?? [] as $clave => $valor) {
                    if (is_string($valor)) {
                        $datos['info'][ltrim($clave, '/')] = preg_replace('/^[ub]:/', '', $valor);
                    }
                }
            }
        } catch (RuntimeException) {
            // Sin diccionario /Info legible: se devuelven solo los datos básicos
        }

        return $datos;
    }

    public function rangoValido(string $rango): string
    {
        $rango = preg_replace('/\s+/', '', $rango);
        $parte = '(?:r?\d+|z)(?:-(?:r?\d+|z))?';
        if (! preg_match('/^'.$parte.'(?:,'.$parte.')*$/', $rango)) {
            throw new RuntimeException('Rango de páginas no válido. Ejemplos: 1-3,5 · 3,1,2 · 2-z');
        }

        return $rango;
    }

    private function verificar(string $salida): void
    {
        // qpdf --check: 0 = válido, 3 = válido con advertencias
        if (! is_file($salida) || ! in_array($this->codigoSalida([$this->qpdf(), '--check', $salida]), [0, 3], true)) {
            throw new RuntimeException('El PDF resultante no es válido; se descartó.');
        }
    }

    private function ejecutar(array $argumentos, ?float $timeout = null): string
    {
        $proceso = new Process($argumentos, null, null, null, $timeout ?? (float) config('documentos.pdf.timeout'));
        $proceso->run();

        // qpdf termina con 3 cuando hubo advertencias pero el resultado es válido
        if (! in_array($proceso->getExitCode(), [0, 3], true)) {
            $error = trim($proceso->getErrorOutput() ?: $proceso->getOutput());
            throw new RuntimeException('No se pudo procesar el PDF: '.mb_substr(str_replace(dirname($argumentos[count($argumentos) - 1]), '', $error), 0, 300));
        }

        return $proceso->getOutput();
    }

    private function codigoSalida(array $argumentos): ?int
    {
        $proceso = new Process($argumentos, null, null, null, 60.0);
        $proceso->run();

        return $proceso->getExitCode();
    }

    private function funciona(array $argumentos): bool
    {
        try {
            return $this->codigoSalida($argumentos) === 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function qpdf(): string
    {
        return (string) config('documentos.pdf.qpdf');
    }

    private function ghostscript(): string
    {
        return (string) config('documentos.pdf.ghostscript');
    }
}
