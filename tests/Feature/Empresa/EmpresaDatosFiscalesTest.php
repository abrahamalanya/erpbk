<?php

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Ubigeo\Models\UbigeoDepartamento;
use App\Modules\Ubigeo\Models\UbigeoDistrito;
use App\Modules\Ubigeo\Models\UbigeoProvincia;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);

    $dep = UbigeoDepartamento::create(['codigo' => '15', 'nombre' => 'Lima']);
    $prov = UbigeoProvincia::create(['ubigeo_departamento_id' => $dep->id, 'codigo' => '01', 'nombre' => 'Lima']);
    $this->distrito = UbigeoDistrito::create(['ubigeo_provincia_id' => $prov->id, 'codigo' => '01', 'nombre' => 'Lima']);

    $this->sistemas = User::factory()->create();
    $this->sistemas->assignRole('sistemas');
});

it('guarda el domicilio fiscal estructurado de la empresa', function () {
    Sanctum::actingAs($this->sistemas, ['*']);

    $this->postJson('/api/empresas', [
        'nombre' => 'CREDIMAS PERU',
        'ruc' => '20601030013',
        'razon_social' => 'CREDIMAS PERU S.A.C.',
        'domicilio_calle' => 'Av. Javier Prado Este',
        'domicilio_numero' => '4200',
        'domicilio_interior' => 'Piso 3',
        'ubigeo_distrito_id' => $this->distrito->id,
        'correo_facturacion' => 'facturacion@credimasperu.com',
        'telefono_facturacion' => '01 2345678',
    ])->assertCreated();

    $empresa = Empresa::query()->where('ruc', '20601030013')->firstOrFail();

    expect($empresa->razon_social)->toBe('CREDIMAS PERU S.A.C.')
        ->and($empresa->domicilio_calle)->toBe('Av. Javier Prado Este')
        ->and($empresa->correo_facturacion)->toBe('facturacion@credimasperu.com')
        ->and($empresa->datosFiscales()['direccion'])->toBe('Av. Javier Prado Este N.° 4200 Piso 3')
        ->and($empresa->datosFiscales()['ubigeo'])->toBe('150101')
        ->and($empresa->datosFiscalesFaltantes())->toBe([]);
});

it('rechaza un RUC de empresa con el digito verificador incorrecto', function () {
    Sanctum::actingAs($this->sistemas, ['*']);

    $this->postJson('/api/empresas', [
        'nombre' => 'EMPRESA RUC MALO',
        'ruc' => '20123456789',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('ruc')
        ->assertJsonPath('errors.ruc.0', 'El RUC no es válido: el dígito verificador no coincide.');
});

it('rechaza un RUC de empresa que ya pertenece a otra', function () {
    Empresa::factory()->create(['ruc' => '20601030013']);

    Sanctum::actingAs($this->sistemas, ['*']);

    $this->postJson('/api/empresas', [
        'nombre' => 'OTRA EMPRESA',
        'ruc' => '20601030013',
    ])->assertUnprocessable()->assertJsonValidationErrors('ruc');
});

it('rechaza un RUC de empresa con longitud distinta de 11', function () {
    Sanctum::actingAs($this->sistemas, ['*']);

    $this->postJson('/api/empresas', [
        'nombre' => 'EMPRESA RUC CORTO',
        'ruc' => '2060103001',
    ])->assertUnprocessable()->assertJsonValidationErrors('ruc');
});

it('permite actualizar el RUC de la propia empresa sin chocar consigo misma', function () {
    $empresa = Empresa::factory()->create(['ruc' => '20601030013']);

    Sanctum::actingAs($this->sistemas, ['*']);

    $this->putJson("/api/empresas/{$empresa->id}", [
        'nombre' => $empresa->nombre,
        'razon_social' => 'CREDIMAS PERU S.A.C.',
        'domicilio_calle' => 'Av. Javier Prado Este 4200',
        'ubigeo_distrito_id' => $this->distrito->id,
    ])->assertSuccessful();

    expect($empresa->fresh()->razon_social)->toBe('CREDIMAS PERU S.A.C.');
});

it('guarda el punto de emision y el codigo de establecimiento de la agencia', function () {
    $empresa = Empresa::factory()->create();

    Sanctum::actingAs($this->sistemas, ['*']);

    $this->postJson('/api/agencias', [
        'nombre' => 'Agencia Alameda',
        'empresa_id' => $empresa->id,
        'telefono' => '01 5555555',
        'domicilio_calle' => 'Av. Alameda Central',
        'domicilio_numero' => '1234',
        'ubigeo_distrito_id' => $this->distrito->id,
        'correo_facturacion' => 'alameda@credimasperu.com',
        'codigo_establecimiento' => '0001',
    ])->assertCreated();

    $agencia = Agencia::query()->where('nombre', 'Agencia Alameda')->firstOrFail();

    expect($agencia->codigo_establecimiento)->toBe('0001')
        ->and($agencia->domicilio_calle)->toBe('Av. Alameda Central')
        ->and($agencia->ubigeoDistrito->ubigeo())->toBe('150101')
        // Sin RUC propio: la sucursal emite con el de la casa matriz.
        ->and($agencia->ruc)->toBeNull();
});

it('rechaza un RUC de agencia invalido', function () {
    $empresa = Empresa::factory()->create();

    Sanctum::actingAs($this->sistemas, ['*']);

    $this->postJson('/api/agencias', [
        'nombre' => 'Agencia Con Ruc Malo',
        'empresa_id' => $empresa->id,
        'ruc' => '20601030014',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('ruc')
        ->assertJsonPath('errors.ruc.0', 'El RUC no es válido: el dígito verificador no coincide.');
});

it('devuelve la direccion fiscal ya armada en el JSON de la empresa', function () {
    $empresa = Empresa::factory()->create([
        'domicilio_calle' => 'Av. Javier Prado Este',
        'domicilio_numero' => '4200',
        'ubigeo_distrito_id' => $this->distrito->id,
    ]);

    Sanctum::actingAs($this->sistemas, ['*']);

    $this->getJson("/api/empresas/{$empresa->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.direccion_fiscal_completa', 'Av. Javier Prado Este N.° 4200');
});
