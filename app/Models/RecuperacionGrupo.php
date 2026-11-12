<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecuperacionGrupo extends Model
{
    protected $table = 'recuperacion_grupos';

    protected $fillable = ['tipo', 'clave', 'confianza', 'motivo', 'total', 'estado', 'carpeta_destino_id', 'lote', 'revisado_por', 'revisado_en'];

    protected function casts(): array
    {
        return ['revisado_en' => 'datetime'];
    }

    public function objetos(){
        return $this->hasMany(InventarioObjeto::class, 'grupo_id');
    }

    public function carpetaDestino(){
        return $this->belongsTo(Carpeta::class, 'carpeta_destino_id')->withTrashed();
    }

    public function revisadoPor(){
        return $this->belongsTo(User::class, 'revisado_por');
    }
}
