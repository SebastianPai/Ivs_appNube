<?php

namespace App\Http\Controllers;

use App\Models\Actividad;
use App\Models\ArchivoVersion;
use App\Models\Archivos;
use App\Models\TareaDocumental;
use App\Services\Documentos\AlmacenDocumental;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class ArchivoController extends Controller
{
    public function __construct(private AlmacenDocumental $almacen) {}

    /** Descarga (versión actual o una anterior). La autorización se valida aquí, antes de firmar ninguna URL. */
    public function descargar(Request $request, Archivos $archivo): Response
    {
        Gate::authorize('download', $archivo);

        $version = $this->version($request, $archivo);
        $nombre = $version->id === $archivo->version_actual_id
            ? $archivo->nombre
            : pathinfo($archivo->nombre, PATHINFO_FILENAME)." (v{$version->numero})".($archivo->extension ? '.'.$archivo->extension : '');

        Actividad::registrar('archivo.descargado', $archivo, $archivo->nombre, ['version' => $version->numero]);

        return $this->almacen->respuesta($version, $nombre, false);
    }

    /** Contenido en línea para el visor: mismas reglas que la descarga y solo para tipos visualizables. */
    public function contenido(Request $request, Archivos $archivo): Response
    {
        Gate::authorize('view', $archivo);
        abort_unless($archivo->esVisualizable(), 415, 'Este tipo de archivo no se puede visualizar.');

        return $this->almacen->respuesta($this->version($request, $archivo), $archivo->nombre, true);
    }

    public function visor(Archivos $archivo)
    {
        Gate::authorize('view', $archivo);

        // Navegación entre documentos visualizables de la misma carpeta
        $hermanos = Archivos::query()
            ->visiblePara(auth()->user())
            ->where('carpeta_id', $archivo->carpeta_id)
            ->whereIn('extension', ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'mp4', 'webm', 'm4v', 'ogv', 'mp3', 'wav', 'm4a', 'ogg'])
            ->orderBy('nombre')
            ->pluck('id')
            ->values();
        $posicion = $hermanos->search($archivo->id);

        return view('documentos.visor', [
            'archivo' => $archivo,
            'anterior' => $posicion !== false && $posicion > 0 ? $hermanos[$posicion - 1] : null,
            'siguiente' => $posicion !== false ? ($hermanos[$posicion + 1] ?? null) : null,
            'puedeDescargar' => Gate::allows('download', $archivo),
            'puedeEditar' => Gate::allows('update', $archivo),
        ]);
    }

    /** Resultado de una herramienta PDF: solo para quien la ejecutó. */
    public function resultadoTarea(TareaDocumental $tarea): Response
    {
        abort_unless($tarea->user_id === auth()->id() && $tarea->estado === 'completada', 404);

        $ruta = $tarea->resultado['ruta'] ?? null;
        abort_unless($ruta && Storage::disk('local')->exists($ruta), 410, 'El resultado ya no está disponible.');

        return Storage::disk('local')->download($ruta, $tarea->resultado['nombre'] ?? basename($ruta), [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function version(Request $request, Archivos $archivo): ArchivoVersion
    {
        $id = $request->integer('version') ?: $archivo->version_actual_id;

        return ArchivoVersion::where('archivo_id', $archivo->id)->findOrFail($id);
    }
}
