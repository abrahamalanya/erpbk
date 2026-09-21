<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enlaza un voucher de pago con el cobro que lo originó, para poder abrir
     * el voucher de cualquier cobro (GET /cobros/{cobro}/voucher). Nulo en
     * los demás documentos y en los vouchers anteriores a este cambio.
     */
    public function up(): void
    {
        Schema::table('documentos_credito_prendario', function (Blueprint $table): void {
            $table->foreignId('cobro_id')->nullable()->after('credito_id')->constrained('cobros')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documentos_credito_prendario', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cobro_id');
        });
    }
};
