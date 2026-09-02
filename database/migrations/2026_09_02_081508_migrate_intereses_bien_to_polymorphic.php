<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * intereses_bien -> intereses (polymorphic): the public storefront now
     * lists any garantía en venta (bien, vehículo, …), so a "me interesa"
     * points at an articulo of any type. Existing rows become
     * articulo_type = 'bien'. Fresh table + copy to stay driver-agnostic.
     */
    public function up(): void
    {
        Schema::create('intereses', function (Blueprint $table) {
            $table->id();
            $table->string('articulo_type');
            $table->unsignedBigInteger('articulo_id');
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('agencia_id')->constrained()->restrictOnDelete();

            $table->string('nombre');
            $table->string('telefono');
            $table->string('email')->nullable();
            $table->text('mensaje')->nullable();
            $table->timestamp('atendido_at')->nullable();

            $table->timestamps();

            $table->index(['articulo_type', 'articulo_id']);
        });

        if (Schema::hasTable('intereses_bien')) {
            DB::table('intereses_bien')->orderBy('id')->chunk(500, function ($filas): void {
                DB::table('intereses')->insert($filas->map(fn ($fila): array => [
                    'articulo_type' => 'bien',
                    'articulo_id' => $fila->bien_id,
                    'empresa_id' => $fila->empresa_id,
                    'agencia_id' => $fila->agencia_id,
                    'nombre' => $fila->nombre,
                    'telefono' => $fila->telefono,
                    'email' => $fila->email,
                    'mensaje' => $fila->mensaje,
                    'atendido_at' => $fila->atendido_at,
                    'created_at' => $fila->created_at,
                    'updated_at' => $fila->updated_at,
                ])->all());
            });

            Schema::drop('intereses_bien');
        }
    }

    public function down(): void
    {
        Schema::create('intereses_bien', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bien_id')->constrained('bienes')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('agencia_id')->constrained()->restrictOnDelete();

            $table->string('nombre');
            $table->string('telefono');
            $table->string('email')->nullable();
            $table->text('mensaje')->nullable();
            $table->timestamp('atendido_at')->nullable();

            $table->timestamps();

            $table->index('bien_id');
        });

        DB::table('intereses')->where('articulo_type', 'bien')->orderBy('id')->chunk(500, function ($filas): void {
            DB::table('intereses_bien')->insert($filas->map(fn ($fila): array => [
                'bien_id' => $fila->articulo_id,
                'empresa_id' => $fila->empresa_id,
                'agencia_id' => $fila->agencia_id,
                'nombre' => $fila->nombre,
                'telefono' => $fila->telefono,
                'email' => $fila->email,
                'mensaje' => $fila->mensaje,
                'atendido_at' => $fila->atendido_at,
                'created_at' => $fila->created_at,
                'updated_at' => $fila->updated_at,
            ])->all());
        });

        Schema::dropIfExists('intereses');
    }
};
