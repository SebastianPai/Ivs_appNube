{{-- Explorador de carpetas para «Mover a…» y «Copiar a…» (estilo Google Drive). Datos: Unidad::explorarDestinos(). --}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="ivsdd"
        x-data="{
            estado: $wire.$entangle('{{ $getStatePath() }}'),
            unidad: @js($inicio['unidad']),
            padre: @js($inicio['padre']),
            excluir: @js($excluir),
            busqueda: '',
            datos: { carpetas: [], migas: [], actual: null },
            cargando: true,
            elegida: null,
            async cargar() {
                this.cargando = true
                this.datos = await $wire.explorarDestinos(this.padre, this.unidad, this.busqueda, this.excluir)
                this.unidad = this.datos.unidad
                this.cargando = false
                // Por defecto el destino es la carpeta abierta (si se puede editar)
                if (! this.busqueda) {
                    this.elegida = null
                    this.estado = this.datos.actual?.editable ? 'c:' + this.datos.actual.id : null
                }
            },
            entrar(c) {
                if (c.bloqueada) return
                this.busqueda = ''
                this.padre = c.id
                this.cargar()
            },
            elegir(c) {
                if (c.bloqueada || ! c.editable) return
                this.elegida = c.id
                this.estado = 'c:' + c.id
            },
            cambiarUnidad(u) { this.unidad = u; this.padre = null; this.busqueda = ''; this.cargar() },
            subir() {
                const migas = this.datos.migas
                this.padre = migas.length > 1 ? migas[migas.length - 2].id : null
                this.cargar()
            },
            irA(id) { this.padre = id; this.busqueda = ''; this.cargar() },
            get nombreDestino() {
                if (this.estado === 'raiz:compartida') return 'Raíz de la unidad compartida'
                if (this.estado === 'raiz:privada') return 'Raíz de la unidad privada'
                if (! this.estado) return null
                const id = Number(String(this.estado).slice(2))
                return this.datos.carpetas.find(c => c.id === id)?.nombre
                    ?? (this.datos.actual?.id === id ? this.datos.actual.nombre : 'la carpeta elegida')
            },
        }"
        x-init="cargar()"
    >
        {{-- Unidades --}}
        <div class="ivsdd-tabs">
            <button type="button" class="ivsdd-tab" :class="unidad === 'compartida' && 'activa'" x-on:click="cambiarUnidad('compartida')">
                <x-filament::icon icon="heroicon-m-user-group" class="ivsdd-i" /> Unidad compartida
            </button>
            @if ($conPrivada)
                <button type="button" class="ivsdd-tab" :class="unidad === 'privada' && 'activa'" x-on:click="cambiarUnidad('privada')">
                    <x-filament::icon icon="heroicon-m-lock-closed" class="ivsdd-i" /> Unidad privada
                </button>
            @endif
        </div>

        {{-- Buscador --}}
        <label class="ivsdd-buscar">
            <x-filament::icon icon="heroicon-m-magnifying-glass" class="ivsdd-i" />
            <input type="search" placeholder="Buscar una carpeta…" x-model="busqueda" x-on:input.debounce.300ms="cargar()" x-on:keydown.enter.prevent>
        </label>

        {{-- Ruta --}}
        <div class="ivsdd-migas" x-show="! busqueda">
            <button type="button" class="ivsdd-atras" x-show="padre" x-on:click="subir()" title="Atrás">
                <x-filament::icon icon="heroicon-m-arrow-left" class="ivsdd-i" />
            </button>
            <button type="button" class="ivsdd-miga" x-on:click="irA(null)" x-text="unidad === 'privada' ? 'Unidad privada' : 'Unidad compartida'"></button>
            <template x-for="m in datos.migas" :key="m.id">
                <span class="ivsdd-miga-ctn">
                    <x-filament::icon icon="heroicon-m-chevron-right" class="ivsdd-i-sm" />
                    <button type="button" class="ivsdd-miga" x-on:click="irA(m.id)" x-text="m.nombre"></button>
                </span>
            </template>
        </div>

        {{-- Lista --}}
        <div class="ivsdd-lista" :class="cargando && 'cargando'">
            @if ($permitirRaiz)
                <template x-if="! padre && ! busqueda">
                    <div class="ivsdd-fila" :class="estado === 'raiz:' + unidad && 'elegida'" x-on:click="estado = 'raiz:' + unidad; elegida = null">
                        <span class="ivsdd-ico" style="color: #a99ea0"><x-filament::icon icon="heroicon-s-home" class="ivsdd-icono" /></span>
                        <span class="ivsdd-nombre">Raíz de la unidad <small>(solo carpetas)</small></span>
                    </div>
                </template>
            @endif

            <template x-for="c in datos.carpetas" :key="c.id">
                <div
                    class="ivsdd-fila"
                    :class="{ elegida: elegida === c.id, bloqueada: c.bloqueada, 'sin-permiso': ! c.editable && ! c.bloqueada }"
                    x-on:click="elegir(c)"
                    x-on:dblclick="entrar(c)"
                    :title="c.bloqueada ? 'No puedes moverlo dentro de sí mismo' : (! c.editable ? 'Sin permiso para agregar aquí' : '')"
                >
                    <span class="ivsdd-ico" :style="c.color ? 'color:' + c.color : ''" x-html="c.icono"></span>
                    <span class="ivsdd-nombre">
                        <span x-text="c.nombre"></span>
                        <small x-show="c.ubicacion" x-text="(c.privada ? '🔒 ' : '') + c.ubicacion"></small>
                    </span>
                    <x-filament::icon icon="heroicon-m-lock-closed" class="ivsdd-i-sm ivsdd-candado" x-show="! c.editable && ! c.bloqueada" />
                    <button type="button" class="ivsdd-entrar" x-show="(c.hijas || busqueda) && ! c.bloqueada" x-on:click.stop="entrar(c)" title="Abrir">
                        <x-filament::icon icon="heroicon-m-chevron-right" class="ivsdd-i" />
                    </button>
                </div>
            </template>

            <div class="ivsdd-vacio" x-show="! cargando && datos.carpetas.length === 0">
                <x-filament::icon icon="heroicon-o-folder-open" class="ivsdd-vacio-i" />
                <span x-text="busqueda ? 'No hay carpetas con ese nombre' : 'Esta carpeta no tiene subcarpetas'"></span>
            </div>
        </div>

        {{-- Destino --}}
        <div class="ivsdd-destino" :class="! estado && 'vacio'">
            <x-filament::icon icon="heroicon-m-arrow-down-on-square" class="ivsdd-i" />
            <span x-show="estado">{{ $permitirRaiz || ($verbo ?? 'mover') === 'mover' ? 'Se moverá a' : 'Se copiará a' }} <b x-text="nombreDestino"></b></span>
            <span x-show="! estado">Elige una carpeta o entra en ella</span>
        </div>
    </div>
</x-dynamic-component>
