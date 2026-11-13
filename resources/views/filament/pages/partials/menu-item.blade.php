{{--
    Botón «⋮» de una fila. El menú en sí se dibuja una sola vez para toda la página (partials/menu-compartido):
    antes cada fila armaba su propio menú completo de Filament y eso se llevaba ~90 % del tiempo de render.
--}}
<button
    type="button"
    class="ivsd-menu-btn"
    aria-label="Acciones de {{ $item['nombre'] }}"
    aria-haspopup="menu"
    x-on:click.stop="abrirMenu($event, @js([
        'clave' => $item['clave'],
        'id' => $item['id'],
        'carpeta' => $esCarpeta,
        'papelera' => (bool) $item['enPapelera'],
        'editable' => (bool) ($item['editable'] ?? false),
        'personalizable' => (bool) ($item['personalizable'] ?? false),
        'visualizable' => (bool) ($item['visualizable'] ?? false),
    ]))"
>{!! $iconoMenu !!}</button>
