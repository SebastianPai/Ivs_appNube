<?php

namespace App\Services\Documentos\Recuperacion;

use App\Models\Actividad;
use App\Models\InventarioObjeto;
use App\Models\RecuperacionGrupo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Propone agrupaciones a partir del inventario. Solo escribe propuestas en la BD: nunca mueve objetos
 * ni crea carpetas. Cada propuesta lleva un nivel de confianza y el motivo; un administrador la aprueba.
 *
 * Evidencias, de mayor a menor peso:
 *  - carpeta física existente en el bucket (salvo nombres genéricos como "uploads") → 85
 *  - identificador numérico compartido "5289147-1.pdf", "5289147-2.pdf"… → 55 a 80 según consistencia
 *  - identificador numérico sin otros archivos → 30
 *  - sin patrón reconocible → 0, queda pendiente de revisión individual
 */
class AnalizadorRecuperacion
{
    public const DIRECTORIOS_GENERICOS = ['uploads', 'upload', 'files', 'archivos', 'documentos', 'docs', 'tmp', 'temp', 'public', 'storage', 'images', 'imagenes', 'pdf', 'pdfs'];

    public function analizar(): array
    {
        $objetos = InventarioObjeto::whereNotIn('estado', ['importado', 'descartado'])->get();

        DB::transaction(function () use ($objetos) {
            $this->marcarDuplicados();

            foreach ($objetos as $objeto) {
                [$clave, $sufijo] = $this->patron($objeto->nombre);
                $objeto->clave_patron = $clave;
                $objeto->sufijo = $sufijo;
            }

            $usados = [];
            foreach ($this->proponer($objetos) as $propuesta) {
                $grupo = RecuperacionGrupo::firstOrNew(['tipo' => $propuesta['tipo'], 'clave' => mb_substr($propuesta['clave'], 0, 300)]);
                if (in_array($grupo->estado, ['importado', 'descartado'], true) && $grupo->exists) {
                    // Aparecieron objetos nuevos en un grupo ya revisado: vuelve a quedar pendiente
                    $grupo->estado = 'propuesto';
                }
                $grupo->fill([
                    'confianza' => $propuesta['confianza'],
                    'motivo' => mb_substr($propuesta['motivo'], 0, 500),
                ]);
                $grupo->estado ??= 'propuesto';
                $grupo->save();
                $usados[] = $grupo->id;

                foreach ($propuesta['objetos'] as $objeto) {
                    $objeto->grupo_id = $grupo->id;
                    $objeto->estado = $propuesta['tipo'] === 'sin_clasificar' ? 'sin_clasificar' : 'agrupado';
                    $objeto->save();
                }
            }

            RecuperacionGrupo::whereIn('id', $usados)->get()->each(function (RecuperacionGrupo $g) {
                $g->update(['total' => $g->objetos()->count()]);
            });

            // Propuestas que se quedaron sin objetos tras un nuevo análisis
            RecuperacionGrupo::where('estado', 'propuesto')->whereDoesntHave('objetos')->delete();
        });

        $resumen = [
            'objetos_analizados' => $objetos->count(),
            'grupos' => RecuperacionGrupo::where('estado', 'propuesto')->count(),
            'sin_clasificar' => InventarioObjeto::where('estado', 'sin_clasificar')->count(),
            'duplicados' => InventarioObjeto::whereNotNull('duplicado_de_id')->count(),
        ];

        Actividad::registrar('recuperacion.analisis', null, 'Análisis de agrupaciones', $resumen);

        return $resumen;
    }

    /** @return array{0: ?string, 1: ?int} */
    public function patron(string $nombre): array
    {
        $base = pathinfo($nombre, PATHINFO_FILENAME);

        if (preg_match('/^(\d{3,})[-_ ](\d{1,3})$/', $base, $m)) {
            return [$m[1], (int) $m[2]];
        }

        if (preg_match('/^(\d{3,})$/', $base, $m)) {
            return [$m[1], null];
        }

        return [null, null];
    }

