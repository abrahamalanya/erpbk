<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Soporte de pago por cuotas individuales (créditos diarios, ver
     * CreditoService::pagarCuotasDiario()). `pagada_at` es la fuente de
     * verdad de si una cuota ya se pagó; `mora_pagada` congela la mora
     * efectivamente cobrada por esa cuota en el momento del pago (no se
     * recalcula después); `cobro_id` referencia el Cobro que la pagó — un
     * mismo Cobro puede cubrir varias cuotas a la vez.
     */
    public function up(): void
    {
        Schema::table('cuotas_credito_prendario', function (Blueprint $table): void {
            $table->timestamp('pagada_at')->nullable()->after('monto_total');
            $table->decimal('mora_pagada', 10, 2)->nullable()->after('pagada_at');
            $table->foreignId('cobro_id')->nullable()->after('mora_pagada')->constrained('cobros')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cuotas_credito_prendario', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cobro_id');
            $table->dropColumn(['pagada_at', 'mora_pagada']);
        });
    }
};
