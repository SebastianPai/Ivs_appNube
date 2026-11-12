<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RecuperacionGrupoResource\Pages;
use App\Models\Carpeta;
use App\Models\RecuperacionGrupo;
use App\Services\Documentos\AccesoDocumental;
use App\Services\Documentos\Recuperacion\ImportadorRecuperacion;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Throwable;

class RecuperacionGrupoResource extends Resource
{
    protected static ?string $model = RecuperacionGrupo::class;

    protected static ?string $navigationIcon = 'heroicon-o-lifebuoy';

    protected static ?string $navigationGroup = 'Administración';

    protected static ?string $navigationLabel = 'Recuperación documental';

    protected static ?string $modelLabel = 'propuesta de recuperación';

    protected static ?string $pluralModelLabel = 'Recuperación documental';

    protected static ?string $slug = 'recuperacion';

    public const TIPOS = [
        'expediente_inspeccion' => 'Expediente de inspección',
        'fotos_inspeccion' => 'Fotos de inspección',
        'categoria' => 'Categoría',
        'lote_subida' => 'Lote de subida',
        'carpeta_fisica' => 'Carpeta física',
        'patron_numerico' => 'Identificador compartido',
        'individual' => 'Identificador único',
        'sin_clasificar' => 'Sin clasificar',
    ];

    public static function canViewAny(): bool
    {
        return app(AccesoDocumental::class)->esAdmin(auth()->user());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('confianza', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('carpetaDestino:id,nombre'))
            ->columns([
                TextColumn::make('tipo')->label('Evidencia')->badge()
                    ->formatStateUsing(fn (string $state) => self::TIPOS[$state] ?? 'Sin clasificar')
                    ->color(fn (string $state) => match ($state) {
                        'carpeta_fisica', 'expediente_inspeccion' => 'success',
                        'patron_numerico', 'categoria', 'fotos_inspeccion' => 'info',
                        'individual', 'lote_subida' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('clave')->label('Clave')->searchable()->sortable()->limit(50)->tooltip(fn ($record) => $record->clave)->fontFamily('mono'),
                TextColumn::make('total')->label('Archivos')->numeric()->sortable(),
                TextColumn::make('confianza')->label('Confianza')->sortable()->suffix(' %')
                    ->color(fn (int $state) => $state >= 75 ? 'success' : ($state >= 50 ? 'warning' : 'danger')),
                TextColumn::make('motivo')->label('Motivo')->wrap()->limit(160)->color('gray'),
                TextColumn::make('estado')->label('Estado')->badge()
                    ->color(fn (string $state) => match ($state) { 'importado' => 'success', 'descartado' => 'gray', default => 'warning' }),
                TextColumn::make('carpetaDestino.nombre')->label('Destino')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('estado')->options(['propuesto' => 'Propuesto', 'importado' => 'Importado', 'descartado' => 'Descartado'])->default('propuesto'),
                SelectFilter::make('tipo')->label('Evidencia')->options(self::TIPOS),
            ])
            ->actions([
                Tables\Actions\Action::make('objetos')
                    ->label('Ver archivos')
                    ->icon('heroicon-m-eye')
                    ->color('gray')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->modalWidth('4xl')
                    ->modalContent(fn (RecuperacionGrupo $record) => view('filament.recuperacion.objetos', [
                        'objetos' => $record->objetos()->with('duplicadoDe:id,objeto')->orderBy('objeto')->limit(500)->get(),
                    ])),
                Tables\Actions\Action::make('importar')
                    ->label('Aprobar e importar')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->visible(fn (RecuperacionGrupo $record) => $record->estado === 'propuesto')
                    ->modalDescription('Se crearán registros que apuntan a los archivos originales del almacenamiento. Nada se mueve, copia ni borra en el bucket, y la importación se puede revertir.')
                    ->form(fn (RecuperacionGrupo $record) => [
                        Placeholder::make('aviso')->hiddenLabel()->content(new HtmlString(
                            '<strong>'.e($record->total).' archivo(s)</strong> · confianza '.e($record->confianza).' %<br><span style="color: rgb(var(--gray-500))">'.e($record->motivo).'</span>'
                        )),
                        Radio::make('unidad')->label('Unidad')->options(['compartida' => 'Compartida', 'privada' => 'Privada'])->default('compartida')->inline()->live(),
                        Select::make('padre')->label('Carpeta contenedora')->searchable()
                            ->placeholder('Raíz de la unidad')
                            ->options(fn (Get $get) => Carpeta::where('unidad', $get('unidad') ?? 'compartida')->orderBy('ruta')->limit(300)->get()
                                ->mapWithKeys(fn (Carpeta $c) => [$c->id => $c->ancestrosYYo()->pluck('nombre')->implode(' / ')])),
                        Toggle::make('crear_subcarpeta')->label('Crear una subcarpeta para este grupo')->default($record->tipo !== 'sin_clasificar')->live(),
                        TextInput::make('nombre')->label('Nombre de la subcarpeta')->default(basename($record->clave))->maxLength(200)
                            ->visible(fn (Get $get) => (bool) $get('crear_subcarpeta'))->required(fn (Get $get) => (bool) $get('crear_subcarpeta')),
                    ])
                    ->action(function (RecuperacionGrupo $record, array $data) {
                        $padre = ! empty($data['padre']) ? Carpeta::find($data['padre']) : null;
                        if (! $padre && empty($data['crear_subcarpeta'])) {
                            Notification::make()->danger()->title('Los archivos deben quedar dentro de una carpeta: elige una o crea una subcarpeta.')->send();

                            return;
                        }

                        try {
                            $r = app(ImportadorRecuperacion::class)->importarGrupo(auth()->user(), $record, $padre, (bool) $data['crear_subcarpeta'], $data['nombre'] ?? null, $padre?->unidad ?? $data['unidad']);
                            Notification::make()
                                ->title("Importados: {$r['importados']} · ya vinculados: {$r['ya_vinculados']} · inaccesibles: {$r['inaccesibles']}")
                                ->color($r['inaccesibles'] ? 'warning' : 'success')
                                ->body($r['inaccesibles'] ? 'Los inaccesibles figuraban en el inventario pero no se pudieron leer; no se dan por recuperados.' : null)
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
                Tables\Actions\Action::make('descartar')
                    ->label('Descartar')
                    ->icon('heroicon-m-x-mark')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Marca la propuesta como descartada. Los archivos del almacenamiento no se tocan.')
                    ->visible(fn (RecuperacionGrupo $record) => $record->estado === 'propuesto')
                    ->action(fn (RecuperacionGrupo $record) => app(ImportadorRecuperacion::class)->descartar(auth()->user(), $record)),
                Tables\Actions\Action::make('revertir')
                    ->label('Revertir')
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Elimina los registros creados por esta importación (no los archivos del almacenamiento). Los archivos que ya tienen versiones nuevas se conservan.')
                    ->visible(fn (RecuperacionGrupo $record) => $record->estado === 'importado')
                    ->action(function (RecuperacionGrupo $record) {
                        $r = app(ImportadorRecuperacion::class)->revertir(auth()->user(), $record);
                        Notification::make()->success()->title("Revertidos: {$r['revertidos']} · conservados: {$r['conservados']}")->send();
                    }),
            ])
            ->emptyStateHeading('Sin propuestas')
            ->emptyStateDescription('Ejecuta el inventario (solo lectura) y el análisis para generar propuestas de recuperación.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRecuperacionGrupos::route('/'),
        ];
    }
}
