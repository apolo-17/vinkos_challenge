<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Statistic extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'jyv',
        'badmail',
        'baja',
        'fecha_envio',
        'fecha_open',
        'opens',
        'opens_virales',
        'fecha_click',
        'clicks',
        'clicks_virales',
        'links',
        'ips',
        'navegadores',
        'plataformas'
    ];

    public function visitor()
    {
        return $this->belongsTo(Visitor::class, 'email', 'email');
    }
}
