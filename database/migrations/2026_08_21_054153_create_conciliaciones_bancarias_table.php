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
        Schema::create('conciliaciones_bancarias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cuenta_bancaria_id')->constrained('cuentas_bancarias')->restrictOnDelete();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();

            $table->decimal('saldo_sistema', 12, 2);
            $table->decimal('saldo_banco', 12, 2);
            $table->decimal('diferencia', 12, 2);
            $table->string('observacion')->nullable();

            $table->foreignId('conciliado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->date('fecha');

            $table->timestamps();

            $table->index(['cuenta_bancaria_id', 'fecha']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conciliaciones_bancarias');
    }
};
