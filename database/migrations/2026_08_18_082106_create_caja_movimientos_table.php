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
        Schema::create('caja_movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caja_ciclo_id')->constrained()->restrictOnDelete();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();

            $table->string('tipo');
            $table->decimal('monto', 12, 2);
            $table->string('concepto')->nullable();

            $table->foreignId('billetaje_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->date('fecha_caja');

            $table->timestamps();

            $table->index('caja_ciclo_id');
            $table->index('fecha_caja');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('caja_movimientos');
    }
};
