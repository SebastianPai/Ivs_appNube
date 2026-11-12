<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarpetaPermiso extends Model
{
    public const NIVEL_VER = 'ver';
    public const NIVEL_EDITAR = 'editar';

    protected $fillable = ['carpeta_id', 'user_id', 'role_id', 'nivel', 'otorgado_por'];

    public function carpeta(){
        return $this->belongsTo(Carpeta::class);
    }

    public function user(){
        return $this->belongsTo(User::class);
    }

    public function role(){
        return $this->belongsTo(Role::class);
    }
}
