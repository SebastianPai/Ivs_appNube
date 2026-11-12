<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class Actividad extends Model
{
    protected $table = 'actividades';

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'accion', 'sujeto_tipo', 'sujeto_id', 'descripcion', 'resultado', 'metadatos', 'ip'];

    protected function casts(): array
    {
        return ['metadatos' => 'array'];
    }

    protected static function booted(): void
    {
        // El historial es de solo inserción
        static::updating(fn () => throw new LogicException('El historial de actividad no se puede modificar.'));
        static::deleting(fn () => throw new LogicException('El historial de actividad no se puede eliminar.'));
    }

    public function user(){
        return $this->belongsTo(User::class);
    }

    public static function registrar(string $accion, ?Model $sujeto = null, ?string $descripcion = null, array $metadatos = [], string $resultado = 'ok'): self
    {
        return static::create([
            'user_id' => auth()->id(),
            'accion' => $accion,
            'sujeto_tipo' => $sujeto ? class_basename($sujeto) : null,
            'sujeto_id' => $sujeto?->getKey(),
            'descripcion' => $descripcion ? mb_substr($descripcion, 0, 500) : null,
            'resultado' => $resultado,
            'metadatos' => $metadatos ?: null,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
