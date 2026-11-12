<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['password'] = filled($data['password'] ?? null) ? $data['password'] : config('documentos.password_inicial');

        return $data;
    }

    protected function afterCreate(): void
    {
        // Siempre debe elegir su propia contraseña en el primer ingreso
        $this->record->forceFill(['debe_cambiar_password' => true])->save();
    }
}
