<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('restablecerPassword')
                ->label('Restablecer contraseña')
                ->icon('heroicon-o-key')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription(fn () => 'La contraseña volverá a ser «'.config('documentos.password_inicial').'» y deberá cambiarla al ingresar.')
                ->action(function () {
                    $this->record->forceFill([
                        'password' => config('documentos.password_inicial'),
                        'debe_cambiar_password' => true,
                    ])->save();
                    \Filament\Notifications\Notification::make()->title('Contraseña restablecida')->success()->send();
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
