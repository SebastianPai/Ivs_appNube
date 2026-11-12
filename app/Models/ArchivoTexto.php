<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArchivoTexto extends Model
{
    public const INDEXADO = 'indexado';
    public const SIN_TEXTO = 'sin_texto';
    public const ERROR = 'error';

    protected $primaryKey = 'archivo_id';

    public $incrementing = false;

    protected $fillable = ['archivo_id', 'version_id', 'estado', 'texto'];
}
