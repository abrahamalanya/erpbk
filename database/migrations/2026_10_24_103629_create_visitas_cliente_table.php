<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estado de permanencia del asesor cerca del lugar de cobranza de un
     * cliente, un día a la vez — lo computa VisitaClienteService en cada
     * ping GPS válido (ver UbicacionAsesorController::store()). Una sola
     * fila por cliente+asesor+fecha (nunca se duplica por ping, solo se
     * actualiza): `confirmada_en` no nulo es el estado "amarillo" del mapa
     * unificado; el "verde" (cobro de hoy) no se guarda aquí, se deriva de
     * `cobros` en el momento de leer.
     */
    public function up(): void
    {
        Schema::create('visitas_cliente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('asesor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('agencia_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('fecha');
            $table->timestamp('inicio_en')->nullable();
            $table->timestamp('ultima_lectura_en')->nullable();
            $table->timestamp('confirmada_en')->nullable();
            $table->timestamps();

            $table->unique(['cliente_id', 'asesor_id', 'fecha']);
            $table->index(['asesor_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitas_cliente');
    }
};
