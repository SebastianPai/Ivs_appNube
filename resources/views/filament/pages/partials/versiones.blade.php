@php
    $formato = fn ($b) => $b >= 1048576 ? number_format($b / 1048576, 2, ',', '.').' MB' : number_format($b / 1024, 1, ',', '.').' KB';
    $lista = $versiones->values();
@endphp
<div class="ivsd-versiones">
    @if ($puedeEditar)
        <div class="ivsd-versiones-cabecera">
            <p>La versión actual se marca en rojo. Restaurar una versión crea una nueva versión con ese contenido; no se pierde ninguna.</p>
            <x-filament::button size="sm" icon="heroicon-m-arrow-up-tray" wire:click="replaceMountedAction('nuevaVersion', { clave: 'a:{{ $archivo->id }}' })">Subir nueva versión</x-filament::button>
        </div>
    @endif

    <table>
        <thead>
            <tr>
                <th scope="col">Versión</th>
                <th scope="col">Fecha</th>
                <th scope="col">Autor</th>
                <th scope="col">Tamaño</th>
                <th scope="col">Cambio</th>
                <th scope="col">Comentario</th>
                <th scope="col"><span class="sr-only">Acciones</span></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lista as $i => $v)
                @php($previa = $lista[$i + 1] ?? null)
                <tr @class(['actual' => $v->id === $archivo->version_actual_id])>
                    <td><strong>v{{ $v->numero }}</strong>@if ($v->id === $archivo->version_actual_id) <span class="ivsd-insignia">actual</span>@endif</td>
                    <td title="{{ $v->created_at?->format('d/m/Y H:i:s') }}">{{ $v->created_at?->format('d/m/Y H:i') }}</td>
                    <td>{{ $v->creador?->name ?? '—' }}</td>
                    <td>{{ $formato($v->tamano) }}</td>
                    <td class="ivsd-tenue">
                        @if (! $previa)
                            Original
                        @elseif ($v->hash_md5 && $v->hash_md5 === $previa->hash_md5)
                            Mismo contenido que v{{ $previa->numero }}
                        @else
                            {{ $v->tamano >= $previa->tamano ? '+' : '−' }}{{ $formato(abs($v->tamano - $previa->tamano)) }}
                            @if ($v->mime !== $previa->mime) · tipo {{ $v->mime }} @endif
                        @endif
                        @if ($v->restauradaDesde) · restaurada de v{{ $v->restauradaDesde->numero }} @endif
                    </td>
                    <td>{{ $v->comentario }}</td>
                    <td class="ivsd-versiones-acciones">
                        @if ($puedeDescargar)
                            <x-filament::icon-button tag="a" icon="heroicon-m-arrow-down-tray" color="gray" size="sm" :href="route('documentos.descargar', ['archivo' => $archivo->id, 'version' => $v->id])" label="Descargar v{{ $v->numero }}" />
                        @endif
                        @if ($puedeEditar && $v->id !== $archivo->version_actual_id)
                            <x-filament::button size="xs" color="gray" wire:click="restaurarVersion({{ $v->id }})" wire:confirm="¿Restaurar la versión {{ $v->numero }}? Se creará una versión nueva con su contenido.">Restaurar</x-filament::button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
