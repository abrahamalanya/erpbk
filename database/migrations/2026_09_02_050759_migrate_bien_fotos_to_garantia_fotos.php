<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * bien_fotos -> garantia_fotos (polymorphic), so a vehículo can carry
     * the same multi-photo set a bien already does. Built as a fresh table +
     * row copy (rather than an in-place rename) to avoid cross-driver foreign
     * key rename quirks. Existing rows land as garantia_type = 'bien'.
     */
    public function up(): void
    {
        Schema::create('garantia_fotos', function (Blueprint $table) {
            $table->id();
            $table->string('garantia_type');
            $table->unsignedBigInteger('garantia_id');
            $table->string('path');
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();

            $table->index(['garantia_type', 'garantia_id']);
        });

        if (Schema::hasTable('bien_fotos')) {
            DB::table('bien_fotos')->orderBy('id')->chunk(500, function ($filas): void {
                DB::table('garantia_fotos')->insert($filas->map(fn ($fila): array => [
                    'garantia_type' => 'bien',
                    'garantia_id' => $fila->bien_id,
                    'path' => $fila->path,
                    'orden' => $fila->orden,
                    'created_at' => $fila->created_at,
                    'updated_at' => $fila->updated_at,
                ])->all());
            });

            Schema::drop('bien_fotos');
        }
    }

    public function down(): void
    {
        Schema::create('bien_fotos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bien_id')->constrained('bienes')->cascadeOnDelete();
            $table->string('path');
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();
        });

        DB::table('garantia_fotos')->where('garantia_type', 'bien')->orderBy('id')->chunk(500, function ($filas): void {
            DB::table('bien_fotos')->insert($filas->map(fn ($fila): array => [
                'bien_id' => $fila->garantia_id,
                'path' => $fila->path,
                'orden' => $fila->orden,
                'created_at' => $fila->created_at,
                'updated_at' => $fila->updated_at,
            ])->all());
        });

        Schema::dropIfExists('garantia_fotos');
    }
};
