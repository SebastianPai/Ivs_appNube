<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventarioObjeto extends Model
{
    protected $table = 'inventario_objetos';

    protected $fillable = [
        'disco', 'objeto', 'directorio', 'nombre', 'extension', 'tamano', 'mime', 'hash_md5', 'modificado_en',
        'metadatos', 'clave_patron', 'sufijo', 'grupo_id', 'duplicado_de_id', 'archivo_id', 'estado', 'ejecucion_id',
    ];

    protected function casts(): array
    {
        return [
            'metadatos' => 'array',
            'modificado_en' => 'datetime',
            'tamano' => 'integer',
        ];
    }

    public function grupo(){
        return $this->belongsTo(RecuperacionGrupo::class, 'grupo_id');
    }

    public function duplicadoDe(){
        return $this->belongsTo(InventarioObjeto::class, 'duplicado_de_id');
    }

    public function archivo(){
        return $this->belongsTo(Archivos::class, 'archivo_id')->withTrashed();
    }
}
