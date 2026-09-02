<?php

namespace Database\Factories;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\Credito;
use App\Modules\CreditoHipotecario\Models\Inmueble;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoVehicular\Models\Vehiculo;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;

/**
 * @extends Factory<Credito>
 */
class CreditoFactory extends Factory
{
    protected $model = Credito::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'agencia_id' => Agencia::factory(),
            'tipo_credito' => 'prendario',
            'cliente_id' => Cliente::factory(),
            'registrado_por' => User::factory(),
            'numero_refrendo' => 0,
            'monto_prestamo' => fake()->randomFloat(2, 100, 2000),
            'interes' => fake()->randomFloat(2, 5, 20),
            'tipo_cuota' => fake()->randomElement(['diario', 'semanal', 'quincenal', 'mensual']),
            'plazo_dias' => 30,
            'estado' => 'pendiente',
        ];
    }

    /**
     * If no test explicitly attached a garantía (via paraBien() /
     * paraVehiculo() / paraInmueble()), attach a fresh one matching the
     * crédito's tipo — most tests only care about the crédito's own state.
     *
     * @var array<string, array{modelo: class-string, relacion: string}>
     */
    private const GARANTIA_POR_TIPO = [
        'prendario' => ['modelo' => Bien::class, 'relacion' => 'bienes'],
        'vehicular' => ['modelo' => Vehiculo::class, 'relacion' => 'vehiculos'],
        'hipotecario' => ['modelo' => Inmueble::class, 'relacion' => 'inmuebles'],
    ];

    public function configure(): static
    {
        return $this->afterCreating(function (Credito $credito): void {
            $config = self::GARANTIA_POR_TIPO[$credito->tipo_credito ?? 'prendario'];

            foreach (self::GARANTIA_POR_TIPO as $otra) {
                if ($credito->garantiasComo($otra['modelo'])->exists()) {
                    return;
                }
            }

            $garantia = $config['modelo']::factory()->create([
                'empresa_id' => $credito->empresa_id,
                'agencia_id' => $credito->agencia_id,
                'cliente_id' => $credito->cliente_id,
            ]);

            $credito->{$config['relacion']}()->attach($garantia->id);
        });
    }

    /**
     * A vehicular crédito (garantía is a Vehiculo). Pair with paraVehiculo()
     * or let configure() attach a fresh one.
     */
    public function vehicular(): static
    {
        return $this->state(fn (): array => ['tipo_credito' => 'vehicular']);
    }

    public function hipotecario(): static
    {
        return $this->state(fn (): array => ['tipo_credito' => 'hipotecario']);
    }

    public function paraVehiculo(Vehiculo $vehiculo): static
    {
        return $this->state(fn (): array => [
            'tipo_credito' => 'vehicular',
            'empresa_id' => $vehiculo->empresa_id,
            'agencia_id' => $vehiculo->agencia_id,
            'cliente_id' => $vehiculo->cliente_id,
        ])->hasAttached($vehiculo, [], 'vehiculos');
    }

    public function paraInmueble(Inmueble $inmueble): static
    {
        return $this->state(fn (): array => [
            'tipo_credito' => 'hipotecario',
            'empresa_id' => $inmueble->empresa_id,
            'agencia_id' => $inmueble->agencia_id,
            'cliente_id' => $inmueble->cliente_id,
        ])->hasAttached($inmueble, [], 'inmuebles');
    }

    /**
     * Attach the crédito to the given bien (and its empresa/agencia/cliente).
     */
    public function paraBien(Bien $bien): static
    {
        return $this->state(fn (): array => [
            'empresa_id' => $bien->empresa_id,
            'agencia_id' => $bien->agencia_id,
            'cliente_id' => $bien->cliente_id,
        ])->hasAttached($bien, [], 'bienes');
    }

    /**
     * Attach the crédito to several bienes at once (all must share the same
     * cliente — same assumption CreditoService::registrar() enforces).
     *
     * @param  Collection<int, Bien>  $bienes
     */
    public function paraBienes(Collection $bienes): static
    {
        $primero = $bienes->first();

        return $this->state(fn (): array => [
            'empresa_id' => $primero->empresa_id,
            'agencia_id' => $primero->agencia_id,
            'cliente_id' => $primero->cliente_id,
        ])->hasAttached($bienes, [], 'bienes');
    }

    /**
     * Mark the crédito as activo, with a vencimiento in the past (mora scenarios).
     */
    public function vencido(int $diasVencido = 5): static
    {
        return $this->state(fn (): array => [
            'estado' => 'vencido',
            'fecha_desembolso' => now()->subDays(30 + $diasVencido)->toDateString(),
            'fecha_vencimiento' => now()->subDays($diasVencido)->toDateString(),
        ]);
    }

    /**
     * Mark the crédito as activo with a vencimiento still in the future.
     */
    public function activo(): static
    {
        return $this->state(fn (): array => [
            'estado' => 'activo',
            'fecha_desembolso' => now()->toDateString(),
            'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        ]);
    }
}
