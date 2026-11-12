<?php

namespace App\Services\Documentos\Recuperacion;

use Illuminate\Support\Str;
use SimpleXMLElement;
use ZipArchive;

/**
 * Lee la cabecera "INFORMACION GENERAL" de los formatos de inspección en Excel (OIN-FO-OP-006 Informe Final,
 * OIN-FO-OP-003 Lista de chequeo…) para saber a qué estación pertenece un expediente.
 * Solo lee el archivo local que recibe; no depende de PhpSpreadsheet.
 */
class LectorFichaInspeccion
{
    /** Etiqueta normalizada (prefijo) => campo de la ficha */
    private const ETIQUETAS = [
        'NOMBRE COMERCIAL' => 'nombre',
        'NOMBRE:' => 'nombre', // Lista de chequeo: "Nombre: ESTACION DE SERVICIO …" en una sola celda
        'NOMBRE DEL SOLICITANTE O RAZON SOCIAL' => 'razon_social',
        'RAZON SOCIAL' => 'razon_social',
        'NIT' => 'nit',
        'TIPO DE INSTALACION:' => 'tipo',
        'DIRECCION Y CIUDAD' => 'direccion',
        'DIRECCION EDS' => 'direccion',
        'CIUDAD / MUNICIPIO' => 'ciudad',
        'DEPARTAMENTO' => 'departamento',
        'FECHA DE LA INSPECCION' => 'fecha_inspeccion',
        'CERTIFICADO NO' => 'certificado',
        'CODIGO SICOM' => 'sicom',
    ];

    /** @return array<string, string>|null Campos encontrados, o null si el archivo no tiene la cabecera */
    public function leer(string $rutaXlsx): ?array
    {
        $zip = new ZipArchive();
        if ($zip->open($rutaXlsx) !== true) {
            return null;
        }

        try {
            $compartidas = $this->textosCompartidos($zip->getFromName('xl/sharedStrings.xml') ?: null);
            $hoja = $zip->getFromName('xl/worksheets/sheet1.xml');
        } finally {
            $zip->close();
        }

        if (! $hoja) {
            return null;
        }

        $ficha = [];
        foreach ($this->filas($hoja, $compartidas) as $celdas) {
            foreach ($celdas as $i => $texto) {
                $campo = $this->campo($texto);
                if (! $campo || isset($ficha[$campo])) {
                    continue;
                }
                // El valor va en la misma celda tras ":" o, si no, en la siguiente celda con contenido de la fila
                $enLinea = str_contains($texto, ':') ? trim(substr($texto, strpos($texto, ':') + 1)) : '';
                $valor = $enLinea !== '' ? $enLinea : collect(array_slice($celdas, $i + 1))->first(fn ($v) => trim($v) !== '');
                $valor = $valor !== null ? trim(preg_replace('/\s+/u', ' ', $valor)) : '';
                $valor = preg_replace('/^(\d+)\.0$/', '$1', $valor); // Números que Excel guardó como 636231.0
                if ($this->esValor($valor)) {
                    $ficha[$campo] = mb_substr($valor, 0, 150);
                }
            }
        }

        if (isset($ficha['nombre'])) {
            $ficha['nombre_carpeta'] = $this->nombreCarpeta($ficha['nombre']);
        }

        return $ficha ?: null;
    }

    /** "ESTACION DE SERVICIO GASOLINERA ANDERSON" → "EDS GASOLINERA ANDERSON" */
    public function nombreCarpeta(string $nombre): string
    {
        $n = Str::upper(Str::ascii($nombre));
        $n = preg_replace('/^(ESTACION(ES)?( DE SERVICIOS?)?|E\.?D\.?S\.?)\s+/', '', $n);
        $n = trim(preg_replace('/[^A-Z0-9 .&-]+/', ' ', $n));

        return 'EDS '.preg_replace('/\s+/', ' ', $n);
    }

    /** En una plantilla sin llenar la "siguiente celda" es otra etiqueta o una casilla de selección */
    private function esValor(string $valor): bool
    {
        return $valor !== ''
            && ! in_array(Str::upper($valor), ['N.A', 'NA', 'N/A', '-'], true)
            && ! str_ends_with($valor, ':')
            && ! preg_match('/\(\s*X?\s*\)/i', $valor)
            && $this->campo($valor) === null;
    }

    private function campo(string $texto): ?string
    {
        $t = Str::upper(Str::ascii(trim(preg_replace('/\s+/u', ' ', $texto))));
        foreach (self::ETIQUETAS as $etiqueta => $campo) {
            if (str_starts_with($t, $etiqueta) && ($etiqueta !== 'NIT' || preg_match('/^NIT\b/', $t))) {
                return $campo;
            }
        }

        return null;
    }

    /** @return string[] */
    private function textosCompartidos(?string $xml): array
    {
        if (! $xml) {
            return [];
        }

        $sst = new SimpleXMLElement($xml);
        $textos = [];
        foreach ($sst->si as $si) {
            // Un texto con formato mixto viene partido en varios <r><t>
            $partes = $si->xpath('.//*[local-name()="t"]') ?: [];
            $textos[] = implode('', array_map('strval', $partes));
        }

        return $textos;
    }

    /** @return iterable<string[]> Celdas de cada fila (solo las primeras filas: la cabecera) */
    private function filas(string $xml, array $compartidas): iterable
    {
        $hoja = new SimpleXMLElement($xml);
        $n = 0;
        foreach ($hoja->sheetData->row ?? [] as $fila) {
            if (++$n > 40) {
                break;
            }
            $celdas = [];
            foreach ($fila->c as $c) {
                $tipo = (string) $c['t'];
                $celdas[] = match ($tipo) {
                    's' => $compartidas[(int) $c->v] ?? '',
                    'inlineStr' => implode('', array_map('strval', $c->xpath('.//*[local-name()="t"]') ?: [])),
                    default => (string) $c->v,
                };
            }
            yield $celdas;
        }
    }
}
