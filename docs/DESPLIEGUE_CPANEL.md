# Subir Nube de IVS Certificaciones a cPanel

> **Producción actual:** https://nube.ivscertificaciones.com — app en `/home/wwwivsce/ivs_administracion`,
> carpeta pública `/home/wwwivsce/nube.ivscertificaciones.com`, PHP 8.3.
>
> **Actualizar = hacer push a `main`.** El workflow `.github/workflows/deploy.yml` copia por SSH solo lo que
> cambió, corre las migraciones y regenera las cachés (mismo esquema que Ivs_appCerti). Requiere el secreto
> `SSH_PRIVATE_KEY` en GitHub. La guía de abajo queda para una instalación desde cero.

Guía para publicar el gestor documental en el hosting de ivscertificaciones.com (cPanel).

## Requisitos del hosting

- **PHP 8.2 o superior.** Se cambia en cPanel → *MultiPHP Manager* o *Seleccionar versión de PHP*.
- **Extensiones de PHP:** ctype, curl, dom, fileinfo, filter, iconv, **intl**, mbstring, openssl, pdo_mysql, tokenizer, xmlreader, **zip**. En hostings con *CloudLinux* se activan desde *Seleccionar versión de PHP → Extensiones*.
- MySQL 5.7+ o MariaDB 10.3+.
- Acceso a **Tareas Cron**. La *Terminal* de cPanel ayuda, pero no es obligatoria.

## 1. Generar el paquete (en tu PC)

```bash
php deploy/cpanel/empaquetar.php
```

El comando deja en `deploy/dist/`:

| Archivo | Dónde va |
|---|---|
| `ivs_administracion.zip` | `/home/<usuario>/ivs_administracion`, **fuera** de `public_html` |
| `public_html.zip` | `/home/<usuario>/public_html` |
| `base_de_datos.sql` | se importa en phpMyAdmin |
| `env.cpanel.example` | plantilla del `.env` de producción |

El paquete **no lleva** `.env`, las credenciales de Firebase, las pruebas ni los logs. `deploy/dist/` está excluido de Git porque contiene la base de datos.

## 2. Base de datos

1. cPanel → **Bases de datos MySQL**: crea la base (por ejemplo `<usuario>_ivs`) y un usuario con contraseña segura. Después, en *Añadir usuario a base de datos*, dale **TODOS LOS PRIVILEGIOS**.
2. cPanel → **phpMyAdmin**: selecciona la base, ve a **Importar** y sube `base_de_datos.sql`.
   - Si el archivo pasa del límite de phpMyAdmin, comprímelo en .zip o .gz antes de subirlo; phpMyAdmin acepta comprimidos.

## 3. Archivos

1. cPanel → **Administrador de archivos**, en la carpeta de inicio (`/home/<usuario>`):
   - Sube `ivs_administracion.zip` y elige **Extraer**. Debe quedar `/home/<usuario>/ivs_administracion/app`, `/vendor`, etc.
2. Entra en `public_html`. **Primero respalda o borra lo que haya**: si queda un `index.html` viejo, tiene prioridad sobre `index.php`.
   - Sube `public_html.zip` y elige **Extraer**. Muestra los archivos ocultos para verificar que estén `.htaccess` y `.user.ini`.
3. Credenciales de Firebase: sube `firebase-cuenta-servicio.json` a `/home/<usuario>/ivs_administracion/storage/credenciales/`. Nunca lo pongas dentro de `public_html`.
4. Permisos: las carpetas `ivs_administracion/storage` y `ivs_administracion/bootstrap/cache` deben tener **755** y ser escribibles por PHP. Es lo normal en cPanel.

## 4. Archivo .env

1. Copia `env.cpanel.example` a `/home/<usuario>/ivs_administracion/.env`.
2. Completa:
   - `APP_KEY`: **el mismo valor** del `.env` de tu PC.
   - `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`: los del paso 2.
   - Reemplaza `<usuario_cpanel>` en `FIREBASE_CREDENTIALS` por tu usuario real.
   - Los datos del correo, si se van a enviar notificaciones.

