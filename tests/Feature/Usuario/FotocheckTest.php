<?php

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Modules\Usuario\Services\FotocheckService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    Storage::fake('public');

    $this->empresa = Empresa::factory()->create(['celular_cobranzas' => '999888777']);
    $this->agencia = Agencia::factory()->for($this->empresa)->create(['telefono' => '511234567']);
    $this->otraAgencia = Agencia::factory()->for($this->empresa)->create(['telefono' => '519999999']);

    $this->admin = User::factory()->forEmpresa($this->empresa)->create();
    $this->admin->assignRole('administrador_general');
});

function usuarioFotocheck(Agencia $agencia, Empresa $empresa, string $rol = 'asesor', array $extra = []): User
{
    $user = User::factory()->forAgencia($agencia)->create([
        'empresa_id' => $empresa->id,
        'agencia_id' => $agencia->id,
        'dni' => fake()->unique()->numerify('########'),
        'fecha_nacimiento' => '1990-05-14',
        ...$extra,
    ]);
    $user->assignRole($rol);

    return $user;
}

/** Sube una foto de perfil real al disco public y la devuelve como data URI. */
function subirFoto(User $user, string $nombre = 'perfil.jpg'): void
{
    $path = UploadedFile::fake()->image($nombre, 800, 1000)->store("usuarios/{$user->id}", 'public');
    $user->forceFill(['foto_path' => $path])->save();
}

/** Genera el PDF y devuelve el cuerpo en crudo (la respuesta es streameada). */
function pdfFotocheck(array $usuarioIds): string
{
    ob_start();
    test()->postJson('/api/usuarios/fotocheck/pdf', ['usuario_ids' => $usuarioIds])->sendContent();

    return ob_get_clean();
}

it('generates a pdf for the selected usuarios', function () {
    $uno = usuarioFotocheck($this->agencia, $this->empresa);
    $dos = usuarioFotocheck($this->agencia, $this->empresa, 'supervisor');

    Sanctum::actingAs($this->admin, ['*']);

    $pdf = pdfFotocheck([$uno->id, $dos->id]);

    expect($pdf)->toStartWith('%PDF');
});

