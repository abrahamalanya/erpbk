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
        Schema::create('creditos_prendarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('agencia_id')->constrained()->restrictOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('refrendo_de_credito_id')->nullable()->constrained('creditos_prendarios')->nullOnDelete();
            $table->unsignedInteger('numero_refrendo')->default(0);

            $table->decimal('monto_prestamo', 12, 2);
            $table->decimal('interes', 5, 2);
            $table->string('tipo_cuota');
            $table->unsignedInteger('plazo_dias');

            $table->string('estado')->default('pendiente');

            $table->foreignId('aprobado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('fecha_aprobacion')->nullable();
            $table->string('motivo_rechazo')->nullable();

            $table->date('fecha_desembolso')->nullable();
            $table->date('fecha_vencimiento')->nullable();

            $table->timestamps();

            $table->index(['empresa_id', 'estado']);
            $table->index('fecha_vencimiento');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('creditos_prendarios');
    }
};
