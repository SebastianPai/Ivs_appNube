<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Althinect\FilamentSpatieRolesPermissions\FilamentSpatieRolesPermissionsPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('inicio')
            ->path('inicio')
            // Mismo diseño de ingreso que app.ivscertificaciones.com
            ->login(\App\Filament\Pages\Auth\Login::class)
            ->colors([
                // Paleta de la marca, igual a app.ivscertificaciones.com: rojo IVS suavizado
                'primary' => [
                    50 => '251, 244, 244',
                    100 => '245, 230, 230',
                    200 => '236, 208, 208',
                    300 => '221, 174, 175',
                    400 => '201, 131, 134',
                    500 => '181, 99, 106',
                    600 => '165, 72, 78',
                    700 => '139, 60, 66',
                    800 => '115, 52, 58',
                    900 => '97, 47, 52',
                    950 => '54, 23, 25',
                ],
                // Paleta para personalizar carpetas (las claves coinciden con config/documentos.php)
                'ambar' => Color::Amber,
                'rojo' => Color::Red,
                'naranja' => Color::Orange,
                'verde' => Color::Green,
                'turquesa' => Color::Teal,
                'azul' => Color::Blue,
                'indigo' => Color::Indigo,
                'violeta' => Color::Violet,
                'rosa' => Color::Pink,
                'gris' => Color::Gray,
            ])
            ->favicon(asset('images/favicon-32.png'))
            ->brandName('IVS Certificaciones · Nube')
            ->brandLogo(fn () => view('filament.marca'))
            ->brandLogoHeight('2.5rem')
            // Menú en la barra superior, como en app.ivscertificaciones.com
            ->topNavigation()
            ->navigationGroups([
                NavigationGroup::make('Documentos'),
                NavigationGroup::make('Administración')->collapsed(false),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            // Sin tablero: la portada del panel es el gestor documental
            ->pages([])
            ->homeUrl(fn () => \App\Filament\Pages\Unidad::getUrl())
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            ->profile()
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => collect(['documentos', 'ivs-tema'])
                ->map(fn ($css) => '<link rel="stylesheet" href="'.asset("css/$css.css").'?v='.@filemtime(public_path("css/$css.css")).'">')
                ->implode(''))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            //->plugin(FilamentSpatieRolesPermissionsPlugin::make())
            ->authMiddleware([
                Authenticate::class,
                \App\Http\Middleware\ExigirCambioPassword::class,
            ], isPersistent: true);
    }
}
