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
        Schema::create('simulaciones_credito', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('agencia_id')->constrained()->restrictOnDelete();
            $table->string('tipo_credito');
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->decimal('monto_prestamo', 12, 2);
            $table->decimal('interes', 5, 2);
            $table->string('tipo_cuota');
            $table->unsignedInteger('numero_cuotas')->nullable();
            $table->unsignedInteger('plazo_dias');

            $table->date('fecha_base');
            $table->decimal('monto_total_pagar', 12, 2);
            $table->json('cronograma');

            $table->timestamps();

            $table->index(['empresa_id', 'agencia_id']);
            $table->index(['empresa_id', 'tipo_credito']);
            $table->index('cliente_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('simulaciones_credito');
    }
};
