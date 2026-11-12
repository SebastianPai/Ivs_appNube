<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\Select;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Administración';

    protected static ?string $modelLabel = 'usuario';

    protected static ?string $pluralModelLabel = 'Usuarios';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
                TextInput::make('name')->label('Nombre')->required()->maxLength(255),
                TextInput::make('email')->label('Correo')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                // Al crear se propone la contraseña inicial; el usuario debe cambiarla en su primer ingreso.
                // Al editar, la contraseña solo cambia si se escribe una nueva.
                TextInput::make('password')->label('Contraseña')->password()->revealable()
                    ->default(fn () => config('documentos.password_inicial'))
                    ->required(fn (string $operation) => $operation === 'create')
                    ->minLength(8)
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->helperText(fn (string $operation) => $operation === 'edit'
                        ? 'Déjala vacía para conservar la actual.'
                        : 'Contraseña inicial. Se le pedirá cambiarla en su primer ingreso.'),
                Forms\Components\CheckboxList::make('roles')
                    ->label('¿Qué puede hacer esta persona?')
                    ->relationship('roles', 'name')
                    ->descriptions(fn () => \App\Models\Role::pluck('descripcion', 'id')->map(fn ($d) => $d ?? '')->all())
                    ->helperText('Marca al menos un rol. Usuario = solo la unidad compartida · Gerencia = compartida y privada · Admin = todo, incluidos usuarios y papelera.')
                    ->required()
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                //
                TextColumn::make('name')->label('Nombre')->searchable(),
                TextColumn::make('email')->label('Correo')->searchable(),
                TextColumn::make('roles.name')->label('Roles')->badge(),
                Tables\Columns\IconColumn::make('debe_cambiar_password')->label('Contraseña inicial')->boolean()
                    ->trueIcon('heroicon-o-key')->falseIcon('heroicon-o-check-circle')
                    ->trueColor('warning')->falseColor('success'),

            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
