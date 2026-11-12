<?php

return [

    // Disco donde se guardan los archivos nuevos. "firebase" en producción, "documentos_local" en desarrollo.
    'disco' => env('DOCUMENTOS_DISK', 'documentos_local'),

    // Disco que contiene los archivos originales a recuperar (normalmente el bucket de Firebase).
    'disco_recuperacion' => env('DOCUMENTOS_DISCO_RECUPERACION', env('DOCUMENTOS_DISK', 'documentos_local')),

    // Prefijo de los objetos que crea la aplicación. Nunca se escribe fuera de él.
    'prefijo_objetos' => 'documentos',

    // Minutos de validez de las URL firmadas de descarga (solo discos en la nube).
    'minutos_url_firmada' => (int) env('DOCUMENTOS_MINUTOS_URL', 5),

    'subida' => [
        // No puede superar upload_max_filesize/post_max_size de PHP ni la regla de config/livewire.php
        'max_kb' => (int) env('DOCUMENTOS_MAX_KB', 40960),
        'max_archivos' => 20,
        // Extensiones permitidas. Ejecutables y scripts quedan fuera.
        'extensiones' => [
            'pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif',
            'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'odt', 'ods', 'odp',
            'txt', 'rtf', 'zip', 'rar', '7z', 'xml', 'json',
            'mp4', 'webm', 'm4v', 'mov', 'ogv', 'mp3', 'wav', 'm4a', 'ogg',
        ],
    ],

    // Búsqueda dentro de los PDF (texto extraído; los escaneados no tienen texto)
    'busqueda' => [
        'max_kb' => (int) env('DOCUMENTOS_INDEXAR_MAX_KB', 30720),
        'max_paginas' => 300,
        'max_caracteres' => 500000,
    ],

    // Contraseña con la que se crean los usuarios; deben cambiarla en el primer ingreso
    'password_inicial' => env('DOCUMENTOS_PASSWORD_INICIAL', 'Ivscerti2026'),

    // Solo estos tipos se muestran dentro del navegador; el resto se descarga.
    'visualizables' => [
        'pdf' => ['application/pdf'],
        'imagen' => ['image/png', 'image/jpeg', 'image/webp', 'image/gif'],
        'video' => ['video/mp4', 'video/webm', 'video/ogg', 'video/x-m4v'],
        'audio' => ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/mp4', 'audio/x-m4a', 'audio/ogg'],
    ],

    // Personalización de carpetas: solo se aceptan estas claves (nunca CSS ni nombres de ícono arbitrarios)
    'colores' => [
        'ambar' => ['Ámbar', '#f59e0b'],
        'rojo' => ['Rojo', '#dc2626'],
        'naranja' => ['Naranja', '#ea580c'],
        'verde' => ['Verde', '#16a34a'],
        'turquesa' => ['Turquesa', '#0d9488'],
        'azul' => ['Azul', '#2563eb'],
        'indigo' => ['Índigo', '#4f46e5'],
        'violeta' => ['Violeta', '#7c3aed'],
        'rosa' => ['Rosa', '#db2777'],
        'gris' => ['Gris', '#6b7280'],
    ],
    'iconos' => [
        'folder' => 'Carpeta',
        'briefcase' => 'Maletín',
        'document-text' => 'Documentos',
        'academic-cap' => 'Certificaciones',
        'clipboard-document-check' => 'Auditorías',
        'users' => 'Personas',
        'building-office' => 'Empresa',
        'shield-check' => 'Calidad',
        'banknotes' => 'Finanzas',
        'chart-bar' => 'Informes',
        'wrench-screwdriver' => 'Técnico',
        'truck' => 'Vehículos',
        'photo' => 'Imágenes',
        'film' => 'Videos',
        'archive-box' => 'Archivo',
        'star' => 'Destacado',
        'flag' => 'Importante',
        'heart' => 'Favorito',
    ],

    // Respaldo diario de la BD y del manifiesto de carpetas en el bucket (documentos:respaldar)
    'respaldos' => [
        'disco' => env('DOCUMENTOS_DISCO_RESPALDOS', env('DOCUMENTOS_DISK', 'documentos_local')),
        'prefijo' => 'respaldos',
        'dias' => (int) env('DOCUMENTOS_RESPALDOS_DIAS', 30),
    ],

    // Hosting compartido (cPanel) sin procesos permanentes: el cron de schedule:run vacía la cola cada minuto
    'cola_por_cron' => (bool) env('DOCUMENTOS_COLA_POR_CRON', false),

    // Solo para el costo ESTIMADO del panel de almacenamiento (precio por GB/mes del almacenamiento estándar)
    'precio_gb_mes_usd' => (float) env('DOCUMENTOS_PRECIO_GB_MES', 0.026),

    'pdf' => [
        'qpdf' => env('QPDF_PATH', 'qpdf'),
        'ghostscript' => env('GHOSTSCRIPT_PATH', PHP_OS_FAMILY === 'Windows' ? 'gswin64c' : 'gs'),
        'max_kb' => (int) env('DOCUMENTOS_PDF_MAX_KB', 204800),
        'timeout' => (int) env('DOCUMENTOS_PDF_TIMEOUT', 300),

        // Compresión al subir: se comprime ANTES de enviar al almacenamiento, así se escribe una sola vez.
        // off | baja (sin pérdida) | media (imágenes JPEG calidad 75) | alta (requiere Ghostscript)
        'compresion_automatica' => env('DOCUMENTOS_PDF_COMPRESION_AUTO', 'media'),
        // Solo se guarda la versión comprimida si ahorra al menos este porcentaje
        'ahorro_minimo' => (int) env('DOCUMENTOS_PDF_AHORRO_MINIMO', 5),
        'timeout_subida' => (int) env('DOCUMENTOS_PDF_TIMEOUT_SUBIDA', 90),
    ],

    // Caché del navegador para el visor. Las versiones son inmutables, así que repetir una vista no vuelve a leer el bucket.
    'cache_visor_segundos' => (int) env('DOCUMENTOS_CACHE_VISOR', 86400),

];
