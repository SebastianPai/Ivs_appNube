<?php

namespace App\Services\Documentos\Busqueda;

use RuntimeException;

/** No se pudo leer el archivo del almacenamiento (bucket caído, sin facturación, credenciales…). */
class AlmacenamientoNoDisponible extends RuntimeException {}
