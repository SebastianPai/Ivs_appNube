<?php

namespace App\Console\Commands;

use App\Services\Documentos\Recuperacion\IdentificadorExpedientes;
use Illuminate\Console\Command;

class IdentificarExpedientes extends Command
{
    protected $signature = 'documentos:identificar
        {--disco= : Disco inventariado (por defecto DOCUMENTOS_DISCO_RECUPERACION)}
        {--forzar : Vuelve a leer formatos que ya tienen ficha}';

    protected $description = 'Lee los formatos de inspección (Informe final, Lista de chequeo…) para saber a qué estación pertenece cada expediente (solo lectura)';

    public function handle(IdentificadorExpedientes $identificador): int
    {
        $disco = $this->option('disco') ?: config('documentos.disco_recuperacion');
        $this->info("Leyendo formatos de inspección en «{$disco}» (solo lectura)…");

        $resumen = $identificador->ejecutar($disco, (bool) $this->option('forzar'), function (array $r, $objeto) {
            $this->line(sprintf('  %4d  %s', $r['revisados'], mb_substr($objeto->nombre, 0, 90)));
        });

        $this->table(array_keys($resumen), [array_values($resumen)]);
        $this->comment('Siguiente paso: php artisan documentos:analizar');

        return self::SUCCESS;
    }
}
