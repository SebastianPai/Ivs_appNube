<?php

namespace App\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/** Cambio obligatorio de la contraseña inicial en el primer ingreso. */
class CambiarContrasena extends Page
{
    protected static ?string $slug = 'cambiar-contrasena';

    protected static ?string $title = 'Cambia tu contraseña';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.cambiar-contrasena';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('password')->label('Nueva contraseña')
                    ->password()->revealable()->required()
                    ->rule(Password::min(8))
                    ->notIn([config('documentos.password_inicial')])
                    ->validationMessages(['not_in' => 'Elige una contraseña distinta a la inicial.'])
                    ->same('password_confirmation'),
                TextInput::make('password_confirmation')->label('Confirmar contraseña')
                    ->password()->revealable()->required()->dehydrated(false),
            ])
            ->statePath('data');
    }

    public function guardar()
    {
        $datos = $this->form->getState();
        $user = auth()->user();

        $user->forceFill([
            'password' => Hash::make($datos['password']),
            'debe_cambiar_password' => false,
        ])->save();

        // AuthenticateSession compara el hash guardado en la sesión: se actualiza para no cerrar la sesión
        session()->put('password_hash_'.Filament::getAuthGuard(), $user->getAuthPassword());

        Notification::make()->title('Contraseña actualizada')->success()->send();

        return redirect()->to(Unidad::canAccess() ? Unidad::getUrl() : Filament::getUrl());
    }
}
