<?php

namespace Database\Seeders;

use App\Nucleo\Models\Banco;
use Illuminate\Database\Seeder;

class BancoSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    private const BANCOS = [
        'BCP',
        'Interbank',
        'BBVA',
        'Scotiabank',
        'Banco de la Nación',
        'Banco Pichincha',
        'Banco Falabella',
        'Caja Huancayo',
        'Caja Arequipa',
        'Caja Piura',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::BANCOS as $nombre) {
            Banco::query()->firstOrCreate(['nombre' => $nombre], ['activo' => true]);
        }
    }
}
