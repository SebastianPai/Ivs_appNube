# Gestor documental IVS — documentación técnica

## Arquitectura

| Capa | Dónde | Notas |
|---|---|---|
| Interfaz tipo Drive | `app/Filament/Pages/Unidad.php` + `resources/views/filament/pages/unidad.blade.php` | Livewire. Árbol con carga progresiva, lista/cuadrícula, selección múltiple, arrastrar y soltar (subir archivos o carpetas completas, mover), detalles, papelera, visor. |
| Política de acceso | `app/Services/Documentos/AccesoDocumental.php` | Única fuente de verdad de permisos. Las políticas de Laravel y todas las operaciones delegan aquí. |
| Carpetas | `GestorCarpetas` | Ruta materializada (`/1/5/9/`), zona de restricción, mover, papelera por lotes, permisos, personalización. |
| Archivos y versiones | `GestorArchivos` | Subida con compresión automática de PDF, versiones inmutables, restauración, copia lógica, purga segura. |
| Almacenamiento | `AlmacenDocumental` | Disco abstracto: `documentos_local` en desarrollo, `firebase` (GCS) en producción. |
| Recuperación | `Services/Documentos/Recuperacion/*` | Inventario de solo lectura, análisis con confianza, importación por referencia y reversión. |
| Herramientas PDF | `Pdf/HerramientasPdf` + `Jobs/ProcesarPdfJob` | qpdf (y Ghostscript opcional), en cola. |
| Auditoría | `Models/Actividad` | Solo inserción; el modelo impide modificar o borrar registros. |

La base de datos guarda la estructura lógica (carpetas, archivos, versiones, permisos). El bucket guarda solo binarios. Una carpeta de la app **no** es un directorio del bucket.

## Modelo de permisos

- **Carpeta abierta** (unidad compartida, sin restricción): la ven todos los usuarios con `drive.acceder`. Pueden editarla quienes tienen `documentos.editar_compartido` o un permiso «editar» explícito.
- **Carpeta restringida**: solo la ven los usuarios o roles con permiso en ella o en un ancestro **dentro de la misma zona**. Una subcarpeta restringida corta la herencia. Las raíces de la unidad privada siempre son restringidas.
- **Eliminar** (papelera, restaurar, eliminación definitiva) y **gestionar permisos**: solo el rol `Admin`, verificado por rol en el servidor.
- **Unidad privada completa** (permiso `unidad_privada.acceder`): ve las carpetas privadas y lo que heredan de sus raíces, con su mismo nivel de la compartida. Las subcarpetas privadas restringidas a propósito siguen siendo excepciones.
- Roles iniciales (`RolesYPermisosSeeder`, idempotente), con su descripción visible en Usuarios y Roles:
  - `Usuario`: solo la unidad compartida; consulta y descarga.
  - `Gerencia`: compartida y privada; sube, organiza y crea carpetas.
  - `Admin`: todo, incluidos papelera, usuarios y permisos.

## Conectar Firebase Storage

1. En la consola de Google Cloud del proyecto de Firebase, crea una **cuenta de servicio** con el rol *Storage Object Viewer* para recuperar, o *Storage Object Admin* para operar. Descarga su clave JSON.
2. Guarda el JSON en `storage/credenciales/` (está en `.gitignore`), nunca dentro de `public/`.
3. En `.env`:
   ```
   FIREBASE_CREDENTIALS=C:\ruta\al\proyecto\storage\credenciales\cuenta.json
   FIREBASE_PROJECT_ID=mi-proyecto
   FIREBASE_STORAGE_BUCKET=mi-proyecto.appspot.com   # o mi-proyecto.firebasestorage.app
   DOCUMENTOS_DISCO_RECUPERACION=firebase
   DOCUMENTOS_DISK=firebase    # cuando las subidas nuevas deban ir al bucket
   ```
4. `php artisan config:clear` y luego `php artisan documentos:verificar-almacenamiento`. Muestra el proyecto, la cuenta, los permisos efectivos y una muestra de objetos, todo en modo solo lectura.

Las reglas de seguridad de Firebase Storage deben **denegar** el acceso directo de clientes (`allow read, write: if false;`). La app usa la cuenta de servicio, que no está sujeta a esas reglas, y entrega los archivos solo después de autorizar.

