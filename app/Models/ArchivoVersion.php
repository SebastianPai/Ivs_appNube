<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArchivoVersion extends Model
{
    protected $table = 'archivo_versiones';

    protected $fillable = [
        'archivo_id', 'numero', 'disco', 'objeto', 'tamano', 'hash_md5', 'hash_original', 'mime',
        'creado_por', 'comentario', 'restaurada_desde_id', 'metadatos',
    ];

    protected function casts(): array
    {
        return [
            'metadatos' => 'array',
            'tamano' => 'integer',
            'numero' => 'integer',
        ];
    }

    public function archivo(){
        return $this->belongsTo(Archivos::class, 'archivo_id')->withTrashed();
    }

    public function creador(){
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function restauradaDesde(){
        return $this->belongsTo(ArchivoVersion::class, 'restaurada_desde_id');
    }
}
