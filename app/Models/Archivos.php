<?php

namespace App\Models;

use App\Services\Documentos\AccesoDocumental;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Archivos extends Model
{
    use HasFactory, SoftDeletes;

    public const ESTADO_ACTIVO = 'activo';
    public const ESTADO_PENDIENTE = 'pendiente_revision';

    protected $fillable = [
        'nombre', 'carpeta_id', 'nombre_original', 'extension', 'mime', 'tamano', 'hash_md5', 'hash_original',
        'identificador_documental', 'estado', 'origen', 'subido_por',
    ];

    protected function casts(): array
    {
        return [
            'tamano' => 'integer',
            'papelera_raiz' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Archivos $archivo) {
            $archivo->uuid ??= (string) Str::uuid();
        });
    }

    public function carpeta(){
        return $this->belongsTo(Carpeta::class)->withTrashed();
    }

    public function versiones(){
        return $this->hasMany(ArchivoVersion::class, 'archivo_id')->orderByDesc('numero');
    }

    public function versionActual(){
        return $this->belongsTo(ArchivoVersion::class, 'version_actual_id');
    }

    public function subidoPor(){
        return $this->belongsTo(User::class, 'subido_por');
    }

    public function scopeVisiblePara(Builder $query, User $user): Builder
    {
        return app(AccesoDocumental::class)->filtrarArchivos($query, $user);
    }

    /** Categoría usada para iconos, filtros y para decidir si se puede previsualizar. */
    public function tipo(): string
    {
        return self::tipoPorExtension($this->extension);
    }

    public static function tipoPorExtension(?string $extension): string
    {
        return match (strtolower((string) $extension)) {
            'pdf' => 'pdf',
            'png', 'jpg', 'jpeg', 'webp', 'gif' => 'imagen',
            'doc', 'docx', 'odt', 'rtf' => 'word',
            'xls', 'xlsx', 'ods', 'csv' => 'excel',
            'ppt', 'pptx', 'odp' => 'powerpoint',
            'zip', 'rar', '7z' => 'comprimido',
            'txt', 'xml', 'json' => 'texto',
            'mp4', 'webm', 'm4v', 'mov', 'ogv' => 'video',
            'mp3', 'wav', 'm4a', 'ogg' => 'audio',
            default => 'otro',
        };
    }

    public function esVisualizable(): bool
    {
        $mimes = config('documentos.visualizables.'.$this->tipo());

        return is_array($mimes) && in_array($this->mime, $mimes, true);
    }
}
