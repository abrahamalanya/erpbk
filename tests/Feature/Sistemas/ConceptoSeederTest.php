<?php

use App\Modules\Empresa\Models\Empresa;
use App\Modules\Sistemas\Models\Concepto;
use Database\Seeders\ConceptoSeeder;

const EGRESOS_POR_DEFECTO = 23;
const INGRESOS_POR_DEFECTO = 6;

it('seeds the default egreso and ingreso conceptos for every empresa', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();

    $this->seed(ConceptoSeeder::class);

    foreach ([$empresaA, $empresaB] as $empresa) {
        expect(Concepto::query()->where('empresa_id', $empresa->id)->where('tipo', 'gasto')->count())
            ->toBe(EGRESOS_POR_DEFECTO)
            ->and(Concepto::query()->where('empresa_id', $empresa->id)->where('tipo', 'ingreso')->count())
            ->toBe(INGRESOS_POR_DEFECTO);
    }
});

it('marks the seeded conceptos as system defaults: active and with no creador', function () {
    $empresa = Empresa::factory()->create();

    $this->seed(ConceptoSeeder::class);

    $soat = Concepto::query()->where('empresa_id', $empresa->id)->where('nombre', 'SOAT')->first();

    expect($soat)->not->toBeNull()
        ->and($soat->tipo)->toBe('gasto')
        ->and($soat->activo)->toBeTrue()
        ->and($soat->creado_por)->toBeNull();
});

it('is idempotent — re-running creates no duplicates', function () {
    Empresa::factory()->create();

    $this->seed(ConceptoSeeder::class);
    $total = Concepto::query()->count();
    $this->seed(ConceptoSeeder::class);

    expect(Concepto::query()->count())->toBe($total);
});

it('does not touch or duplicate a concepto an empresa already added', function () {
    $empresa = Empresa::factory()->create();
    $propio = Concepto::factory()->paraEmpresa($empresa)->create(['tipo' => 'gasto', 'nombre' => 'Movilidad interna propia']);

    $this->seed(ConceptoSeeder::class);
    $this->seed(ConceptoSeeder::class);

    expect(Concepto::query()->where('empresa_id', $empresa->id)->where('tipo', 'gasto')->count())
        ->toBe(EGRESOS_POR_DEFECTO + 1)
        ->and(Concepto::query()->where('nombre', 'Movilidad interna propia')->count())->toBe(1)
        ->and($propio->fresh()->exists)->toBeTrue();
});
