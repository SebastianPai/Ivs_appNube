<?php

namespace App\Services\Documentos;

use App\Models\Actividad;
use App\Models\Archivos;
use App\Models\Carpeta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Respaldo diario en el mismo bucket (respaldos/AAAA/MM/):
 *  - nube-ivs-…sql.gz: volcado completo de la base de datos, en PHP puro (en cPanel mysqldump suele no estar)
 *  - nube-ivs-…manifiesto.json.gz: carpeta → archivos → objeto del bucket. Basta para reconstruir la
 *    organización aunque se pierda la base de datos (lo que pasó con la app anterior).
 */
class RespaldoDocumental
{
    // Tablas que se respaldan solo con su estructura: su contenido es temporal
    private const SOLO_ESTRUCTURA = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'];

    /** @return array{sql: string, manifiesto: string, borrados: int, bytes: int} */
    public function ejecutar(): array
    {
        $disco = (string) config('documentos.respaldos.disco');
        $prefijo = trim((string) config('documentos.respaldos.prefijo'), '/');
        $ahora = now();
        $base = sprintf('%s/%s/nube-ivs-%s', $prefijo, $ahora->format('Y/m'), $ahora->format('Ymd-His'));

        $temporalSql = $this->volcarBaseDeDatos();
        $temporalManifiesto = $this->manifiesto($ahora);

        try {
            $bytes = filesize($temporalSql) + filesize($temporalManifiesto);
            $this->subir($disco, $temporalSql, "$base.sql.gz");
            $this->subir($disco, $temporalManifiesto, "$base.manifiesto.json.gz");
        } finally {
            @unlink($temporalSql);
            @unlink($temporalManifiesto);
        }

        $borrados = $this->podar($disco, $prefijo, (int) config('documentos.respaldos.dias'));
        $resultado = ['sql' => "$base.sql.gz", 'manifiesto' => "$base.manifiesto.json.gz", 'borrados' => $borrados, 'bytes' => $bytes];
        Actividad::registrar('respaldo.creado', null, 'Respaldo en '.$disco, $resultado);

        return $resultado;
    }

    private function volcarBaseDeDatos(): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'sql');
        $gz = gzopen($ruta, 'wb6');
        $pdo = DB::connection()->getPdo();

        gzwrite($gz, "-- Respaldo de Nube de IVS Certificaciones · ".now()->toIso8601String()."\n");
        gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($this->tablas() as $tabla => $crear) {
            gzwrite($gz, "DROP TABLE IF EXISTS `$tabla`;\n$crear;\n\n");

            if (in_array($tabla, self::SOLO_ESTRUCTURA, true)) {
                continue;
            }

            $lote = [];
            foreach (DB::table($tabla)->cursor() as $registro) {
                $valores = array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), (array) $registro);
                $lote[] = '('.implode(',', $valores).')';
                if (count($lote) === 200) {
                    gzwrite($gz, "INSERT INTO `$tabla` VALUES ".implode(",\n", $lote).";\n");
                    $lote = [];
                }
            }
            if ($lote) {
                gzwrite($gz, "INSERT INTO `$tabla` VALUES ".implode(",\n", $lote).";\n");
            }
            gzwrite($gz, "\n");
        }

        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);

        return $ruta;
    }

    /** @return array<string, string> tabla => sentencia CREATE TABLE */
    private function tablas(): array
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return collect(DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"))
                ->mapWithKeys(fn ($t) => [$t->name => $t->sql])->all();
        }

        return collect(DB::select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"'))
            ->mapWithKeys(function ($fila) {
                $tabla = array_values((array) $fila)[0];

                return [$tabla => array_values((array) DB::selectOne("SHOW CREATE TABLE `$tabla`"))[1]];
            })->all();
    }

    private function manifiesto(Carbon $ahora): string
    {
        $carpetas = Carpeta::query()->get(['id', 'nombre', 'carpeta_padre_id', 'unidad', 'ruta', 'color', 'icono', 'restringida'])->keyBy('id');
        $rutaDe = function (?int $id) use ($carpetas): ?string {
            $carpeta = $id ? $carpetas->get($id) : null;
            if (! $carpeta) {
                return null;
            }
            $nombres = collect(array_filter(explode('/', (string) $carpeta->ruta)))->map(fn ($i) => $carpetas->get((int) $i)?->nombre)->filter();

            return ($carpeta->unidad === Carpeta::UNIDAD_PRIVADA ? 'Unidad privada' : 'Unidad compartida').'/'.$nombres->implode('/');
        };

        $ruta = tempnam(sys_get_temp_dir(), 'man');
        $gz = gzopen($ruta, 'wb6');

        // Se escribe como JSON en streaming para no cargar miles de archivos en memoria
        $cabecera = json_encode([
            'generado' => $ahora->toIso8601String(),
            'aplicacion' => config('app.name'),
            'carpetas' => $carpetas->map(fn ($c) => [
                'id' => $c->id, 'ruta' => $rutaDe($c->id), 'color' => $c->color, 'icono' => $c->icono, 'restringida' => (bool) $c->restringida,
            ])->values(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        gzwrite($gz, substr($cabecera, 0, -1).',"archivos":[');

        $primero = true;
        Archivos::withTrashed()->with('versiones:id,archivo_id,numero,disco,objeto,hash_md5,tamano')
            ->chunkById(500, function ($archivos) use ($gz, $rutaDe, &$primero) {
                foreach ($archivos as $a) {
                    $fila = json_encode([
                        'id' => $a->id,
                        'carpeta' => $rutaDe($a->carpeta_id),
                        'nombre' => $a->nombre,
                        'estado' => $a->trashed() ? 'papelera' : $a->estado,
                        'version_actual_id' => $a->version_actual_id,
                        'versiones' => $a->versiones->map->only(['id', 'numero', 'disco', 'objeto', 'hash_md5', 'tamano'])->values(),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    gzwrite($gz, ($primero ? '' : ',')."\n".$fila);
                    $primero = false;
                }
            });

        gzwrite($gz, "\n]}\n");
        gzclose($gz);

        return $ruta;
    }

    private function subir(string $disco, string $local, string $destino): void
    {
        $stream = fopen($local, 'rb');
        try {
            $ok = Storage::disk($disco)->writeStream($destino, $stream, ['visibility' => 'private', 'mimetype' => 'application/gzip']);
        } finally {
            is_resource($stream) && fclose($stream);
        }

        if ($ok === false) {
            throw new RuntimeException("No se pudo guardar el respaldo $destino.");
        }
    }

    /** Borra solo respaldos propios (nube-ivs-*) más viejos que la retención. Nunca toca documentos. */
    private function podar(string $disco, string $prefijo, int $dias): int
    {
        if ($dias <= 0) {
            return 0;
        }

        $limite = now()->subDays($dias)->format('Ymd');
        $borrados = 0;
        foreach (Storage::disk($disco)->allFiles($prefijo) as $objeto) {
            if (preg_match('#/nube-ivs-(\d{8})-\d{6}\.(sql|manifiesto\.json)\.gz$#', $objeto, $m) && $m[1] < $limite) {
                Storage::disk($disco)->delete($objeto);
                $borrados++;
            }
        }

        return $borrados;
    }
}
