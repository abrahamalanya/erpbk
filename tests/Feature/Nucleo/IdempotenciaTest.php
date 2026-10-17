<?php

use App\Modules\Usuario\Models\User;
use App\Nucleo\Http\Middleware\EnsureIdempotency;
use App\Nucleo\Models\IdempotencyKey;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    // Ruta de prueba: cuenta cuántas veces se ejecuta realmente el efecto.
    $this->contador = (object) ['n' => 0];
    $contador = $this->contador;

    Route::middleware(['auth:sanctum', EnsureIdempotency::class])
        ->post('/_test/idempotencia', function () use ($contador) {
            $contador->n++;

            return response()->json([
                'success' => true,
                'message' => 'ok',
                'data' => ['ejecucion' => $contador->n],
            ]);
        });

    Sanctum::actingAs(User::factory()->create());
});

it('ejecuta el efecto una sola vez y reenvía la misma respuesta para igual clave', function () {
    $primera = $this->withHeader('Idempotency-Key', 'clave-abc')->postJson('/_test/idempotencia');
    $segunda = $this->withHeader('Idempotency-Key', 'clave-abc')->postJson('/_test/idempotencia');

    $primera->assertOk()->assertJsonPath('data.ejecucion', 1);
    $segunda->assertOk()->assertJsonPath('data.ejecucion', 1);
    $segunda->assertHeader('Idempotency-Replayed', 'true');

    expect($this->contador->n)->toBe(1);
    expect(IdempotencyKey::query()->count())->toBe(1);
});

it('ejecuta dos veces con claves distintas', function () {
    $this->withHeader('Idempotency-Key', 'clave-1')->postJson('/_test/idempotencia')->assertOk();
    $this->withHeader('Idempotency-Key', 'clave-2')->postJson('/_test/idempotencia')
        ->assertOk()->assertJsonPath('data.ejecucion', 2);

    expect($this->contador->n)->toBe(2);
    expect(IdempotencyKey::query()->count())->toBe(2);
});

it('sin header se ejecuta siempre (compatible con la web)', function () {
    $this->postJson('/_test/idempotencia')->assertOk();
    $this->postJson('/_test/idempotencia')->assertOk()->assertJsonPath('data.ejecucion', 2);

    expect($this->contador->n)->toBe(2);
    expect(IdempotencyKey::query()->count())->toBe(0);
});
