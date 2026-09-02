<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * bien_credito_prendario was superseded by the polymorphic
     * credito_garantia pivot (migration 2026_09_02_044228). Nothing has
     * written to it since; drop it.
     */
    public function up(): void
    {
        Schema::dropIfExists('bien_credito_prendario');
    }

    public function down(): void
    {
        Schema::create('bien_credito_prendario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credito_prendario_id')->constrained('creditos_prendarios')->cascadeOnDelete();
            $table->foreignId('bien_id')->constrained('bienes')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['credito_prendario_id', 'bien_id'], 'bien_credito_prendario_unique');
        });

        // Re-hydrate from the polymorphic pivot so a rollback is lossless.
        if (Schema::hasTable('credito_garantia')) {
            DB::table('credito_garantia')->where('garantia_type', 'bien')->orderBy('id')->chunk(500, function ($filas): void {
                DB::table('bien_credito_prendario')->insert($filas->map(fn ($fila): array => [
                    'credito_prendario_id' => $fila->credito_id,
                    'bien_id' => $fila->garantia_id,
                    'created_at' => $fila->created_at,
                    'updated_at' => $fila->updated_at,
                ])->all());
            });
        }
    }
};
