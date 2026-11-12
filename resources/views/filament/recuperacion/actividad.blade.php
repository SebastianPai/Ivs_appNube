<dl class="ivsd-propiedades" style="font-size: .875rem;">
    <dt>Fecha</dt><dd>{{ $actividad->created_at?->format('d/m/Y H:i:s') }}</dd>
    <dt>Usuario</dt><dd>{{ $actividad->user?->name ?? 'Sistema' }}</dd>
    <dt>Acción</dt><dd>{{ $actividad->accion }}</dd>
    <dt>Elemento</dt><dd>{{ $actividad->sujeto_tipo ? $actividad->sujeto_tipo.' #'.$actividad->sujeto_id : '—' }}</dd>
    <dt>Descripción</dt><dd>{{ $actividad->descripcion ?? '—' }}</dd>
    <dt>Resultado</dt><dd>{{ $actividad->resultado }}</dd>
    <dt>IP</dt><dd>{{ $actividad->ip ?? '—' }}</dd>
</dl>
@if ($actividad->metadatos)
    <pre class="ivsd-mono" style="margin-top: 1rem; padding: .75rem; border-radius: .5rem; background: rgba(127,127,127,.08); white-space: pre-wrap;">{{ json_encode($actividad->metadatos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
@endif
