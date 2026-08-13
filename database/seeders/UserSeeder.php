<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Usuario de sistema: acceso total
        $sistema = User::create([
            'nombre' => 'Abraham',
            'apellido' => 'Alanya',
            'email' => 'abrahamalanya@laravel.com',
            'password' => bcrypt('abrahamalanya'),
            'estado' => 'activo',
        ]);

        $sistema->assignRole('sistemas');

        User::create([
            'nombre' => 'Admin',
            'apellido' => 'Sistema',
            'email' => 'admin.abrahamalanya@laravel.com',
            'password' => bcrypt('abrahamalanya'),
            'estado' => 'activo',
        ]);

        User::create([
            'nombre' => 'Gestor',
            'apellido' => 'Créditos',
            'email' => 'gestor.abrahamalanya@laravel.com',
            'password' => bcrypt('abrahamalanya'),
            'estado' => 'activo',
        ]);

        User::create([
            'nombre' => 'Ejecutivo',
            'apellido' => 'Ventas',
            'email' => 'ejecutivo.abrahamalanya@laravel.com',
            'password' => bcrypt('abrahamalanya'),
            'estado' => 'activo',
        ]);
    }
}