## 5. Optimizar (una sola vez, y después de cada actualización)

Con la **Terminal** de cPanel:

```bash
cd ~/ivs_administracion
php artisan optimize
php artisan filament:optimize
php artisan documentos:verificar-almacenamiento   # comprueba la conexión con Firebase y los permisos
```

Si no hay Terminal, crea una tarea cron temporal con el comando de abajo. Espera a que corra una vez (1 minuto) y bórrala:

```
cd /home/<usuario>/ivs_administracion && php artisan optimize && php artisan filament:optimize
```

> Si cambias el `.env` después, repite `php artisan optimize`. Mientras esté en caché, Laravel no vuelve a leer el `.env`.

## 6. Tarea cron (obligatoria)

cPanel → **Tareas Cron**, *Una vez por minuto* (`* * * * *`):

```
cd /home/<usuario>/ivs_administracion && php artisan schedule:run >> /dev/null 2>&1
```

Este cron procesa la cola de trabajos (herramientas PDF, inventarios) y las tareas de limpieza. Si `php` no funciona, usa la ruta completa de tu versión, por ejemplo `/usr/local/bin/php` o `/opt/cpanel/ea-php82/root/usr/bin/php`. Soporte te la puede dar.

## 7. SSL y verificación

1. cPanel → **SSL/TLS Status**: ejecuta *AutoSSL* para que `https://` funcione. El `.env` usa cookies seguras, que solo funcionan con https.
2. Abre `https://ivscertificaciones.com/inicio` e inicia sesión.
3. **Cambia la contraseña del administrador** en tu perfil, arriba a la derecha.

## Diferencias con el entorno local

- **Subidas nuevas:** van directo al bucket de Firebase (`DOCUMENTOS_DISK=firebase`). Los archivos recuperados ya están ahí y solo se referencian; no se copia nada.
- **Herramientas PDF**: casi todas necesitan `qpdf`, que normalmente no está en hosting compartido. Sin él, esas opciones se ocultan y los PDF se suben sin comprimir. Funcionan siempre «Imágenes a PDF» y, en el visor, vista de páginas, imprimir y PDF a JPG. Pregúntale a soporte si pueden instalar qpdf y, si lo hacen, pon su ruta en `QPDF_PATH`.
- **Respaldo diario**: el mismo cron guarda cada madrugada la base de datos y el manifiesto de carpetas en el bucket (`respaldos/`). Para probarlo a mano: `php artisan documentos:respaldar`.
- **Límite de subida:** 100 MB por archivo (`public_html/.user.ini`). Si el hosting tiene un tope menor, manda el del hosting.

## Actualizar más adelante

1. En tu PC: `php deploy/cpanel/empaquetar.php`.
2. Pon el sitio en mantenimiento (`php artisan down`), reemplaza las carpetas `app`, `config`, `resources`, `routes`, `vendor`, `database` y `bootstrap/app.php`. **No toques** `.env` ni `storage`.
3. Corre `php artisan migrate --force`, luego `php artisan optimize` y `php artisan filament:optimize`, y por último `php artisan up`.
4. **No vuelvas a importar `base_de_datos.sql`**: sobrescribiría los datos de producción.

## Problemas frecuentes

| Síntoma | Causa probable |
|---|---|
| Error 500 en blanco | `storage` sin permisos de escritura, `.env` mal escrito o versión de PHP menor a 8.2. Revisa `storage/logs/laravel-*.log`. |
| «Class "Collator" not found» o errores de `intl` | Falta activar la extensión **intl**. |
| La página sale sin estilos | No se extrajo bien `public_html.zip` (faltan `css/` y `js/`), o `APP_URL` no coincide con el dominio. |
| No abre los archivos recuperados | La ruta de `FIREBASE_CREDENTIALS` es incorrecta o el .json no está. Corre `php artisan documentos:verificar-almacenamiento`. |
| No se puede iniciar sesión | El sitio está en http y no en https (por `SESSION_SECURE_COOKIE=true`). Activa SSL. |
