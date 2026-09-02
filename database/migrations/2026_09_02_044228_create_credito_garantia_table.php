<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Polymorphic replacement for bien_credito_prendario: a crédito can now
     * hold garantías of different kinds (bien mueble, vehículo, inmueble).
     * garantia_type stores the morph alias ('bien', 'vehiculo', ...) set in
     * AppServiceProvider. The old pivot is left in place (unused) and dropped
     * by a later cleanup migration once this one is confirmed in production.
     */
    public function up(): void
    {
        Schema::create('credito_garantia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credito_id')->constrained('creditos_prendarios')->cascadeOnDelete();
            $table->string('garantia_type');
            $table->unsignedBigInteger('garantia_id');
            $table->timestamps();

            $table->unique(['credito_id', 'garantia_type', 'garantia_id'], 'credito_garantia_unique');
            $table->index(['garantia_type', 'garantia_id']);
        });

        if (Schema::hasTable('bien_credito_prendario')) {
            DB::table('bien_credito_prendario')->orderBy('id')->chunk(500, function ($filas): void {
                DB::table('credito_garantia')->insert($filas->map(fn ($fila): array => [
                    'credito_id' => $fila->credito_prendario_id,
                    'garantia_type' => 'bien',
                    'garantia_id' => $fila->bien_id,
                    'created_at' => $fila->created_at,
                    'updated_at' => $fila->updated_at,
                ])->all());
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_garantia');
    }
};
