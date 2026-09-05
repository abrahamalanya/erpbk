<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivote polimórfico: un crédito puede tener garantías de distintos
     * tipos (bien mueble, vehículo, inmueble). garantia_type guarda el alias
     * de morph ('bien', 'vehiculo', ...) definido en AppServiceProvider.
     */
    public function up(): void
    {
        Schema::create('credito_garantia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credito_id')->constrained('creditos_prendarios')->cascadeOnDelete();
            $table->string('garantia_type');
            $table->unsignedBigInteger('garantia_id');
            $table->timestamps();

            $table->unique(['credito_id', 'garantia_type', 'garantia_id'], 'credito_garantia_unique');
            $table->index(['garantia_type', 'garantia_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_garantia');
    }
};
