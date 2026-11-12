<?php

namespace App\Console\Commands;

use Google\Cloud\Storage\StorageClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Diagnóstico de SOLO LECTURA del almacenamiento: configuración, identidad de la cuenta de servicio
 * (sin mostrar la clave), permisos efectivos sobre el bucket y una muestra de objetos.
 */
class VerificarAlmacenamiento extends Command
{
    protected $signature = 'documentos:verificar-almacenamiento {--disco= : Disco a verificar (por defecto el de recuperación)} {--muestra=10}';

    protected $description = 'Comprueba el acceso real al almacenamiento (Firebase/GCS) sin modificar nada';

    public function handle(): int
    {
        $disco = $this->option('disco') ?: config('documentos.disco_recuperacion');
        $config = config("filesystems.disks.$disco");

        if (! $config) {
            $this->error("El disco «{$disco}» no existe en config/filesystems.php.");

            return self::FAILURE;
        }

        $this->info("Disco: $disco (driver {$config['driver']})");

        if ($config['driver'] === 'gcs' && ! $this->verificarGcs($config)) {
            return self::FAILURE;
        }

        try {
            $n = 0;
            $this->line('Muestra de objetos:');
            foreach (Storage::disk($disco)->getDriver()->listContents('', true) as $item) {
                if (! $item->isFile()) {
                    continue;
                }
                $this->line(sprintf('  %-70s %10s bytes', $item->path(), number_format((int) $item->fileSize())));
                if (++$n >= (int) $this->option('muestra')) {
                    break;
                }
            }
            $this->info($n === 0 ? 'Acceso correcto, pero no se encontraron objetos.' : "Lectura correcta ($n objetos de muestra).");
        } catch (Throwable $e) {
            $this->error('No se pudo listar el almacenamiento: '.class_basename($e).' — '.mb_substr($e->getMessage(), 0, 300));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function verificarGcs(array $config): bool
    {
        $ruta = $config['key_file'] ?? null;
        if (! $ruta) {
            $this->warn('FIREBASE_CREDENTIALS está vacío: se intentarán las credenciales por defecto de Google (ADC).');
        } elseif (! is_readable($ruta)) {
            $this->error("No se puede leer el archivo de credenciales indicado en FIREBASE_CREDENTIALS.");

            return false;
        } else {
            $json = json_decode((string) file_get_contents($ruta), true);
            if (($json['type'] ?? null) !== 'service_account') {
                $this->error('El archivo de credenciales no es una cuenta de servicio de Google.');

                return false;
            }
            // Solo datos de identificación; la clave privada nunca se muestra
            $this->line('Proyecto: '.($json['project_id'] ?? '?'));
            $this->line('Cuenta de servicio: '.($json['client_email'] ?? '?'));
        }

        if (empty($config['bucket'])) {
            $this->error('FIREBASE_STORAGE_BUCKET está vacío. Suele ser «<proyecto>.appspot.com» o «<proyecto>.firebasestorage.app».');

            return false;
        }

        $this->line('Bucket: '.$config['bucket']);

        try {
            $bucket = (new StorageClient(array_filter(['keyFilePath' => $ruta, 'projectId' => $config['project_id'] ?? null])))->bucket($config['bucket']);
            $permisos = $bucket->iam()->testPermissions([
                'storage.objects.list', 'storage.objects.get', 'storage.objects.create', 'storage.objects.delete',
            ]);
            $this->line('Permisos efectivos de la cuenta: '.($permisos ? implode(', ', $permisos) : 'ninguno'));

            if (! in_array('storage.objects.list', $permisos, true) || ! in_array('storage.objects.get', $permisos, true)) {
                $this->error('La cuenta no puede listar o leer objetos: no es posible inventariar.');

                return false;
            }
            if (in_array('storage.objects.delete', $permisos, true)) {
                $this->warn('La cuenta puede BORRAR objetos. La aplicación nunca borra originales, pero considera restringirla.');
            }

            $info = $bucket->info();
            $this->line('Acceso uniforme a nivel de bucket: '.(($info['iamConfiguration']['uniformBucketLevelAccess']['enabled'] ?? false) ? 'sí' : 'no'));
            $this->line('Versionado de objetos del bucket: '.(($info['versioning']['enabled'] ?? false) ? 'activado' : 'desactivado'));
        } catch (Throwable $e) {
            $this->error('No se pudo consultar el bucket: '.class_basename($e).' — '.mb_substr($e->getMessage(), 0, 300));

            return false;
        }

        return true;
    }
}