    private function proponer(Collection $objetos): array
    {
        $propuestas = [];
        $restantes = collect();

        foreach ($objetos->groupBy(fn ($o) => (string) $o->directorio) as $directorio => $enDirectorio) {
            $ultimo = strtolower(basename((string) $directorio));
            if ($directorio !== '' && ! in_array($ultimo, self::DIRECTORIOS_GENERICOS, true)) {
                $propuestas[] = [
                    'tipo' => 'carpeta_fisica',
                    'clave' => (string) $directorio,
                    'confianza' => 85,
                    'motivo' => "Los {$enDirectorio->count()} objetos están en la carpeta física «{$directorio}» del almacenamiento.",
                    'objetos' => $enDirectorio,
                ];
            } else {
                $restantes = $restantes->merge($enDirectorio);
            }
        }

        [$subidas, $restantes] = $restantes->partition(fn ($o) => preg_match(self::SUBIDA, $o->nombre));
        array_push($propuestas, ...$this->proponerSubidas($subidas));

        [$conClave, $sinClave] = $restantes->partition(fn ($o) => $o->clave_patron !== null);

        foreach ($conClave->groupBy('clave_patron') as $clave => $miembros) {
            if ($miembros->count() === 1) {
                $propuestas[] = [
                    'tipo' => 'individual',
                    'clave' => (string) $clave,
                    'confianza' => 30,
                    'motivo' => "Único archivo con el identificador {$clave}; no hay otros documentos que confirmen un expediente.",
                    'objetos' => $miembros,
                ];
                continue;
            }

            $sufijos = $miembros->pluck('sufijo')->filter()->sort()->values();
            $consecutivos = $sufijos->isNotEmpty() && $sufijos->first() === 1 && $sufijos->last() === $sufijos->count() && $sufijos->unique()->count() === $sufijos->count();
            $mismaExtension = $miembros->pluck('extension')->unique()->count() === 1;

            $confianza = 55 + ($consecutivos ? 15 : 0) + ($mismaExtension ? 10 : 0);
            $evidencias = array_filter([
                "{$miembros->count()} archivos comparten el identificador {$clave}",
                $consecutivos ? 'sufijos consecutivos desde 1' : 'sufijos no consecutivos o repetidos',
                $mismaExtension ? 'misma extensión' : 'extensiones distintas',
            ]);

            $propuestas[] = [
                'tipo' => 'patron_numerico',
                'clave' => (string) $clave,
                'confianza' => min($confianza, 80),
                'motivo' => ucfirst(implode('; ', $evidencias)).'. Es una hipótesis basada en el nombre: confirma qué representa el número antes de importar.',
                'objetos' => $miembros,
            ];
        }

        foreach ($sinClave as $objeto) {
            $propuestas[] = [
                'tipo' => 'sin_clasificar',
                'clave' => $objeto->objeto,
                'confianza' => 0,
                'motivo' => 'El nombre no sigue ningún patrón reconocible; requiere revisión manual.',
                'objetos' => collect([$objeto]),
            ];
        }

        return $propuestas;
    }

    /**
     * Archivos "1750447964-5.3 G DISEÑO TANQUES.pdf": la app anterior los subía a una carpeta plana con la hora
     * Unix delante. La relación con el cliente estaba en su base de datos (perdida), así que se reconstruye con:
     *  - lotes de subida: un expediente se subía de una vez y en orden alfabético (4.1.B … 6.2 … OIN-FO-OP-006);
     *    un salto de más de 10 min o el regreso a un numeral menor marca el inicio de otro expediente
     *  - la ficha leída de sus formatos (documentos:identificar) o el nombre "EDS …" en los archivos
     *  - palabras clave para lo que no es inspección (personal, sistema de gestión, comercial, certificados)
     */
    public const SUBIDA = '/^(\d{10})-(.+)$/';

    private const NUMERAL = '/^\d{1,2}\.\d?[A-Z0-9]/i';

    private const PAUSA_ENTRE_LOTES = 600;

    private const MULTIMEDIA = ['jpg', 'jpeg', 'png', 'heic', 'webp', 'mp4', 'mov', '3gp'];

