<?php

namespace Database\Factories;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoPrendario\Models\CreditoPrendario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditoPrendario>
 */
class CreditoPrendarioFactory extends Factory
{
    protected $model = CreditoPrendario::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $bien = Bien::factory()->create();

        return [
            'empresa_id' => $bien->empresa_id,
            'agencia_id' => $bien->agencia_id,
            'bien_id' => $bien->id,
            'cliente_id' => Cliente::factory()->forAgencia($bien->agencia),
            'registrado_por' => $bien->registrado_por,
            'numero_refrendo' => 0,
            'monto_prestamo' => fake()->randomFloat(2, 100, 2000),
            'interes' => fake()->randomFloat(2, 5, 20),
            'tipo_cuota' => fake()->randomElement(['diario', 'semanal', 'quincenal', 'mensual']),
            'plazo_dias' => 30,
            'estado' => 'pendiente',
        ];
    }

    /**
     * Attach the crédito to the given bien (and its empresa/agencia).
     */
    public function paraBien(Bien $bien): static
    {
        return $this->state(fn (): array => [
            'empresa_id' => $bien->empresa_id,
            'agencia_id' => $bien->agencia_id,
            'bien_id' => $bien->id,
        ]);
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
