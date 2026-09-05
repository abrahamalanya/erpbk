<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fotos polimórficas de una garantía (bien, vehículo, inmueble, ...).
     */
    public function up(): void
    {
        Schema::create('garantia_fotos', function (Blueprint $table) {
            $table->id();
            $table->string('garantia_type');
            $table->unsignedBigInteger('garantia_id');
            $table->string('path');
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();

            $table->index(['garantia_type', 'garantia_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('garantia_fotos');
    }
};