    private const CATEGORIAS = [
        'Certificados emitidos' => '/^CERTIFICADO\b.*\b(EDS|GNV|AUTOMOTRIZ|FLUVIAL|VEHICULO|ESTACION)\b/',
        'Comercial' => '/OFERTA|CUENTA ?DE ?COBRO|COTIZACION|OIN\W*FO\W*AD\W*001|CAMARA DE COMERCIO|\bRUT\b/',
        'Personal' => '/HOJA[ _]DE[ _]VIDA|CONTRATO|OTRO ?SI|DIPLOMA|CERTIFICADOS? LABORAL|INFORME INTERPRETATIVO|CEDULA|SOPORTES? ACADEMICO|TARJETA PROFESIONAL|COMPETENCIAS LABORALES|PRUEBA DE CONOCIMIENTO|EVALUACION EN CAMPO|INDUCCION/',
        'Sistema de gestión' => '/OIN\W*(PR|MA|DI|IN|DC)\W|OIN\W*FO\W*(CA|GE|AD)\W|\bFO\W*(AD|CA|OP|SI)\W*\d|AUDITORIA|\bACTA\b|PROCEDIMIENTO|MANUAL|ONAC|ICONTEC|\bISO\b|PCAC|IMPARCIALIDAD|MATRIZ|LISTADO MAESTRO|METROLOG|ACCION(ES)? CORRECTIVA|CAPACITACION|ENCUESTA/',
    ];

    private function proponerSubidas(Collection $objetos): array
    {
        $propuestas = [];
        $sueltos = [];
        $fotos = [];
        $posibles = [];

        foreach ($this->expedientes($objetos) as $lote) {
            $numerales = $lote->filter(fn ($o) => preg_match(self::NUMERAL, $this->original($o)))->count();
            $fecha = date('Y-m-d', $this->hora($lote->first()));

            if ($numerales >= 5 || ($numerales >= 2 && $this->ficha($lote))) {
                $propuestas[] = $this->propuestaInspeccion($lote, $numerales, $fecha);
                continue;
            }

            $subida = date('Y-m-d H\hi', $this->hora($lote->first()));
            foreach ($lote as $objeto) {
                $categoria = $this->categoria($this->original($objeto));
                match (true) {
                    $categoria !== null => $sueltos['c:'.$categoria][] = $objeto,
                    in_array(strtolower((string) $objeto->extension), self::MULTIMEDIA, true) => $fotos[$subida][] = $objeto,
                    default => $sueltos['l:'.$subida][] = $objeto,
                };
            }
        }

        // Las fotos de campo se subían aparte: su fecha de captura coincide con la fecha de inspección de la ficha
        $porFechaInspeccion = collect($propuestas)->filter(fn ($p) => $p['fecha_inspeccion'] ?? null)->groupBy('fecha_inspeccion');
        foreach ($fotos as $subida => $miembros) {
            $tomada = collect($miembros)->map(fn ($o) => $this->fechaCaptura($this->original($o)))->filter()->countBy()->sortDesc();
            $candidatas = $tomada->isNotEmpty() ? ($porFechaInspeccion[$tomada->keys()->first()] ?? collect()) : collect();
            $estaciones = $candidatas->pluck('nombre')->unique();

            if ($estaciones->count() === 1 && $tomada->first() >= count($miembros) / 2) {
                $inspeccion = $candidatas->first();
                $propuestas[] = [
                    'tipo' => 'fotos_inspeccion',
                    'clave' => "{$inspeccion['clave']} - Fotos",
                    'confianza' => 60,
                    'motivo' => count($miembros)." fotos/videos subidos el {$subida}; {$tomada->first()} fueron tomados el {$tomada->keys()->first()}, la fecha de inspección que registra la ficha de {$inspeccion['nombre']}.",
                    'objetos' => collect($miembros),
                ];
                continue;
            }

            $sueltos['l:'.$subida] = array_merge($sueltos['l:'.$subida] ?? [], $miembros);
            $posibles[$subida] = collect([
                $tomada->isNotEmpty() ? 'Fotos tomadas el '.$tomada->keys()->take(3)->implode(', ').'.' : null,
                $estaciones->count() > 1 ? "Esa fecha coincide con la inspección de: {$estaciones->implode(', ')}." : null,
            ])->filter()->implode(' ');
        }

        foreach ($sueltos as $clave => $miembros) {
            [$tipo, $nombre] = explode(':', $clave, 2);
            $propuestas[] = $tipo === 'c'
                ? [
                    'tipo' => 'categoria',
                    'clave' => $nombre,
                    'confianza' => 60,
                    'motivo' => count($miembros)." archivos sueltos cuyo nombre corresponde a «{$nombre}» (por palabras clave). Revisa la lista antes de importar.",
                    'objetos' => collect($miembros),
                ]
                : [
                    'tipo' => 'lote_subida',
                    'clave' => "Por revisar {$nombre}",
                    'confianza' => 15,
                    'motivo' => count($miembros)." archivos subidos juntos el {$nombre} sin nombre de cliente ni categoría reconocible; probablemente son del mismo trámite."
                        .(! empty($posibles[$nombre]) ? ' '.$posibles[$nombre] : ''),
                    'objetos' => collect($miembros),
                ];
        }

        return $propuestas;
    }

