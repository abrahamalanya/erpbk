<?php

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

it('logs in as sistemas and can view /auth/me', function () {
    $user = User::factory()->create();
    $user->assignRole('sistemas');

    $login = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSuccessful();

    $token = $login->json('data.access_token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/auth/me')
        ->assertSuccessful()
        ->assertJsonPath('data.email', $user->email);
});

it('logs in as a tenant-scoped user and can view /auth/me', function () {
    $agencia = Agencia::factory()->create();
    $user = User::factory()->forAgencia($agencia)->create();
    $user->assignRole('administrador_agencia');

    $login = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSuccessful();

    $token = $login->json('data.access_token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/auth/me')
        ->assertSuccessful()
        ->assertJsonPath('data.agencia_id', $agencia->id)
        ->assertJsonPath('data.empresa_id', $agencia->empresa_id);
});

it('includes the effective permission names on login and /auth/me', function () {
    $this->seed(PermissionSeeder::class);

    $agencia = Agencia::factory()->create();
    $user = User::factory()->forAgencia($agencia)->create();
    $user->assignRole('asesor');

    $login = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSuccessful();

    expect($login->json('data.user.permission_names'))->toContain('clientes.ver');

    $token = $login->json('data.access_token');

    $me = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/auth/me')
        ->assertSuccessful();

    expect($me->json('data.permission_names'))->toContain('clientes.ver');
});
