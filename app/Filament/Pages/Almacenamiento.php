<?php

namespace App\Filament\Pages;

use App\Services\Documentos\EstadisticasDocumentales;
use Filament\Actions\Action;
use Filament\Pages\Page;

class Almacenamiento extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?string $navigationLabel = 'Almacenamiento';

    protected static ?string $title = 'Uso del almacenamiento';

    protected static ?string $navigationGroup = 'Administración';

    protected static ?int $navigationSort = -1;

    protected static string $view = 'filament.pages.almacenamiento';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->esAdmin();
    }

    public function getSubheading(): ?string
    {
        return 'Calculado con los registros de la base de datos, sin consultar el bucket (no genera costos en Firebase).';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recalcular')
                ->label('Recalcular')
                ->icon('heroicon-m-arrow-path')
                ->color('gray')
                ->action(fn () => app(EstadisticasDocumentales::class)->resumen(true)),
        ];
    }

    protected function getViewData(): array
    {
        return ['r' => app(EstadisticasDocumentales::class)->resumen()];
    }

    public static function bytes(int|float $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unidad) {
            if ($bytes < 1024 || $unidad === 'TB') {
                return ($unidad === 'B' ? (int) $bytes : number_format($bytes, $bytes < 10 ? 2 : 1, ',', '.')).' '.$unidad;
            }
            $bytes /= 1024;
        }

        return '';
    }
}
