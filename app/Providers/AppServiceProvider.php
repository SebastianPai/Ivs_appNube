<?php

namespace App\Providers;

use Google\Cloud\Storage\StorageClient;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use BezhanSalleh\FilamentLanguageSwitch\LanguageSwitch;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter;
use League\Flysystem\GoogleCloudStorage\UniformBucketLevelAccessVisibility;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\Documentos\AccesoDocumental::class);

        // Después del login se entra directo al gestor documental (no a la última página visitada)
        $this->app->bind(
            \Filament\Http\Responses\Auth\Contracts\LoginResponse::class,
            \App\Http\Responses\LoginResponse::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
        LanguageSwitch::configureUsing(function (LanguageSwitch $switch) {
            $switch
                ->locales(['es','en','fr'])
                ; // also accepts a closure
        });

        // Firebase Storage es un bucket de Google Cloud Storage: basta el cliente oficial, sin SDK de Firebase
        Storage::extend('gcs', function ($app, array $config) {
            $client = new StorageClient(array_filter([
                'keyFilePath' => $config['key_file'] ?? null,
                'projectId' => $config['project_id'] ?? null,
            ]));

            // Sin ACL por objeto: el acceso lo decide el bucket (privado) y las URL firmadas
            $adapter = new GoogleCloudStorageAdapter(
                $client->bucket((string) ($config['bucket'] ?? '')),
                (string) ($config['path_prefix'] ?? ''),
                new UniformBucketLevelAccessVisibility(),
            );

            $disk = new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
            $disk->buildTemporaryUrlsUsing(
                fn (string $path, $expiration, array $options) => $adapter->temporaryUrl($path, $expiration, new Config($options))
            );

            return $disk;
        });
    }
}
