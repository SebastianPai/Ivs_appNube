<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventarioEjecucion extends Model
{
    protected $table = 'inventario_ejecuciones';

    protected $fillable = ['disco', 'prefijo', 'estado', 'procesados', 'nuevos', 'actualizados', 'ultimo_objeto', 'error', 'iniciado_por', 'finalizado_en'];

    protected function casts(): array
    {
        return ['finalizado_en' => 'datetime'];
    }

    public function iniciadoPor(){
        return $this->belongsTo(User::class, 'iniciado_por');
    }
}