## Recuperación de los archivos existentes

```
php artisan documentos:inventario --disco=firebase        # solo lectura, reanudable con --reanudar
php artisan documentos:identificar --disco=firebase       # lee las fichas de los formatos de inspección (solo lectura)
php artisan documentos:analizar                           # propone grupos + informe en storage/app/recuperacion
```

Después, en **Administración → Recuperación documental**, revisa cada propuesta (evidencia, confianza, motivo), y apruébala, descártala o revierte una ya importada.

- **Confianza**: carpeta física del bucket 85 %. Identificador numérico compartido (`5289147-1.pdf`, `-2`, …) de 55 a 80 %. Identificador único 30 %. Sin patrón 0 %, queda pendiente de revisión.

### Bucket de la app anterior (`uploads/{hora unix}-{nombre}`)

La app anterior subía todo a `uploads/` sin carpetas. La relación con cada cliente estaba en su base de datos MySQL del hosting, que se perdió al cancelarse el plan en mayo de 2026. La organización se reconstruye con estas evidencias:

| Tipo de propuesta | Cómo se detecta | Confianza |
|---|---|---|
| Expediente de inspección | Lote subido de una vez y en orden alfabético (4.1.B … 6.2 … OIN-FO-OP-006). Un lote se corta tras más de 10 min sin subidas, o cuando la lista vuelve a un numeral menor (empieza otra estación). Se nombra con la ficha del Informe Final o de la Lista de chequeo: estación, razón social, NIT, SICOM y ciudad. | 85 % con ficha, 55-70 % con «EDS …» en los nombres, 35 % sin identificar |
| Fotos de inspección | Fotos de un lote aparte cuya fecha de captura coincide con la fecha de inspección de una sola ficha | 60 % |
| Categoría | Archivos sueltos clasificados por palabras clave: Personal, Sistema de gestión, Comercial, Certificados emitidos | 60 % |
| Lote de subida («Por revisar») | Lo demás, agrupado por hora de subida. El motivo indica la fecha en que se tomaron las fotos. | 15 % |

`documentos:identificar` solo descarga los formatos OIN-FO-OP-001/003/004/006 en .xlsx, unos 40 MB en total. Las copias idénticas reutilizan la ficha del original.
- **Importar** crea registros que **apuntan** al objeto original. No se copia, mueve ni renombra nada en el bucket. Antes se comprueba que el objeto sea accesible: si no lo es, queda como «inaccesible» y no se da por recuperado.
- **Revertir** borra solo los registros creados. Conserva los archivos que ya tienen versiones nuevas.
- Los originales inventariados **nunca** se borran desde la app, ni siquiera con la eliminación definitiva.

## Consistencia entre la base de datos y el bucket

- **Subida**: validar → detectar duplicado → comprimir (PDF) → escribir el objeto en una ruta nueva y **legible** (`documentos/{unidad}/{carpetas}/{nombre}__v{n}-{aleatorio}.ext`, con metadatos `carpeta`, `nombre` y `archivo_uuid`) → transacción en la base de datos. Si la base de datos falla, se borra el objeto recién escrito (compensación).
- **Mover o renombrar** (un archivo o una carpeta entera) también reubica el objeto en el bucket, en segundo plano (`ReubicarObjetosJob`). Primero copia del lado del servidor, luego actualiza la base de datos y al final borra la ruta anterior, solo si ya nadie la referencia: una copia lógica puede seguir apuntando a ella. Si algo falla, el archivo sigue abriéndose desde su ruta anterior.
  - Solo se reubican los objetos con ruta legible (`…__v{n}-{aleatorio}.ext`). Los recuperados de la app anterior (`uploads/…`) y los de formato antiguo **no se mueven nunca**.
- **Versiones**: los objetos son inmutables. Restaurar crea una versión nueva que apunta al mismo objeto. El número de versión se asigna con bloqueo de fila e índice único.
- **Purga**: primero se borra en la base de datos y después los objetos que ya ninguna versión ni el inventario referencian.
- **Conciliación**: `php artisan documentos:conciliar [--huerfanos]` informa de versiones sin objeto y de objetos huérfanos. No borra nada.

