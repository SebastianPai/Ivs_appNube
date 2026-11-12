<?php

namespace App\Console\Commands;

use App\Services\Documentos\RespaldoDocumental;
use Illuminate\Console\Command;

class RespaldarDocumental extends Command
{
    protected $signature = 'documentos:respaldar';

    protected $description = 'Guarda en el bucket un respaldo de la base de datos y el manifiesto de carpetas y archivos';

    public function handle(RespaldoDocumental $respaldo): int
    {
        $this->info('Respaldando en «'.config('documentos.respaldos.disco').'»…');
        $r = $respaldo->ejecutar();

        $this->line('  Base de datos: '.$r['sql']);
        $this->line('  Manifiesto:    '.$r['manifiesto']);
        $this->line(sprintf('  Tamaño: %.1f MB · respaldos antiguos borrados: %d', $r['bytes'] / 1048576, $r['borrados']));

        return self::SUCCESS;
    }
}
