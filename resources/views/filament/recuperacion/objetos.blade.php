<div class="ivsd-versiones">
    <table>
        <thead>
            <tr>
                <th scope="col">Objeto en el almacenamiento</th>
                <th scope="col">Tamaño</th>
                <th scope="col">Modificado</th>
                <th scope="col">Estado</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($objetos as $o)
                <tr>
                    <td class="ivsd-mono">
                        {{ $o->objeto }}
                        @if ($o->duplicadoDe)<br><span class="ivsd-tenue">Idéntico a {{ $o->duplicadoDe->objeto }}</span>@endif
                    </td>
                    <td>{{ number_format($o->tamano / 1024, 1, ',', '.') }} KB</td>
                    <td>{{ $o->modificado_en?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td>{{ $o->estado }}@if ($o->archivo_id) · #{{ $o->archivo_id }}@endif</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @if ($objetos->count() >= 500)
        <p class="ivsd-tenue">Se muestran los primeros 500. El CSV del inventario contiene la lista completa.</p>
    @endif
</div>
