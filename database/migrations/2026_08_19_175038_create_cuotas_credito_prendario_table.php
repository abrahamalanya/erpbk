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
        Schema::create('cuotas_credito_prendario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credito_id')->constrained('creditos_prendarios')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('numero_cuota');
            $table->date('fecha_vencimiento');
            $table->decimal('monto_capital', 10, 2);
            $table->decimal('monto_interes', 10, 2);
            $table->decimal('monto_total', 10, 2);

            $table->timestamps();

            $table->index('credito_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cuotas_credito_prendario');
    }
};
