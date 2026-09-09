<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aval (garante) de un crédito — solo lo usan los créditos hipotecarios.
     * Es una persona registrada igual que un cliente, por eso apunta a la
     * tabla clientes.
     */
    public function up(): void
    {
        Schema::table('creditos_prendarios', function (Blueprint $table): void {
            $table->foreignId('aval_id')->nullable()->after('cliente_id')->constrained('clientes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('creditos_prendarios', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('aval_id');
        });
    }
};
