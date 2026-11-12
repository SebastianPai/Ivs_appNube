<x-filament-panels::page>
    @php
        $formato = fn ($b) => $b >= 1048576 ? number_format($b / 1048576, 2, ',', '.').' MB' : number_format(($b ?? 0) / 1024, 1, ',', '.').' KB';
        $nombres = \App\Filament\Pages\HerramientasPdf::HERRAMIENTAS;
    @endphp


    <form wire:submit="procesar">
        <x-filament::section>
            {{ $this->form }}
            <div style="margin-top: 1rem; display: flex; gap: .75rem; align-items: center;">
                <x-filament::button type="submit" icon="heroicon-m-play" wire:loading.attr="disabled" wire:target="procesar">Procesar</x-filament::button>
                <span wire:loading wire:target="procesar" style="color: rgb(var(--gray-500)); font-size: .875rem;">Preparando…</span>
            </div>
        </x-filament::section>
    </form>

    @if ($metadatos)
        <x-filament::section heading="Metadatos de «{{ $metadatos['nombre'] }}»">
            <dl class="ivsd-propiedades" style="font-size: .875rem;">
                <dt>Páginas</dt><dd>{{ $metadatos['paginas'] }}</dd>
                <dt>Versión PDF</dt><dd>{{ $metadatos['version_pdf'] ?? '—' }}</dd>
                <dt>Tamaño</dt><dd>{{ $formato($metadatos['tamano']) }}</dd>
                <dt>Cifrado</dt><dd>{{ $metadatos['cifrado'] ? 'Sí' : 'No' }}</dd>
                <dt>Optimizado para web</dt><dd>{{ $metadatos['linealizado'] ? 'Sí' : 'No' }}</dd>
                @foreach ($metadatos['info'] ?? [] as $clave => $valor)
                    <dt>{{ $clave }}</dt><dd>{{ $valor }}</dd>
                @endforeach
            </dl>
        </x-filament::section>
    @endif

    <x-filament::section heading="Mis resultados" description="Los resultados se conservan 24 horas. El documento original nunca se modifica.">
        <div class="ivsd-tareas" @if ($enCurso) wire:poll.3s @endif>
            @forelse ($tareas as $tarea)
                <div class="ivsd-tarea" wire:key="tarea-{{ $tarea->id }}">
                    <div>
                        <strong>{{ $nombres[$tarea->tipo] ?? $tarea->tipo }}</strong>
                        · {{ $tarea->parametros['nombre_base'] ?? '' }}
                        <br>
                        <small>{{ $tarea->created_at->diffForHumans() }}</small>
                        @if ($tarea->estado === 'completada')
                            @php($r = $tarea->resultado)
                            <small>· {{ $formato($r['tamano_original']) }} → {{ $formato($r['tamano_final']) }}</small>
                            @if ($tarea->tipo === 'comprimir')
                                <small class="ivsd-reduccion {{ $r['reduccion'] <= 0 ? 'nula' : '' }}">
                                    · {{ $r['reduccion'] > 0 ? 'reducción del '.$r['reduccion'].' %' : 'sin reducción: el documento ya estaba optimizado' }}
                                </small>
                            @endif
                            @if ($r['paginas'])<small>· {{ $r['paginas'] }} páginas</small>@endif
                            @if ($r['partes'])<small>· {{ $r['partes'] }} partes</small>@endif
                        @elseif ($tarea->estado === 'error')
                            <small style="color: rgb(var(--danger-600));">· {{ $tarea->error }}</small>
                        @elseif ($tarea->estado === 'expirada')
                            <small>· expirado</small>
                        @else
                            <small>· {{ $tarea->estado === 'procesando' ? 'procesando…' : 'en cola…' }}</small>
                        @endif
                    </div>
                    <div class="ivsd-tarea-acciones">
                        @if ($tarea->estado === 'completada')
                            <x-filament::button size="sm" icon="heroicon-m-arrow-down-tray" tag="a" :href="route('documentos.tarea.resultado', $tarea)">Descargar</x-filament::button>
                            @if (str_ends_with($tarea->resultado['nombre'], '.pdf'))
                                {{ ($this->guardarAction)(['tarea' => $tarea->uuid]) }}
                            @endif
                            @if ($puedeReemplazar[$tarea->id] ?? false)
                                {{ ($this->reemplazarAction)(['tarea' => $tarea->uuid]) }}
                            @endif
                        @elseif (in_array($tarea->estado, ['pendiente', 'procesando'], true))
                            <x-filament::loading-indicator style="width: 1.25rem; height: 1.25rem;" />
                        @endif
                    </div>
                </div>
            @empty
                <p style="color: rgb(var(--gray-500)); font-size: .875rem;">Todavía no has procesado ningún documento.</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>