## Optimización de costos (Firebase cobra por uso)

- Los PDF se comprimen **antes** de subir: una sola escritura y menos almacenamiento y salida. Si se ahorra menos del 5 % se guarda el original.
- Volver a subir el mismo archivo no escribe nada: se detecta por el hash del original.
- Las copias y restauraciones referencian el mismo objeto: no duplican binarios.
- No hay `exists()` antes de escribir.
- El inventario usa los metadatos del listado: 1 operación por cada 1.000 objetos y ninguna petición individual.
- El visor envía `ETag` y caché privada: reabrir un documento responde `304` sin leer el bucket.
- Las descargas en la nube usan URL firmadas de 5 minutos y el video usa rangos servidos por GCS.
- El panel **Almacenamiento** se calcula con la base de datos: verlo no genera operaciones.

## Operación

- **Cola** (herramientas PDF e inventario): `php artisan queue:work --tries=1 --timeout=3700`. En producción, con un supervisor.
- **Programador**: `php artisan schedule:work` (o cron con `schedule:run`). Limpia los temporales de las herramientas PDF cada hora.
- **Herramientas PDF**: comprimir, unir, dividir, extraer/ordenar, eliminar páginas, rotar, numerar, marca de agua, proteger y quitar contraseña, e imágenes a PDF. En el visor: vista de páginas, imprimir y PDF a JPG (.zip).
  - **qpdf** es necesario para todas, salvo «Imágenes a PDF» (PHP puro) y las funciones del visor (se hacen en el navegador). Se configura con `QPDF_PATH`.
  - **Ghostscript** es opcional, solo para la compresión «alta» (`GHOSTSCRIPT_PATH`).
  - Las contraseñas de «Proteger» y «Quitar contraseña» viajan cifradas a la cola y se borran de la tarea al terminar.
- **Límites de subida**: `DOCUMENTOS_MAX_KB` debe ser menor o igual que `upload_max_filesize` y `post_max_size` de `php.ini` (XAMPP: 40M). Las carpetas se suben por lotes que respetan esos límites.

## Respaldo y recuperación

1. **Automático, cada día a las 03:00** (`documentos:respaldar`, lo lanza el cron de `schedule:run`). Deja en el bucket, bajo `respaldos/AAAA/MM/`:
   - `nube-ivs-…sql.gz`: la base de datos completa. Se genera en PHP, así que no necesita `mysqldump`, y se importa con phpMyAdmin o con `mysql`.
   - `nube-ivs-…manifiesto.json.gz`: cada carpeta con su ruta y cada archivo con su carpeta, su nombre y los objetos de sus versiones. Con él se reconstruye la organización aunque se pierda la base de datos.
   - Se conservan 30 días (`DOCUMENTOS_RESPALDOS_DIAS`). El inventario ignora `respaldos/`.
2. **Bucket**: activar el *Object Versioning* del bucket (verificar con `documentos:verificar-almacenamiento`) o una réplica periódica con `gsutil rsync`.
3. **Código**: repositorio Git remoto. El incidente original se debió a cambios sin subir.
4. **Si se pierde la base de datos**: descargar el último `respaldos/…sql.gz` desde la consola de Firebase (Storage) e importarlo. Lo subido después del respaldo es reconocible por su ruta legible y sus metadatos (`carpeta`, `nombre`).

## Pendientes y limitaciones conocidas

- **Laravel 11** ya no recibe parches de seguridad. Conviene migrar a Laravel 12, que es compatible con Filament 3.3.
- Sin miniaturas de imágenes: se usan íconos por tipo.
- La búsqueda es por nombre, identificador y extensión. La búsqueda dentro del contenido de los PDF queda pendiente: requeriría extraer el texto en cola.
- Firebase está conectado (proyecto `ivscertificaciones-c208f`, bucket `ivscertificaciones-c208f.appspot.com`), con 7.455 objetos inventariados. Las subidas nuevas siguen en local hasta activar `DOCUMENTOS_DISK=firebase`.
- 16 expedientes de inspección no tienen ficha ni nombre en los archivos. Para identificarlos haría falta leer el texto de sus PDF (pendiente).
