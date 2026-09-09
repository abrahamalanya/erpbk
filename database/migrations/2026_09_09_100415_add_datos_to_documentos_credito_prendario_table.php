<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot de datos propios de un documento que no se pueden re-derivar
     * del crédito después (p.ej. el monto pagado y su desglose en un voucher
     * de pago, el saldo de caja tras un desembolso). Los demás documentos
     * (contrato, declaración, ...) siguen renderizándose en vivo y no lo usan.
     */
    public function up(): void
    {
        Schema::table('documentos_credito_prendario', function (Blueprint $table): void {
            $table->json('datos')->nullable()->after('archivo_firmado_path');
        });
    }

    public function down(): void
    {
        Schema::table('documentos_credito_prendario', function (Blueprint $table): void {
            $table->dropColumn('datos');
        });
    }
};
