@php($clave = $item['clave'])
<x-filament::dropdown placement="bottom-end" teleport>
    <x-slot name="trigger">
        <x-filament::icon-button icon="heroicon-m-ellipsis-vertical" color="gray" size="sm" label="Acciones de {{ $item['nombre'] }}" />
    </x-slot>

    <x-filament::dropdown.list>
        @if ($item['enPapelera'])
            <x-filament::dropdown.list.item icon="heroicon-m-arrow-uturn-left" wire:click="mountAction('restaurar', { clave: '{{ $clave }}' })">Restaurar</x-filament::dropdown.list.item>
            <x-filament::dropdown.list.item icon="heroicon-m-x-circle" color="danger" wire:click="mountAction('purgar', { clave: '{{ $clave }}' })">Eliminar definitivamente</x-filament::dropdown.list.item>
        @elseif ($esCarpeta)
            <x-filament::dropdown.list.item icon="heroicon-m-folder-open" wire:click="abrirCarpeta({{ $item['id'] }})">Abrir</x-filament::dropdown.list.item>
            @if ($item['editable'])
                <x-filament::dropdown.list.item icon="heroicon-m-pencil-square" wire:click="mountAction('renombrar', { clave: '{{ $clave }}' })">Renombrar</x-filament::dropdown.list.item>
                <x-filament::dropdown.list.item icon="heroicon-m-arrow-right-circle" wire:click="mountAction('mover', { claves: ['{{ $clave }}'] })">Mover a…</x-filament::dropdown.list.item>
            @endif
            @if ($item['personalizable'])
                <x-filament::dropdown.list.item icon="heroicon-m-swatch" wire:click="mountAction('personalizar', { clave: '{{ $clave }}' })">Color e ícono</x-filament::dropdown.list.item>
            @endif
            @if ($esAdmin)
                <x-filament::dropdown.list.item icon="heroicon-m-shield-check" wire:click="mountAction('permisos', { clave: '{{ $clave }}' })">Permisos y acceso</x-filament::dropdown.list.item>
                <x-filament::dropdown.list.item icon="heroicon-m-trash" color="danger" wire:click="mountAction('papelera', { claves: ['{{ $clave }}'] })">Enviar a la papelera</x-filament::dropdown.list.item>
            @endif
            <x-filament::dropdown.list.item icon="heroicon-m-information-circle" wire:click="verDetalle('{{ $clave }}')">Detalles</x-filament::dropdown.list.item>
        @else
            @if ($item['visualizable'])
                <x-filament::dropdown.list.item icon="heroicon-m-eye" x-on:click="abrirVisor({{ $item['id'] }})">Abrir</x-filament::dropdown.list.item>
            @endif
            @if ($puedeDescargar)
                <x-filament::dropdown.list.item icon="heroicon-m-arrow-down-tray" tag="a" :href="route('documentos.descargar', $item['id'])">Descargar</x-filament::dropdown.list.item>
            @endif
            @if ($item['editable'])
                <x-filament::dropdown.list.item icon="heroicon-m-pencil-square" wire:click="mountAction('renombrar', { clave: '{{ $clave }}' })">Renombrar</x-filament::dropdown.list.item>
                <x-filament::dropdown.list.item icon="heroicon-m-arrow-right-circle" wire:click="mountAction('mover', { claves: ['{{ $clave }}'] })">Mover a…</x-filament::dropdown.list.item>
                <x-filament::dropdown.list.item icon="heroicon-m-arrow-up-tray" wire:click="mountAction('nuevaVersion', { clave: '{{ $clave }}' })">Subir nueva versión</x-filament::dropdown.list.item>
            @endif
            <x-filament::dropdown.list.item icon="heroicon-m-document-duplicate" wire:click="mountAction('copiar', { claves: ['{{ $clave }}'] })">Copiar a…</x-filament::dropdown.list.item>
            <x-filament::dropdown.list.item icon="heroicon-m-clock" wire:click="mountAction('versiones', { clave: '{{ $clave }}' })">Historial de versiones</x-filament::dropdown.list.item>
            <x-filament::dropdown.list.item icon="heroicon-m-information-circle" wire:click="verDetalle('{{ $clave }}')">Detalles</x-filament::dropdown.list.item>
            @if ($esAdmin)
                <x-filament::dropdown.list.item icon="heroicon-m-trash" color="danger" wire:click="mountAction('papelera', { claves: ['{{ $clave }}'] })">Enviar a la papelera</x-filament::dropdown.list.item>
            @endif
        @endif
    </x-filament::dropdown.list>
</x-filament::dropdown>