    /** @return Collection<int, Collection> */
    private function expedientes(Collection $objetos): Collection
    {
        $ordenados = $objetos->sortBy(fn ($o) => sprintf('%010d|%s', $this->hora($o), $this->orden($o)))->values();
        $lotes = collect();
        $actual = collect();
        $anterior = null;

        foreach ($ordenados as $objeto) {
            if ($anterior && $this->iniciaExpediente($anterior, $objeto)) {
                $lotes->push($actual);
                $actual = collect();
            }
            $actual->push($objeto);
            $anterior = $objeto;
        }

        return $actual->isEmpty() ? $lotes : $lotes->push($actual);
    }

    private function iniciaExpediente(InventarioObjeto $anterior, InventarioObjeto $objeto): bool
    {
        if ($this->hora($objeto) - $this->hora($anterior) > self::PAUSA_ENTRE_LOTES) {
            return true;
        }

        // Dentro de un mismo lote, volver a un numeral menor (… OIN-FO-OP-006 → 4.1.B) es otra estación
        return $this->hora($objeto) > $this->hora($anterior)
            && preg_match(self::NUMERAL, $this->original($objeto))
            && strcmp($this->orden($objeto), $this->orden($anterior)) < 0
            && (! preg_match(self::NUMERAL, $this->original($anterior)) || $this->orden($objeto)[0] < $this->orden($anterior)[0]);
    }

    private function propuestaInspeccion(Collection $lote, int $numerales, string $fecha): array
    {
        $ficha = $this->ficha($lote);
        $enNombres = $this->edsEnNombres($lote);

        if ($ficha) {
            $nombre = $ficha['nombre_carpeta'];
            $confianza = 85;
            $evidencia = 'Datos leídos de sus formatos de inspección: '.collect([
                $ficha['razon_social'] ?? null ? "razón social {$ficha['razon_social']}" : null,
                $ficha['nit'] ?? null ? "NIT {$ficha['nit']}" : null,
                $ficha['sicom'] ?? null ? "SICOM {$ficha['sicom']}" : null,
                $ficha['ciudad'] ?? null ? $ficha['ciudad'].(isset($ficha['departamento']) ? " ({$ficha['departamento']})" : '') : null,
                $ficha['certificado'] ?? null ? "certificado {$ficha['certificado']}" : null,
            ])->filter()->implode(', ').'.';
        } elseif ($enNombres) {
            [$nombre, $veces] = $enNombres;
            $confianza = $veces >= 2 ? 70 : 55;
            $evidencia = "El nombre «{$nombre}» aparece en {$veces} nombre(s) de archivo del lote; no hay formato con la ficha.";
        } else {
            $nombre = 'Inspección sin identificar';
            $confianza = 35;
            $evidencia = 'No hay ficha ni nombre de estación: ábrelo para identificarlo.';
        }

        return [
            'tipo' => 'expediente_inspeccion',
            'clave' => "{$nombre} ({$fecha})",
            'confianza' => $confianza,
            'motivo' => "Expediente de inspección: {$lote->count()} archivos subidos juntos el {$fecha}, {$numerales} con numeral de la lista de chequeo. {$evidencia}",
            'objetos' => $lote,
            // Solo para asociar las fotos de campo; no se guarda
            'nombre' => $nombre,
            'fecha_inspeccion' => $ficha ? $this->fechaFicha($ficha['fecha_inspeccion'] ?? '') : null,
        ];
    }

