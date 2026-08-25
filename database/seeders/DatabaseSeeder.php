<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            BancoSeeder::class,
            EmpresaSeeder::class,
            AgenciaSeeder::class,
            CuentaBancariaSeeder::class,
            UserSeeder::class,
            ClienteSeeder::class,
            BienSeeder::class,
        ]);
    }
}
