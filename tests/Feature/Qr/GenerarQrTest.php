<?php

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Qr\Models\QrGenerado;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
});

it('generates a PNG QR for a user with role sistemas', function () {
    $user = User::factory()->forAgencia($this->agencia)->create();
    $user->assignRole('sistemas');
    Sanctum::actingAs($user, ['*']);

    $response = $this->get('/api/qr/generar?url='.urlencode('https://umax.test'));

    $response->assertSuccessful();
    expect($response->headers->get('content-type'))->toBe('image/png');
    expect(strlen($response->getContent()))->toBeGreaterThan(0);
});

it('generates a PNG QR for a user with role administrador_general', function () {
    $user = User::factory()->forAgencia($this->agencia)->create();
    $user->assignRole('administrador_general');
    Sanctum::actingAs($user, ['*']);

    $response = $this->get('/api/qr/generar?url='.urlencode('https://umax.test'));

    $response->assertSuccessful();
    expect($response->headers->get('content-type'))->toBe('image/png');
});

it('forbids a user without sistemas or administrador_general role', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->get('/api/qr/generar?url='.urlencode('https://umax.test'))->assertForbidden();
});

it('forbids an administrador_agencia without sistemas or administrador_general', function () {
    $admin = User::factory()->forAgencia($this->agencia)->create();
    $admin->assignRole('administrador_agencia');
    Sanctum::actingAs($admin, ['*']);

    $this->get('/api/qr/generar?url='.urlencode('https://umax.test'))->assertForbidden();
});

it('rejects a missing or invalid url', function () {
    $user = User::factory()->forAgencia($this->agencia)->create();
    $user->assignRole('sistemas');
    Sanctum::actingAs($user, ['*']);

    $this->getJson('/api/qr/generar')->assertUnprocessable();
    $this->getJson('/api/qr/generar?url=no-es-una-url')->assertUnprocessable();
});

it('requires authentication', function () {
    $this->getJson('/api/qr/generar?url='.urlencode('https://umax.test'))->assertUnauthorized();
});

it('persists, lists, downloads and deletes a QR without changing the downloaded URL', function (string $role) {
    $user = User::factory()->forAgencia($this->agencia)->create();
    $user->assignRole($role);
    Sanctum::actingAs($user, ['*']);
    $url = 'https://example.com/pagos?cliente=123&origen=qr#detalle';
    $expectedPng = (new PngWriter)->write(new QrCode(data: $url, size: 400, margin: 10))->getString();

    $created = $this->postJson('/api/qr', ['url' => $url, 'user_id' => 999, 'generado_por' => 'Falso'])
        ->assertCreated()->assertJsonPath('data.url', $url)
        ->assertJsonPath('data.user_id', $user->id)
        ->assertJsonPath('data.generado_por', trim($user->nombre.' '.$user->apellido));
    $id = $created->json('data.id');
    $this->assertDatabaseHas('qr_generados', ['id' => $id, 'url' => $url, 'user_id' => $user->id]);
    expect($created->json('data.created_at'))->not->toBeNull();
    expect(base64_decode(explode(',', $created->json('data.imagen_png'), 2)[1]))->toBe($expectedPng);

    $this->getJson('/api/qr')->assertOk()->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.id', $id)
        ->assertJsonPath('data.data.0.imagen_png', $created->json('data.imagen_png'));
    $downloaded = $this->get('/api/qr/'.$id.'/png')->assertOk();
    expect($downloaded->headers->get('content-type'))->toBe('image/png');
    expect($downloaded->headers->get('content-disposition'))->toContain('qr-'.$id.'.png');
    expect($downloaded->getContent())->toBe($expectedPng);
    expect(substr($expectedPng, 0, 8))->toBe("\x89PNG\r\n\x1a\n");
    $this->get('/api/qr/'.$id.'/png')->assertOk();
    $this->assertDatabaseCount('qr_generados', 1);

    $this->deleteJson('/api/qr/'.$id)->assertOk();
    $this->assertDatabaseMissing('qr_generados', ['id' => $id]);
    $this->getJson('/api/qr')->assertJsonPath('data.total', 0);
    $this->getJson('/api/qr/'.$id.'/png')->assertNotFound();
    $this->deleteJson('/api/qr/'.$id)->assertNotFound();
    expect($downloaded->getContent())->toBe($expectedPng);
})->with(['sistemas', 'administrador_general']);

