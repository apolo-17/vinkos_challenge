<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Visitor extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'fecha_primera_visita',
        'fecha_ultima_visita',
        'visitas_totales',
        'visitas_anio_actual',
        'visitas_mes_actual'
    ];

    public function statistics()
    {
        return $this->hasMany(Statistic::class, 'email', 'email');
    }
}
