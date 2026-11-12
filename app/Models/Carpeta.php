<?php

namespace App\Models;

use App\Services\Documentos\AccesoDocumental;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Carpeta extends Model
{
    use HasFactory, SoftDeletes;

    public const UNIDAD_COMPARTIDA = 'compartida';
    public const UNIDAD_PRIVADA = 'privada';

    protected $fillable = ['nombre', 'carpeta_padre_id', 'unidad', 'restringida', 'descripcion', 'color', 'icono', 'creado_por'];

    /** Ícono heroicon (sólido) de la carpeta; si no eligieron uno, la carpeta normal. */
    public static function iconoDe(?string $icono): string
    {
        return 'heroicon-s-'.(array_key_exists((string) $icono, config('documentos.iconos')) ? $icono : 'folder');
    }

    /** Color hexadecimal de la paleta cerrada; null = color por defecto del tema. */
    public static function colorDe(?string $color): ?string
    {
        return config('documentos.colores.'.$color.'.1');
    }

    protected function casts(): array
    {
        return [
            'restringida' => 'boolean',
            'papelera_raiz' => 'boolean',
        ];
    }

    public function padre(){
        return $this->belongsTo(Carpeta::class, 'carpeta_padre_id');
    }

    public function carpetasHijas(){
        return $this->hasMany(Carpeta::class,'carpeta_padre_id');
    }

    public function archivos(){
        return $this->hasMany(Archivos::class);
    }

    public function permisos(){
        return $this->hasMany(CarpetaPermiso::class);
    }

    public function creador(){
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function scopeVisiblePara(Builder $query, User $user): Builder
    {
        return app(AccesoDocumental::class)->filtrarCarpetas($query, $user);
    }

    /** IDs de la ruta materializada, de la raíz a esta carpeta (incluida). */
    public function idsRuta(): array
    {
        return array_map('intval', array_values(array_filter(explode('/', (string) $this->ruta))));
    }

    /** Ubicación legible para nombrar objetos en el bucket: ["Unidad compartida", "Inspecciones", "EDS X"] */
    public function nombresRuta(): array
    {
        return [
            $this->unidad === self::UNIDAD_PRIVADA ? 'Unidad privada' : 'Unidad compartida',
            ...$this->ancestrosYYo()->pluck('nombre')->all(),
        ];
    }

    /** Carpetas desde la raíz hasta esta, para los breadcrumbs. */
    public function ancestrosYYo()
    {
        $ids = $this->idsRuta();

        return Carpeta::withTrashed()->whereIn('id', $ids)->get()
            ->sortBy(fn (Carpeta $c) => array_search($c->id, $ids))->values();
    }

    public function esDescendienteDe(Carpeta $otra): bool
    {
        return $this->id !== $otra->id && str_starts_with((string) $this->ruta, (string) $otra->ruta);
    }
}
