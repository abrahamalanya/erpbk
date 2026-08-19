<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoPrendario\Models\ConfiguracionCreditoPrendario;
use App\Modules\CreditoPrendario\Models\CreditoPrendario;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    ConfiguracionCreditoPrendario::factory()->deEmpresa($this->empresa, 'electro')->create([
        'plazo_dias' => 30, 'dias_espera_mora' => 15, 'tasa_mora_diaria' => 1,
    ]);
    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $this->bien = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro']);
});

it('transitions an overdue activo crédito to vencido', function () {
    $credito = CreditoPrendario::factory()->paraBien($this->bien)->create([
        'estado' => 'activo',
        'fecha_desembolso' => now()->subDays(31)->toDateString(),
        'fecha_vencimiento' => now()->subDay()->toDateString(),
    ]);

    $this->artisan('creditos-prendarios:actualizar-estados')->assertExitCode(0);

    expect($credito->fresh()->estado)->toBe('vencido');
});

it('leaves an activo crédito untouched while still within its plazo', function () {
    $credito = CreditoPrendario::factory()->paraBien($this->bien)->activo()->create();

    $this->artisan('creditos-prendarios:actualizar-estados');

    expect($credito->fresh()->estado)->toBe('activo');
});

it('moves a vencido crédito to en_venta once dias_espera_mora passes, marking the bien disponible_venta', function () {
    $credito = CreditoPrendario::factory()->paraBien($this->bien)->create([
        'estado' => 'vencido',
        'fecha_desembolso' => now()->subDays(46)->toDateString(),
        'fecha_vencimiento' => now()->subDays(16)->toDateString(),
    ]);

    $this->artisan('creditos-prendarios:actualizar-estados');

    expect($credito->fresh()->estado)->toBe('en_venta')
        ->and($this->bien->fresh()->estado)->toBe('disponible_venta');
});

it('keeps a vencido crédito untouched while still inside the dias_espera_mora window', function () {
    $credito = CreditoPrendario::factory()->paraBien($this->bien)->create([
        'estado' => 'vencido',
        'fecha_desembolso' => now()->subDays(35)->toDateString(),
        'fecha_vencimiento' => now()->subDays(5)->toDateString(),
    ]);

    $this->artisan('creditos-prendarios:actualizar-estados');

    expect($credito->fresh()->estado)->toBe('vencido');
});
