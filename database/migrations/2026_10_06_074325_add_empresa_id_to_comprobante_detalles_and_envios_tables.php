<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Both child tables need `empresa_id` so their models can use the
     * `BelongsToTenant` trait (the global scope expects the column on every
     * tenant table). It's denormalized — the parent comprobante already has
     * it — but every other tenant table in the project carries it too.
     */
    public function up(): void
    {
        Schema::table('comprobante_detalles', function (Blueprint $table): void {
            $table->foreignId('empresa_id')->nullable()->after('comprobante_id')
                ->constrained()->restrictOnDelete();
        });

        Schema::table('comprobante_envios', function (Blueprint $table): void {
            $table->foreignId('empresa_id')->nullable()->after('comprobante_id')
                ->constrained()->restrictOnDelete();
        });

        // Backfill from the parent comprobante for any rows that exist.
        DB::statement(
            'UPDATE comprobante_detalles SET empresa_id = (SELECT empresa_id FROM comprobantes WHERE comprobantes.id = comprobante_detalles.comprobante_id) WHERE empresa_id IS NULL'
        );
        DB::statement(
            'UPDATE comprobante_envios SET empresa_id = (SELECT empresa_id FROM comprobantes WHERE comprobantes.id = comprobante_envios.comprobante_id) WHERE empresa_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::table('comprobante_detalles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('empresa_id');
        });

        Schema::table('comprobante_envios', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('empresa_id');
        });
    }
};
