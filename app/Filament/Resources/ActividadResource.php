<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActividadResource\Pages;
use App\Models\Actividad;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Historial de solo lectura: no hay formularios, edición ni borrado. */
class ActividadResource extends Resource
{
    protected static ?string $model = Actividad::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Administración';

    protected static ?string $navigationLabel = 'Historial de actividad';

    protected static ?string $modelLabel = 'actividad';

    protected static ?string $pluralModelLabel = 'Historial de actividad';

    protected static ?string $slug = 'actividad';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user && ($user->esAdmin() || $user->can('auditoria.ver'));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user:id,name'))
            ->columns([
                TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i:s')->sortable(),
                TextColumn::make('user.name')->label('Usuario')->placeholder('Sistema')->searchable(),
                TextColumn::make('accion')->label('Acción')->badge()->searchable()
                    ->color(fn (string $state) => match (true) {
                        str_contains($state, 'eliminado') || str_contains($state, 'papelera') => 'danger',
                        str_starts_with($state, 'recuperacion') => 'info',
                        str_contains($state, 'permisos') => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('sujeto_tipo')->label('Elemento')->formatStateUsing(fn ($state, $record) => $state ? "$state #{$record->sujeto_id}" : '—'),
                TextColumn::make('descripcion')->label('Descripción')->limit(80)->wrap()->searchable(),
                TextColumn::make('resultado')->label('Resultado')->badge()->color(fn ($state) => $state === 'ok' ? 'success' : 'danger'),
                TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('user_id')->label('Usuario')->searchable()->options(fn () => User::orderBy('name')->pluck('name', 'id')),
                SelectFilter::make('accion')->label('Acción')->options(fn () => Actividad::query()->distinct()->orderBy('accion')->pluck('accion', 'accion')),
                SelectFilter::make('sujeto_tipo')->label('Tipo de elemento')->options(['Archivos' => 'Archivos', 'Carpeta' => 'Carpetas', 'RecuperacionGrupo' => 'Recuperación', 'TareaDocumental' => 'Herramientas PDF']),
                Filter::make('fecha')->form([
                    DatePicker::make('desde')->label('Desde'),
                    DatePicker::make('hasta')->label('Hasta'),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['desde'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                    ->when($data['hasta'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),
            ])
            ->actions([
                Tables\Actions\Action::make('detalle')
                    ->label('Detalle')
                    ->icon('heroicon-m-eye')
                    ->color('gray')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->modalContent(fn (Actividad $record) => view('filament.recuperacion.actividad', ['actividad' => $record])),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActividades::route('/'),
        ];
    }
}
