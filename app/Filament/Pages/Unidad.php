<?php

namespace App\Filament\Pages;

use App\Models\Actividad;
use App\Models\ArchivoVersion;
use App\Models\ArchivoTexto;
use App\Models\Archivos;
use App\Models\Carpeta;
use App\Models\Role;
use App\Models\User;
use App\Services\Documentos\AccesoDocumental;
use App\Services\Documentos\EstadisticasDocumentales;
use App\Services\Documentos\GestorArchivos;
use App\Services\Documentos\GestorCarpetas;
use App\Services\Documentos\NombresDocumentales;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * Gestor documental tipo Drive: unidad compartida, unidad privada, recientes, papelera y pendientes de revisión.
 * La interfaz solo oculta acciones; cada operación se vuelve a autorizar en los servicios.
 */
class Unidad extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-folder';

    protected static ?string $navigationLabel = 'Documentos';

    protected static ?string $title = 'Documentos';

    protected static ?string $navigationGroup = 'Documentos';

    protected static ?int $navigationSort = -2;

    protected static string $view = 'filament.pages.unidad';

    public const TIPOS = [
        'pdf' => ['PDF', ['pdf']],
        'imagen' => ['Imágenes', ['png', 'jpg', 'jpeg', 'webp', 'gif']],
        'word' => ['Word', ['doc', 'docx', 'odt', 'rtf']],
        'excel' => ['Excel', ['xls', 'xlsx', 'ods', 'csv']],
        'powerpoint' => ['PowerPoint', ['ppt', 'pptx', 'odp']],
        'comprimido' => ['Comprimidos', ['zip', 'rar', '7z']],
        'texto' => ['Texto', ['txt', 'xml', 'json']],
        'video' => ['Videos', ['mp4', 'webm', 'm4v', 'mov', 'ogv']],
        'audio' => ['Audio', ['mp3', 'wav', 'm4a', 'ogg']],
    ];

    private const ORDENES = ['nombre', 'updated_at', 'tamano', 'extension'];

    private const POR_PAGINA = 60;

    // history: cada carpeta abierta queda en el historial, así funciona el botón "atrás" del navegador
    #[Url(as: 'seccion', history: true)]
    public string $seccion = 'compartida';

    #[Url(as: 'carpeta', history: true)]
    public ?int $carpetaId = null;

    #[Url(as: 'q', except: '')]
    public string $busqueda = '';

    public string $filtroTipo = '';

    public string $filtroDesde = '';

    // Estas propiedades solo las cambia el servidor: el navegador no puede alterarlas
    #[Locked]
    public string $orden = 'nombre';

    #[Locked]
    public string $direccion = 'asc';

    #[Locked]
    public string $vista = 'lista';

    #[Locked]
    public array $seleccion = [];

    #[Locked]
    public ?string $detalle = null;

    #[Locked]
    public array $expandidas = [];

    #[Locked]
    public int $limite = self::POR_PAGINA;

    /** Archivos soltados sobre el área de trabajo (subida por arrastre). */
    public array $soltados = [];

    /** Ruta relativa de cada archivo de $soltados cuando se sube una carpeta ("Clientes/ACME/acta.pdf"). */
    public array $rutasSoltadas = [];

    /** Totales acumulados mientras se sube una carpeta por lotes. */
    #[Locked]
    public array $resumenSubida = ['subidos' => 0, 'repetidos' => 0, 'errores' => 0];

    private array $editableMemo = [];

    public static function canAccess(): bool
    {
        return app(AccesoDocumental::class)->puedeAcceder(auth()->user());
    }

    public function getMaxContentWidth(): MaxWidth
    {
        return MaxWidth::Full;
    }

    public function getHeading(): string
    {
        return '';
    }

    public function mount(): void
    {
        if (! in_array($this->seccion, $this->seccionesDisponibles(), true)) {
            $this->seccion = 'compartida';
        }

        if ($this->carpetaId) {
            $carpeta = $this->carpeta();
            if ($carpeta) {
                $this->seccion = $carpeta->unidad;
                $this->expandirHasta($carpeta);
            } else {
                $this->carpetaId = null;
            }
        }

        $this->vista = session('documentos.vista', 'lista') === 'cuadricula' ? 'cuadricula' : 'lista';
    }

    // ── Navegación ──────────────────────────────────────────────────────────

    public function irA(string $seccion): void
    {
        if (! in_array($seccion, $this->seccionesDisponibles(), true)) {
            $this->avisar('No tienes acceso a esa sección.');

            return;
        }

        $this->seccion = $seccion;
        $this->carpetaId = null;
        $this->busqueda = '';
        $this->reiniciarVista();
    }

    public function abrirCarpeta(int $id): void
    {
        $carpeta = Carpeta::find($id);
        if (! $carpeta || ! $this->acceso()->puedeVerCarpeta($this->user(), $carpeta)) {
            $this->avisar('No tienes acceso a esa carpeta o ya no existe.');

            return;
        }

        $this->carpetaId = $carpeta->id;
        $this->seccion = $carpeta->unidad;
        $this->busqueda = '';
        $this->reiniciarVista();
        $this->expandirHasta($carpeta);
    }

    /** Va a la carpeta contenedora visible más cercana, o a la raíz de la sección. */
    public function subirNivel(): void
    {
        $actual = $this->carpeta();
        if (! $actual) {
            return;
        }

        $visibles = Carpeta::visiblePara($this->user())->whereIn('id', $actual->idsRuta())->pluck('id')->all();
        $padre = collect($actual->ancestrosYYo())->reverse()->skip(1)->first(fn ($c) => in_array($c->id, $visibles, true));

        $padre ? $this->abrirCarpeta($padre->id) : $this->irA($actual->unidad);
    }

    /** Al volver con el botón "atrás" del navegador Livewire cambia la propiedad desde la URL: se valida igual que al abrir. */
    public function updatedCarpetaId(): void
    {
        $carpeta = $this->carpetaId ? Carpeta::find($this->carpetaId) : null;
        if ($this->carpetaId && (! $carpeta || ! $this->acceso()->puedeVerCarpeta($this->user(), $carpeta))) {
            $this->carpetaId = null;
            $this->avisar('No tienes acceso a esa carpeta o ya no existe.');
        }

        if ($carpeta && $this->carpetaId) {
            $this->seccion = $carpeta->unidad;
            $this->expandirHasta($carpeta);
        }
        $this->reiniciarVista();
    }

    public function updatedSeccion(): void
    {
        if (! in_array($this->seccion, $this->seccionesDisponibles(), true)) {
            $this->seccion = 'compartida';
        }
        $this->reiniciarVista();
    }

    public function alternarRama(int $id): void
    {
        $this->expandidas = in_array($id, $this->expandidas, true)
            ? array_values(array_diff($this->expandidas, [$id]))
            : array_slice([...$this->expandidas, $id], -300);
    }

    public function ordenarPor(string $campo): void
    {
        if (! in_array($campo, self::ORDENES, true)) {
            return;
        }

        $this->direccion = $this->orden === $campo && $this->direccion === 'asc' ? 'desc' : 'asc';
        $this->orden = $campo;
    }

    public function cambiarVista(string $vista): void
    {
        $this->vista = $vista === 'cuadricula' ? 'cuadricula' : 'lista';
        session(['documentos.vista' => $this->vista]);
    }

    public function cargarMas(): void
    {
        $this->limite = min($this->limite + self::POR_PAGINA, 2000);
    }

    public function updatedBusqueda(): void
    {
        $this->busqueda = mb_substr($this->busqueda, 0, 100);
        $this->reiniciarVista();
    }

    public function updatedFiltroTipo(): void
    {
        $this->reiniciarVista();
    }

    public function updatedFiltroDesde(): void
    {
        $this->reiniciarVista();
    }

    public function alternarSeleccion(string $clave): void
    {
        if (! preg_match('/^[ca]:\d+$/', $clave)) {
            return;
        }

        $this->seleccion = in_array($clave, $this->seleccion, true)
            ? array_values(array_diff($this->seleccion, [$clave]))
            : array_slice([...$this->seleccion, $clave], -2000);
    }

    public function seleccionarTodo(): void
    {
        $listado = $this->listado();
        $todas = $listado['carpetas']->pluck('clave')->merge($listado['archivos']->pluck('clave'))->all();
        $this->seleccion = count($this->seleccion) === count($todas) ? [] : $todas;
    }

    public function limpiarSeleccion(): void
    {
        $this->seleccion = [];
    }

    public function verDetalle(?string $clave): void
    {
        $this->detalle = $clave !== null && preg_match('/^[ca]:\d+$/', $clave) ? $clave : null;
    }

    // ── Operaciones directas (arrastrar y soltar, versiones) ────────────────

    public function updatedSoltados(): void
    {
        $archivos = array_values(array_filter($this->soltados, fn ($f) => $f instanceof TemporaryUploadedFile));
        $rutas = array_values($this->rutasSoltadas);
        $this->soltados = [];
        $this->rutasSoltadas = [];

        if (count($archivos) > config('documentos.subida.max_archivos')) {
            $this->avisar('Puedes subir como máximo '.config('documentos.subida.max_archivos').' archivos por lote.');

            return;
        }

        $base = $this->carpeta();
        if (! $rutas) {
            $this->subirArchivos($archivos, $base);

            return;
        }

        // Subida de carpeta: cada archivo va a la subcarpeta indicada por su ruta relativa
        $porDestino = [];
        foreach ($archivos as $i => $archivo) {
            $segmentos = $this->segmentosRuta((string) ($rutas[$i] ?? ''));
            array_pop($segmentos); // el último segmento es el nombre del archivo
            $porDestino[implode('/', $segmentos)][] = $archivo;
        }

        foreach ($porDestino as $ruta => $grupo) {
            try {
                $destino = $base && $ruta !== '' ? app(GestorCarpetas::class)->asegurarRuta($this->user(), $base, explode('/', $ruta)) : $base;
            } catch (Throwable $e) {
                $this->resumenSubida['errores'] += count($grupo);
                $this->intentar(fn () => throw $e);
                array_walk($grupo, fn ($f) => @unlink($f->getRealPath()));
                continue;
            }
            $this->subirArchivos($grupo, $destino, acumular: true);
        }
    }

    /** Crea la estructura de una carpeta subida (incluidas las subcarpetas vacías) antes de enviar los archivos. */
    public function crearEstructura(array $directorios): void
    {
        $base = $this->carpeta();
        if (! $base || ! $this->puedeSubirAqui()) {
            $this->avisar('No puedes subir carpetas aquí.');

            return;
        }

        $this->resumenSubida = ['subidos' => 0, 'repetidos' => 0, 'errores' => 0];
        $rutas = collect($directorios)->filter(fn ($d) => is_string($d))->take(500)
            ->map(fn ($d) => implode('/', $this->segmentosRuta($d)))->filter()->unique()->sort();

        foreach ($rutas as $ruta) {
            if (! $this->intentar(fn () => app(GestorCarpetas::class)->asegurarRuta($this->user(), $base, explode('/', $ruta)))) {
                return;
            }
        }
    }

    public function finalizarSubidaCarpeta(): void
    {
        ['subidos' => $subidos, 'repetidos' => $repetidos, 'errores' => $errores] = $this->resumenSubida;
        $this->resumenSubida = ['subidos' => 0, 'repetidos' => 0, 'errores' => 0];

        Notification::make()
            ->color($errores ? 'warning' : 'success')
            ->title('Carpeta subida')
            ->body("$subidos archivo(s) subidos".($repetidos ? ", $repetidos ya existían" : '').($errores ? ", $errores con error" : '').'.')
            ->send();
    }

    public function moverA(array $claves, int $destinoId): void
    {
        $claves = array_values(array_filter($claves, fn ($c) => $c !== 'c:'.$destinoId));
        if ($claves) {
            $this->moverClaves($claves, 'c:'.$destinoId);
        }
    }

    public function restaurarVersion(int $versionId): void
    {
        $version = ArchivoVersion::find($versionId);
        $archivo = $version?->archivo;
        if (! $archivo) {
            $this->avisar('La versión ya no existe.');

            return;
        }

        $this->intentar(function () use ($archivo, $version) {
            $nueva = app(GestorArchivos::class)->restaurarVersion($this->user(), $archivo, $version);
            Notification::make()->success()->title("Se creó la versión {$nueva->numero} a partir de la versión {$version->numero}")->send();
        });
    }

    // ── Acciones con formulario (modales de Filament) ───────────────────────

    public function crearCarpetaAction(): Action
    {
        return Action::make('crearCarpeta')
            ->label('Nueva carpeta')
            ->icon('heroicon-m-folder-plus')
            ->color('gray')
            ->modalWidth(MaxWidth::Medium)
            ->modalSubmitActionLabel('Crear')
            ->visible(fn () => $this->puedeCrearCarpetaAqui())
            ->form([
                TextInput::make('nombre')->label('Nombre de la carpeta')->required()->maxLength(200)->autofocus(),
            ])
            ->action(fn (array $data) => $this->intentar(
                fn () => app(GestorCarpetas::class)->crear($this->user(), $this->carpeta(), $data['nombre'], $this->unidadActual()),
                'Carpeta creada'
            ));
    }

    public function subirAction(): Action
    {
        return Action::make('subir')
            ->label('Subir archivos')
            ->icon('heroicon-m-arrow-up-tray')
            ->modalWidth(MaxWidth::TwoExtraLarge)
            ->modalSubmitActionLabel('Subir')
            ->visible(fn () => $this->puedeSubirAqui())
            ->form([
                FileUpload::make('archivos')
                    ->label('Archivos')
                    ->multiple()
                    ->storeFiles(false)
                    ->required()
                    ->maxFiles((int) config('documentos.subida.max_archivos'))
                    ->maxSize((int) config('documentos.subida.max_kb'))
                    ->helperText('Máximo '.config('documentos.subida.max_archivos').' archivos de '.round(config('documentos.subida.max_kb') / 1024).' MB cada uno. Tipos permitidos: '.implode(', ', config('documentos.subida.extensiones')).'.'),
            ])
            ->action(fn (array $data) => $this->subirArchivos($data['archivos'] ?? [], $this->carpeta()));
    }

    public function renombrarAction(): Action
    {
        return Action::make('renombrar')
            ->label('Renombrar')
            ->modalWidth(MaxWidth::Medium)
            ->modalSubmitActionLabel('Guardar')
            ->fillForm(fn (array $arguments) => ['nombre' => $this->resolverVisible($arguments['clave'] ?? '')?->nombre])
            ->form([TextInput::make('nombre')->label('Nuevo nombre')->required()->maxLength(200)->autofocus()])
            ->action(function (array $data, array $arguments) {
                $item = $this->resolverVisible($arguments['clave'] ?? '');
                if (! $item) {
                    $this->avisar('El elemento ya no existe.');

                    return;
                }

                $this->intentar(fn () => $item instanceof Carpeta
                    ? app(GestorCarpetas::class)->renombrar($this->user(), $item, $data['nombre'])
                    : app(GestorArchivos::class)->renombrar($this->user(), $item, $data['nombre']), 'Nombre actualizado');
            });
    }

    public function personalizarAction(): Action
    {
        $colores = config('documentos.colores');
        $iconos = config('documentos.iconos');

        return Action::make('personalizar')
            ->label('Color e ícono')
            ->modalWidth(MaxWidth::ThreeExtraLarge)
            ->modalSubmitActionLabel('Guardar')
            ->modalHeading(fn (array $arguments) => 'Personalizar «'.($this->resolverVisible($arguments['clave'] ?? '')?->nombre ?? '').'»')
            ->fillForm(function (array $arguments) {
                $carpeta = $this->resolverVisible($arguments['clave'] ?? '');

                return ['color' => $carpeta?->color ?? '', 'icono' => $carpeta?->icono ?? 'folder'];
            })
            ->form([
                ToggleButtons::make('color')
                    ->label('Color')
                    ->inline()
                    ->options(['' => 'Predeterminado'] + array_map(fn ($c) => $c[0], $colores))
                    ->icons(array_fill_keys(['', ...array_keys($colores)], 'heroicon-s-folder'))
                    ->colors(['' => 'gray'] + array_combine(array_keys($colores), array_keys($colores))),
                ToggleButtons::make('icono')
                    ->label('Ícono')
                    ->inline()
                    ->required()
                    ->options($iconos)
                    ->icons(array_combine(array_keys($iconos), array_map(fn ($i) => 'heroicon-s-'.$i, array_keys($iconos)))),
            ])
            ->action(function (array $data, array $arguments) {
                $carpeta = $this->resolverVisible($arguments['clave'] ?? '');
                if (! $carpeta instanceof Carpeta) {
                    $this->avisar('La carpeta ya no existe.');

                    return;
                }

                $this->intentar(
                    fn () => app(GestorCarpetas::class)->personalizar($this->user(), $carpeta, ($data['color'] ?? '') ?: null, $data['icono'] ?? null),
                    'Carpeta personalizada'
                );
            });
    }

    public function moverAction(): Action
    {
        return Action::make('mover')
            ->label('Mover a…')
            ->modalHeading(fn (array $arguments) => 'Mover '.$this->descripcionElementos($arguments['claves'] ?? $this->seleccion))
            ->modalWidth(MaxWidth::TwoExtraLarge)
            ->modalSubmitActionLabel('Mover aquí')
            ->form(fn (array $arguments) => [$this->exploradorDestino('destino', $arguments['claves'] ?? $this->seleccion, true)])
            ->action(fn (array $data, array $arguments) => $this->moverClaves($arguments['claves'] ?? $this->seleccion, $data['destino']));
    }

    public function copiarAction(): Action
    {
        return Action::make('copiar')
            ->label('Copiar a…')
            ->modalHeading(fn (array $arguments) => 'Copiar '.$this->descripcionElementos($arguments['claves'] ?? $this->seleccion))
            ->modalWidth(MaxWidth::TwoExtraLarge)
            ->modalSubmitActionLabel('Copiar aquí')
            ->form(fn (array $arguments) => [$this->exploradorDestino('destino', $arguments['claves'] ?? $this->seleccion, false)])
            ->action(function (array $data, array $arguments) {
                $destino = $this->carpetaDestino($data['destino']);
                $copiados = 0;
                foreach ($arguments['claves'] ?? $this->seleccion as $clave) {
                    $item = $this->resolverVisible($clave);
                    if ($item instanceof Archivos && $destino) {
                        $copiados += $this->intentar(fn () => app(GestorArchivos::class)->copiar($this->user(), $item, $destino)) ? 1 : 0;
                    }
                }
                if ($copiados) {
                    Notification::make()->success()->title($copiados === 1 ? 'Archivo copiado' : "$copiados archivos copiados")->send();
                }
            });
    }

    public function nuevaVersionAction(): Action
    {
        return Action::make('nuevaVersion')
            ->label('Subir nueva versión')
            ->modalWidth(MaxWidth::Large)
            ->modalDescription('La versión actual se conserva en el historial y podrás restaurarla cuando quieras.')
            ->modalSubmitActionLabel('Subir versión')
            ->form([
                FileUpload::make('archivo')->label('Archivo')->storeFiles(false)->required()->maxSize((int) config('documentos.subida.max_kb')),
                Textarea::make('comentario')->label('Comentario (opcional)')->maxLength(500)->rows(2),
            ])
            ->action(function (array $data, array $arguments) {
                $archivo = $this->resolverVisible($arguments['clave'] ?? '');
                $subida = $data['archivo'] ?? null;
                if (! $archivo instanceof Archivos || ! $subida instanceof TemporaryUploadedFile) {
                    $this->avisar('No se pudo leer el archivo.');

                    return;
                }

                $anterior = $archivo->version_actual_id;
                $this->intentar(function () use ($archivo, $subida, $data, $anterior) {
                    try {
                        $version = app(GestorArchivos::class)->nuevaVersion($this->user(), $archivo, $subida->getRealPath(), $subida->getClientOriginalName(), $data['comentario'] ?? null);
                    } finally {
                        @unlink($subida->getRealPath());
                    }
                    $version->id === $anterior
                        ? Notification::make()->info()->title('Sin cambios')->body('El contenido es idéntico a la versión actual; no se creó una versión nueva.')->send()
                        : Notification::make()->success()->title("Versión {$version->numero} creada")->send();
                });
            });
    }

    public function versionesAction(): Action
    {
        return Action::make('versiones')
            ->label('Historial de versiones')
            ->modalWidth(MaxWidth::FourExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar')
            ->modalHeading(fn (array $arguments) => 'Versiones de «'.($this->resolverVisible($arguments['clave'] ?? '')?->nombre ?? '').'»')
            ->modalContent(function (array $arguments) {
                $archivo = $this->resolverVisible($arguments['clave'] ?? '');
                abort_unless($archivo instanceof Archivos, 404);

                return view('filament.pages.partials.versiones', [
                    'archivo' => $archivo,
                    'versiones' => $archivo->versiones()->with(['creador:id,name', 'restauradaDesde:id,numero'])->get(),
                    'puedeEditar' => $this->acceso()->puedeEditarArchivo($this->user(), $archivo),
                    'puedeDescargar' => $this->acceso()->puedeDescargar($this->user(), $archivo),
                ]);
            });
    }

    public function permisosAction(): Action
    {
        return Action::make('permisos')
            ->label('Permisos y acceso')
            ->modalWidth(MaxWidth::ThreeExtraLarge)
            ->modalSubmitActionLabel('Guardar permisos')
            ->visible(fn () => $this->esAdmin())
            ->modalHeading(fn (array $arguments) => 'Acceso a «'.(Carpeta::find($this->idDe($arguments['clave'] ?? ''))?->nombre ?? '').'»')
            ->fillForm(function (array $arguments) {
                $carpeta = Carpeta::with('permisos')->find($this->idDe($arguments['clave'] ?? ''));

                return [
                    'restringida' => (bool) $carpeta?->restringida,
                    'permisos' => $carpeta?->permisos->map(fn ($p) => [
                        'tipo' => $p->user_id ? 'usuario' : 'rol',
                        'user_id' => $p->user_id,
                        'role_id' => $p->role_id,
                        'nivel' => $p->nivel,
                    ])->all() ?? [],
                ];
            })
            ->form(function (array $arguments) {
                $carpeta = Carpeta::find($this->idDe($arguments['clave'] ?? ''));
                $raizPrivada = $carpeta && $carpeta->carpeta_padre_id === null && $carpeta->unidad === Carpeta::UNIDAD_PRIVADA;

                return [
                    Placeholder::make('explicacion')->hiddenLabel()->content(
                        'Los accesos se heredan hacia las subcarpetas. Una carpeta restringida solo es visible para los usuarios y roles indicados '
                        .'y corta la herencia de las carpetas superiores. Los administradores siempre tienen acceso. Eliminar archivos sigue reservado a administradores.'
                    ),
                    Toggle::make('restringida')
                        ->label('Carpeta restringida')
                        ->helperText($raizPrivada ? 'Las carpetas raíz de la unidad privada siempre son restringidas.' : 'Si está desactivada, todos los usuarios autorizados de la unidad compartida la ven.')
                        ->disabled($raizPrivada),
                    Repeater::make('permisos')
                        ->label('Accesos explícitos')
                        ->addActionLabel('Añadir acceso')
                        ->defaultItems(0)
                        ->columns(3)
                        ->schema([
                            Select::make('tipo')->label('Tipo')->options(['usuario' => 'Usuario', 'rol' => 'Rol'])->default('usuario')->required()->live(),
                            Select::make('user_id')->label('Usuario')->searchable()
                                ->options(fn () => User::orderBy('name')->limit(500)->pluck('name', 'id'))
                                ->visible(fn (Get $get) => $get('tipo') === 'usuario')
                                ->required(fn (Get $get) => $get('tipo') === 'usuario'),
                            Select::make('role_id')->label('Rol')
                                ->options(fn () => Role::orderBy('name')->pluck('name', 'id'))
                                ->visible(fn (Get $get) => $get('tipo') === 'rol')
                                ->required(fn (Get $get) => $get('tipo') === 'rol'),
                            Select::make('nivel')->label('Nivel')->required()->default('ver')
                                ->options(['ver' => 'Ver y descargar', 'editar' => 'Editar (subir, organizar, versiones)']),
                        ]),
                ];
            })
            ->action(function (array $data, array $arguments) {
                $carpeta = Carpeta::find($this->idDe($arguments['clave'] ?? ''));
                if (! $carpeta) {
                    $this->avisar('La carpeta ya no existe.');

                    return;
                }

                $permisos = array_map(fn ($p) => [
                    'user_id' => ($p['tipo'] ?? '') === 'usuario' ? ($p['user_id'] ?? null) : null,
                    'role_id' => ($p['tipo'] ?? '') === 'rol' ? ($p['role_id'] ?? null) : null,
                    'nivel' => $p['nivel'] ?? 'ver',
                ], $data['permisos'] ?? []);

                $this->intentar(
                    fn () => app(GestorCarpetas::class)->configurarAcceso($this->user(), $carpeta, (bool) ($data['restringida'] ?? $carpeta->restringida), $permisos),
                    'Permisos actualizados'
                );
            });
    }

    public function papeleraAction(): Action
    {
        return Action::make('papelera')
            ->label('Enviar a la papelera')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('¿Enviar a la papelera?')
            ->modalDescription('Las carpetas se envían con todo su contenido. Podrás restaurarlas desde la papelera.')
            ->modalSubmitActionLabel('Enviar a la papelera')
            ->visible(fn () => $this->esAdmin())
            ->action(function (array $arguments) {
                $n = 0;
                foreach ($arguments['claves'] ?? $this->seleccion as $clave) {
                    $item = $this->resolver($clave);
                    if (! $item || $item->trashed()) {
                        continue;
                    }
                    $n += $this->intentar(fn () => $item instanceof Carpeta
                        ? app(GestorCarpetas::class)->enviarPapelera($this->user(), $item)
                        : app(GestorArchivos::class)->enviarPapelera($this->user(), $item)) ? 1 : 0;
                }
                $this->seleccion = [];
                $this->detalle = null;
                if ($n) {
                    Notification::make()->success()->title($n === 1 ? 'Enviado a la papelera' : "$n elementos enviados a la papelera")->send();
                }
            });
    }

    public function restaurarAction(): Action
    {
        return Action::make('restaurar')
            ->label('Restaurar')
            ->requiresConfirmation()
            ->modalHeading('¿Restaurar este elemento?')
            ->visible(fn () => $this->esAdmin())
            ->action(function (array $arguments) {
                $item = $this->resolver($arguments['clave'] ?? '');
                if ($item) {
                    $this->intentar(fn () => $item instanceof Carpeta
                        ? app(GestorCarpetas::class)->restaurar($this->user(), $item)
                        : app(GestorArchivos::class)->restaurar($this->user(), $item), 'Elemento restaurado');
                }
            });
    }

    public function purgarAction(): Action
    {
        return Action::make('purgar')
            ->label('Eliminar definitivamente')
            ->color('danger')
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalHeading('¿Eliminar definitivamente?')
            ->modalDescription('Se borrarán el registro, todas sus versiones y los binarios que ya no use ningún otro documento. Los originales recuperados del almacenamiento no se borran. Esta acción no se puede deshacer.')
            ->modalSubmitActionLabel('Eliminar definitivamente')
            ->visible(fn () => $this->esAdmin())
            ->action(function (array $arguments) {
                $item = $this->resolver($arguments['clave'] ?? '');
                if ($item) {
                    $this->intentar(fn () => $item instanceof Carpeta
                        ? app(GestorCarpetas::class)->purgar($this->user(), $item)
                        : app(GestorArchivos::class)->purgar($this->user(), $item), 'Eliminado definitivamente');
                    $this->detalle = null;
                }
            });
    }

    // ── Datos para la vista ──────────────────────────────────────────────────

    protected function getViewData(): array
    {
        $user = $this->user();

        return [
            'listado' => $this->listado(),
            'migas' => $this->migas(),
            'arbolCompartido' => $this->arbol(Carpeta::UNIDAD_COMPARTIDA),
            'arbolPrivado' => $this->tieneUnidadPrivada() ? $this->arbol(Carpeta::UNIDAD_PRIVADA) : null,
            'detalleItem' => $this->datosDetalle(),
            'esAdmin' => $this->esAdmin(),
            'puedeSubir' => $this->puedeSubirAqui(),
            'puedeDescargar' => $user->esAdmin() || $user->can('documentos.descargar'),
            'puedeHerramientas' => $user->esAdmin() || $user->can('herramientas.pdf'),
            'tipos' => array_map(fn ($t) => $t[0], self::TIPOS),
            'almacenamiento' => $this->esAdmin() ? app(EstadisticasDocumentales::class)->breve() : null,
            'maxArchivos' => min((int) config('documentos.subida.max_archivos'), (int) ini_get('max_file_uploads') ?: 20),
            'maxBytesArchivo' => (int) config('documentos.subida.max_kb') * 1024,
            // Margen bajo post_max_size: cada lote viaja en una sola petición
            'maxBytesLote' => (int) floor($this->bytesIni((string) ini_get('post_max_size')) * 0.85),
            'enlaces' => $this->enlaces(),
        ];
    }

    /** Accesos que en el resto del panel están en el menú de Filament (aquí ese menú se oculta). */
    private function enlaces(): array
    {
        $enlaces = [];
        if (HerramientasPdf::canAccess()) {
            $enlaces[] = ['url' => HerramientasPdf::getUrl(), 'icono' => 'heroicon-o-wrench-screwdriver', 'etiqueta' => 'Herramientas PDF'];
        }

        foreach ([
            [\App\Filament\Resources\RecuperacionGrupoResource::class, 'heroicon-o-lifebuoy', 'Recuperación documental'],
            [\App\Filament\Resources\ActividadResource::class, 'heroicon-o-clipboard-document-list', 'Historial de actividad'],
            [\App\Filament\Resources\UserResource::class, 'heroicon-o-users', 'Usuarios'],
            [\App\Filament\Resources\RoleResource::class, 'heroicon-o-shield-check', 'Roles'],
        ] as [$recurso, $icono, $etiqueta]) {
            if ($recurso::canViewAny()) {
                $enlaces[] = ['url' => $recurso::getUrl(), 'icono' => $icono, 'etiqueta' => $etiqueta];
            }
        }

        return $enlaces;
    }

    private function bytesIni(string $valor): int
    {
        $numero = (int) $valor;

        return match (strtolower(substr(trim($valor), -1))) {
            'g' => $numero * 1024 ** 3,
            'm' => $numero * 1024 ** 2,
            'k' => $numero * 1024,
            default => $numero ?: 8 * 1024 ** 2,
        };
    }

    private function listado(): array
    {
        $user = $this->user();
        $buscar = trim($this->busqueda);
        $carpetas = collect();
        $archivosQ = null;
        $titulo = '';

        if ($this->seccion === 'papelera' && $this->esAdmin()) {
            $titulo = 'Papelera';
            $carpetas = Carpeta::onlyTrashed()->where('papelera_raiz', true)
                ->when($buscar !== '', fn ($q) => $q->where('nombre', 'like', $this->like($buscar)))
                ->with('creador:id,name')->orderByDesc('deleted_at')->limit($this->limite)->get();
            $archivosQ = Archivos::onlyTrashed()->where('papelera_raiz', true)
                ->when($buscar !== '', fn ($q) => $q->where('archivos.nombre', 'like', $this->like($buscar)));
        } elseif ($buscar !== '') {
            $titulo = 'Resultados para «'.$buscar.'»';
            if ($this->filtroTipo === '') {
                $carpetas = Carpeta::visiblePara($user)->where('nombre', 'like', $this->like($buscar))
                    ->with('creador:id,name')->orderBy('nombre')->limit(30)->get();
            }
            $like = $this->like($buscar);
            $archivosQ = Archivos::visiblePara($user)->where(fn ($q) => $q
                ->where('archivos.nombre', 'like', $like)
                ->orWhere('archivos.identificador_documental', 'like', $like)
                ->orWhere('archivos.extension', mb_strtolower(ltrim($buscar, '.')))
                ->orWhereIn('archivos.id', ArchivoTexto::select('archivo_id')->where('texto', 'like', $like)));
        } elseif ($this->seccion === 'recientes') {
            $titulo = 'Recientes';
            $archivosQ = Archivos::visiblePara($user)->whereNotNull('archivos.carpeta_id');
        } elseif ($this->seccion === 'pendientes' && $this->esAdmin()) {
            $titulo = 'Pendientes de revisión';
            $archivosQ = Archivos::where('estado', Archivos::ESTADO_PENDIENTE);
        } else {
            $actual = $this->carpeta();
            $titulo = $actual?->nombre ?? ($this->seccion === 'privada' ? 'Unidad privada' : 'Unidad compartida');
            $carpetasQ = Carpeta::visiblePara($user)->with('creador:id,name');
            if ($actual) {
                $carpetasQ->where('carpeta_padre_id', $actual->id);
                $archivosQ = Archivos::visiblePara($user)->where('archivos.carpeta_id', $actual->id);
            } else {
                $carpetasQ->whereIn('id', $this->idsRaiz($this->seccion === 'privada' ? Carpeta::UNIDAD_PRIVADA : Carpeta::UNIDAD_COMPARTIDA));
            }
            $campo = in_array($this->orden, ['nombre', 'updated_at'], true) ? $this->orden : 'nombre';
            $carpetas = $carpetasQ->orderBy($campo, $this->direccion)->limit(500)->get();
        }

        $archivos = collect();
        $hayMas = false;
        if ($archivosQ) {
            if (isset(self::TIPOS[$this->filtroTipo])) {
                $archivosQ->whereIn('archivos.extension', self::TIPOS[$this->filtroTipo][1]);
            }
            if ($desde = $this->fechaDesde()) {
                $archivosQ->where('archivos.updated_at', '>=', $desde);
            }

            $archivosQ->with(['subidoPor:id,name', 'versionActual:id,numero', 'carpeta:id,nombre,unidad']);
            $this->seccion === 'recientes' && $buscar === ''
                ? $archivosQ->orderByDesc('archivos.updated_at')
                : $archivosQ->orderBy('archivos.'.$this->orden, $this->direccion)->orderBy('archivos.id');

            $archivos = $archivosQ->limit($this->limite + 1)->get();
            $hayMas = $archivos->count() > $this->limite;
            $archivos = $archivos->take($this->limite);
        }

        $enPapelera = $this->seccion === 'papelera';
        $mostrarUbicacion = $buscar !== '' || in_array($this->seccion, ['recientes', 'papelera'], true);

        return [
            'titulo' => $titulo,
            'carpetas' => $carpetas->map(fn (Carpeta $c) => [
                'clave' => 'c:'.$c->id,
                'id' => $c->id,
                'nombre' => $c->nombre,
                'restringida' => $c->restriccion_id !== null,
                'icono' => Carpeta::iconoDe($c->icono),
                'color' => Carpeta::colorDe($c->color),
                'fecha' => $c->deleted_at ?? $c->updated_at,
                'autor' => $c->creador?->name,
                'editable' => ! $enPapelera && $this->editableCarpeta($c),
                'personalizable' => ! $enPapelera && $this->editableEn($c->id),
                'enPapelera' => $enPapelera,
            ]),
            'archivos' => $archivos->map(fn (Archivos $a) => [
                'clave' => 'a:'.$a->id,
                'id' => $a->id,
                'nombre' => $a->nombre,
                'tipo' => $a->tipo(),
                'extension' => $a->extension,
                'tamano' => $a->tamano,
                'fecha' => $a->deleted_at ?? $a->updated_at,
                'autor' => $a->subidoPor?->name,
                'version' => $a->versionActual?->numero,
                'visualizable' => $a->esVisualizable(),
                'ubicacion' => $mostrarUbicacion ? ($a->carpeta?->nombre ?? 'Sin carpeta') : null,
                'carpetaId' => $a->carpeta_id,
                'editable' => ! $enPapelera && $this->editableEn($a->carpeta_id),
                'pendiente' => $a->estado === Archivos::ESTADO_PENDIENTE,
                'enPapelera' => $enPapelera,
            ]),
            'hayMas' => $hayMas,
        ];
    }

    private function migas(): array
    {
        if (trim($this->busqueda) !== '') {
            return [['etiqueta' => 'Resultados de búsqueda', 'accion' => null]];
        }

        $raiz = match ($this->seccion) {
            'privada' => 'Unidad privada',
            'recientes' => 'Recientes',
            'papelera' => 'Papelera',
            'pendientes' => 'Pendientes de revisión',
            default => 'Unidad compartida',
        };

        $migas = [['etiqueta' => $raiz, 'accion' => "irA('{$this->seccion}')"]];
        if ($actual = $this->carpeta()) {
            // Solo se muestran los ancestros que el usuario puede ver
            $visibles = Carpeta::visiblePara($this->user())->whereIn('id', $actual->idsRuta())->pluck('id')->all();
            foreach ($actual->ancestrosYYo() as $c) {
                if (in_array($c->id, $visibles, true)) {
                    $migas[] = ['etiqueta' => $c->nombre, 'accion' => "abrirCarpeta({$c->id})"];
                }
            }
        }

        return $migas;
    }

    /** Árbol con carga progresiva: raíces + hijos de las ramas expandidas. */
    private function arbol(string $unidad): array
    {
        $user = $this->user();
        $raices = $this->idsRaiz($unidad);
        $expandidas = $this->expandidas;

        $nodos = Carpeta::visiblePara($user)
            ->where(fn ($q) => $q->whereIn('id', $raices ?: [0])->orWhereIn('carpeta_padre_id', $expandidas ?: [0]))
            ->where('unidad', $unidad)
            ->orderBy('nombre')
            ->limit(2000)
            ->get(['id', 'nombre', 'carpeta_padre_id', 'restriccion_id', 'color', 'icono']);

        $conHijos = Carpeta::visiblePara($user)->whereIn('carpeta_padre_id', $nodos->pluck('id')->all() ?: [0])
            ->distinct()->pluck('carpeta_padre_id')->flip();
        $porPadre = $nodos->groupBy(fn ($n) => (int) $n->carpeta_padre_id);

        $construir = function (Collection $lista, int $profundidad) use (&$construir, $porPadre, $conHijos, $expandidas): array {
            return $lista->map(function ($n) use (&$construir, $porPadre, $conHijos, $expandidas, $profundidad) {
                $expandida = in_array($n->id, $expandidas, true) && $profundidad < 30;

                return [
                    'id' => $n->id,
                    'nombre' => $n->nombre,
                    'restringida' => $n->restriccion_id !== null,
                    'icono' => Carpeta::iconoDe($n->icono),
                    'color' => Carpeta::colorDe($n->color),
                    'tieneHijos' => isset($conHijos[$n->id]),
                    'expandida' => $expandida,
                    'hijos' => $expandida ? $construir($porPadre->get($n->id, collect()), $profundidad + 1) : [],
                ];
            })->values()->all();
        };

        return $construir($nodos->whereIn('id', $raices), 0);
    }

    private function datosDetalle(): ?array
    {
        if (! $this->detalle) {
            return null;
        }

        $item = $this->resolverVisible($this->detalle);
        if (! $item) {
            return null;
        }

        $actividad = Actividad::with('user:id,name')
            ->where('sujeto_tipo', class_basename($item))->where('sujeto_id', $item->id)
            ->latest('id')->limit(8)->get();

        if ($item instanceof Carpeta) {
            return [
                'clave' => 'c:'.$item->id,
                'tipo' => 'carpeta',
                'icono' => Carpeta::iconoDe($item->icono),
                'color' => Carpeta::colorDe($item->color),
                'nombre' => $item->nombre,
                'filas' => array_filter([
                    'Ubicación' => $item->unidad === Carpeta::UNIDAD_PRIVADA ? 'Unidad privada' : 'Unidad compartida',
                    'Acceso' => $item->restriccion_id ? 'Restringido' : 'Todos los usuarios autorizados',
                    'Subcarpetas' => Carpeta::visiblePara($this->user())->where('carpeta_padre_id', $item->id)->count(),
                    'Archivos' => Archivos::visiblePara($this->user())->where('archivos.carpeta_id', $item->id)->count(),
                    'Creada por' => $item->creador?->name,
                    'Creada' => $item->created_at?->format('d/m/Y H:i'),
                    'Modificada' => $item->updated_at?->format('d/m/Y H:i'),
                ], fn ($v) => $v !== null),
                'permisos' => $this->esAdmin()
                    ? $item->permisos()->with(['user:id,name', 'role:id,name'])->get()->map(fn ($p) => ($p->user?->name ?? 'Rol '.$p->role?->name).' · '.$p->nivel)->all()
                    : [],
                'actividad' => $actividad,
                'editable' => ! $item->trashed() && $this->editableCarpeta($item),
                'enPapelera' => $item->trashed(),
            ];
        }

        $version = $item->versionActual;

        return [
            'clave' => 'a:'.$item->id,
            'id' => $item->id,
            'tipo' => $item->tipo(),
            'nombre' => $item->nombre,
            'filas' => array_filter([
                'Tipo' => mb_strtoupper((string) $item->extension).' · '.$item->mime,
                'Tamaño' => $this->bytes($item->tamano),
                'Ubicación' => $item->carpeta?->nombre ?? 'Sin carpeta (pendiente de revisión)',
                'Versión actual' => $version ? 'v'.$version->numero : null,
                'Identificador documental' => $item->identificador_documental,
                'Origen' => match ($item->origen) { 'recuperacion' => 'Recuperado del almacenamiento', 'copia' => 'Copia', default => 'Subida' },
                'Subido por' => $item->subidoPor?->name,
                'Creado' => $item->created_at?->format('d/m/Y H:i'),
                'Modificado' => $item->updated_at?->format('d/m/Y H:i'),
                'MD5' => $item->hash_md5,
            ], fn ($v) => $v !== null && $v !== ''),
            'actividad' => $actividad,
            'visualizable' => $item->esVisualizable(),
            'editable' => ! $item->trashed() && $this->acceso()->puedeEditarArchivo($this->user(), $item),
            'descargable' => ! $item->trashed() && $this->acceso()->puedeDescargar($this->user(), $item),
            'enPapelera' => $item->trashed(),
        ];
    }

    // ── Apoyo ────────────────────────────────────────────────────────────────

    public function seccionesDisponibles(): array
    {
        $secciones = ['compartida', 'recientes'];
        if ($this->tieneUnidadPrivada()) {
            $secciones[] = 'privada';
        }
        if ($this->esAdmin()) {
            array_push($secciones, 'papelera', 'pendientes');
        }

        return $secciones;
    }

    public function bytes(?int $bytes): string
    {
        $bytes = (int) $bytes;
        foreach (['B', 'KB', 'MB', 'GB'] as $unidad) {
            if ($bytes < 1024 || $unidad === 'GB') {
                return ($unidad === 'B' ? $bytes : number_format($bytes, 1, ',', '.')).' '.$unidad;
            }
            $bytes /= 1024;
        }

        return '';
    }

    private function carpeta(): ?Carpeta
    {
        if (! $this->carpetaId) {
            return null;
        }

        $carpeta = Carpeta::find($this->carpetaId);

        return $carpeta && $this->acceso()->puedeVerCarpeta($this->user(), $carpeta) ? $carpeta : null;
    }

    private function unidadActual(): string
    {
        return $this->carpeta()?->unidad ?? ($this->seccion === 'privada' ? Carpeta::UNIDAD_PRIVADA : Carpeta::UNIDAD_COMPARTIDA);
    }

    private function enNavegacion(): bool
    {
        return in_array($this->seccion, ['compartida', 'privada'], true) && trim($this->busqueda) === '';
    }

    private function puedeCrearCarpetaAqui(): bool
    {
        return $this->enNavegacion() && $this->acceso()->puedeCrearCarpetaEn($this->user(), $this->carpeta());
    }

    private function puedeSubirAqui(): bool
    {
        $carpeta = $this->carpeta();

        return $this->enNavegacion() && $carpeta !== null && $this->acceso()->puedeEditarEn($this->user(), $carpeta);
    }

    private function tieneUnidadPrivada(): bool
    {
        return $this->esAdmin() || Carpeta::visiblePara($this->user())->where('unidad', Carpeta::UNIDAD_PRIVADA)->exists();
    }

    /** Carpetas superiores visibles de una unidad: raíces, o la carpeta más alta a la que el usuario tiene acceso. */
    private function idsRaiz(string $unidad): array
    {
        if ($this->esAdmin()) {
            return Carpeta::whereNull('carpeta_padre_id')->where('unidad', $unidad)->pluck('id')->all();
        }

        $visibles = Carpeta::visiblePara($this->user())->where('unidad', $unidad)->pluck('carpeta_padre_id', 'id');

        return $visibles->filter(fn ($padre) => $padre === null || ! $visibles->has($padre))->keys()->all();
    }

    private function expandirHasta(Carpeta $carpeta): void
    {
        $this->expandidas = array_values(array_unique([...$this->expandidas, ...array_slice($carpeta->idsRuta(), 0, -1)]));
    }

    private function reiniciarVista(): void
    {
        $this->seleccion = [];
        $this->detalle = null;
        $this->limite = self::POR_PAGINA;
    }

    private function fechaDesde(): ?\Carbon\CarbonInterface
    {
        return match ($this->filtroDesde) {
            'hoy' => now()->startOfDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            'anio' => now()->startOfYear(),
            default => null,
        };
    }

    private function editableEn(?int $carpetaId): bool
    {
        if ($this->esAdmin()) {
            return true;
        }
        if ($carpetaId === null) {
            return false;
        }

        return $this->editableMemo[$carpetaId] ??= (($c = Carpeta::find($carpetaId)) !== null && $this->acceso()->puedeEditarEn($this->user(), $c));
    }

    private function editableCarpeta(Carpeta $carpeta): bool
    {
        return $this->esAdmin() || ($carpeta->carpeta_padre_id !== null && $this->editableEn($carpeta->carpeta_padre_id) && $this->editableEn($carpeta->id));
    }

    /** "Clientes/../ACME//acta.pdf" → ["Clientes", "ACME", "acta.pdf"]: sin saltos de directorio ni segmentos vacíos. */
    private function segmentosRuta(string $ruta): array
    {
        $segmentos = array_filter(explode('/', str_replace('\\', '/', $ruta)), fn ($s) => ! in_array(trim($s), ['', '.', '..'], true));

        return array_slice(array_map(fn ($s) => NombresDocumentales::limpiar($s), array_values($segmentos)), 0, 20);
    }

    private function subirArchivos(iterable $archivos, ?Carpeta $carpeta, bool $acumular = false): void
    {
        $subidos = 0;
        $repetidos = 0;
        $errores = [];

        foreach ($archivos as $archivo) {
            if (! $archivo instanceof TemporaryUploadedFile) {
                continue;
            }
            $nombre = $archivo->getClientOriginalName();
            try {
                [, $creado] = app(GestorArchivos::class)->subir($this->user(), $carpeta, $archivo->getRealPath(), $nombre);
                $creado ? $subidos++ : $repetidos++;
            } catch (ValidationException $e) {
                $errores[] = collect($e->errors())->flatten()->first();
            } catch (AuthorizationException $e) {
                $errores[] = $e->getMessage();
                break;
            } catch (Throwable $e) {
                report($e);
                $errores[] = "«{$nombre}»: no se pudo guardar. Inténtalo de nuevo.";
            } finally {
                @unlink($archivo->getRealPath());
            }
        }

        if ($acumular) {
            $this->resumenSubida['subidos'] += $subidos;
            $this->resumenSubida['repetidos'] += $repetidos;
            $this->resumenSubida['errores'] += count($errores);
            if ($errores) {
                Notification::make()->danger()->title('Algunos archivos no se subieron')->body(implode("\n", array_slice($errores, 0, 5)))->send();
            }

            return;
        }

        if ($subidos) {
            Notification::make()->success()->title($subidos === 1 ? 'Archivo subido' : "$subidos archivos subidos")->send();
        }
        if ($repetidos) {
            Notification::make()->info()->title('Sin duplicados')->body($repetidos === 1 ? 'Un archivo ya existía con el mismo nombre y contenido.' : "$repetidos archivos ya existían con el mismo nombre y contenido.")->send();
        }
        if ($errores) {
            Notification::make()->danger()->title('Algunos archivos no se subieron')->body(implode("\n", array_slice($errores, 0, 5)))->persistent()->send();
        }
    }

    private function moverClaves(array $claves, ?string $destinoValor): void
    {
        [$destino, $unidadRaiz] = $this->interpretarDestino($destinoValor);
        if ($destino === null && $unidadRaiz === null) {
            $this->avisar('Selecciona una carpeta de destino válida.');

            return;
        }

        $movidos = 0;
        foreach ($claves as $clave) {
            $item = $this->resolverVisible($clave);
            if (! $item) {
                continue;
            }
            if ($item instanceof Archivos && ! $destino) {
                $this->avisar('Los archivos deben estar dentro de una carpeta.');
                continue;
            }
            $movidos += $this->intentar(fn () => $item instanceof Carpeta
                ? app(GestorCarpetas::class)->mover($this->user(), $item, $destino, $unidadRaiz)
                : app(GestorArchivos::class)->mover($this->user(), $item, $destino)) ? 1 : 0;
        }

        $this->seleccion = [];
        if ($movidos) {
            Notification::make()->success()->title($movidos === 1 ? 'Elemento movido' : "$movidos elementos movidos")->send();
        }
    }

    /** Explorador de carpetas (como «Mover a» de Google Drive) para elegir el destino. */
    private function exploradorDestino(string $nombre, array $claves, bool $permitirRaiz): ViewField
    {
        $actual = $this->carpeta();

        return ViewField::make($nombre)
            ->hiddenLabel()
            ->required()
            ->validationMessages(['required' => 'Elige la carpeta de destino.'])
            ->view('filament.pages.unidad-destino')
            ->viewData([
                'inicio' => [
                    'unidad' => $actual?->unidad ?? ($this->seccion === 'privada' ? Carpeta::UNIDAD_PRIVADA : Carpeta::UNIDAD_COMPARTIDA),
                    'padre' => $actual?->id,
                ],
                'excluir' => array_values($claves),
                'permitirRaiz' => $permitirRaiz && $this->esAdmin(),
                'verbo' => $permitirRaiz ? 'mover' : 'copiar',
                'conPrivada' => $this->esAdmin() || $this->idsRaiz(Carpeta::UNIDAD_PRIVADA) !== [],
            ]);
    }

    private function descripcionElementos(array $claves): string
    {
        $n = count($claves);
        if ($n !== 1) {
            return "$n elementos";
        }
        $item = $this->resolverVisible($claves[0]);

        return $item ? '«'.$item->nombre.'»' : '1 elemento';
    }

    /**
     * Datos del explorador de destino: subcarpetas de $padreId (o raíces de la unidad), o resultados de búsqueda.
     * Solo devuelve carpetas visibles para el usuario; elegir una sin permiso de edición no está permitido.
     */
    #[Renderless]
    public function explorarDestinos(?int $padreId = null, string $unidad = 'compartida', string $busqueda = '', array $excluir = []): array
    {
        $user = $this->user();
        $unidad = $unidad === Carpeta::UNIDAD_PRIVADA ? Carpeta::UNIDAD_PRIVADA : Carpeta::UNIDAD_COMPARTIDA;
        $bloqueadas = collect($excluir)->filter(fn ($c) => is_string($c) && str_starts_with($c, 'c:'))
            ->map(fn ($c) => (int) substr($c, 2))->filter()->values()->all();
        $estaBloqueada = fn (Carpeta $c) => collect($bloqueadas)->contains(fn ($id) => in_array($id, $c->idsRuta(), true));

        $padre = $padreId ? Carpeta::visiblePara($user)->find($padreId) : null;
        $busqueda = trim($busqueda);

        if ($busqueda !== '') {
            $carpetas = Carpeta::visiblePara($user)->where('nombre', 'like', $this->like($busqueda))
                ->orderBy('nombre')->limit(40)->get();
        } elseif ($padre) {
            $unidad = $padre->unidad;
            $carpetas = Carpeta::visiblePara($user)->where('carpeta_padre_id', $padre->id)->orderBy('nombre')->get();
        } else {
            $carpetas = Carpeta::visiblePara($user)->whereIn('id', $this->idsRaiz($unidad) ?: [0])->orderBy('nombre')->get();
        }

        $conHijas = $carpetas->isEmpty() ? collect() : Carpeta::visiblePara($user)
            ->whereIn('carpeta_padre_id', $carpetas->pluck('id'))->distinct()->pluck('carpeta_padre_id')->flip();

        $idsNombres = $carpetas->flatMap->idsRuta()->merge($padre?->idsRuta() ?? [])->unique()->values()->all();
        $nombresRuta = Carpeta::whereIn('id', $idsNombres ?: [0])->pluck('nombre', 'id');

        $icono = fn (Carpeta $c) => svg(Carpeta::iconoDe($c->icono), 'ivsdd-icono')->toHtml();

        return [
            'unidad' => $unidad,
            'actual' => $padre && ! $estaBloqueada($padre) ? [
                'id' => $padre->id,
                'nombre' => $padre->nombre,
                'editable' => $this->editableEn($padre->id),
            ] : null,
            'migas' => $padre ? collect($padre->idsRuta())->map(fn ($id) => ['id' => $id, 'nombre' => $nombresRuta[$id] ?? '…'])->values()->all() : [],
            'carpetas' => $carpetas->map(fn (Carpeta $c) => [
                'id' => $c->id,
                'nombre' => $c->nombre,
                'color' => Carpeta::colorDe($c->color),
                'icono' => $icono($c),
                'hijas' => $conHijas->has($c->id),
                'editable' => $this->editableEn($c->id),
                'bloqueada' => $estaBloqueada($c),
                'privada' => $c->unidad === Carpeta::UNIDAD_PRIVADA,
                'ubicacion' => $busqueda !== '' ? collect($c->idsRuta())->slice(0, -1)->map(fn ($id) => $nombresRuta[$id] ?? '…')->implode(' › ') : null,
            ])->all(),
        ];
    }

    private function campoDestino(string $nombre, string $etiqueta, bool $permitirRaiz): Select
    {
        return Select::make($nombre)
            ->label($etiqueta)
            ->required()
            ->searchable()
            ->placeholder('Busca una carpeta…')
            ->options(fn () => $this->opcionesDestino('', $permitirRaiz))
            ->getSearchResultsUsing(fn (string $search) => $this->opcionesDestino($search, $permitirRaiz))
            ->getOptionLabelUsing(fn ($value) => $this->opcionesDestino('', $permitirRaiz)[$value] ?? (($c = $this->carpetaDestino($value)) ? $this->rutaLegible($c) : $value));
    }

    /** Carpetas donde el usuario puede editar (la autorización se repite al ejecutar). */
    private function opcionesDestino(string $busqueda, bool $permitirRaiz): array
    {
        $opciones = [];
        if ($permitirRaiz && $this->esAdmin()) {
            $opciones['raiz:compartida'] = '⟂ Raíz de la unidad compartida';
            $opciones['raiz:privada'] = '⟂ Raíz de la unidad privada';
        }

        $candidatas = Carpeta::visiblePara($this->user())
            ->when($busqueda !== '', fn ($q) => $q->where('nombre', 'like', $this->like($busqueda)))
            ->orderBy('ruta')->limit(50)->get();

        $nombres = Carpeta::whereIn('id', $candidatas->flatMap->idsRuta()->unique()->all() ?: [0])->pluck('nombre', 'id');

        foreach ($candidatas as $c) {
            if ($this->editableEn($c->id)) {
                $opciones['c:'.$c->id] = ($c->unidad === Carpeta::UNIDAD_PRIVADA ? '🔒 ' : '').collect($c->idsRuta())->map(fn ($id) => $nombres[$id] ?? '…')->implode(' / ');
            }
        }

        return $opciones;
    }

    private function rutaLegible(Carpeta $carpeta): string
    {
        return $carpeta->ancestrosYYo()->pluck('nombre')->implode(' / ');
    }

    /** @return array{0: ?Carpeta, 1: ?string} */
    private function interpretarDestino(?string $valor): array
    {
        if (in_array($valor, ['raiz:compartida', 'raiz:privada'], true)) {
            return [null, substr($valor, 5)];
        }

        $carpeta = $this->carpetaDestino($valor);

        return [$carpeta, $carpeta?->unidad];
    }

    private function carpetaDestino(?string $valor): ?Carpeta
    {
        if (! is_string($valor) || ! str_starts_with($valor, 'c:')) {
            return null;
        }

        $carpeta = Carpeta::find((int) substr($valor, 2));

        return $carpeta && $this->acceso()->puedeVerCarpeta($this->user(), $carpeta) ? $carpeta : null;
    }

    private function resolver(string $clave): Carpeta|Archivos|null
    {
        [$tipo, $id] = array_pad(explode(':', $clave, 2), 2, '0');
        $conPapelera = $this->esAdmin();

        return match ($tipo) {
            'c' => ($conPapelera ? Carpeta::withTrashed() : Carpeta::query())->find((int) $id),
            'a' => ($conPapelera ? Archivos::withTrashed() : Archivos::query())->find((int) $id),
            default => null,
        };
    }

    /** Igual que resolver(), pero solo devuelve elementos que el usuario puede ver (para no filtrar nombres ni datos). */
    private function resolverVisible(string $clave): Carpeta|Archivos|null
    {
        $item = $this->resolver($clave);

        return match (true) {
            $item instanceof Carpeta => ($this->esAdmin() || $this->acceso()->puedeVerCarpeta($this->user(), $item)) ? $item : null,
            $item instanceof Archivos => $this->acceso()->puedeVerArchivo($this->user(), $item) ? $item : null,
            default => null,
        };
    }

    private function idDe(string $clave): int
    {
        return (int) (explode(':', $clave, 2)[1] ?? 0);
    }

    private function intentar(callable $operacion, ?string $exito = null): bool
    {
        try {
            $operacion();
            if ($exito) {
                Notification::make()->success()->title($exito)->send();
            }
            // Si el visor está abierto se recarga para mostrar el nombre o la versión nuevos
            $this->dispatch('documento-actualizado');

            return true;
        } catch (AuthorizationException $e) {
            $this->avisar($e->getMessage() ?: 'No tienes permiso para realizar esta acción.');
        } catch (ValidationException $e) {
            $this->avisar((string) collect($e->errors())->flatten()->first());
        } catch (LockTimeoutException) {
            $this->avisar('Otra persona está modificando este lugar en este momento. Inténtalo de nuevo.');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->avisar($e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $this->avisar('Ocurrió un error inesperado. Inténtalo de nuevo.');
        }

        return false;
    }

    private function avisar(string $mensaje): void
    {
        Notification::make()->danger()->title($mensaje)->send();
    }

    private function like(string $texto): string
    {
        return '%'.addcslashes($texto, '%_\\').'%';
    }

    private function esAdmin(): bool
    {
        return $this->user()->esAdmin();
    }

    private function user(): User
    {
        return auth()->user();
    }

    private function acceso(): AccesoDocumental
    {
        return app(AccesoDocumental::class);
    }
}
