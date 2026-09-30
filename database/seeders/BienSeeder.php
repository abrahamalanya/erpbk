<?php

namespace Database\Seeders;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use Illuminate\Database\Seeder;

class BienSeeder extends Seeder
{
    /**
     * Demo product catalog. Each entry's "foto" is a sample already
     * published under clientes/samples/ (by ClienteSeeder's convention) —
     * reused across every bien of that product, same "don't duplicate demo
     * files" approach as ClienteSeeder.
     *
     * @var list<array<string, mixed>>
     */
    private const CATALOGO = [
        [
            'tipo' => 'electro',
            'nombre' => 'Laptop',
            'marcas' => ['HP', 'Dell', 'Lenovo', 'Asus', 'Acer'],
            'foto' => 'laptop.jpg',
            'valor_min' => 800,
            'valor_max' => 3500,
        ],
        [
            'tipo' => 'electro',
            'nombre' => 'Refrigeradora',
            'marcas' => ['LG', 'Samsung', 'Indurama', 'Mabe', 'Electrolux'],
            'foto' => 'refrigeradora.jpg',
            'valor_min' => 600,
            'valor_max' => 2500,
        ],
        [
            'tipo' => 'varios',
            'nombre' => 'Ropa',
            'marcas' => [],
            'foto' => 'ropa.jpg',
            'valor_min' => 50,
            'valor_max' => 400,
        ],
    ];

    /**
     * @var list<string>
     */
    private const FOTOS_ADICIONALES = ['adicional1.jpg', 'adicional2.jpg', 'adicional3.jpg'];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $agencia = Agencia::where('nombre', 'Agencia Alameda')->firstOrFail();

        $clientes = Cliente::where('agencia_id', $agencia->id)->get();

        foreach ($clientes as $cliente) {
            foreach (range(1, fake()->numberBetween(3, 5)) as $i) {
                $this->crearBien($cliente);
            }
        }
    }

    private function crearBien(Cliente $cliente): void
    {
        $producto = fake()->randomElement(self::CATALOGO);
        $esElectro = $producto['tipo'] === 'electro';
        $precioVenta = fake()->randomFloat(2, $producto['valor_min'] * 1.10, $producto['valor_max'] * 1.20);
        $precioOferta = fake()->boolean(35)
            ? round($precioVenta * fake()->randomFloat(2, 0.80, 0.95), 2)
            : null;

        $bien = Bien::query()->create([
            'empresa_id' => $cliente->empresa_id,
            'agencia_id' => $cliente->agencia_id,
            'cliente_id' => $cliente->id,
            'registrado_por' => $cliente->asesor_id,
            'tipo' => $producto['tipo'],
            'nombre' => $producto['nombre'],
            'marca' => $esElectro ? fake()->randomElement($producto['marcas']) : null,
            'modelo' => $esElectro ? fake()->bothify('MOD-####') : null,
            'serie' => $esElectro ? fake()->bothify('SN-########') : null,
            'observacion' => fake()->sentence(),
            'valorizacion' => fake()->randomFloat(2, $producto['valor_min'], $producto['valor_max']),
            'precio_venta' => $precioVenta,
            'precio_oferta' => $precioOferta,
            'puntaje' => fake()->numberBetween(1, 10),
            'foto_cliente_producto_path' => 'clientes/samples/cliente_producto.jpg',
            'video_path' => fake()->boolean(35) ? 'clientes/samples/video.mp4' : null,
            'estado' => 'en_garantia',
        ]);

        // DatabaseSeeder usa WithoutModelEvents, por lo que el hook que
        // asigna el código de la garantía no se dispara.
        $bien->forceFill([
            'codigo' => 'B-'.str_pad((string) $bien->id, 6, '0', STR_PAD_LEFT),
        ])->saveQuietly();

        $fotos = [$producto['foto'], ...fake()->randomElements(self::FOTOS_ADICIONALES, fake()->numberBetween(1, 3))];

        foreach (array_values($fotos) as $orden => $archivo) {
            $bien->fotos()->create([
                'path' => "clientes/samples/{$archivo}",
                'orden' => $orden,
            ]);
        }
    }
}