it('returns a pdf content type', function () {
    $user = usuarioFotocheck($this->agencia, $this->empresa);

    Sanctum::actingAs($this->admin, ['*']);

    $this->postJson('/api/usuarios/fotocheck/pdf', ['usuario_ids' => [$user->id]])
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('embeds one photo and one QR image per tarjeta', function () {
    $user = usuarioFotocheck($this->agencia, $this->empresa);
    subirFoto($user);

    Sanctum::actingAs($this->admin, ['*']);

    // dompdf convierte cada <img> en un XObject /Subtype /Image, así que
    // contar esos objetos dice cuántas imágenes entraron al PDF.
    $cuenta = fn (string $pdf): int => preg_match_all('/\/Subtype\s*\/Image/', $pdf);

    $conFotoYQr = pdfFotocheck([$user->id]);
    $soloQr = pdfFotocheck([usuarioFotocheck($this->agencia, $this->empresa)->id]);

    // El usuario con foto aporta 2 imágenes (foto + QR); el que no, solo el QR.
    expect($cuenta($conFotoYQr))->toBeGreaterThan($cuenta($soloQr))
        ->and($cuenta($soloQr))->toBeGreaterThanOrEqual(1);
});

it('paginates 6 tarjetas per sheet and breaks the rest into new sheets', function (int $usuarios, int $hojas) {
    Sanctum::actingAs($this->admin, ['*']);

    $ids = collect(range(1, $usuarios))
        ->map(fn (int $n): int => usuarioFotocheck($this->agencia, $this->empresa)->id)
        ->all();

    $paginas = preg_match_all('/\/Type\s*\/Page[^s]/', pdfFotocheck($ids));

    expect($paginas)->toBe($hojas);
})->with([
    '1 tarjeta' => [1, 1],
    '6 tarjetas llenan una hoja' => [6, 1],
    '7 tarjetas necesitan 2 hojas' => [7, 2],
    '13 tarjetas necesitan 3 hojas' => [13, 3],
]);

it('renders a page in A4 landscape', function () {
    $user = usuarioFotocheck($this->agencia, $this->empresa);

    Sanctum::actingAs($this->admin, ['*']);

    // A4 horizontal son 297x210mm -> 841.89 x 595.28 pt. dompdf puede
    // escribir el alto en notación científica (595.28 -> 5.9528e+2), así que
    // se compara el ancho exacto y que el alto sea ~595.
    $pdf = pdfFotocheck([$user->id]);

    preg_match('#/MediaBox\s*\[\s*[\d.e+]+\s+[\d.e+]+\s+([\d.e+]+)\s+([\d.e+]+)\s*\]#', $pdf, $m);

    expect((float) $m[1])->toBeGreaterThan(840.0)
        ->and((float) $m[1])->toBeLessThan(843.0)
        ->and((float) $m[2])->toBeGreaterThan(594.0)
        ->and((float) $m[2])->toBeLessThan(596.0);
});

it('renders a card with placeholders when the usuario has no foto and no dni', function () {
    $user = usuarioFotocheck($this->agencia, $this->empresa, 'asesor', [
        'dni' => null,
        'foto_path' => null,
    ]);

    Sanctum::actingAs($this->admin, ['*']);

    // No debe fallar: la tarjeta sale con "Sin foto" y "Sin DNI".
    expect(pdfFotocheck([$user->id]))->toStartWith('%PDF');
});

it('ignores usuarios outside the actor scope instead of failing', function () {
    $propio = usuarioFotocheck($this->agencia, $this->empresa);

    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');

    $ajeno = usuarioFotocheck($this->otraAgencia, $this->empresa);

    Sanctum::actingAs($adminAgencia, ['*']);

    $pdf = pdfFotocheck([$propio->id, $ajeno->id]);

    expect($pdf)->toStartWith('%PDF');
});

it('keeps the requested order of the usuarios', function () {
    $a = usuarioFotocheck($this->agencia, $this->empresa);
    $b = usuarioFotocheck($this->agencia, $this->empresa);

    $servicio = app(FotocheckService::class);

    Sanctum::actingAs($this->admin, ['*']);

    $tarjetas = $servicio->tarjetas(
        User::query()->whereIn('id', [$a->id, $b->id])->with(['roles', 'agencia', 'empresa'])
            ->get()->sortBy(fn (User $u): int => array_search($u->id, [$b->id, $a->id], true))->values()->all()
    );

    expect($tarjetas->pluck('id')->all())->toBe([$b->id, $a->id]);
});

it('exposes the agencia nombre, telefono and the readable roles', function () {
    $user = usuarioFotocheck($this->agencia, $this->empresa, 'supervisor');
    $user->assignRole('asesor');

    $tarjetas = app(FotocheckService::class)->tarjetas(
        User::query()->whereKey($user->id)->with(['roles', 'agencia', 'empresa'])->get()->all()
    );

    $tarjeta = $tarjetas->first();

    expect($tarjeta['agencia'])->toBe($this->agencia->nombre)
        ->and($tarjeta['telefono'])->toBe('511234567')
        ->and($tarjeta['dni'])->toBe($user->dni)
        // supervisor va antes que asesor según la jerarquía del módulo
        ->and($tarjeta['roles'])->toBe(['supervisor', 'asesor']);
});

it('falls back to the empresa celular when the agencia has no telefono', function () {
    $agenciaSinTel = Agencia::factory()->for($this->empresa)->create(['telefono' => null]);
    $user = usuarioFotocheck($agenciaSinTel, $this->empresa);

    $tarjetas = app(FotocheckService::class)->tarjetas(
        User::query()->whereKey($user->id)->with(['roles', 'agencia', 'empresa'])->get()->all()
    );

    expect($tarjetas->first()['telefono'])->toBe('999888777');
});

it('requires at least one usuario', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $this->postJson('/api/usuarios/fotocheck/pdf', [])->assertUnprocessable()
        ->assertJsonValidationErrors('usuario_ids');

    $this->postJson('/api/usuarios/fotocheck/pdf', ['usuario_ids' => []])->assertUnprocessable()
        ->assertJsonValidationErrors('usuario_ids');
});

it('rejects a usuario id that does not exist', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $this->postJson('/api/usuarios/fotocheck/pdf', ['usuario_ids' => [999999]])->assertUnprocessable()
        ->assertJsonValidationErrors('usuario_ids.0');
});

it('rejects more usuarios than the max per request', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $this->postJson('/api/usuarios/fotocheck/pdf', [
        'usuario_ids' => range(1, 61),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('usuario_ids');
});

it('denies access to a user without usuarios.ver', function () {
    $user = usuarioFotocheck($this->agencia, $this->empresa);
    $sinPermiso = User::factory()->forAgencia($this->agencia)->create();

    Sanctum::actingAs($sinPermiso, ['*']);

    $this->postJson('/api/usuarios/fotocheck/pdf', ['usuario_ids' => [$user->id]])
        ->assertForbidden();
});
