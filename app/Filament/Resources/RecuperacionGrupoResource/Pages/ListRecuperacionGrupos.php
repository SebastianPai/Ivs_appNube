<?php

namespace App\Filament\Resources\RecuperacionGrupoResource\Pages;

use App\Filament\Resources\RecuperacionGrupoResource;
use App\Jobs\InventariarAlmacenamientoJob;
use App\Services\Documentos\Recuperacion\AnalizadorRecuperacion;
use App\Services\Documentos\Recuperacion\InformeRecuperacion;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class ListRecuperacionGrupos extends ListRecords
{
    protected static string $resource = RecuperacionGrupoResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        $r = app(InformeRecuperacion::class)->resumen();
        $e = $r['ultima_ejecucion'];
        $ultimo = $e
            ? "Último inventario: «{$e->disco}» · {$e->estado} · {$e->procesados} objetos · ".$e->created_at->diffForHumans()
                .($e->estado === 'error' ? ' · '.e(mb_substr((string) $e->error, 0, 160)) : '')
            : 'Todavía no se ha ejecutado ningún inventario.';

        return new HtmlString(sprintf(
            '%s<br><strong>%d</strong> objetos (%s MB) · <strong>%d</strong> agrupados · <strong>%d</strong> sin clasificar · <strong>%d</strong> duplicados · <strong>%d</strong> importados · <strong>%d</strong> inaccesibles',
            e($ultimo), $r['objetos'], number_format($r['bytes'] / 1048576, 1, ',', '.'), $r['agrupados'], $r['sin_clasificar'], $r['duplicados'], $r['importados'], $r['inaccesibles']
        ));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('inventariar')
                ->label('Inventariar almacenamiento')
                ->icon('heroicon-m-magnifying-glass-circle')
                ->modalDescription('Lista los objetos del almacenamiento y guarda sus metadatos. Es de SOLO LECTURA: no mueve, renombra ni borra nada. En la nube cuesta una operación de listado por cada 1.000 objetos. Se ejecuta en segundo plano (requiere «php artisan queue:work»).')
                ->form([
                    Select::make('disco')->label('Disco')->required()
                        ->default(config('documentos.disco_recuperacion'))
                        ->options(collect(config('filesystems.disks'))->filter(fn ($d, $k) => in_array($d['driver'] ?? '', ['gcs', 'local'], true) && ! in_array($k, ['public'], true))->mapWithKeys(fn ($d, $k) => [$k => "$k ({$d['driver']})"])),
                    TextInput::make('prefijo')->label('Solo bajo esta ruta (opcional)')->maxLength(500),
                    Toggle::make('reanudar')->label('Reanudar el último inventario interrumpido')->default(true),
                ])
                ->action(function (array $data) {
                    InventariarAlmacenamientoJob::dispatch($data['disco'], $data['prefijo'] ?: null, auth()->id(), (bool) $data['reanudar']);
                    Notification::make()->success()->title('Inventario en cola')->body('Actualiza esta página para ver el progreso.')->send();
                }),
            Action::make('analizar')
                ->label('Analizar y proponer')
                ->icon('heroicon-m-sparkles')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Agrupa los objetos inventariados por evidencias (carpetas físicas, identificadores numéricos) y detecta duplicados. Solo crea propuestas; no aplica nada.')
                ->action(function () {
                    $r = app(AnalizadorRecuperacion::class)->analizar();
                    Notification::make()->success()->title("Propuestas: {$r['grupos']} · sin clasificar: {$r['sin_clasificar']} · duplicados: {$r['duplicados']}")->send();
                }),
            Action::make('informe')
                ->label('Descargar informe')
                ->icon('heroicon-m-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $rutas = app(InformeRecuperacion::class)->generar();

                    return Storage::disk('local')->download($rutas['markdown']);
                }),
            Action::make('csv')
                ->label('Inventario CSV')
                ->icon('heroicon-m-table-cells')
                ->color('gray')
                ->action(fn () => Storage::disk('local')->download(app(InformeRecuperacion::class)->generar()['csv'])),
        ];
    }
}
