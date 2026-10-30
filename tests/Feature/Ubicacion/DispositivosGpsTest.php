<?php

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Ubicacion\Models\DispositivoGps;
use App\Modules\Ubicacion\Models\UbicacionAsesor;
use App\Modules\Ubicacion\Models\UbicacionAsesorHistorial;
use App\Modules\Ubicacion\Services\DispositivoGpsService;
use App\Modules\Ubicacion\Services\UbicacionAsesorService;
use App\Modules\Usuario\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->agencia = Agencia::factory()->for(Empresa::factory()->create())->create();
    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    $this->travelTo(now()->startOfSecond());
});

it('keeps two phones independent and never regresses live positions', function () {
    $tokens = [$this->asesor->createToken('phone-a')->accessToken, $this->asesor->createToken('phone-b')->accessToken];
    foreach ($tokens as $i => $token) {
        $key = hash('sha256', "phone-$i");
        app(DispositivoGpsService::class)->vincular($this->asesor, $key, $token->id);
        app(UbicacionAsesorService::class)->registrar($this->asesor, [
            'dispositivo_id' => $key, 'token_id' => $token->id, 'latitud' => -12 - $i, 'longitud' => -77,
            'capturado_en' => now()->toISOString(),
        ]);
    }
    $this->travel(2)->minutes();
    app(UbicacionAsesorService::class)->registrar($this->asesor, [
        'dispositivo_id' => hash('sha256', 'phone-0'), 'token_id' => $tokens[0]->id,
        'latitud' => -12.1, 'longitud' => -77, 'capturado_en' => now()->toISOString(),
    ]);
    app(UbicacionAsesorService::class)->registrar($this->asesor, [
        'dispositivo_id' => hash('sha256', 'phone-0'), 'token_id' => $tokens[0]->id,
        'latitud' => -80, 'longitud' => -77, 'capturado_en' => now()->subMinute()->toISOString(),
    ]);
    expect(UbicacionAsesor::count())->toBe(2)
        ->and(UbicacionAsesorHistorial::count())->toBe(3)
        ->and(UbicacionAsesor::orderBy('id')->first()->latitud)->toBe(-12.1)
        ->and(UbicacionAsesorHistorial::whereNull('dispositivo_id')->count())->toBe(0);

    config(['services.openrouteservice.key' => null]);
    $history = app(UbicacionAsesorService::class)->historialDe($this->asesor, CarbonImmutable::now(), CarbonImmutable::now());
    expect($history['recorridos'])->toHaveCount(2)
        ->and($history['recorridos'][0]['aproximado'])->toBeTrue()
        ->and($history['recorridos'][0]['trazado'])->toBe([])
        ->and($history['resumen']['distancia_m'])->toBeLessThan(20000);
});

it('reassigns a physical phone without changing history or revoking other phones', function () {
    $old = $this->asesor->createToken('old')->accessToken;
    $other = $this->asesor->createToken('other')->accessToken;
    $key = hash('sha256', 'shared-phone');
    $device = app(DispositivoGpsService::class)->vincular($this->asesor, $key, $old->id);
    app(UbicacionAsesorService::class)->registrar($this->asesor, ['dispositivo_id' => $key, 'token_id' => $old->id, 'latitud' => -12, 'longitud' => -77]);
    $next = User::factory()->forAgencia($this->agencia)->create();
    $new = $next->createToken('new')->accessToken;
    app(DispositivoGpsService::class)->vincular($next, $key, $new->id);
    expect($device->fresh()->user_id)->toBe($next->id)
        ->and(UbicacionAsesor::count())->toBe(0)
        ->and(UbicacionAsesorHistorial::first()->user_id)->toBe($this->asesor->id)
        ->and($old->fresh())->toBeNull()
        ->and($other->fresh())->not->toBeNull();
});

it('uses actual provider geometry including turns rather than GPS straight lines', function () {
    $token = $this->asesor->createToken('gps')->accessToken;
    $key = hash('sha256', 'road-phone');
    app(DispositivoGpsService::class)->vincular($this->asesor, $key, $token->id);
    foreach ([-12.0, -12.01] as $lat) {
        app(UbicacionAsesorService::class)->registrar($this->asesor, ['dispositivo_id' => $key, 'token_id' => $token->id, 'latitud' => $lat, 'longitud' => -77]);
        $this->travel(1)->minutes();
    }
    config(['services.openrouteservice.key' => 'test']);
    Http::fake(['api.heigit.org/*' => Http::response(['features' => [['geometry' => ['coordinates' => [[-77, -12], [-77.005, -12], [-77.005, -12.01], [-77, -12.01]]], 'properties' => ['summary' => ['distance' => 2000, 'duration' => 120]]]]])]);
    $history = app(UbicacionAsesorService::class)->historialDe($this->asesor, CarbonImmutable::now(), CarbonImmutable::now());
    expect($history['recorridos'][0]['trazado'])->toHaveCount(4)
        ->and($history['recorridos'][0]['trazado'][1])->toBe([-12.0, -77.005])
        ->and($history['recorridos'][0]['aproximado'])->toBeFalse();
    Http::assertSentCount(1);
});

it('binds a phone at login and marks its session inactive at logout', function () {
    $key = hash('sha256', 'login-phone');
    $response = $this->postJson('/api/auth/login', ['email' => $this->asesor->email, 'password' => 'password', 'dispositivo_id' => $key])->assertSuccessful();
    $token = $response->json('data.access_token');
    $this->withToken($token)->postJson('/api/ubicaciones-asesores', ['latitud' => -12, 'longitud' => -77, 'dispositivo_id' => $key])->assertSuccessful();
    $this->withToken($token)->postJson('/api/auth/logout')->assertSuccessful();
    expect(DispositivoGps::first()->token_id)->toBeNull()
        ->and(UbicacionAsesor::count())->toBe(1);
});

it('requires one calendar day and rejects the old date range', function () {
    $admin = User::factory()->forAgencia($this->agencia)->create();
    $admin->assignRole('administrador_agencia');
    Sanctum::actingAs($admin);
    $path = '/api/ubicaciones-asesores/historial?asesor_id='.$this->asesor->id;
    $this->getJson($path.'&desde=2026-10-01&hasta=2026-10-30')->assertUnprocessable();
    $this->getJson($path.'&fecha=2026-02-30')->assertUnprocessable();
    $this->getJson($path.'&fecha=2026-10-30')->assertSuccessful()->assertJsonCount(0, 'data.recorridos');
});

it('upgrades a legacy phone token without splitting its recorded history', function () {
    $token = $this->asesor->createToken('legacy')->accessToken;
    $service = app(UbicacionAsesorService::class);
    $first = $service->registrar($this->asesor, ['token_id' => $token->id, 'latitud' => -12, 'longitud' => -77]);
    $this->travel(1)->minutes();
    $second = $service->registrar($this->asesor, ['token_id' => $token->id, 'dispositivo_id' => hash('sha256', 'physical-upgrade'), 'latitud' => -12.01, 'longitud' => -77]);
    expect($first->dispositivo_id)->toBe($second->dispositivo_id)
        ->and(UbicacionAsesorHistorial::count())->toBe(2);
    expect(fn () => $service->registrar($this->asesor, ['token_id' => $token->id, 'dispositivo_id' => hash('sha256', 'another-phone'), 'latitud' => -13, 'longitud' => -77]))
        ->toThrow(HttpException::class);
});
