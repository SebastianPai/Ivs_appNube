<x-filament-panels::page>
    @php
        $b = fn ($n) => \App\Filament\Pages\Almacenamiento::bytes($n);
        $nombresTipo = ['pdf' => 'PDF', 'imagen' => 'Imágenes', 'word' => 'Word', 'excel' => 'Excel', 'powerpoint' => 'PowerPoint', 'comprimido' => 'Comprimidos', 'texto' => 'Texto', 'video' => 'Videos', 'audio' => 'Audio', 'otro' => 'Otros'];
        $maxTipo = max(1, collect($r['por_tipo'])->max('bytes') ?? 1);
        $maxCarpeta = max(1, collect($r['carpetas_pesadas'])->max('bytes') ?? 1);
        $maxMes = max(1, collect($r['meses'])->max('total'));
        $partes = array_filter([
            ['Versiones actuales', $r['bytes_actuales'], 'var(--ivsa-1)'],
            ['Historial de versiones', $r['bytes_historial'], 'var(--ivsa-2)'],
            ['Papelera', $r['bytes_papelera'], 'var(--ivsa-3)'],
            ['Originales sin importar', $r['bytes_sin_importar'], 'var(--ivsa-4)'],
        ], fn ($p) => $p[1] > 0);
        $totalPartes = max(1, array_sum(array_column($partes, 1)));
    @endphp

    <div class="ivsa">
        <div class="ivsa-tarjetas">
            <div class="ivsa-tarjeta destacada">
                <span>Espacio ocupado</span>
                <strong>{{ $b($r['bytes_ocupados']) }}</strong>
                <small>{{ number_format($r['objetos_fisicos'] + $r['originales_sin_importar'], 0, ',', '.') }} objetos en el almacenamiento</small>
            </div>
            <div class="ivsa-tarjeta">
                <span>Archivos</span>
                <strong>{{ number_format($r['archivos'], 0, ',', '.') }}</strong>
                <small>{{ number_format($r['versiones'], 0, ',', '.') }} versiones · {{ number_format($r['carpetas'], 0, ',', '.') }} carpetas ({{ $r['carpetas_privadas'] }} privadas)</small>
            </div>
            <div class="ivsa-tarjeta">
                <span>Ahorro por compresión</span>
                <strong>{{ $b($r['ahorro_compresion']['bytes']) }}</strong>
                <small>{{ $r['ahorro_compresion']['archivos'] }} PDF comprimidos al subir</small>
            </div>
            <div class="ivsa-tarjeta">
                <span>Costo estimado de almacenamiento</span>
                <strong>US$ {{ number_format($r['costo_mensual_usd'], 2, ',', '.') }}<small>/mes</small></strong>
                <small>Solo almacenamiento a US$ {{ config('documentos.precio_gb_mes_usd') }}/GB. No incluye descargas ni operaciones.</small>
            </div>
        </div>

        <x-filament::section heading="¿En qué se usa el espacio?">
            <div class="ivsa-barra-apilada" role="img" aria-label="Distribución del espacio">
                @foreach ($partes as [$etiqueta, $valor, $color])
                    <span style="width: {{ max(1, $valor / $totalPartes * 100) }}%; background: {{ $color }}" title="{{ $etiqueta }}: {{ $b($valor) }}"></span>
                @endforeach
            </div>
            <ul class="ivsa-leyenda">
                @foreach ($partes as [$etiqueta, $valor, $color])
                    <li><i style="background: {{ $color }}"></i>{{ $etiqueta }} <strong>{{ $b($valor) }}</strong></li>
                @endforeach
            </ul>
            @if ($r['archivos_papelera'] || $r['pendientes_revision'] || $r['originales_sin_importar'])
                <p class="ivsa-nota">
                    @if ($r['archivos_papelera']) {{ $r['archivos_papelera'] }} archivo(s) en la papelera siguen ocupando espacio hasta eliminarlos definitivamente. @endif
                    @if ($r['originales_sin_importar']) {{ $r['originales_sin_importar'] }} original(es) inventariados aún sin importar. @endif
                    @if ($r['pendientes_revision']) {{ $r['pendientes_revision'] }} archivo(s) pendientes de revisión. @endif
                </p>
            @endif
        </x-filament::section>

        <div class="ivsa-columnas">
            <x-filament::section heading="Por tipo de archivo">
                @forelse ($r['por_tipo'] as $tipo => $d)
                    <div class="ivsa-fila">
                        <span>{{ $nombresTipo[$tipo] ?? $tipo }} <small>{{ number_format($d['total'], 0, ',', '.') }}</small></span>
                        <div class="ivsa-barra"><i style="width: {{ max(1, $d['bytes'] / $maxTipo * 100) }}%"></i></div>
                        <strong>{{ $b($d['bytes']) }}</strong>
                    </div>
                @empty
                    <p class="ivsa-nota">Todavía no hay archivos.</p>
                @endforelse
            </x-filament::section>

            <x-filament::section heading="Carpetas que más ocupan">
                @forelse ($r['carpetas_pesadas'] as $c)
                    <div class="ivsa-fila">
                        <span>@if ($c['privada'])🔒 @endif{{ $c['nombre'] }} <small>{{ number_format($c['total'], 0, ',', '.') }}</small></span>
                        <div class="ivsa-barra"><i style="width: {{ max(1, $c['bytes'] / $maxCarpeta * 100) }}%"></i></div>
                        <strong>{{ $b($c['bytes']) }}</strong>
                    </div>
                @empty
                    <p class="ivsa-nota">Todavía no hay carpetas con archivos.</p>
                @endforelse
            </x-filament::section>

            <x-filament::section heading="Archivos subidos por mes">
                <div class="ivsa-meses">
                    @foreach ($r['meses'] as $m)
                        <div title="{{ $m['etiqueta'] }}: {{ $m['total'] }}">
                            <span class="ivsa-valor">{{ $m['total'] }}</span>
                            <i style="height: {{ max(2, $m['total'] / $maxMes * 100) }}%"></i>
                            <small>{{ $m['etiqueta'] }}</small>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>

            <x-filament::section heading="Usuarios con más espacio">
                @forelse ($r['usuarios'] as $u)
                    <div class="ivsa-fila">
                        <span>{{ $u['nombre'] }} <small>{{ number_format($u['total'], 0, ',', '.') }}</small></span>
                        <strong>{{ $b($u['bytes']) }}</strong>
                    </div>
                @empty
                    <p class="ivsa-nota">Sin datos.</p>
                @endforelse
            </x-filament::section>
        </div>

        <p class="ivsa-nota">Actualizado {{ $r['calculado_en']->diffForHumans() }} (se recalcula cada 5 minutos).</p>
    </div>
</x-filament-panels::page>
