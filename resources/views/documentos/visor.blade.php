<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $archivo->nombre }} · Visor</title>
    <style>
        :root { --fondo: #1f2023; --barra: #2b2c30; --texto: #e8e8ea; --tenue: #a0a1a8; --borde: #3a3b40; --acento: #dc2626; --resaltado: rgba(250, 204, 21, .45); }
        * { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; background: var(--fondo); color: var(--texto); font: 14px/1.4 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        .barra { position: sticky; top: 0; z-index: 2; display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; padding: .5rem .75rem; background: var(--barra); border-bottom: 1px solid var(--borde); }
        .titulo { flex: 1 1 12rem; min-width: 0; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .grupo { display: flex; align-items: center; gap: .25rem; }
        button, .boton { display: inline-flex; align-items: center; justify-content: center; gap: .25rem; min-width: 2.25rem; height: 2.25rem; padding: 0 .6rem; border: 1px solid var(--borde); border-radius: .5rem; background: transparent; color: var(--texto); font: inherit; text-decoration: none; cursor: pointer; }
        button:hover, .boton:hover { background: rgba(255,255,255,.07); }
        button:disabled { opacity: .4; cursor: default; }
        button:focus-visible, .boton:focus-visible, input:focus-visible { outline: 2px solid var(--acento); outline-offset: 2px; }
        input { height: 2.25rem; border: 1px solid var(--borde); border-radius: .5rem; background: var(--fondo); color: var(--texto); padding: 0 .5rem; font: inherit; }
        input[type=number] { width: 4rem; text-align: center; }
        .tenue { color: var(--tenue); }
        .area { display: flex; height: calc(100% - 3.3rem); }
        main { position: relative; flex: 1; min-width: 0; height: 100%; overflow: auto; display: flex; flex-direction: column; align-items: center; gap: 1rem; padding: 1rem; }
        .miniaturas { flex: none; width: 10.5rem; height: 100%; overflow: auto; display: flex; flex-direction: column; align-items: center; gap: .75rem; padding: .75rem; background: var(--barra); border-right: 1px solid var(--borde); }
        .miniaturas[hidden] { display: none; }
        .miniatura { flex-direction: column; width: 100%; height: auto; padding: .25rem; border: 2px solid transparent; }
        .miniatura canvas { display: block; width: 100%; min-height: 5rem; background: #fff; box-shadow: 0 1px 4px rgba(0,0,0,.4); }
        .miniatura span { font-size: .75rem; color: var(--tenue); }
        .miniatura.actual { border-color: var(--acento); }
        #impresion { display: none; }
        @media print {
            @page { margin: 0; }
            html, body { height: auto; background: #fff; }
            body.imprimiendo-pdf > *:not(#impresion) { display: none !important; }
            body.imprimiendo-pdf #impresion { display: block; }
            #impresion img { display: block; width: 100%; height: 100vh; object-fit: contain; break-after: page; }
        }
        .pagina { position: relative; background: #fff; box-shadow: 0 2px 10px rgba(0,0,0,.4); }
        .pagina canvas { display: block; }
        .capa-texto { position: absolute; inset: 0; overflow: hidden; line-height: 1; opacity: 1; }
        .capa-texto span { position: absolute; color: transparent; white-space: pre; transform-origin: 0 0; cursor: text; }
        .capa-texto span::selection { background: rgba(37, 99, 235, .35); }
        .capa-texto span.coincidencia { background: var(--resaltado); border-radius: 2px; }
        .imagen { flex: 1; width: 100%; display: flex; align-items: center; justify-content: center; overflow: auto; }
        .imagen img { max-width: none; transform-origin: center center; transition: transform .1s ease-out; image-rendering: auto; }
        .multimedia { flex: 1; width: 100%; display: flex; align-items: center; justify-content: center; }
        .multimedia video { max-width: 100%; max-height: calc(100vh - 6rem); border-radius: .5rem; background: #000; }
        .multimedia audio { width: min(40rem, 100%); }
        .acciones { display: none; }
        .incrustado .acciones { display: flex; }
        .estado { margin: auto; text-align: center; color: var(--tenue); max-width: 32rem; }
        @media (max-width: 640px) { .barra { padding: .4rem; gap: .35rem; } .titulo { flex-basis: 100%; } button, .boton { min-width: 2.5rem; height: 2.5rem; } }
        .estado.error { color: #fca5a5; }
        .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
        @media (max-width: 640px) { .ocultar-movil { display: none; } }
    </style>
</head>
<body>
@php($esPdf = $archivo->tipo() === 'pdf')
<header class="barra" role="toolbar" aria-label="Controles del visor">
    <span class="titulo" title="{{ $archivo->nombre }}">{{ $archivo->nombre }}</span>

    <span class="grupo" aria-label="Documentos de la carpeta">
        @if ($anterior)<a class="boton" href="{{ route('documentos.visor', $anterior) }}" title="Documento anterior" aria-label="Documento anterior">⟨⟨</a>@endif
        @if ($siguiente)<a class="boton" href="{{ route('documentos.visor', $siguiente) }}" title="Documento siguiente" aria-label="Documento siguiente">⟩⟩</a>@endif
    </span>

    @if ($esPdf)
        <span class="grupo">
            <button type="button" id="verPaginas" title="Vista de páginas" aria-label="Vista de páginas" aria-pressed="false">▦</button>
            <button type="button" id="anterior" aria-label="Página anterior">‹</button>
            <label class="grupo"><span class="sr">Página</span><input type="number" id="numero" min="1" value="1" aria-label="Número de página"></label>
            <span class="tenue">/ <span id="total">…</span></span>
            <button type="button" id="siguiente" aria-label="Página siguiente">›</button>
        </span>
        <span class="grupo ocultar-movil">
            <label><span class="sr">Buscar en el documento</span><input type="search" id="buscar" placeholder="Buscar en el texto" size="16"></label>
            <button type="button" id="buscarSiguiente" aria-label="Siguiente coincidencia">↓</button>
            <span class="tenue" id="resultadoBusqueda" aria-live="polite"></span>
        </span>
    @endif

    <span class="grupo">
        <button type="button" id="alejar" aria-label="Reducir">−</button>
        <span class="tenue" id="zoom" aria-live="polite">100%</span>
        <button type="button" id="acercar" aria-label="Ampliar">+</button>
        <button type="button" id="ajustar" class="ocultar-movil" title="Ver la página completa / ajustar al ancho">Ajustar</button>
        <button type="button" id="pantalla" aria-label="Pantalla completa" title="Pantalla completa">⛶</button>
        @if ($puedeDescargar && $esPdf)
            <button type="button" id="imprimir" title="Imprimir (Ctrl+P)" aria-label="Imprimir">⎙</button>
            <button type="button" id="aImagenes" class="ocultar-movil" title="Descargar las páginas como imágenes JPG (.zip)" aria-label="Descargar como imágenes">JPG</button>
        @endif
        @if ($puedeDescargar)
            <a class="boton" href="{{ route('documentos.descargar', $archivo) }}" title="Descargar" aria-label="Descargar">⭳</a>
        @endif
    </span>

    {{-- Organizar sin salir del visor: solo cuando está dentro del gestor (las acciones se ejecutan allí, con sus permisos) --}}
    <span class="grupo acciones" aria-label="Organizar">
        @if ($puedeEditar)
            <button type="button" data-accion="renombrar" title="Renombrar">Renombrar</button>
            <button type="button" data-accion="mover" title="Mover a otra carpeta">Mover</button>
            <button type="button" data-accion="nuevaVersion" title="Subir una nueva versión" class="ocultar-movil">Nueva versión</button>
        @endif
        <button type="button" data-accion="copiar" title="Copiar a otra carpeta" class="ocultar-movil">Copiar</button>
        <button type="button" data-accion="versiones" title="Historial de versiones">Versiones</button>
        <button type="button" data-accion="detalles" title="Detalles">ⓘ</button>
    </span>
</header>

<div class="area">
    @if ($esPdf)
        <aside id="miniaturas" class="miniaturas" aria-label="Páginas" hidden></aside>
    @endif
    <main id="lienzo" tabindex="-1">
        <p class="estado" id="estado" role="status">Cargando…</p>
    </main>
</div>
{{-- Páginas ya dibujadas que se imprimen; solo se ven al imprimir --}}
<div id="impresion" aria-hidden="true"></div>

@if (! $archivo->esVisualizable())
    <script>
        document.getElementById('estado').textContent = @js($puedeDescargar
            ? 'Este tipo de archivo no se puede previsualizar en el navegador. Usa el botón de descarga para abrirlo.'
            : 'Este tipo de archivo no se puede previsualizar en el navegador.');
        ['acercar', 'alejar', 'ajustar', 'zoom'].forEach((id) => document.getElementById(id).style.display = 'none');
    </script>
@elseif ($esPdf)
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js" integrity="sha512-q+4liFwdPC/bNdhUpZx6aXDx/h77yEQtn4I1slHydcbZK34nLaR3cAeYSJshoxIOq3mjEf7xJE8YWIUHMn+oCQ==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script>
    (() => {
        const url = @js(route('documentos.contenido', $archivo));
        const lienzo = document.getElementById('lienzo');
        const estado = document.getElementById('estado');
        const $ = (id) => document.getElementById(id);
        let pdf = null, escala = 1, ajustado = true, paginas = [], textos = [], coincidencias = [], indice = -1;
        // 'pagina': se ve la hoja completa (en pantallas anchas el ajuste al ancho dejaba solo el margen superior)
        let modoAjuste = 'pagina';
        const miniaturas = $('miniaturas');

        if (! window.pdfjsLib) {
            estado.textContent = 'No se pudo cargar el visor de PDF. Revisa tu conexión o descarga el documento.';
            estado.classList.add('error');
            return;
        }
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

        const carga = pdfjsLib.getDocument({ url, withCredentials: true, isEvalSupported: false });
        // Los archivos vienen de Firebase: mostrar el avance evita pensar que "no abre"
        carga.onProgress = ({ loaded, total }) => {
            estado.textContent = total
                ? `Cargando… ${Math.min(100, Math.round(loaded / total * 100))} %`
                : `Cargando… ${(loaded / 1048576).toFixed(1)} MB`;
        };
        carga.promise.then(async (doc) => {
            pdf = doc;
            $('total').textContent = pdf.numPages;
            $('numero').max = pdf.numPages;
            estado.remove();
            if (pdf.numPages > 1 && window.innerWidth > 900) alternarMiniaturas(true);
            await renderizar();
        }).catch((e) => {
            estado.textContent = e?.name === 'PasswordException'
                ? 'El PDF está protegido con contraseña y no se puede mostrar aquí.'
                : 'No se pudo abrir el documento. Puede estar dañado o no ser un PDF válido.';
            estado.classList.add('error');
        });

        async function renderizar() {
            const actual = paginaVisible();
            lienzo.querySelectorAll('.pagina').forEach((n) => n.remove());
            paginas = [];
            if (ajustado) {
                const base = (await pdf.getPage(1)).getViewport({ scale: 1 });
                const alAncho = (lienzo.clientWidth - 48) / base.width;
                const alAlto = (lienzo.clientHeight - 32) / base.height;
                escala = Math.max(0.25, Math.min(3, modoAjuste === 'ancho' ? alAncho : Math.min(alAncho, alAlto)));
            }
            $('zoom').textContent = Math.round(escala * 100) + '%';

            for (let n = 1; n <= pdf.numPages; n++) {
                const caja = document.createElement('div');
                caja.className = 'pagina';
                caja.dataset.pagina = n;
                caja.setAttribute('aria-label', 'Página ' + n);
                lienzo.appendChild(caja);
                paginas.push(caja);
            }
            // Las páginas se dibujan cuando entran en pantalla: no se renderiza todo el documento de golpe
            const observador = new IntersectionObserver((entradas) => {
                entradas.forEach((e) => { if (e.isIntersecting) { dibujar(e.target); observador.unobserve(e.target); } });
            }, { root: lienzo, rootMargin: '600px' });

            for (const caja of paginas) {
                const pagina = await pdf.getPage(+caja.dataset.pagina);
                const vp = pagina.getViewport({ scale: escala });
                caja.style.width = vp.width + 'px';
                caja.style.height = vp.height + 'px';
                caja.style.setProperty('--scale-factor', escala); // la capa de texto de PDF.js lo exige
                observador.observe(caja);
            }
            irA(actual);
        }

        async function dibujar(caja) {
            const pagina = await pdf.getPage(+caja.dataset.pagina);
            const vp = pagina.getViewport({ scale: escala });
            const ratio = window.devicePixelRatio || 1;
            const canvas = document.createElement('canvas');
            canvas.width = Math.floor(vp.width * ratio);
            canvas.height = Math.floor(vp.height * ratio);
            canvas.style.width = vp.width + 'px';
            canvas.style.height = vp.height + 'px';
            caja.appendChild(canvas);
            await pagina.render({ canvasContext: canvas.getContext('2d'), viewport: vp, transform: ratio !== 1 ? [ratio, 0, 0, ratio, 0, 0] : null }).promise;

            const capa = document.createElement('div');
            capa.className = 'capa-texto';
            caja.appendChild(capa);
            await pdfjsLib.renderTextLayer({ textContentSource: await pagina.getTextContent(), container: capa, viewport: vp }).promise;
            resaltar(caja);
        }

        function paginaVisible() {
            const arriba = lienzo.scrollTop + 40;
            const p = paginas.find((c) => c.offsetTop + c.offsetHeight > arriba);
            return p ? +p.dataset.pagina : 1;
        }

        function irA(n) {
            n = Math.max(1, Math.min(pdf?.numPages || 1, n));
            const caja = paginas[n - 1];
            if (caja) lienzo.scrollTop = caja.offsetTop - 16;
            marcarMiniatura(n);
            $('numero').value = n;
        }

        lienzo.addEventListener('scroll', () => {
            if (! pdf) return;
            const n = paginaVisible();
            $('numero').value = n;
            marcarMiniatura(n);
        }, { passive: true });

        // Vista de páginas: miniaturas que se dibujan al entrar en pantalla
        let miniaturasCreadas = false;
        function alternarMiniaturas(mostrar = miniaturas.hidden) {
            miniaturas.hidden = ! mostrar;
            $('verPaginas').setAttribute('aria-pressed', String(mostrar));
            if (mostrar && ! miniaturasCreadas && pdf) crearMiniaturas();
            if (ajustado && paginas.length) renderizar();
        }
        function crearMiniaturas() {
            miniaturasCreadas = true;
            const observador = new IntersectionObserver((entradas) => {
                entradas.forEach(async (e) => {
                    if (! e.isIntersecting) return;
                    observador.unobserve(e.target);
                    const pagina = await pdf.getPage(+e.target.dataset.pagina);
                    const vp = pagina.getViewport({ scale: 1 });
                    const v = pagina.getViewport({ scale: 260 / vp.width });
                    const canvas = e.target.querySelector('canvas');
                    canvas.width = v.width;
                    canvas.height = v.height;
                    await pagina.render({ canvasContext: canvas.getContext('2d'), viewport: v }).promise;
                });
            }, { root: miniaturas, rootMargin: '400px' });

            for (let n = 1; n <= pdf.numPages; n++) {
                const boton = document.createElement('button');
                boton.type = 'button';
                boton.className = 'miniatura';
                boton.dataset.pagina = n;
                boton.setAttribute('aria-label', 'Ir a la página ' + n);
                boton.innerHTML = '<canvas></canvas><span>' + n + '</span>';
                boton.onclick = () => irA(n);
                miniaturas.appendChild(boton);
                observador.observe(boton);
            }
            marcarMiniatura(paginaVisible());
        }
        function marcarMiniatura(n) {
            if (! miniaturasCreadas) return;
            miniaturas.querySelectorAll('.miniatura.actual').forEach((m) => m.classList.remove('actual'));
            const actual = miniaturas.querySelector(`[data-pagina="${n}"]`);
            if (! actual) return;
            actual.classList.add('actual');
            if (actual.offsetTop < miniaturas.scrollTop || actual.offsetTop + actual.offsetHeight > miniaturas.scrollTop + miniaturas.clientHeight) {
                miniaturas.scrollTop = actual.offsetTop - 12;
            }
        }
        $('verPaginas').onclick = () => alternarMiniaturas();

        // Imprimir: se dibuja cada página a ~150 ppp y se imprime ese juego de imágenes. No depende del visor
        // de PDF del navegador, que no puede imprimir el contenido protegido (sandbox) del gestor.
        let imprimiendo = false;
        async function imprimir() {
            if (! pdf || imprimiendo) return;
            imprimiendo = true;
            const boton = $('imprimir');
            const contenedor = $('impresion');
            contenedor.querySelectorAll('img').forEach((i) => URL.revokeObjectURL(i.src));
            contenedor.innerHTML = '';
            try {
                for (let n = 1; n <= pdf.numPages; n++) {
                    boton.textContent = `${n}/${pdf.numPages}`;
                    const pagina = await pdf.getPage(n);
                    const vp = pagina.getViewport({ scale: 2 });
                    const canvas = document.createElement('canvas');
                    canvas.width = vp.width;
                    canvas.height = vp.height;
                    await pagina.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
                    const blob = await new Promise((listo) => canvas.toBlob(listo, 'image/jpeg', 0.92));
                    const img = new Image();
                    img.src = URL.createObjectURL(blob);
                    await img.decode();
                    contenedor.appendChild(img);
                }
                document.body.classList.add('imprimiendo-pdf');
                window.print();
                document.body.classList.remove('imprimiendo-pdf');
            } finally {
                boton.textContent = '⎙';
                imprimiendo = false;
            }
        }
        // PDF a JPG: cada página a ~150 ppp, empaquetadas en un ZIP. Todo ocurre en el navegador (sin servidor)
        async function aImagenes() {
            const boton = $('aImagenes');
            if (! pdf || boton.disabled) return;
            boton.disabled = true;
            try {
                await cargarScript('https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js', 'sha512-XMVd28F1oH/O71fzwBnV7HucLxVwtxf26XV8P4wPk26EDxuGZ91N8bsOttmnomcCD3CS5ZMRL50H0GgOHvegtg==');
                const zip = new JSZip();
                const base = @js(pathinfo($archivo->nombre, PATHINFO_FILENAME));
                const cifras = String(pdf.numPages).length;
                for (let n = 1; n <= pdf.numPages; n++) {
                    boton.textContent = `${n}/${pdf.numPages}`;
                    const pagina = await pdf.getPage(n);
                    const vp = pagina.getViewport({ scale: 2 });
                    const canvas = document.createElement('canvas');
                    canvas.width = vp.width;
                    canvas.height = vp.height;
                    const ctx = canvas.getContext('2d');
                    ctx.fillStyle = '#fff';
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                    await pagina.render({ canvasContext: ctx, viewport: vp }).promise;
                    zip.file(`${base} - página ${String(n).padStart(cifras, '0')}.jpg`, await new Promise((listo) => canvas.toBlob(listo, 'image/jpeg', 0.9)));
                }
                const enlace = document.createElement('a');
                enlace.href = URL.createObjectURL(await zip.generateAsync({ type: 'blob' }));
                enlace.download = `${base} (imágenes).zip`;
                enlace.click();
                setTimeout(() => URL.revokeObjectURL(enlace.href), 10000);
            } catch (e) {
                alert('No se pudieron generar las imágenes. Revisa tu conexión e inténtalo de nuevo.');
            } finally {
                boton.textContent = 'JPG';
                boton.disabled = false;
            }
        }
        function cargarScript(src, integrity) {
            if (window.JSZip) return Promise.resolve();
            return new Promise((listo, error) => {
                const s = document.createElement('script');
                Object.assign(s, { src, integrity, crossOrigin: 'anonymous', referrerPolicy: 'no-referrer', onload: listo, onerror: error });
                document.head.appendChild(s);
            });
        }

        if ($('imprimir')) {
            $('aImagenes').onclick = aImagenes;
            $('imprimir').onclick = imprimir;
            document.addEventListener('keydown', (e) => {
                if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'p') { e.preventDefault(); imprimir(); }
            });
        }
        $('anterior').onclick = () => irA(paginaVisible() - 1);
        $('siguiente').onclick = () => irA(paginaVisible() + 1);
        $('numero').onchange = (e) => irA(+e.target.value);
        $('acercar').onclick = () => { ajustado = false; escala = Math.min(4, escala * 1.2); renderizar(); };
        $('alejar').onclick = () => { ajustado = false; escala = Math.max(0.25, escala / 1.2); renderizar(); };
        // Primer clic vuelve al ajuste; si ya estaba ajustado, alterna entre página completa y ancho
        $('ajustar').onclick = () => {
            if (ajustado) modoAjuste = modoAjuste === 'pagina' ? 'ancho' : 'pagina';
            ajustado = true;
            $('ajustar').textContent = modoAjuste === 'pagina' ? 'Ajustar' : 'Ancho';
            renderizar();
        };

        // Búsqueda de texto: se extrae el texto de cada página una sola vez
        async function buscar() {
            const termino = $('buscar').value.trim().toLocaleLowerCase();
            coincidencias = [];
            indice = -1;
            if (! termino || ! pdf) { $('resultadoBusqueda').textContent = ''; paginas.forEach(resaltar); return; }
            for (let n = 1; n <= pdf.numPages; n++) {
                textos[n] ??= (await (await pdf.getPage(n)).getTextContent()).items.map((i) => i.str).join(' ').toLocaleLowerCase();
                if (textos[n].includes(termino)) coincidencias.push(n);
            }
            $('resultadoBusqueda').textContent = coincidencias.length ? `${coincidencias.length} pág.` : 'Sin resultados';
            paginas.forEach(resaltar);
            siguiente();
        }
        function siguiente() {
            if (! coincidencias.length) return;
            indice = (indice + 1) % coincidencias.length;
            irA(coincidencias[indice]);
        }
        function resaltar(caja) {
            const termino = $('buscar')?.value.trim().toLocaleLowerCase();
            caja.querySelectorAll('.capa-texto span').forEach((s) => {
                s.classList.toggle('coincidencia', !! termino && s.textContent.toLocaleLowerCase().includes(termino));
            });
        }
        let espera;
        $('buscar').addEventListener('input', () => { clearTimeout(espera); espera = setTimeout(buscar, 350); });
        $('buscar').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); siguiente(); } });
        $('buscarSiguiente').onclick = siguiente;

        document.addEventListener('keydown', (e) => {
            if (e.target.tagName === 'INPUT') return;
            if (e.key === 'ArrowRight' || e.key === 'PageDown') irA(paginaVisible() + 1);
            if (e.key === 'ArrowLeft' || e.key === 'PageUp') irA(paginaVisible() - 1);
        });
        let redimension;
        window.addEventListener('resize', () => { if (ajustado && pdf) { clearTimeout(redimension); redimension = setTimeout(renderizar, 200); } });
    })();
    </script>
@elseif (in_array($archivo->tipo(), ['video', 'audio'], true))
    <script>
    (() => {
        const lienzo = document.getElementById('lienzo');
        const estado = document.getElementById('estado');
        const contenedor = document.createElement('div');
        contenedor.className = 'multimedia';
        const medio = document.createElement(@js($archivo->tipo()));
        medio.controls = true;
        medio.preload = 'metadata'; // solo los metadatos: no descarga todo el archivo hasta reproducir
        medio.playsInline = true;
        @unless ($puedeDescargar) medio.setAttribute('controlslist', 'nodownload'); @endunless
        medio.onloadedmetadata = () => estado.remove();
        medio.onerror = () => { estado.textContent = 'Tu navegador no puede reproducir este formato. Descárgalo para verlo.'; estado.classList.add('error'); };
        medio.src = @js(route('documentos.contenido', $archivo));
        contenedor.appendChild(medio);
        lienzo.appendChild(contenedor);
        // El zoom no aplica a multimedia
        ['acercar', 'alejar', 'ajustar', 'zoom'].forEach((id) => document.getElementById(id).style.display = 'none');
    })();
    </script>
@else
    <script>
    (() => {
        const lienzo = document.getElementById('lienzo');
        const estado = document.getElementById('estado');
        const contenedor = document.createElement('div');
        contenedor.className = 'imagen';
        const img = new Image();
        img.alt = @js($archivo->nombre);
        img.decoding = 'async';
        let escala = 1;

        const aplicar = () => { img.style.transform = `scale(${escala})`; document.getElementById('zoom').textContent = Math.round(escala * 100) + '%'; };
        const ajustar = () => {
            escala = Math.min(1, (lienzo.clientWidth - 32) / img.naturalWidth, (lienzo.clientHeight - 32) / img.naturalHeight);
            aplicar();
        };

        img.onload = () => { estado.remove(); contenedor.appendChild(img); lienzo.appendChild(contenedor); ajustar(); };
        img.onerror = () => { estado.textContent = 'No se pudo mostrar la imagen. Puede estar dañada o en un formato no compatible.'; estado.classList.add('error'); };
        img.src = @js(route('documentos.contenido', $archivo));

        document.getElementById('acercar').onclick = () => { escala = Math.min(8, escala * 1.25); aplicar(); };
        document.getElementById('alejar').onclick = () => { escala = Math.max(0.05, escala / 1.25); aplicar(); };
        document.getElementById('ajustar').onclick = ajustar;
        lienzo.addEventListener('wheel', (e) => { if (! e.ctrlKey) return; e.preventDefault(); escala = Math.max(0.05, Math.min(8, escala * (e.deltaY < 0 ? 1.1 : 0.9))); aplicar(); }, { passive: false });
    })();
    </script>
@endif
<script>
    document.getElementById('pantalla').onclick = () => {
        document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen?.();
    };

    // Dentro del gestor: las acciones se piden a la ventana padre (mismo origen) y se abren como modales sobre el visor
    if (window.parent !== window) {
        document.body.classList.add('incrustado');
        document.querySelectorAll('[data-accion]').forEach((boton) => {
            boton.addEventListener('click', () => window.parent.postMessage(
                { ivsd: true, accion: boton.dataset.accion, id: {{ (int) $archivo->id }} },
                window.location.origin
            ));
        });
    }
</script>
</body>
</html>