    /** "27/08/2024" o "29 de marzo de 2024" → "2024-08-27" */
    private function fechaFicha(string $texto): ?string
    {
        $meses = ['ENERO' => 1, 'FEBRERO' => 2, 'MARZO' => 3, 'ABRIL' => 4, 'MAYO' => 5, 'JUNIO' => 6, 'JULIO' => 7, 'AGOSTO' => 8, 'SEPTIEMBRE' => 9, 'OCTUBRE' => 10, 'NOVIEMBRE' => 11, 'DICIEMBRE' => 12];
        $t = Str::upper(Str::ascii($texto));

        if (preg_match('/\b(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})\b/', $t, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (preg_match('/\b(\d{1,2}) DE ('.implode('|', array_keys($meses)).') (?:DE(?:L)? )?(\d{4})\b/', $t, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $meses[$m[2]], $m[1]);
        }

        return null;
    }

    /** Fecha de captura según el nombre que pone la cámara o WhatsApp: 20250930_082142.jpg, WhatsApp Image 2025-12-16 … */
    private function fechaCaptura(string $nombre): ?string
    {
        if (preg_match('/(?:^|IMG[_-]|VID[_-])(20\d{2})(\d{2})(\d{2})[_-]/', $nombre, $m)
            || preg_match('/WhatsApp (?:Image|Video) (20\d{2})-(\d{2})-(\d{2})/i', $nombre, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
        }

        return null;
    }

    private function ficha(Collection $lote): ?array
    {
        $fichas = $lote->map(fn ($o) => $o->metadatos['ficha'] ?? null)->filter(fn ($f) => ! empty($f['nombre_carpeta']));
        if ($fichas->isEmpty()) {
            return null;
        }

        // La estación más repetida; de sus fichas, la más completa
        $nombre = $fichas->countBy('nombre_carpeta')->sortDesc()->keys()->first();

        return $fichas->where('nombre_carpeta', $nombre)->sortByDesc(fn ($f) => count($f))->first();
    }

    /** @return array{0: string, 1: int}|null */
    private function edsEnNombres(Collection $lote): ?array
    {
        $meses = 'ENERO|FEBRERO|MARZO|ABRIL|MAYO|JUNIO|JULIO|AGOSTO|SEPTIEMBRE|OCTUBRE|NOVIEMBRE|DICIEMBRE';
        $nombres = $lote->map(function ($o) use ($meses) {
            $base = Str::upper(Str::ascii(pathinfo($this->original($o), PATHINFO_FILENAME)));

            // "DISEÑO TANQUES EDS Y USO" no es un nombre: se descartan los que empiezan por conector
            return preg_match('/\bEDS\s+(?!(?:Y|DE|DEL|EN)\b)([A-Z0-9 ]{3,40}?)(?=\s*(?:[-_(.,]|\d{4}|'.$meses.'|NARINO|REV\b|$))/', $base, $m)
                ? 'EDS '.trim($m[1])
                : null;
        })->filter();

        if ($nombres->isEmpty()) {
            return null;
        }

        $conteo = $nombres->countBy()->sortDesc();

        return [$conteo->keys()->first(), $conteo->first()];
    }

    private function categoria(string $nombre): ?string
    {
        $n = Str::upper(Str::ascii($nombre));
        foreach (self::CATEGORIAS as $categoria => $patron) {
            if (preg_match($patron, $n)) {
                return $categoria;
            }
        }

        return null;
    }

    private function hora(InventarioObjeto $o): int
    {
        return (int) substr($o->nombre, 0, 10);
    }

    /** Nombre con el que el usuario subió el archivo, sin la hora */
    private function original(InventarioObjeto $o): string
    {
        return preg_match(self::SUBIDA, $o->nombre, $m) ? $m[2] : $o->nombre;
    }

    private function orden(InventarioObjeto $o): string
    {
        return Str::upper(Str::ascii($this->original($o)));
    }

    private function marcarDuplicados(): void
    {
        InventarioObjeto::query()->update(['duplicado_de_id' => null]);

        $hashes = InventarioObjeto::whereNotNull('hash_md5')
            ->select('hash_md5', 'tamano')
            ->groupBy('hash_md5', 'tamano')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($hashes as $h) {
            $copias = InventarioObjeto::where('hash_md5', $h->hash_md5)->where('tamano', $h->tamano)->orderBy('objeto')->get();
            $original = $copias->shift();
            InventarioObjeto::whereIn('id', $copias->pluck('id'))->update(['duplicado_de_id' => $original->id]);
        }
    }
}
