<x-filament-panels::page>
    @php
        $hayDetalle = $detalleItem !== null;
        $totalElementos = $listado['carpetas']->count() + $listado['archivos']->count();
        $seleccionados = count($this->seleccion);
        $iconoTipo = [
            'pdf' => 'heroicon-o-document-text', 'imagen' => 'heroicon-o-photo', 'word' => 'heroicon-o-document',
            'excel' => 'heroicon-o-table-cells', 'powerpoint' => 'heroicon-o-presentation-chart-bar',
            'comprimido' => 'heroicon-o-archive-box', 'texto' => 'heroicon-o-document-text', 'otro' => 'heroicon-o-document',
            'video' => 'heroicon-o-film', 'audio' => 'heroicon-o-musical-note',
        ];
        // Qué hace un toque en pantallas táctiles: c = abrir carpeta, v = visor, d = descargar
        $accionToque = fn ($item) => $item['enPapelera'] ? '' : (str_starts_with($item['clave'], 'c:') ? 'c' : ($item['visualizable'] ? 'v' : 'd'));
        $secciones = [
            ['compartida', 'Unidad compartida', 'heroicon-o-building-office-2'],
            ['privada', 'Unidad privada', 'heroicon-o-lock-closed'],
            ['recientes', 'Recientes', 'heroicon-o-clock'],
            ['pendientes', 'Pendientes de revisión', 'heroicon-o-inbox-stack'],
            ['papelera', 'Papelera', 'heroicon-o-trash'],
        ];
        $disponibles = $this->seccionesDisponibles();
        $carpetaActualId = $this->carpetaId;
        $busquedaActiva = trim($this->busqueda) !== '';
    @endphp

    <div
        class="ivsd {{ $hayDetalle ? 'con-detalle' : '' }}"
        x-data="gestorDocumental({ puedeSubir: @js($puedeSubir), puedeDescargar: @js($puedeDescargar), maxArchivos: @js($maxArchivos), maxBytesArchivo: @js($maxBytesArchivo), maxBytesLote: @js($maxBytesLote) })"
        x-on:message.window="recibirMensajeVisor($event)"
        x-on:documento-actualizado.window="recargarVisor()"
        x-on:keydown.escape.window="if (visorUrl && ! visorOculto) { cerrarVisor() } else if (! visorUrl && $wire.seleccion.length) { $wire.limpiarSeleccion() }"
    >
        {{-- Fondo para cerrar el menú en móvil tocando fuera --}}
        <div class="ivsd-fondo-lateral" x-show="lateralAbierta" x-cloak x-transition.opacity x-on:click="lateralAbierta = false" aria-hidden="true"></div>

        {{-- Barra lateral --}}
        <aside class="ivsd-lateral" :class="{ 'abierta': lateralAbierta }" aria-label="Navegación de documentos">
            <a href="{{ \App\Filament\Pages\Unidad::getUrl() }}" class="ivsd-marca">
                <img src="{{ asset('images/ivs_img.png') }}" alt="">
                <span>{{ config('app.name') }}</span>
            </a>
            <nav class="ivsd-secciones">
                @foreach ($secciones as [$clave, $etiqueta, $icono])
                    @continue(! in_array($clave, $disponibles, true))
                    <button type="button" wire:click="irA('{{ $clave }}')" x-on:click="lateralAbierta = false"
                        class="ivsd-seccion {{ $this->seccion === $clave && ! $busquedaActiva ? 'activa' : '' }}"
                        @if ($this->seccion === $clave && ! $busquedaActiva) aria-current="page" @endif>
                        <x-filament::icon :icon="$icono" class="ivsd-i" />
                        <span>{{ $etiqueta }}</span>
                    </button>

                    @if ($clave === 'compartida' && count($arbolCompartido))
                        <ul class="ivsd-arbol" role="tree" aria-label="Carpetas de la unidad compartida">
                            @include('filament.pages.partials.arbol-nodo', ['nodos' => $arbolCompartido, 'actualId' => $carpetaActualId])
                        </ul>
                    @endif
                    @if ($clave === 'privada' && $arbolPrivado)
                        <ul class="ivsd-arbol" role="tree" aria-label="Carpetas de la unidad privada">
                            @include('filament.pages.partials.arbol-nodo', ['nodos' => $arbolPrivado, 'actualId' => $carpetaActualId])
                        </ul>
                    @endif
                @endforeach
            </nav>

            @if ($almacenamiento)
                <a href="{{ \App\Filament\Pages\Almacenamiento::getUrl() }}" class="ivsd-medidor" title="Ver el uso del almacenamiento">
                    <strong>{{ \App\Filament\Pages\Almacenamiento::bytes($almacenamiento['bytes']) }} ocupados</strong>
                    {{ number_format($almacenamiento['archivos'], 0, ',', '.') }} archivos
                </a>
            @endif

            {{-- Este menú reemplaza al lateral de Filament en esta página: aquí van también los accesos generales --}}
            <nav class="ivsd-secciones ivsd-enlaces" aria-label="Herramientas y administración">
                @foreach ($enlaces as $enlace)
                    <a href="{{ $enlace['url'] }}" class="ivsd-seccion">
                        <x-filament::icon :icon="$enlace['icono']" class="ivsd-i" />
                        <span>{{ $enlace['etiqueta'] }}</span>
                    </a>
                @endforeach
            </nav>
        </aside>

        {{-- Área principal --}}
        <section class="ivsd-principal"
            x-on:dragenter.prevent="entrarArrastre($event)"
            x-on:dragover.prevent="sobreArea($event)"
            x-on:dragleave.prevent="salirArrastre($event)"
            x-on:drop.prevent="soltarEnArea($event)"
            aria-label="Contenido">

            <header class="ivsd-barra">
                <button type="button" class="ivsd-boton-icono ivsd-solo-movil" x-on:click="lateralAbierta = ! lateralAbierta" aria-label="Mostrar carpetas">
                    <x-filament::icon icon="heroicon-o-bars-3" class="ivsd-i" />
                </button>

                <div class="ivsd-navegacion"
                    x-on:keydown.backspace.window="if (! $event.target.closest('input, textarea, select, [contenteditable], .fi-modal')) { $event.preventDefault(); history.back() }">
                    <button type="button" class="ivsd-boton-icono" x-on:click="history.back()" title="Atrás (Alt+←)" aria-label="Atrás">
                        <x-filament::icon icon="heroicon-m-arrow-left" class="ivsd-i" />
                    </button>
                    <button type="button" class="ivsd-boton-icono" wire:click="subirNivel" @disabled(count($migas) < 2) title="Subir un nivel" aria-label="Subir un nivel">
                        <x-filament::icon icon="heroicon-m-arrow-up" class="ivsd-i" />
                    </button>
                </div>

                <nav class="ivsd-migas" aria-label="Ubicación">
                    <ol>
                        @foreach ($migas as $i => $miga)
                            <li>
                                @if ($i > 0)<x-filament::icon icon="heroicon-m-chevron-right" class="ivsd-i-sm ivsd-tenue" />@endif
                                @if ($miga['accion'] && $i < count($migas) - 1)
                                    <button type="button" wire:click="{{ $miga['accion'] }}"
                                        x-on:dragover.prevent x-on:drop.prevent="@if (str_starts_with($miga['accion'], 'abrirCarpeta')) soltarEnCarpeta({{ (int) filter_var($miga['accion'], FILTER_SANITIZE_NUMBER_INT) }}) @endif">{{ $miga['etiqueta'] }}</button>
                                @else
                                    <span aria-current="location">{{ $miga['etiqueta'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </nav>

                {{-- Filament no comprueba la visibilidad al imprimir una acción suelta: se hace aquí --}}
                <div class="ivsd-acciones-principales">
                    @if ($this->crearCarpetaAction->isVisible())
                        {{ $this->crearCarpetaAction }}
                    @endif
                    @if ($this->subirAction->isVisible())
                        <x-filament::button color="gray" icon="heroicon-m-folder-arrow-down" x-on:click="$refs.entradaCarpeta.click()" x-bind:disabled="subiendo">
                            Subir carpeta
                        </x-filament::button>
                        <input type="file" x-ref="entradaCarpeta" webkitdirectory directory multiple hidden x-on:change="desdeEntradaCarpeta($event)">
                        {{ $this->subirAction }}
                    @endif
                </div>
            </header>

            <div class="ivsd-herramientas">
                <label class="ivsd-buscador">
                    <span class="sr-only">Buscar documentos</span>
                    <x-filament::icon icon="heroicon-m-magnifying-glass" class="ivsd-i-sm ivsd-tenue" />
                    <input type="search" wire:model.live.debounce.400ms="busqueda" placeholder="Buscar por nombre, identificador o extensión" maxlength="100" autocomplete="off">
                    <span wire:loading.delay wire:target="busqueda" class="ivsd-girando" aria-hidden="true"></span>
                </label>

                <select wire:model.live="filtroTipo" class="ivsd-select" aria-label="Filtrar por tipo">
                    <option value="">Todos los tipos</option>
                    @foreach ($tipos as $valor => $etiqueta)
                        <option value="{{ $valor }}">{{ $etiqueta }}</option>
                    @endforeach
                </select>

                <select wire:model.live="filtroDesde" class="ivsd-select" aria-label="Filtrar por fecha de modificación">
                    <option value="">Cualquier fecha</option>
                    <option value="hoy">Hoy</option>
                    <option value="7d">Últimos 7 días</option>
                    <option value="30d">Últimos 30 días</option>
                    <option value="anio">Este año</option>
                </select>

                <div class="ivsd-grupo-botones" role="group" aria-label="Vista">
                    <button type="button" wire:click="cambiarVista('lista')" class="{{ $this->vista === 'lista' ? 'activa' : '' }}" aria-pressed="{{ $this->vista === 'lista' ? 'true' : 'false' }}" title="Vista de lista">
                        <x-filament::icon icon="heroicon-m-bars-4" class="ivsd-i-sm" /><span class="sr-only">Lista</span>
                    </button>
                    <button type="button" wire:click="cambiarVista('cuadricula')" class="{{ $this->vista === 'cuadricula' ? 'activa' : '' }}" aria-pressed="{{ $this->vista === 'cuadricula' ? 'true' : 'false' }}" title="Vista de cuadrícula">
                        <x-filament::icon icon="heroicon-m-squares-2x2" class="ivsd-i-sm" /><span class="sr-only">Cuadrícula</span>
                    </button>
                </div>
            </div>

            {{-- Barra de selección --}}
            @if ($seleccionados)
                <div class="ivsd-seleccion" role="region" aria-label="Acciones sobre la selección">
                    <button type="button" class="ivsd-boton-icono" wire:click="limpiarSeleccion" aria-label="Quitar selección">
                        <x-filament::icon icon="heroicon-m-x-mark" class="ivsd-i-sm" />
                    </button>
                    <strong>{{ $seleccionados }} {{ $seleccionados === 1 ? 'seleccionado' : 'seleccionados' }}</strong>
                    <span class="ivsd-espacio"></span>
                    <x-filament::button size="sm" color="gray" icon="heroicon-m-arrow-right-circle" wire:click="mountAction('mover')">Mover</x-filament::button>
                    @if (collect($this->seleccion)->every(fn ($c) => str_starts_with($c, 'a:')))
                        <x-filament::button size="sm" color="gray" icon="heroicon-m-document-duplicate" wire:click="mountAction('copiar')">Copiar</x-filament::button>
                    @endif
                    @if ($esAdmin && $this->seccion !== 'papelera')
                        <x-filament::button size="sm" color="danger" icon="heroicon-m-trash" wire:click="mountAction('papelera')">Papelera</x-filament::button>
                    @endif
                </div>
            @endif

            <div class="ivsd-contenido" wire:loading.class="cargando" wire:target="irA,abrirCarpeta,busqueda,filtroTipo,filtroDesde,ordenarPor,cargarMas">
                @if ($totalElementos === 0)
                    <div class="ivsd-vacio">
                        <x-filament::icon :icon="match (true) { $busquedaActiva => 'heroicon-o-magnifying-glass', $this->seccion === 'papelera' => 'heroicon-o-trash', default => 'heroicon-o-folder-open' }" class="ivsd-i-xl" />
                        @if ($busquedaActiva)
                            <h3>Sin resultados</h3>
                            <p>No hay documentos a los que tengas acceso que coincidan con «{{ $this->busqueda }}».</p>
                        @elseif ($this->seccion === 'papelera')
                            <h3>La papelera está vacía</h3>
                        @elseif ($this->seccion === 'pendientes')
                            <h3>Nada pendiente</h3>
                            <p>Los archivos recuperados sin carpeta aparecerán aquí para revisarlos.</p>
                        @elseif ($this->seccion === 'recientes')
                            <h3>Sin actividad reciente</h3>
                        @elseif ($puedeSubir)
                            <h3>Esta carpeta está vacía</h3>
                            <p>Arrastra archivos aquí o usa «Subir archivos».</p>
                        @elseif (! $carpetaActualId && $this->seccion === 'privada')
                            <h3>No tienes carpetas privadas asignadas</h3>
                        @else
                            <h3>No hay elementos</h3>
                        @endif
                    </div>
                @elseif ($this->vista === 'lista')
                    <div class="ivsd-tabla" role="grid" aria-label="{{ $listado['titulo'] }}" aria-rowcount="{{ $totalElementos }}">
                        <div class="ivsd-fila ivsd-cabecera" role="row">
                            <span role="columnheader" class="ivsd-col-check">
                                <input type="checkbox" wire:click="seleccionarTodo" @checked($seleccionados && $seleccionados === $totalElementos) aria-label="Seleccionar todo">
                            </span>
                            <button type="button" role="columnheader" class="ivsd-col-nombre" wire:click="ordenarPor('nombre')">Nombre {!! $this->orden === 'nombre' ? ($this->direccion === 'asc' ? '↑' : '↓') : '' !!}</button>
                            <span role="columnheader" class="ivsd-col-autor">Propietario</span>
                            <button type="button" role="columnheader" class="ivsd-col-fecha" wire:click="ordenarPor('updated_at')">{{ $this->seccion === 'papelera' ? 'Eliminado' : 'Modificado' }} {!! $this->orden === 'updated_at' ? ($this->direccion === 'asc' ? '↑' : '↓') : '' !!}</button>
                            <button type="button" role="columnheader" class="ivsd-col-tamano" wire:click="ordenarPor('tamano')">Tamaño {!! $this->orden === 'tamano' ? ($this->direccion === 'asc' ? '↑' : '↓') : '' !!}</button>
                            <span role="columnheader" class="ivsd-col-menu"><span class="sr-only">Acciones</span></span>
                        </div>

                        @foreach ($listado['carpetas'] as $item)
                            <div role="row" tabindex="0" wire:key="{{ $item['clave'] }}" data-id="{{ $item['id'] }}" data-abrir="{{ $accionToque($item) }}"
                                class="ivsd-fila {{ in_array($item['clave'], $this->seleccion, true) ? 'seleccionada' : '' }} {{ $this->detalle === $item['clave'] ? 'enfocada' : '' }}"
                                :class="{ 'destino': destinoActual === {{ $item['id'] }} }"
                                draggable="{{ $item['editable'] ? 'true' : 'false' }}"
                                x-on:dragstart="iniciarArrastre($event, '{{ $item['clave'] }}')"
                                @unless ($item['enPapelera'])
                                    x-on:dragover.prevent.stop="destinoActual = {{ $item['id'] }}"
                                    x-on:dragleave="destinoActual = null"
                                    x-on:drop.prevent.stop="soltarEnCarpeta({{ $item['id'] }})"
                                    x-on:keydown.enter="$wire.abrirCarpeta({{ $item['id'] }})"
                                @endunless
                                x-on:click="clic($event, '{{ $item['clave'] }}')"
                                x-on:keydown.space.prevent="$wire.alternarSeleccion('{{ $item['clave'] }}')">
                                <span class="ivsd-col-check ivsd-detener">
                                    <input type="checkbox" wire:click="alternarSeleccion('{{ $item['clave'] }}')" @checked(in_array($item['clave'], $this->seleccion, true)) aria-label="Seleccionar {{ $item['nombre'] }}">
                                </span>
                                <span class="ivsd-col-nombre">
                                    <span class="ivsd-icono-tipo carpeta">
                                        <x-filament::icon :icon="$item['icono']" class="ivsd-i" :style="$item['color'] ? 'color: '.$item['color'] : null" />
                                        @if ($item['restringida'])<x-filament::icon icon="heroicon-s-lock-closed" class="ivsd-candado" />@endif
                                    </span>
                                    <span class="ivsd-nombre" title="{{ $item['nombre'] }}">{{ $item['nombre'] }}</span>
                                </span>
                                <span class="ivsd-col-autor">{{ $item['autor'] ?? '—' }}</span>
                                <span class="ivsd-col-fecha" title="{{ $item['fecha']?->format('d/m/Y H:i') }}">{{ $item['fecha']?->diffForHumans() }}</span>
                                <span class="ivsd-col-tamano">—</span>
                                <span class="ivsd-col-menu ivsd-detener">
                                    @include('filament.pages.partials.menu-item', ['item' => $item, 'esCarpeta' => true])
                                </span>
                            </div>
                        @endforeach

                        @foreach ($listado['archivos'] as $item)
                            <div role="row" tabindex="0" wire:key="{{ $item['clave'] }}" data-id="{{ $item['id'] }}" data-abrir="{{ $accionToque($item) }}"
                                class="ivsd-fila {{ in_array($item['clave'], $this->seleccion, true) ? 'seleccionada' : '' }} {{ $this->detalle === $item['clave'] ? 'enfocada' : '' }}"
                                draggable="{{ $item['editable'] ? 'true' : 'false' }}"
                                x-on:dragstart="iniciarArrastre($event, '{{ $item['clave'] }}')"
                                @unless ($item['enPapelera'])
                                    x-on:keydown.enter="abrirArchivo({{ $item['id'] }}, @js($item['visualizable']), @js($puedeDescargar))"
                                @endunless
                                x-on:click="clic($event, '{{ $item['clave'] }}')"
                                x-on:keydown.space.prevent="$wire.alternarSeleccion('{{ $item['clave'] }}')">
                                <span class="ivsd-col-check ivsd-detener">
                                    <input type="checkbox" wire:click="alternarSeleccion('{{ $item['clave'] }}')" @checked(in_array($item['clave'], $this->seleccion, true)) aria-label="Seleccionar {{ $item['nombre'] }}">
                                </span>
                                <span class="ivsd-col-nombre">
                                    <span class="ivsd-icono-tipo {{ $item['tipo'] }}"><x-filament::icon :icon="$iconoTipo[$item['tipo']]" class="ivsd-i" /></span>
                                    <span class="ivsd-nombre" title="{{ $item['nombre'] }}">{{ $item['nombre'] }}</span>
                                    @if ($item['version'] > 1)<span class="ivsd-insignia" title="Versión actual">v{{ $item['version'] }}</span>@endif
                                    @if ($item['pendiente'])<span class="ivsd-insignia aviso">Pendiente</span>@endif
                                    @if ($item['ubicacion'])<span class="ivsd-ubicacion">{{ $item['ubicacion'] }}</span>@endif
                                </span>
                                <span class="ivsd-col-autor">{{ $item['autor'] ?? '—' }}</span>
                                <span class="ivsd-col-fecha" title="{{ $item['fecha']?->format('d/m/Y H:i') }}">{{ $item['fecha']?->diffForHumans() }}</span>
                                <span class="ivsd-col-tamano">{{ $this->bytes($item['tamano']) }}</span>
                                <span class="ivsd-col-menu ivsd-detener">
                                    @include('filament.pages.partials.menu-item', ['item' => $item, 'esCarpeta' => false])
                                </span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="ivsd-cuadricula" role="list" aria-label="{{ $listado['titulo'] }}">
                        @foreach ($listado['carpetas'] as $item)
                            <div role="listitem" tabindex="0" wire:key="g-{{ $item['clave'] }}" data-id="{{ $item['id'] }}" data-abrir="{{ $accionToque($item) }}"
                                class="ivsd-tarjeta carpeta {{ in_array($item['clave'], $this->seleccion, true) ? 'seleccionada' : '' }} {{ $this->detalle === $item['clave'] ? 'enfocada' : '' }}"
                                :class="{ 'destino': destinoActual === {{ $item['id'] }} }"
                                draggable="{{ $item['editable'] ? 'true' : 'false' }}"
                                x-on:dragstart="iniciarArrastre($event, '{{ $item['clave'] }}')"
                                @unless ($item['enPapelera'])
                                    x-on:dragover.prevent.stop="destinoActual = {{ $item['id'] }}"
                                    x-on:dragleave="destinoActual = null"
                                    x-on:drop.prevent.stop="soltarEnCarpeta({{ $item['id'] }})"
                                    x-on:keydown.enter="$wire.abrirCarpeta({{ $item['id'] }})"
                                @endunless
                                x-on:click="clic($event, '{{ $item['clave'] }}')"
                                x-on:keydown.space.prevent="$wire.alternarSeleccion('{{ $item['clave'] }}')">
                                <span class="ivsd-icono-tipo carpeta">
                                    <x-filament::icon :icon="$item['icono']" class="ivsd-i" :style="$item['color'] ? 'color: '.$item['color'] : null" />
                                    @if ($item['restringida'])<x-filament::icon icon="heroicon-s-lock-closed" class="ivsd-candado" />@endif
                                </span>
                                <span class="ivsd-nombre" title="{{ $item['nombre'] }}">{{ $item['nombre'] }}</span>
                                <span class="ivsd-detener ivsd-tarjeta-check">
                                    <input type="checkbox" wire:click="alternarSeleccion('{{ $item['clave'] }}')" @checked(in_array($item['clave'], $this->seleccion, true)) aria-label="Seleccionar {{ $item['nombre'] }}">
                                </span>
                                <span class="ivsd-detener">@include('filament.pages.partials.menu-item', ['item' => $item, 'esCarpeta' => true])</span>
                            </div>
                        @endforeach

                        @foreach ($listado['archivos'] as $item)
                            <div role="listitem" tabindex="0" wire:key="g-{{ $item['clave'] }}" data-id="{{ $item['id'] }}" data-abrir="{{ $accionToque($item) }}"
                                class="ivsd-tarjeta archivo {{ in_array($item['clave'], $this->seleccion, true) ? 'seleccionada' : '' }} {{ $this->detalle === $item['clave'] ? 'enfocada' : '' }}"
                                draggable="{{ $item['editable'] ? 'true' : 'false' }}"
                                x-on:dragstart="iniciarArrastre($event, '{{ $item['clave'] }}')"
                                @unless ($item['enPapelera'])
                                    x-on:keydown.enter="abrirArchivo({{ $item['id'] }}, @js($item['visualizable']), @js($puedeDescargar))"
                                @endunless
                                x-on:click="clic($event, '{{ $item['clave'] }}')"
                                x-on:keydown.space.prevent="$wire.alternarSeleccion('{{ $item['clave'] }}')">
                                <span class="ivsd-miniatura {{ $item['tipo'] }}">
                                    <x-filament::icon :icon="$iconoTipo[$item['tipo']]" class="ivsd-i-xl" />
                                    <span class="ivsd-ext">{{ mb_strtoupper((string) $item['extension']) }}</span>
                                </span>
                                <span class="ivsd-nombre" title="{{ $item['nombre'] }}">{{ $item['nombre'] }}</span>
                                <span class="ivsd-meta">{{ $this->bytes($item['tamano']) }} · {{ $item['fecha']?->diffForHumans() }}</span>
                                <span class="ivsd-detener ivsd-tarjeta-check">
                                    <input type="checkbox" wire:click="alternarSeleccion('{{ $item['clave'] }}')" @checked(in_array($item['clave'], $this->seleccion, true)) aria-label="Seleccionar {{ $item['nombre'] }}">
                                </span>
                                <span class="ivsd-detener">@include('filament.pages.partials.menu-item', ['item' => $item, 'esCarpeta' => false])</span>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($listado['hayMas'])
                    <div class="ivsd-mas">
                        <x-filament::button color="gray" wire:click="cargarMas" wire:loading.attr="disabled" wire:target="cargarMas">Cargar más</x-filament::button>
                    </div>
                @endif
            </div>

            {{-- Subida por arrastre --}}
            <div class="ivsd-soltar" x-show="arrastrandoArchivos" x-cloak x-transition.opacity aria-hidden="true">
                <div>
                    <x-filament::icon icon="heroicon-o-cloud-arrow-up" class="ivsd-i-xl" />
                    <p x-text="puedeSubir ? 'Suelta archivos o carpetas para subirlos aquí' : 'No puedes subir archivos aquí'"></p>
                </div>
            </div>
            <div class="ivsd-progreso" x-show="subiendo" x-cloak role="status" aria-live="polite">
                <span x-text="estadoSubida"></span>
                <div class="ivsd-progreso-barra"><div :style="'width:' + progreso + '%'"></div></div>
            </div>
        </section>

        {{-- Panel de detalles --}}
        @if ($hayDetalle)
            <aside class="ivsd-detalle" aria-label="Detalles">
                <header>
                    <span class="ivsd-icono-tipo {{ $detalleItem['tipo'] }}">
                        <x-filament::icon :icon="$detalleItem['tipo'] === 'carpeta' ? $detalleItem['icono'] : $iconoTipo[$detalleItem['tipo']]" class="ivsd-i"
                            :style="($detalleItem['color'] ?? null) ? 'color: '.$detalleItem['color'] : null" />
                    </span>
                    <h3 title="{{ $detalleItem['nombre'] }}">{{ $detalleItem['nombre'] }}</h3>
                    <button type="button" class="ivsd-boton-icono" wire:click="verDetalle(null)" aria-label="Cerrar detalles">
                        <x-filament::icon icon="heroicon-m-x-mark" class="ivsd-i-sm" />
                    </button>
                </header>

                @if ($detalleItem['tipo'] !== 'carpeta' && ! $detalleItem['enPapelera'])
                    <div class="ivsd-detalle-acciones">
                        @if ($detalleItem['visualizable'])
                            <x-filament::button size="sm" icon="heroicon-m-eye" x-on:click="abrirVisor({{ $detalleItem['id'] }})">Abrir</x-filament::button>
                        @endif
                        @if ($detalleItem['descargable'])
                            <x-filament::button size="sm" color="gray" icon="heroicon-m-arrow-down-tray" tag="a" :href="route('documentos.descargar', $detalleItem['id'])">Descargar</x-filament::button>
                        @endif
                        <x-filament::button size="sm" color="gray" icon="heroicon-m-clock" wire:click="mountAction('versiones', { clave: '{{ $detalleItem['clave'] }}' })">Versiones</x-filament::button>
                    </div>
                @endif

                <dl class="ivsd-propiedades">
                    @foreach ($detalleItem['filas'] as $etiqueta => $valor)
                        <dt>{{ $etiqueta }}</dt>
                        <dd @class(['ivsd-mono' => $etiqueta === 'MD5'])>{{ $valor }}</dd>
                    @endforeach
                </dl>

                @if (! empty($detalleItem['permisos']))
                    <h4>Accesos explícitos</h4>
                    <ul class="ivsd-lista-simple">
                        @foreach ($detalleItem['permisos'] as $permiso)<li>{{ $permiso }}</li>@endforeach
                    </ul>
                @endif

                <h4>Actividad</h4>
                @forelse ($detalleItem['actividad'] as $actividad)
                    <div class="ivsd-actividad">
                        <strong>{{ $actividad->user?->name ?? 'Sistema' }}</strong>
                        <span>{{ \Illuminate\Support\Str::of($actividad->accion)->after('.')->replace('_', ' ') }}</span>
                        <time datetime="{{ $actividad->created_at?->toIso8601String() }}">{{ $actividad->created_at?->diffForHumans() }}</time>
                    </div>
                @empty
                    <p class="ivsd-tenue">Sin actividad registrada.</p>
                @endforelse
            </aside>
        @endif

        {{-- Visor (documento en la misma pantalla, sin descargarlo) --}}
        <template x-teleport="body">
            <div class="ivsd-visor" x-show="visorUrl && ! visorOculto" x-cloak x-transition.opacity role="dialog" aria-modal="true" aria-label="Visor de documentos">
                <button type="button" class="ivsd-visor-cerrar" x-on:click="cerrarVisor()" aria-label="Cerrar visor">
                    <x-filament::icon icon="heroicon-m-x-mark" class="ivsd-i" />
                </button>
                <iframe x-bind:src="visorUrl" title="Visor de documentos" allow="fullscreen" referrerpolicy="same-origin"></iframe>
            </div>
        </template>
    </div>

    @script
    <script>
        Alpine.data('gestorDocumental', ({ puedeSubir, puedeDescargar, maxArchivos, maxBytesArchivo, maxBytesLote }) => ({
            puedeSubir,
            lateralAbierta: false,
            arrastrandoArchivos: false,
            contadorArrastre: 0,
            destinoActual: null,
            arrastrados: [],
            subiendo: false,
            progreso: 0,
            archivosSubiendo: 0,
            visorUrl: null,
            visorOculto: false,
            temporizadorClic: null,

            init() {
                // El modal de Filament no puede quedar encima del visor (otro contexto de apilamiento):
                // mientras haya una acción abierta el visor se oculta, sin descargarse, y vuelve al cerrarla
                Livewire.hook('commit', ({ component, succeed }) => {
                    if (component.id !== $wire.$id) return;
                    succeed(() => queueMicrotask(() => {
                        if (this.visorOculto && ! ($wire.mountedActions || []).length) this.visorOculto = false;
                    }));
                });
            },

            // Un clic muestra detalles; doble clic abre. e.detail lo cuenta el navegador con la velocidad de doble clic
            // del sistema (en Windows hasta 500 ms), así no se pierde un doble clic algo lento.
            clic(e, clave) {
                if (e.target.closest('.ivsd-detener')) return;
                clearTimeout(this.temporizadorClic);

                // En pantallas táctiles no hay doble clic: un toque abre (los detalles quedan en el menú ⋮)
                const fila = e.currentTarget;
                const abrir = fila.dataset.abrir && (e.detail >= 2 || window.matchMedia('(pointer: coarse)').matches);
                if (abrir) {
                    if (e.detail > 2) return; // un triple clic no abre dos veces
                    const id = Number(fila.dataset.id);
                    if (fila.dataset.abrir === 'c') $wire.abrirCarpeta(id);
                    else this.abrirArchivo(id, fila.dataset.abrir === 'v', puedeDescargar);
                    return;
                }

                this.temporizadorClic = setTimeout(() => $wire.verDetalle(clave), 400);
            },
            // Acciones pedidas desde el visor (iframe del mismo origen): se abren como modales sobre el visor
            recibirMensajeVisor(e) {
                if (e.origin !== window.location.origin || ! e.data || e.data.ivsd !== true) return;
                const id = Number(e.data.id);
                if (! Number.isInteger(id) || id <= 0) return;
                const clave = 'a:' + id;
                switch (e.data.accion) {
                    case 'renombrar': case 'nuevaVersion': case 'versiones':
                        this.visorOculto = true;
                        $wire.mountAction(e.data.accion, { clave }); break;
                    case 'mover': case 'copiar':
                        this.visorOculto = true;
                        $wire.mountAction(e.data.accion, { claves: [clave] }); break;
                    case 'detalles':
                        this.cerrarVisor(); $wire.verDetalle(clave); break;
                }
            },
            recargarVisor() {
                const marco = document.querySelector('.ivsd-visor iframe');
                if (this.visorUrl && marco?.contentWindow) marco.contentWindow.location.reload();
            },
            cancelarClic() {
                clearTimeout(this.temporizadorClic);
            },
            esArrastreDeArchivos(e) {
                return Array.from(e.dataTransfer?.types || []).includes('Files');
            },
            entrarArrastre(e) {
                if (! this.esArrastreDeArchivos(e)) return;
                this.contadorArrastre++;
                this.arrastrandoArchivos = true;
            },
            sobreArea(e) {
                if (this.esArrastreDeArchivos(e)) e.dataTransfer.dropEffect = this.puedeSubir ? 'copy' : 'none';
            },
            salirArrastre(e) {
                if (! this.esArrastreDeArchivos(e)) return;
                this.contadorArrastre = Math.max(0, this.contadorArrastre - 1);
                if (this.contadorArrastre === 0) this.arrastrandoArchivos = false;
            },
            estadoSubida: '',

            async soltarEnArea(e) {
                const habiaArchivos = this.esArrastreDeArchivos(e);
                this.arrastrandoArchivos = false;
                this.contadorArrastre = 0;
                if (! habiaArchivos || ! this.puedeSubir || this.subiendo) return;

                // Las entradas se leen antes de cualquier await: el navegador las invalida al terminar el evento
                const entradas = Array.from(e.dataTransfer.items || [])
                    .map((item) => item.webkitGetAsEntry ? item.webkitGetAsEntry() : null)
                    .filter(Boolean);

                if (! entradas.some((en) => en.isDirectory)) {
                    await this.subir(Array.from(e.dataTransfer.files).map((file) => ({ file, ruta: null })), []);
                    return;
                }

                const archivos = [];
                const directorios = [];
                await Promise.all(entradas.map((en) => this.recorrer(en, '', archivos, directorios)));
                await this.subir(archivos, directorios);
            },
            desdeEntradaCarpeta(e) {
                const archivos = Array.from(e.target.files).map((file) => ({ file, ruta: file.webkitRelativePath || file.name }));
                const directorios = [...new Set(archivos.map((a) => a.ruta.split('/').slice(0, -1).join('/')).filter(Boolean))];
                e.target.value = '';
                this.subir(archivos, directorios);
            },
            async recorrer(entrada, prefijo, archivos, directorios) {
                const ruta = prefijo ? `${prefijo}/${entrada.name}` : entrada.name;
                if (entrada.isFile) {
                    const file = await new Promise((ok, mal) => entrada.file(ok, mal));
                    archivos.push({ file, ruta });
                    return;
                }
                directorios.push(ruta);
                const lector = entrada.createReader();
                // readEntries devuelve los hijos por tandas: se repite hasta que llega vacío
                for (;;) {
                    const tanda = await new Promise((ok, mal) => lector.readEntries(ok, mal));
                    if (! tanda.length) break;
                    await Promise.all(tanda.map((hija) => this.recorrer(hija, ruta, archivos, directorios)));
                }
            },
            /** Sube por lotes (máx. archivos y bytes por petición, según los límites de PHP). ruta = null → archivo suelto. */
            async subir(archivos, directorios) {
                const esCarpeta = directorios.length > 0 || archivos.some((a) => a.ruta && a.ruta.includes('/'));
                const demasiadoGrandes = archivos.filter((a) => a.file.size > maxBytesArchivo);
                archivos = archivos.filter((a) => a.file.size <= maxBytesArchivo);
                if (demasiadoGrandes.length) {
                    new FilamentNotification().title(`${demasiadoGrandes.length} archivo(s) superan el tamaño máximo y se omitieron`)
                        .body(demasiadoGrandes.slice(0, 5).map((a) => a.ruta || a.file.name).join('\n')).warning().send();
                }
                if (! archivos.length && ! directorios.length) return;
                if (! esCarpeta && archivos.length > maxArchivos) {
                    new FilamentNotification().title(`Puedes subir como máximo ${maxArchivos} archivos sueltos a la vez. Para más, sube una carpeta.`).danger().send();
                    return;
                }

                this.subiendo = true;
                this.progreso = 0;
                const total = archivos.length;
                try {
                    if (esCarpeta) {
                        this.estadoSubida = 'Creando carpetas…';
                        await $wire.crearEstructura(directorios);
                    }

                    const lotes = [];
                    let lote = [], bytes = 0;
                    for (const a of archivos) {
                        if (lote.length && (lote.length >= maxArchivos || bytes + a.file.size > maxBytesLote)) {
                            lotes.push(lote);
                            lote = [];
                            bytes = 0;
                        }
                        lote.push(a);
                        bytes += a.file.size;
                    }
                    if (lote.length) lotes.push(lote);

                    let hechos = 0;
                    for (const l of lotes) {
                        this.estadoSubida = `Subiendo ${Math.min(hechos + l.length, total)} de ${total} archivo(s)…`;
                        // Primero las rutas (petición completa) y después los archivos de ese mismo lote
                        await $wire.$set('rutasSoltadas', esCarpeta ? l.map((a) => a.ruta) : []);
                        await new Promise((listo) => $wire.uploadMultiple('soltados', l.map((a) => a.file),
                            listo,
                            () => {
                                new FilamentNotification().title('Un lote no se pudo subir. Revisa el tamaño y el tipo de los archivos.').danger().send();
                                listo();
                            },
                            (ev) => { this.progreso = Math.round(((hechos + l.length * ev.detail.progress / 100) / total) * 100); }
                        ));
                        hechos += l.length;
                    }

                    if (esCarpeta) await $wire.finalizarSubidaCarpeta();
                } finally {
                    this.subiendo = false;
                    this.estadoSubida = '';
                }
            },
            iniciarArrastre(e, clave) {
                const seleccion = $wire.seleccion;
                this.arrastrados = seleccion.includes(clave) ? [...seleccion] : [clave];
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', this.arrastrados.join(','));
            },
            soltarEnCarpeta(id) {
                this.destinoActual = null;
                if (! this.arrastrados.length) return;
                $wire.moverA(this.arrastrados, id);
                this.arrastrados = [];
            },
            abrirArchivo(id, visualizable, descargable) {
                if (visualizable) this.abrirVisor(id);
                else if (descargable) window.location.href = @js(url('/documentos/archivos')) + '/' + id + '/descargar';
            },
            abrirVisor(id) {
                this.visorUrl = @js(url('/documentos/archivos')) + '/' + id + '/visor';
            },
            cerrarVisor() {
                this.visorUrl = null;
                this.visorOculto = false;
            },
        }));
    </script>
    @endscript
</x-filament-panels::page>
