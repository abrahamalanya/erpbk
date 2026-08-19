<?php

namespace Database\Factories;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoPrendario\Models\CreditoPrendario;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;

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
        return [
            'empresa_id' => Empresa::factory(),
            'agencia_id' => Agencia::factory(),
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
     * If no test explicitly attached bienes (via paraBien()/paraBienes()),
     * make sure the crédito still has one matching bien — most tests only
     * care about the crédito's own state, not the specific bien.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (CreditoPrendario $credito): void {
            if ($credito->bienes()->exists()) {
                return;
            }

            $bien = Bien::factory()->create([
                'empresa_id' => $credito->empresa_id,
                'agencia_id' => $credito->agencia_id,
                'cliente_id' => $credito->cliente_id,
            ]);

            $credito->bienes()->attach($bien->id);
        });
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
     * cliente — same assumption CreditoPrendarioService::registrar() enforces).
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
