<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('visitors', function (Blueprint $table) {
            $table->id();
            $table->id();
            $table->string('email')->unique();
            $table->date('fecha_primera_visita')->nullable();
            $table->date('fecha_ultima_visita')->nullable();
            $table->integer('visitas_totales')->default(0);
            $table->integer('visitas_anio_actual')->default(0);
            $table->integer('visitas_mes_actual')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visitors');
    }
};
