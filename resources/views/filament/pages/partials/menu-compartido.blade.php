{{-- Menú de acciones único para todas las filas (se abre junto al «⋮» que se tocó). Estado en gestorDocumental: menu / menuPos. --}}
<template x-teleport="body">
    <div
        x-show="menu"
        x-cloak
        x-ref="menuPanel"
        x-transition.opacity.duration.100ms
        x-on:click.outside="cerrarMenu()"
        x-on:keydown.escape.window="cerrarMenu()"
        x-on:scroll.window.passive="cerrarMenu()"
        x-on:resize.window="cerrarMenu()"
        :style="`top: ${menuPos.top}px; left: ${menuPos.left}px`"
        class="fi-dropdown-panel ivsd-menu divide-y divide-gray-100 rounded-lg bg-white shadow-lg ring-1 ring-gray-950/5 dark:divide-white/5 dark:bg-gray-900 dark:ring-white/10"
        role="menu"
    >
        <x-filament::dropdown.list>
            {{-- Papelera --}}
            <x-filament::dropdown.list.item icon="heroicon-m-arrow-uturn-left" x-show="menu?.papelera" x-on:click="menuAccion('restaurar')">Restaurar</x-filament::dropdown.list.item>
            <x-filament::dropdown.list.item icon="heroicon-m-x-circle" color="danger" x-show="menu?.papelera" x-on:click="menuAccion('purgar')">Eliminar definitivamente</x-filament::dropdown.list.item>

            {{-- Carpetas --}}
            <x-filament::dropdown.list.item icon="heroicon-m-folder-open" x-show="menu && ! menu.papelera && menu.carpeta" x-on:click="const id = menu.id; cerrarMenu(); $wire.abrirCarpeta(id)">Abrir</x-filament::dropdown.list.item>

            {{-- Archivos: abrir y descargar --}}
            <x-filament::dropdown.list.item icon="heroicon-m-eye" x-show="menu && ! menu.papelera && ! menu.carpeta && menu.visualizable" x-on:click="const id = menu.id; cerrarMenu(); abrirVisor(id)">Abrir</x-filament::dropdown.list.item>
            @if ($puedeDescargar)
                <x-filament::dropdown.list.item icon="heroicon-m-arrow-down-tray" tag="a" x-show="menu && ! menu.papelera && ! menu.carpeta" x-bind:href="menu ? @js(url('/documentos/archivos')) + '/' + menu.id + '/descargar' : '#'" x-on:click="cerrarMenu()">Descargar</x-filament::dropdown.list.item>
            @endif

            {{-- Edición --}}
            <x-filament::dropdown.list.item icon="heroicon-m-pencil-square" x-show="menu && ! menu.papelera && menu.editable" x-on:click="menuAccion('renombrar')">Renombrar</x-filament::dropdown.list.item>
            <x-filament::dropdown.list.item icon="heroicon-m-arrow-right-circle" x-show="menu && ! menu.papelera && menu.editable" x-on:click="menuAccion('mover', true)">Mover a…</x-filament::dropdown.list.item>
            <x-filament::dropdown.list.item icon="heroicon-m-arrow-up-tray" x-show="menu && ! menu.papelera && ! menu.carpeta && menu.editable" x-on:click="menuAccion('nuevaVersion')">Subir nueva versión</x-filament::dropdown.list.item>
            <x-filament::dropdown.list.item icon="heroicon-m-swatch" x-show="menu && ! menu.papelera && menu.carpeta && menu.personalizable" x-on:click="menuAccion('personalizar')">Color e ícono</x-filament::dropdown.list.item>
            <x-filament::dropdown.list.item icon="heroicon-m-document-duplicate" x-show="menu && ! menu.papelera && ! menu.carpeta" x-on:click="menuAccion('copiar', true)">Copiar a…</x-filament::dropdown.list.item>
            <x-filament::dropdown.list.item icon="heroicon-m-clock" x-show="menu && ! menu.papelera && ! menu.carpeta" x-on:click="menuAccion('versiones')">Historial de versiones</x-filament::dropdown.list.item>

            @if ($esAdmin)
                <x-filament::dropdown.list.item icon="heroicon-m-shield-check" x-show="menu && ! menu.papelera && menu.carpeta" x-on:click="menuAccion('permisos')">Permisos y acceso</x-filament::dropdown.list.item>
            @endif
            <x-filament::dropdown.list.item icon="heroicon-m-information-circle" x-show="menu && ! menu.papelera" x-on:click="const c = menu.clave; cerrarMenu(); $wire.verDetalle(c)">Detalles</x-filament::dropdown.list.item>
            @if ($esAdmin)
                <x-filament::dropdown.list.item icon="heroicon-m-trash" color="danger" x-show="menu && ! menu.papelera" x-on:click="menuAccion('papelera', true)">Enviar a la papelera</x-filament::dropdown.list.item>
            @endif
        </x-filament::dropdown.list>
    </div>
</template>
