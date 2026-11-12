<?php

namespace App\Filament\Pages\Auth;

use Filament\Forms\Components\Component;
use Filament\Pages\Auth\Login as BaseLogin;
use Filament\Support\Enums\Alignment;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Pantalla de ingreso con el mismo diseño de app.ivscertificaciones.com: tarjeta con el formulario
 * a la izquierda y una ilustración a la derecha. La lógica (límite de intentos, recordar sesión) es la de Filament.
 */
class Login extends BaseLogin
{
    protected static string $view = 'filament.pages.auth.login';

    protected static string $layout = 'filament.layouts.login';

    public function getHeading(): string|Htmlable
    {
        return 'Ingresa a la nube';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Gestor documental de IVS Certificaciones';
    }

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()
            ->hiddenLabel()
            ->placeholder('Correo electrónico');
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->hiddenLabel()
            ->hint(null)
            ->placeholder('Contraseña');
    }

    protected function getFormActions(): array
    {
        return [
            $this->getAuthenticateFormAction()->label('Ingresar'),
        ];
    }

    public function getFormActionsAlignment(): string|Alignment
    {
        return Alignment::End;
    }

    protected function hasFullWidthFormActions(): bool
    {
        return false;
    }
}
