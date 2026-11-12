{{-- Ilustración de la pantalla de ingreso (misma paleta y estilo isométrico de app.ivscertificaciones.com):
     nube con subida, carpeta con documentos, PDF con lupa (búsqueda por contenido), candado (permisos) y sello aprobado. --}}
<svg viewBox="0 0 500 440" xmlns="http://www.w3.org/2000/svg" role="img">
    {{-- Sombra del piso --}}
    <ellipse cx="270" cy="392" rx="200" ry="40" fill="#efdedb" opacity=".55" />

    {{-- Piezas sueltas --}}
    <g>
        <polygon points="20,250 34,243 48,250 34,257" fill="#c98b8d" />
        <polygon points="455,190 467,184 479,190 467,196" fill="#e8c8a0" />
        <polygon points="470,330 482,324 494,330 482,336" fill="#e9cfcc" />
        <polygon points="90,410 102,404 114,410 102,416" fill="#e8c8a0" />
        <polygon points="430,420 440,415 450,420 440,425" fill="#c98b8d" />
        <polygon points="60,130 70,125 80,130 70,135" fill="#e9cfcc" />
        <polygon points="230,22 240,17 250,22 240,27" fill="#e8c8a0" />
    </g>

    {{-- Nubes pequeñas --}}
    <g fill="#f6dcdc">
        <circle cx="92" cy="92" r="12" /><circle cx="108" cy="86" r="16" /><circle cx="124" cy="94" r="11" />
        <rect x="84" y="92" width="48" height="12" rx="6" />
    </g>
    <g fill="#f3e3e1">
        <circle cx="430" cy="64" r="10" /><circle cx="444" cy="58" r="14" /><circle cx="458" cy="66" r="9" />
        <rect x="422" y="64" width="44" height="10" rx="5" />
    </g>

    {{-- Plataforma --}}
    <g>
        <polygon points="110,330 270,250 430,330 270,410" fill="#f6e9e7" />
        <polygon points="110,330 270,410 270,428 110,348" fill="#d9b9b8" />
        <polygon points="270,410 430,330 430,348 270,428" fill="#c98b8d" />
        <polygon points="160,330 270,275 380,330 270,385" fill="#efdedb" />
    </g>

    {{-- Conexiones nube ↔ documentos --}}
    <g fill="none" stroke="#c98b8d" stroke-width="2.5" stroke-linecap="round" stroke-dasharray="3 8">
        <path d="M250 178 C 240 205 232 222 228 248" />
        <path d="M345 178 C 360 196 372 205 382 214" />
    </g>

    {{-- Nube principal --}}
    <g>
        <g fill="#ecd0d0">
            <circle cx="252" cy="146" r="38" /><circle cx="300" cy="118" r="50" /><circle cx="352" cy="144" r="40" />
            <rect x="214" y="140" width="178" height="46" rx="23" />
        </g>
        <g fill="#fbf3f1">
            <circle cx="252" cy="138" r="38" /><circle cx="300" cy="110" r="50" /><circle cx="352" cy="136" r="40" />
            <rect x="214" y="132" width="178" height="46" rx="23" />
        </g>
        {{-- flecha de subida --}}
        <polygon points="300,98 324,124 311,124 311,156 289,156 289,124 276,124" fill="#a9575c" />
        <rect x="282" y="162" width="36" height="6" rx="3" fill="#e4b3b2" />
    </g>

    {{-- Carpeta con documentos --}}
    <g>
        {{-- pestaña y panel trasero --}}
        <polygon points="180,212 180,200 194,193 214,203 214,229" fill="#d9ae7c" />
        <polygon points="180,292 292,348 292,262 180,206" fill="#ddb283" />
        {{-- documentos asomando --}}
        <polygon points="190,262 282,308 282,226 190,180" fill="#fff" stroke="#ead7d5" stroke-width="1.5" />
        <g stroke="#ead7d5" stroke-width="4" stroke-linecap="round">
            <line x1="204" y1="204" x2="262" y2="233" />
            <line x1="204" y1="217" x2="268" y2="249" />
            <line x1="204" y1="230" x2="250" y2="253" />
        </g>
        <polygon points="198,270 288,315 288,246 198,201" fill="#fbf3f1" stroke="#ead7d5" stroke-width="1.5" />
        <rect x="212" y="222" width="26" height="11" rx="3" fill="#c98b8d" transform="matrix(1 .5 0 1 0 -106)" />
        <g stroke="#e9d8d6" stroke-width="4" stroke-linecap="round">
            <line x1="212" y1="240" x2="270" y2="269" />
        </g>
        {{-- panel delantero y canto --}}
        <polygon points="168,300 280,356 280,300 168,244" fill="#e8c8a0" />
        <polygon points="280,356 296,348 296,292 280,300" fill="#c99a68" />
        <polygon points="168,244 280,300 296,292 184,236" fill="#f0d6b4" />
        <circle r="7" fill="#fbf3f1" opacity=".9" transform="matrix(.894 .447 0 1 224 296)" />
    </g>

    {{-- Documento PDF flotante con lupa (búsqueda dentro de los PDF) --}}
    <g>
        <rect x="372" y="214" width="74" height="92" rx="8" fill="#e9d8d6" />
        <rect x="364" y="206" width="74" height="92" rx="8" fill="#fff" stroke="#ead7d5" stroke-width="1.5" />
        <rect x="376" y="218" width="30" height="14" rx="4" fill="#a9575c" />
        <text x="391" y="228.5" text-anchor="middle" font-family="Poppins, sans-serif" font-size="9" font-weight="700" fill="#fff">PDF</text>
        <g stroke="#efe3e1" stroke-width="4" stroke-linecap="round">
            <line x1="376" y1="246" x2="424" y2="246" />
            <line x1="376" y1="258" x2="418" y2="258" />
            <line x1="376" y1="270" x2="424" y2="270" />
            <line x1="376" y1="282" x2="404" y2="282" />
        </g>
        <line x1="436" y1="290" x2="458" y2="312" stroke="#8b3c42" stroke-width="10" stroke-linecap="round" />
        <circle cx="420" cy="274" r="22" fill="#fbf4f4" fill-opacity=".75" stroke="#a9575c" stroke-width="6" />
        <line x1="410" y1="268" x2="424" y2="268" stroke="#e4b3b2" stroke-width="3.5" stroke-linecap="round" />
        <line x1="410" y1="278" x2="430" y2="278" stroke="#e4b3b2" stroke-width="3.5" stroke-linecap="round" />
    </g>

    {{-- Candado (permisos por carpeta) --}}
    <g>
        <path d="M321 312 v-10 a12 12 0 0 1 24 0 v10" fill="none" stroke="#94595e" stroke-width="5.5" />
        <rect x="314" y="310" width="38" height="32" rx="6" fill="#b9787b" />
        <circle cx="333" cy="323" r="4" fill="#fbf3f1" />
        <rect x="331" y="325" width="4" height="9" rx="2" fill="#fbf3f1" />
    </g>

    {{-- Sello aprobado --}}
    <g>
        <circle cx="176" cy="150" r="22" fill="#e8c8a0" />
        <path d="M166 150 l7 7 l13 -14" fill="none" stroke="#fff" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round" />
    </g>

    {{-- Burbuja de "sincronizado" --}}
    <g>
        <polygon points="400,164 410,164 400,175" fill="#f5e6e6" />
        <rect x="392" y="140" width="40" height="26" rx="8" fill="#f5e6e6" />
        <g fill="#c98b8d"><circle cx="403" cy="153" r="3" /><circle cx="412" cy="153" r="3" /><circle cx="421" cy="153" r="3" /></g>
    </g>
</svg>
