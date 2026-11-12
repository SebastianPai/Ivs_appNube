@foreach ($nodos as $nodo)
    <li role="treeitem" wire:key="arbol-{{ $nodo['id'] }}"
        @if ($nodo['tieneHijos']) aria-expanded="{{ $nodo['expandida'] ? 'true' : 'false' }}" @endif
        @if ($nodo['id'] === $actualId) aria-selected="true" @endif>
        <div class="ivsd-nodo {{ $nodo['id'] === $actualId ? 'actual' : '' }}"
            :class="{ 'destino': destinoActual === {{ $nodo['id'] }} }"
            x-on:dragover.prevent="destinoActual = {{ $nodo['id'] }}"
            x-on:dragleave="destinoActual = null"
            x-on:drop.prevent.stop="soltarEnCarpeta({{ $nodo['id'] }})">
            @if ($nodo['tieneHijos'])
                <button type="button" class="ivsd-nodo-flecha" wire:click="alternarRama({{ $nodo['id'] }})"
                    aria-label="{{ $nodo['expandida'] ? 'Contraer' : 'Expandir' }} {{ $nodo['nombre'] }}">
                    <x-filament::icon :icon="$nodo['expandida'] ? 'heroicon-m-chevron-down' : 'heroicon-m-chevron-right'" class="ivsd-i-sm" />
                </button>
            @else
                <span class="ivsd-nodo-flecha" aria-hidden="true"></span>
            @endif
            <button type="button" class="ivsd-nodo-nombre" wire:click="abrirCarpeta({{ $nodo['id'] }})" x-on:click="lateralAbierta = false" title="{{ $nodo['nombre'] }}">
                <x-filament::icon :icon="$nodo['restringida'] && $nodo['icono'] === 'heroicon-s-folder' ? 'heroicon-s-lock-closed' : $nodo['icono']"
                    class="ivsd-i-sm {{ $nodo['restringida'] ? 'ivsd-restringida' : 'ivsd-carpeta' }}"
                    :style="$nodo['color'] ? 'color: '.$nodo['color'] : null" />
                <span>{{ $nodo['nombre'] }}</span>
            </button>
        </div>
        @if ($nodo['expandida'] && count($nodo['hijos']))
            <ul role="group">
                @include('filament.pages.partials.arbol-nodo', ['nodos' => $nodo['hijos'], 'actualId' => $actualId])
            </ul>
        @endif
    </li>
@endforeach