it('keeps legacy generation compatible and records it once', function () {
    $user = User::factory()->forAgencia($this->agencia)->create();
    $user->assignRole('sistemas');
    Sanctum::actingAs($user, ['*']);
    $this->get('/api/qr/generar?url='.urlencode('https://example.com/antigua'))->assertOk();
    $this->assertDatabaseCount('qr_generados', 1);
    $this->assertDatabaseHas('qr_generados', ['url' => 'https://example.com/antigua', 'user_id' => $user->id]);
});

it('paginates the shared history in descending creation order', function () {
    $other = User::factory()->forAgencia($this->agencia)->create();
    QrGenerado::factory()->count(16)->create(['user_id' => $other->id]);
    $user = User::factory()->forAgencia($this->agencia)->create();
    $user->assignRole('administrador_general');
    Sanctum::actingAs($user, ['*']);
    $this->getJson('/api/qr')->assertOk()->assertJsonCount(15, 'data.data')
        ->assertJsonPath('data.last_page', 2)->assertJsonPath('data.total', 16)
        ->assertJsonPath('data.data.0.id', QrGenerado::query()->max('id'));
    $this->getJson('/api/qr?page=2')->assertOk()->assertJsonCount(1, 'data.data');
});

it('rejects invalid new QR data without persisting anything', function (mixed $url) {
    $user = User::factory()->forAgencia($this->agencia)->create();
    $user->assignRole('sistemas');
    Sanctum::actingAs($user, ['*']);
    $this->postJson('/api/qr', ['url' => $url])->assertUnprocessable()->assertJsonValidationErrors('url');
    $this->assertDatabaseCount('qr_generados', 0);
})->with([null, '', 'sin-url', 'javascript:alert(1)', 'ftp://example.com', 'https://example.com/'.str_repeat('a', 2048), 'https://example.com/'.str_repeat('ñ', 1100), [['https://example.com']]]);

it('denies all QR operations to other roles', function (string $role) {
    $qr = QrGenerado::factory()->create();
    $user = User::factory()->forAgencia($this->agencia)->create();
    $user->assignRole($role);
    Sanctum::actingAs($user, ['*']);
    $this->getJson('/api/qr')->assertForbidden();
    $this->postJson('/api/qr', ['url' => 'https://example.com'])->assertForbidden();
    $this->getJson('/api/qr/'.$qr->id.'/png')->assertForbidden();
    $this->deleteJson('/api/qr/'.$qr->id)->assertForbidden();
    $this->getJson('/api/qr/generar?url=https://example.com')->assertForbidden();
    $this->assertDatabaseHas('qr_generados', ['id' => $qr->id]);
    $this->assertDatabaseCount('qr_generados', 1);
})->with(['asesor', 'administrador_agencia', 'supervisor']);

it('requires authentication on every history endpoint', function () {
    $qr = QrGenerado::factory()->create();
    $this->getJson('/api/qr')->assertUnauthorized();
    $this->postJson('/api/qr', ['url' => 'https://example.com'])->assertUnauthorized();
    $this->getJson('/api/qr/'.$qr->id.'/png')->assertUnauthorized();
    $this->deleteJson('/api/qr/'.$qr->id)->assertUnauthorized();
});

it('preserves creator attribution when a user is removed', function () {
    $user = User::factory()->forAgencia($this->agencia)->create();
    $qr = QrGenerado::factory()->create(['user_id' => $user->id, 'generado_por' => 'Autor original']);
    $user->delete();
    expect($qr->fresh()->user_id)->toBeNull();
    expect($qr->fresh()->generado_por)->toBe('Autor original');
    expect($qr->fresh()->url)->toBe($qr->url);
});
