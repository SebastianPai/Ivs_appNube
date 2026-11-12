<?php

namespace App\Services\Documentos;

use App\Models\Archivos;
use App\Models\Carpeta;
use Illuminate\Support\Str;

class NombresDocumentales
{
    /** Quita separadores de ruta, caracteres de control y reservados de Windows/GCS. */
    public static function limpiar(string $nombre): string
    {
        $nombre = basename(str_replace('\\', '/', $nombre));
        $nombre = preg_replace('/[\x00-\x1F\x7F<>:"\/\\\\|?*]+/u', ' ', $nombre) ?? '';
        $nombre = trim(preg_replace('/\s+/u', ' ', $nombre) ?? '', " .\t");
        $nombre = Str::limit($nombre, 200, '');

        return $nombre === '' ? 'Sin nombre' : $nombre;
    }

    public static function extension(string $nombre): ?string
    {
        $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));

        return $ext === '' ? null : $ext;
    }

    /** Si ya existe "Informe.pdf" en la carpeta devuelve "Informe (2).pdf", y así sucesivamente. */
    public static function unicoArchivo(?int $carpetaId, string $nombre, ?int $excepto = null): string
    {
        $existentes = Archivos::query()
            ->where(fn ($q) => $carpetaId === null ? $q->whereNull('carpeta_id') : $q->where('carpeta_id', $carpetaId))
            ->when($excepto, fn ($q) => $q->where('id', '!=', $excepto))
            ->pluck('nombre')
            ->map(fn ($n) => mb_strtolower($n))
            ->all();

        return self::siguienteLibre($nombre, $existentes, true);
    }

    public static function unicoCarpeta(?int $padreId, string $unidad, string $nombre, ?int $excepto = null): string
    {
        $existentes = Carpeta::query()
            ->where(fn ($q) => $padreId === null
                ? $q->whereNull('carpeta_padre_id')->where('unidad', $unidad)
                : $q->where('carpeta_padre_id', $padreId))
            ->when($excepto, fn ($q) => $q->where('id', '!=', $excepto))
            ->pluck('nombre')
            ->map(fn ($n) => mb_strtolower($n))
            ->all();

        return self::siguienteLibre($nombre, $existentes, false);
    }

    private static function siguienteLibre(string $nombre, array $existentesMinusculas, bool $conExtension): string
    {
        if (! in_array(mb_strtolower($nombre), $existentesMinusculas, true)) {
            return $nombre;
        }

        $ext = $conExtension ? pathinfo($nombre, PATHINFO_EXTENSION) : '';
        $base = $ext !== '' ? mb_substr($nombre, 0, -(mb_strlen($ext) + 1)) : $nombre;
        $base = preg_replace('/ \(\d+\)$/u', '', $base);

        for ($i = 2; ; $i++) {
            $candidato = $base.' ('.$i.')'.($ext !== '' ? '.'.$ext : '');
            if (! in_array(mb_strtolower($candidato), $existentesMinusculas, true)) {
                return $candidato;
            }
        }
    }
}
