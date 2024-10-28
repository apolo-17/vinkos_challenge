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
        Schema::create('statistics', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('jyv')->nullable();
            $table->string('badmail')->nullable();
            $table->string('baja')->default(false);
            $table->dateTime('fecha_envio')->nullable();
            $table->dateTime('fecha_open')->nullable();
            $table->string('opens')->default(0);
            $table->string('opens_virales')->default(0);
            $table->dateTime('fecha_click')->nullable();
            $table->string('clicks')->default(0);
            $table->string('clicks_virales')->default(0);
            $table->string('links')->default(0);
            $table->string('ips')->default(0);
            $table->string('navegadores')->nullable();
            $table->string('plataformas')->nullable();

            $table->foreign('email')->references('email')->on('visitors')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('statistics');
    }
};
