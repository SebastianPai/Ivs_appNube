<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TareaDocumental extends Model
{
    protected $table = 'tareas_documentales';

    protected $fillable = ['user_id', 'tipo', 'estado', 'parametros', 'resultado', 'error'];

    protected function casts(): array
    {
        return [
            'parametros' => 'array',
            'resultado' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TareaDocumental $tarea) {
            $tarea->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(){
        return $this->belongsTo(User::class);
    }

    /** Carpeta temporal privada de la tarea (fuera de public/). */
    public function directorio(): string
    {
        return 'tareas/'.$this->uuid;
    }
}
