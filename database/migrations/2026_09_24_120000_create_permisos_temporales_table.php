<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permisos_temporales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->string('permiso', 100);
            $table->text('motivo');
            $table->foreignId('concedido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('concedido_at');
            $table->timestamp('expira_at');
            $table->timestamp('revocado_at')->nullable();
            $table->foreignId('revocado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motivo_revocacion')->nullable();
            $table->timestamps();

            $table->index(['usuario_id', 'permiso', 'expira_at'], 'permisos_temporales_usuario_idx');
            $table->index(['cliente_id', 'permiso', 'expira_at'], 'permisos_temporales_cliente_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permisos_temporales');
    }
};
