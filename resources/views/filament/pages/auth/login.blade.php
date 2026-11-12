{{-- Pantalla de ingreso, igual a la de app.ivscertificaciones.com. Estilos en public/css/ivs-tema.css. --}}
<div class="ivs-login">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=poppins:400,500,600&display=swap">

    {{-- Adornos del fondo --}}
    <div class="ivs-login__fondo" aria-hidden="true">
        <span class="ivs-login__curva"></span>
        <span class="ivs-login__circulo ivs-login__circulo--grande"></span>
        <span class="ivs-login__circulo ivs-login__circulo--chico"></span>
        <span class="ivs-login__punto ivs-login__punto--a"></span>
        <span class="ivs-login__punto ivs-login__punto--b"></span>
        <span class="ivs-login__triangulo ivs-login__triangulo--a"></span>
        <span class="ivs-login__triangulo ivs-login__triangulo--b"></span>
        <span class="ivs-login__cinta ivs-login__cinta--a"></span>
        <span class="ivs-login__cinta ivs-login__cinta--b"></span>
        <span class="ivs-login__marco"></span>
        <span class="ivs-login__puntos ivs-login__puntos--arriba"></span>
        <span class="ivs-login__puntos ivs-login__puntos--izq"></span>
        <span class="ivs-login__puntos ivs-login__puntos--abajo"></span>
    </div>

    <div class="ivs-login__tarjeta">
        <header class="ivs-login__cabecera">
            <span class="ivs-login__marca">
                <img src="{{ asset('images/logo-sm.png') }}" alt="" class="ivs-login__logo">
                <span><b>IVS</b> Certificaciones</span>
                <span class="ivs-login__app">Nube</span>
            </span>
        </header>

        <div class="ivs-login__cuerpo">
            <section class="ivs-login__form">
                <h1 class="ivs-login__titulo">{{ $this->getHeading() }}</h1>

                @if (filled($subheading = $this->getSubheading()))
                    <p class="ivs-login__subtitulo">{{ $subheading }}</p>
                @endif

                {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE, scopes: $this->getRenderHookScopes()) }}

                <x-filament-panels::form id="form" wire:submit="authenticate">
                    {{ $this->form }}

                    <x-filament-panels::form.actions
                        :actions="$this->getCachedFormActions()"
                        :full-width="$this->hasFullWidthFormActions()"
                        :alignment="$this->getFormActionsAlignment()"
                    />
                </x-filament-panels::form>

                {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, scopes: $this->getRenderHookScopes()) }}
            </section>

            <div class="ivs-login__ilustracion" aria-hidden="true">
                @include('filament.pages.auth.login-ilustracion')
            </div>
        </div>

        <footer class="ivs-login__pie">
            <span class="ivs-login__copy">© IVS Certificaciones {{ now()->year }}</span>
            <a class="ivs-login__otra" href="https://app.ivscertificaciones.com">Ir a la plataforma de certificaciones →</a>
        </footer>
    </div>
</div>
