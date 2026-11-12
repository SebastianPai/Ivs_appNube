<?php

namespace App\Filament\Pages;

use App\Jobs\ProcesarPdfJob;
use App\Models\Archivos;
use App\Models\Carpeta;
use App\Models\TareaDocumental;
use App\Models\User;
use App\Services\Documentos\AccesoDocumental;
use App\Services\Documentos\AlmacenDocumental;
use App\Services\Documentos\GestorArchivos;
use App\Services\Documentos\Pdf\HerramientasPdf as Herramientas;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Throwable;

class HerramientasPdf extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationLabel = 'Herramientas PDF';

    protected static ?string $title = 'Herramientas PDF';

    protected static ?string $navigationGroup = 'Documentos';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.herramientas-pdf';

    public ?array $datos = [];

    public ?array $metadatos = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && ($user->esAdmin() || $user->can('herramientas.pdf'));
    }

    public const HERRAMIENTAS = [
        'comprimir' => 'Comprimir',
        'unir' => 'Unir',
        'dividir' => 'Dividir',
        'extraer' => 'Extraer / ordenar páginas',
        'eliminar' => 'Eliminar páginas',
        'rotar' => 'Rotar',
        'numerar' => 'Numerar páginas',
        'marca_agua' => 'Marca de agua',
        'proteger' => 'Proteger con contraseña',
        'desbloquear' => 'Quitar contraseña',
        'imagenes' => 'Imágenes a PDF',
        'metadatos' => 'Ver metadatos',
    ];

    // Funcionan sin qpdf (también en hosting compartido)
    public const SIN_QPDF = ['imagenes'];

    private const VALORES_INICIALES = [
        'nivel' => 'media', 'grados' => 90, 'paginas_por_parte' => 1, 'posicion' => 'abajo-centro', 'con_total' => true,
        'texto_marca' => 'COPIA CONTROLADA', 'opacidad' => 20, 'tamano_pagina' => 'a4',
    ];

    public function mount(): void
    {
        $this->form->fill(['herramienta' => 'comprimir', ...self::VALORES_INICIALES]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('datos')
            ->schema([
                Radio::make('herramienta')
                    ->label('Herramienta')
                    ->inline()
                    ->live()
                    ->required()
                    ->options(fn () => app(Herramientas::class)->disponibles()['qpdf'] ? self::HERRAMIENTAS : array_intersect_key(self::HERRAMIENTAS, array_flip(self::SIN_QPDF))),
                FileUpload::make('imagenes')
                    ->label('Imágenes (JPG, PNG o WEBP), en el orden de las páginas')
                    ->multiple()
                    ->reorderable()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->storeFiles(false)
                    ->maxSize(20480)
                    ->maxFiles(100)
                    ->visible(fn (Get $get) => $get('herramienta') === 'imagenes')
                    ->required(fn (Get $get) => $get('herramienta') === 'imagenes'),
                Select::make('tamano_pagina')
                    ->label('Tamaño de página')
                    ->options(['a4' => 'A4 (con margen)', 'original' => 'Tamaño de la imagen'])
                    ->visible(fn (Get $get) => $get('herramienta') === 'imagenes'),
                Select::make('archivos')
                    ->visible(fn (Get $get) => $get('herramienta') !== 'imagenes')
                    ->label('PDF del gestor documental')
                    ->multiple(fn (Get $get) => $get('herramienta') === 'unir')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => $this->pdfsDisponibles($search))
                    ->options(fn () => $this->pdfsDisponibles(''))
                    ->getOptionLabelsUsing(fn (array $values) => Archivos::visiblePara($this->user())->whereIn('archivos.id', $values)->pluck('nombre', 'archivos.id')->all())
                    ->getOptionLabelUsing(fn ($value) => Archivos::visiblePara($this->user())->whereKey($value)->value('nombre'))
                    ->helperText(fn (Get $get) => $get('herramienta') === 'unir' ? 'El orden de selección es el orden del resultado.' : null),
                FileUpload::make('subidas')
                    ->visible(fn (Get $get) => $get('herramienta') !== 'imagenes')
                    ->label('…o sube PDF desde tu equipo')
                    ->multiple(fn (Get $get) => $get('herramienta') === 'unir')
                    ->acceptedFileTypes(['application/pdf'])
                    ->storeFiles(false)
                    ->maxSize((int) config('documentos.subida.max_kb'))
                    ->maxFiles(20),
                Select::make('nivel')
                    ->label('Nivel de compresión')
                    ->options(fn () => collect(Herramientas::NIVELES)->except(app(Herramientas::class)->disponibles()['ghostscript'] ? [] : ['alta'])->all())
                    ->helperText('La reducción depende del documento: un PDF ya optimizado o con solo texto puede reducirse muy poco o nada.')
                    ->visible(fn (Get $get) => $get('herramienta') === 'comprimir')
                    ->required(fn (Get $get) => $get('herramienta') === 'comprimir'),
                TextInput::make('rango')
                    ->label(fn (Get $get) => $get('herramienta') === 'eliminar' ? 'Páginas que se eliminan' : 'Páginas')
                    ->placeholder('Ej.: 1-3,5 · 3,1,2 · 2-z (z = última)')
                    ->maxLength(200)
                    ->visible(fn (Get $get) => in_array($get('herramienta'), ['extraer', 'rotar', 'eliminar'], true))
                    ->required(fn (Get $get) => in_array($get('herramienta'), ['extraer', 'eliminar'], true))
                    ->helperText(fn (Get $get) => match ($get('herramienta')) {
                        'rotar' => 'Vacío = todas las páginas.',
                        'eliminar' => 'Las demás páginas se conservan en su orden.',
                        default => 'El resultado respeta el orden escrito.',
                    }),
                Select::make('posicion')
                    ->label('Posición del número')
                    ->options(['abajo-centro' => 'Abajo, al centro', 'abajo-derecha' => 'Abajo, a la derecha', 'arriba-derecha' => 'Arriba, a la derecha'])
                    ->visible(fn (Get $get) => $get('herramienta') === 'numerar'),
                Toggle::make('con_total')
                    ->label('Mostrar el total («3 / 10»)')
                    ->visible(fn (Get $get) => $get('herramienta') === 'numerar'),
                TextInput::make('texto_marca')
                    ->label('Texto de la marca de agua')
                    ->maxLength(60)
                    ->visible(fn (Get $get) => $get('herramienta') === 'marca_agua')
                    ->required(fn (Get $get) => $get('herramienta') === 'marca_agua'),
                Select::make('opacidad')
                    ->label('Intensidad')
                    ->options([10 => 'Muy suave', 20 => 'Suave', 35 => 'Media', 50 => 'Fuerte'])
                    ->visible(fn (Get $get) => $get('herramienta') === 'marca_agua'),
                TextInput::make('clave')
                    ->label(fn (Get $get) => $get('herramienta') === 'proteger' ? 'Contraseña nueva' : 'Contraseña actual del PDF')
                    ->password()
                    ->revealable()
                    ->minLength(fn (Get $get) => $get('herramienta') === 'proteger' ? 4 : 1)
                    ->maxLength(100)
                    ->helperText(fn (Get $get) => $get('herramienta') === 'proteger' ? 'Se pedirá para abrir el PDF. Guárdala: no se puede recuperar.' : null)
                    ->visible(fn (Get $get) => in_array($get('herramienta'), ['proteger', 'desbloquear'], true))
                    ->required(fn (Get $get) => in_array($get('herramienta'), ['proteger', 'desbloquear'], true)),
                Select::make('grados')
                    ->label('Rotación')
                    ->options([90 => '90° a la derecha', 180 => '180°', 270 => '90° a la izquierda'])
                    ->visible(fn (Get $get) => $get('herramienta') === 'rotar'),
                TextInput::make('paginas_por_parte')
                    ->label('Páginas por parte')
                    ->numeric()->minValue(1)->maxValue(1000)
                    ->visible(fn (Get $get) => $get('herramienta') === 'dividir'),
            ]);
    }

    public function procesar(Herramientas $herramientas): void
    {
        $datos = $this->form->getState();
        $tipo = $datos['herramienta'];
        $archivos = array_values(array_filter((array) ($datos['archivos'] ?? [])));
        $subidas = array_values(array_filter((array) ($datos['subidas'] ?? []), fn ($f) => $f instanceof TemporaryUploadedFile));
        $imagenes = array_values(array_filter((array) ($datos['imagenes'] ?? []), fn ($f) => $f instanceof TemporaryUploadedFile));

        try {
            if (! in_array($tipo, self::SIN_QPDF, true) && ! $herramientas->disponibles()['qpdf']) {
                throw new RuntimeException('qpdf no está instalado en el servidor: esta herramienta no está disponible.');
            }

            if ($tipo === 'imagenes') {
                if (! $imagenes) {
                    throw ValidationException::withMessages(['datos.imagenes' => 'Sube al menos una imagen.']);
                }
                $this->encolar($tipo, ['tamano_pagina' => $datos['tamano_pagina'] ?? 'a4', 'nombre_base' => pathinfo($imagenes[0]->getClientOriginalName(), PATHINFO_FILENAME).'.pdf'], [], $imagenes, 'imagen');

                return;
            }

            foreach ($archivos as $id) {
                $archivo = Archivos::find($id);
                if (! $archivo || ! app(AccesoDocumental::class)->puedeDescargar($this->user(), $archivo) || $archivo->extension !== 'pdf') {
                    throw new AuthorizationException('Uno de los documentos seleccionados no está disponible para ti.');
                }
            }

            $total = count($archivos) + count($subidas);
            if ($total === 0) {
                throw ValidationException::withMessages(['datos.archivos' => 'Selecciona o sube un PDF.']);
            }
            if ($tipo === 'unir' && $total < 2) {
                throw ValidationException::withMessages(['datos.archivos' => 'Para unir necesitas al menos dos PDF.']);
            }
            if ($tipo !== 'unir' && $total > 1) {
                throw ValidationException::withMessages(['datos.archivos' => 'Esta herramienta trabaja con un solo PDF.']);
            }
            if (in_array($tipo, ['extraer', 'rotar', 'eliminar'], true) && trim((string) ($datos['rango'] ?? '')) !== '') {
                $herramientas->rangoValido($datos['rango']);
            }

            if ($tipo === 'metadatos') {
                $this->metadatos = $this->leerMetadatos($herramientas, $archivos[0] ?? null, $subidas[0] ?? null);

                return;
            }

            $this->encolar($tipo, [
                'archivos' => $archivos,
                'nivel' => $datos['nivel'] ?? 'media',
                'rango' => $datos['rango'] ?? null,
                'grados' => (int) ($datos['grados'] ?? 90),
                'paginas_por_parte' => (int) ($datos['paginas_por_parte'] ?? 1),
                'posicion' => $datos['posicion'] ?? 'abajo-centro',
                'con_total' => (bool) ($datos['con_total'] ?? true),
                'texto_marca' => $datos['texto_marca'] ?? null,
                'opacidad' => (int) ($datos['opacidad'] ?? 20),
                // La contraseña nunca se guarda en claro; el trabajo la borra al terminar
                'clave' => filled($datos['clave'] ?? null) ? Crypt::encryptString($datos['clave']) : null,
                'nombre_base' => $archivos ? Archivos::find($archivos[0])->nombre : $subidas[0]->getClientOriginalName(),
            ], $archivos, $subidas, 'subida');
        } catch (ValidationException $e) {
            throw $e;
        } catch (AuthorizationException|RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    /** @param list<TemporaryUploadedFile> $subidas */
    private function encolar(string $tipo, array $parametros, array $archivos, array $subidas, string $prefijo): void
    {
        $tarea = TareaDocumental::create(['user_id' => $this->user()->id, 'tipo' => $tipo, 'parametros' => array_filter($parametros, fn ($v) => $v !== null)]);

        // Las subidas se guardan en la carpeta privada de la tarea (fuera de public/)
        $rutas = [];
        foreach ($subidas as $i => $subida) {
            $extension = $prefijo === 'imagen' ? strtolower($subida->getClientOriginalExtension() ?: 'jpg') : 'pdf';
            $rutas[] = $subida->storeAs($tarea->directorio(), sprintf('%s-%02d.%s', $prefijo, $i, preg_replace('/[^a-z]/', '', $extension)), 'local');
        }
        if ($rutas) {
            $tarea->update(['parametros' => [...$tarea->parametros, $prefijo === 'imagen' ? 'imagenes' : 'subidas' => $rutas]]);
        }

        ProcesarPdfJob::dispatch($tarea->id);

        $this->form->fill(['herramienta' => $tipo, ...self::VALORES_INICIALES]);
        Notification::make()->success()->title('Procesando…')->body('El resultado aparecerá en «Mis resultados».')->send();
    }

    public function guardarAction(): Action
    {
        return Action::make('guardar')
            ->label('Guardar en carpeta')
            ->icon('heroicon-m-folder-arrow-down')
            ->color('gray')
            ->size('sm')
            ->modalWidth('lg')
            ->form([
                Select::make('carpeta')->label('Carpeta de destino')->searchable()->required()
                    ->options(fn () => $this->carpetasEditables(''))
                    ->getSearchResultsUsing(fn (string $search) => $this->carpetasEditables($search)),
            ])
            ->action(function (array $data, array $arguments) {
                $tarea = $this->tareaPropia($arguments['tarea'] ?? '');
                $carpeta = Carpeta::find($data['carpeta']);
                if (! $tarea || ! $carpeta || ! str_ends_with($tarea->resultado['nombre'], '.pdf')) {
                    Notification::make()->danger()->title('El resultado ya no está disponible.')->send();

                    return;
                }

                try {
                    [$archivo] = app(GestorArchivos::class)->subir($this->user(), $carpeta, Storage::disk('local')->path($tarea->resultado['ruta']), $tarea->resultado['nombre'], 'Generado con Herramientas PDF');
                    Notification::make()->success()->title("Guardado como «{$archivo->nombre}» en «{$carpeta->nombre}»")->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage())->send();
                }
            });
    }

    public function reemplazarAction(): Action
    {
        return Action::make('reemplazar')
            ->label('Guardar como nueva versión del original')
            ->icon('heroicon-m-arrow-path')
            ->color('gray')
            ->size('sm')
            ->requiresConfirmation()
            ->modalDescription('El original se conserva en el historial de versiones y se puede restaurar en cualquier momento.')
            ->action(function (array $arguments) {
                $tarea = $this->tareaPropia($arguments['tarea'] ?? '');
                $archivo = Archivos::find($tarea?->parametros['archivos'][0] ?? 0);
                if (! $tarea || ! $archivo) {
                    Notification::make()->danger()->title('El resultado ya no está disponible.')->send();

                    return;
                }

                try {
                    $version = app(GestorArchivos::class)->nuevaVersion($this->user(), $archivo, Storage::disk('local')->path($tarea->resultado['ruta']), $archivo->nombre, 'Versión '.$tarea->tipo.' con Herramientas PDF');
                    Notification::make()->success()->title("Se creó la versión {$version->numero} de «{$archivo->nombre}»")->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage())->send();
                }
            });
    }

    protected function getViewData(): array
    {
        $tareas = TareaDocumental::where('user_id', $this->user()->id)->latest('id')->limit(15)->get();
        $acceso = app(AccesoDocumental::class);

        return [
            'tareas' => $tareas,
            'enCurso' => $tareas->contains(fn ($t) => in_array($t->estado, ['pendiente', 'procesando'], true)),
            'disponibles' => app(Herramientas::class)->disponibles(),
            'puedeReemplazar' => $tareas->mapWithKeys(function (TareaDocumental $t) use ($acceso) {
                $ids = $t->parametros['archivos'] ?? [];
                $archivo = count($ids) === 1 && empty($t->parametros['subidas']) && in_array($t->tipo, ['comprimir', 'rotar', 'numerar', 'marca_agua', 'eliminar', 'desbloquear'], true) ? Archivos::find($ids[0]) : null;

                return [$t->id => $archivo && $acceso->puedeEditarArchivo($this->user(), $archivo)];
            }),
        ];
    }

    private function leerMetadatos(Herramientas $herramientas, ?int $archivoId, ?TemporaryUploadedFile $subida): array
    {
        $dir = storage_path('app/tareas/meta-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($dir, 0700);

        try {
            $ruta = $dir.'/doc.pdf';
            if ($archivoId) {
                $archivo = Archivos::find($archivoId);
                app(AlmacenDocumental::class)->copiarATemporal($archivo->versionActual, $ruta);
                $nombre = $archivo->nombre;
            } else {
                copy($subida->getRealPath(), $ruta);
                $nombre = $subida->getClientOriginalName();
            }

            return ['nombre' => $nombre] + $herramientas->metadatos($ruta);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    private function pdfsDisponibles(string $buscar): array
    {
        return Archivos::visiblePara($this->user())
            ->where('archivos.extension', 'pdf')
            ->when($buscar !== '', fn ($q) => $q->where('archivos.nombre', 'like', '%'.addcslashes($buscar, '%_\\').'%'))
            ->orderByDesc('archivos.updated_at')
            ->limit(50)
            ->pluck('nombre', 'archivos.id')
            ->all();
    }

    private function carpetasEditables(string $buscar): array
    {
        $acceso = app(AccesoDocumental::class);

        return Carpeta::visiblePara($this->user())
            ->when($buscar !== '', fn ($q) => $q->where('nombre', 'like', '%'.addcslashes($buscar, '%_\\').'%'))
            ->orderBy('nombre')->limit(50)->get()
            ->filter(fn (Carpeta $c) => $acceso->puedeEditarEn($this->user(), $c))
            ->mapWithKeys(fn (Carpeta $c) => [$c->id => $c->ancestrosYYo()->pluck('nombre')->implode(' / ')])
            ->all();
    }

    private function tareaPropia(string $uuid): ?TareaDocumental
    {
        $tarea = TareaDocumental::where('uuid', $uuid)->where('user_id', $this->user()->id)->where('estado', 'completada')->first();

        return $tarea && Storage::disk('local')->exists($tarea->resultado['ruta'] ?? '') ? $tarea : null;
    }

    private function user(): User
    {
        return auth()->user();
    }
}
