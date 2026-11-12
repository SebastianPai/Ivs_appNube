<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use App\Filament\Resources\RoleResource\RelationManagers;
use App\Models\Role;
use Filament\Forms;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Administración';

    protected static ?string $modelLabel = 'rol';

    protected static ?string $pluralModelLabel = 'Roles';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
                TextInput::make('name')->label('Nombre')->required()->maxLength(100)->unique(ignoreRecord: true),
                Forms\Components\Textarea::make('descripcion')
                    ->label('¿Para quién es este rol?')
                    ->placeholder('Ej.: Inspectores que solo consultan documentos de la unidad compartida.')
                    ->helperText('Se muestra al asignar roles a un usuario, para que quede claro qué hace cada uno.')
                    ->rows(2)
                    ->maxLength(500)
                    ->columnSpanFull(),
                Forms\Components\Hidden::make('guard_name')->default('web'),
                Forms\Components\CheckboxList::make('permissions')
                    ->label('Permisos')
                    ->relationship('permissions', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => \Database\Seeders\RolesYPermisosSeeder::PERMISOS[$record->name] ?? $record->name)
                    ->descriptions(fn () => \Spatie\Permission\Models\Permission::pluck('name', 'id')
                        ->map(fn ($nombre) => \Database\Seeders\RolesYPermisosSeeder::AYUDA_PERMISOS[$nombre] ?? '')->all())
                    ->helperText('Eliminar archivos queda reservado al rol Admin aunque se marque aquí: el servidor lo verifica por rol.')
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                //
                TextColumn::make('name')->label('Rol')->searchable()->weight('bold'),
                TextColumn::make('descripcion')->label('Qué puede hacer')->wrap()->color('gray'),
                TextColumn::make('permissions_count')->label('Permisos')->counts('permissions'),
                TextColumn::make('users_count')->label('Usuarios')->counts('users'),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
