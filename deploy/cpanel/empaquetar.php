<?php

/*
 * Arma el paquete para subir a cPanel. Uso (desde la raíz del proyecto):
 *
 *   php deploy/cpanel/empaquetar.php [ruta_a_mysqldump]
 *
 * Genera en deploy/dist/:
 *   ivs_administracion.zip  → se descomprime en /home/<usuario>/ivs_administracion (fuera de public_html)
 *   public_html.zip         → se descomprime en /home/<usuario>/public_html
 *   base_de_datos.sql       → se importa en phpMyAdmin
 *
 * NO incluye .env, credenciales de Firebase, pruebas, logs ni dependencias de desarrollo.
 */

$raiz = dirname(__DIR__, 2);
$dist = "$raiz/deploy/dist";
$staging = sys_get_temp_dir().'/ivs_cpanel_'.date('Ymd_His');
$php = PHP_BINARY;
$composer = getenv('COMPOSER_PHAR') ?: dirname(PHP_BINARY).'/composer.phar';
$mysqldump = $argv[1] ?? (PHP_OS_FAMILY === 'Windows' ? 'C:/xampp/mysql/bin/mysqldump.exe' : 'mysqldump');

// Rutas relativas a la raíz que nunca se suben
$excluir = [
    '.env', '.env.backup', '.env.production', '.git', '.idea', '.vscode', '.phpunit.cache', '.phpunit.result.cache',
    'node_modules', 'tests', 'phpunit.xml', 'deploy', 'storage/credenciales', 'storage/app/recuperacion',
    'storage/logs', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views',
    'storage/framework/testing', 'bootstrap/cache/config.php', 'bootstrap/cache/routes-v7.php',
    'bootstrap/cache/events.php', 'bootstrap/cache/filament', 'bootstrap/cache/packages.php', 'bootstrap/cache/services.php', 'public/hot', 'public/storage',
];

function paso(string $texto): void
{
    echo "\n▶ $texto\n";
}

function ejecutar(string $comando, ?string $cwd = null): void
{
    $proceso = proc_open($comando, [1 => STDOUT, 2 => STDERR], $tuberias, $cwd);
    if (! is_resource($proceso) || proc_close($proceso) !== 0) {
        fwrite(STDERR, "✖ Falló: $comando\n");
        exit(1);
    }
}

function copiar(string $origen, string $destino, string $raiz, array $excluir): void
{
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($origen, FilesystemIterator::SKIP_DOTS),
            function (SplFileInfo $f) use ($raiz, $excluir) {
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($raiz) + 1));
                // Las credenciales nunca, aunque estén en otra carpeta
                if (preg_match('/(firebase-adminsdk|service-account|firebase-cuenta-servicio).*\.json$/i', $rel)) {
                    return false;
                }
                // Descargas a medias que composer deja en vendor/composer (pueden pesar decenas de MB)
                if (preg_match('#^vendor/composer/tmp-#', $rel)) {
                    return false;
                }

                return ! in_array($rel, $excluir, true);
            }
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($origen) + 1);
        $dst = "$destino/$rel";
        $f->isDir() ? @mkdir($dst, 0775, true) : copy($f->getPathname(), $dst);
    }
}

function comprimir(string $carpeta, string $zipRuta): void
{
    @unlink($zipRuta);
    $zip = new ZipArchive();
    $zip->open($zipRuta, ZipArchive::CREATE);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($carpeta, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($carpeta) + 1));
        $f->isDir() ? $zip->addEmptyDir($rel) : $zip->addFile($f->getPathname(), $rel);
    }
    $zip->close();
}

function borrar(string $ruta): void
{
    if (! file_exists($ruta)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ruta, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($ruta);
}

paso("Copiando el proyecto a $staging");
$app = "$staging/ivs_administracion";
mkdir($app, 0775, true);
copiar($raiz, $app, $raiz, $excluir);

// Carpetas que Laravel necesita aunque estén vacías
foreach (['storage/logs', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/credenciales', 'bootstrap/cache'] as $d) {
    @mkdir("$app/$d", 0775, true);
}
file_put_contents("$app/storage/credenciales/.htaccess", "Require all denied\n");

paso('Instalando solo dependencias de producción');
ejecutar(escapeshellarg($php).' '.escapeshellarg($composer).' install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts', $app);
ejecutar(escapeshellarg($php).' artisan package:discover', $app);

paso('Armando public_html');
$publico = "$staging/public_html";
mkdir($publico, 0775, true);
copiar("$app/public", $publico, "$app/public", ['hot', 'storage', 'index.php']);
copy(__DIR__.'/public_html/index.php', "$publico/index.php");
copy(__DIR__.'/public_html/.user.ini', "$publico/.user.ini");

paso('Comprimiendo');
@mkdir($dist, 0775, true);
comprimir($app, "$dist/ivs_administracion.zip");
comprimir($publico, "$dist/public_html.zip");
copy(__DIR__.'/env.cpanel.example', "$dist/env.cpanel.example");

paso('Exportando la base de datos');
// El .env no es un INI válido (comentarios con #, paréntesis…): se leen solo las líneas DB_*
preg_match_all('/^(DB_[A-Z_]+)=(.*)$/m', file_get_contents("$raiz/.env"), $m, PREG_SET_ORDER);
$env = array_column(array_map(fn ($l) => [$l[1], trim(trim($l[2]), '"\'')], $m), 1, 0);
$sql = "$dist/base_de_datos.sql";
$cmd = sprintf('%s --single-transaction --routines --no-tablespaces -h %s -P %s -u %s %s %s > %s',
    escapeshellarg($mysqldump), escapeshellarg($env['DB_HOST'] ?? '127.0.0.1'), escapeshellarg($env['DB_PORT'] ?? '3306'),
    escapeshellarg($env['DB_USERNAME'] ?? 'root'), ($env['DB_PASSWORD'] ?? '') !== '' ? '-p'.escapeshellarg($env['DB_PASSWORD']) : '',
    escapeshellarg($env['DB_DATABASE']), escapeshellarg($sql));
ejecutar($cmd);
// Sesiones, caché y trabajos locales no sirven en el servidor
file_put_contents($sql, "\n-- Limpieza de datos locales temporales\nTRUNCATE TABLE sessions;\nTRUNCATE TABLE cache;\nTRUNCATE TABLE cache_locks;\nTRUNCATE TABLE jobs;\n", FILE_APPEND);

borrar($staging);

paso('Listo');
foreach (glob("$dist/*") as $f) {
    printf("  %-28s %8.1f MB\n", basename($f), filesize($f) / 1048576);
}
echo "\nSigue los pasos de docs/DESPLIEGUE_CPANEL.md\n";
