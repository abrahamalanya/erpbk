<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['filesystems.disks.public.url' => 'http://umax.test/storage']);

    Route::middleware('api')->get('/_test/storage-url', fn () => response()->json([
        'url' => Storage::disk('public')->url('ejemplo.jpg'),
    ]));
});

it('arma la URL de storage con el host real de la petición, no con APP_URL', function () {
    $response = $this->getJson('http://otro-host.local/_test/storage-url');

    $response->assertOk()->assertJsonPath('url', 'http://otro-host.local/storage/ejemplo.jpg');
});

it('sigue usando APP_URL fuera de una petición HTTP (comandos/colas, sin este middleware)', function () {
    expect(Storage::disk('public')->url('ejemplo.jpg'))->toBe('http://umax.test/storage/ejemplo.jpg');
});
