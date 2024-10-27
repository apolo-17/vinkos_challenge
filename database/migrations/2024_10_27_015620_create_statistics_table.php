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
            $table->id();
            $table->string('email');
            $table->boolean('jyv')->default(false);
            $table->boolean('badmail')->default(false);
            $table->boolean('baja')->default(false);
            $table->date('fecha_envio')->nullable();
            $table->date('fecha_open')->nullable();
            $table->integer('opens')->default(0);
            $table->integer('opens_virales')->default(0);
            $table->date('fecha_click')->nullable();
            $table->integer('clicks')->default(0);
            $table->integer('clicks_virales')->default(0);
            $table->integer('links')->default(0);
            $table->integer('ips')->default(0);
            $table->string('navegadores')->nullable();
            $table->string('plataformas')->nullable();
            $table->timestamps();

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
