<?php

namespace Database\Factories;

use App\Modules\Empresa\Models\Empresa;
use App\Modules\Facturacion\Models\CredencialSunat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CredencialSunat>
 */
class CredencialSunatFactory extends Factory
{
    protected $model = CredencialSunat::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'usuario_sol' => 'SOL'.fake()->unique()->numerify('####'),
            'clave_sol' => 'clave-sol-de-prueba',
            'certificado_path' => 'sunat/certificados/prueba.pfx',
            'certificado_password' => 'clave-de-prueba',
            'razon_social_certificado' => 'EMPRESA DE PRUEBA S.A.C.',
            'certificado_vence_en' => now()->addYear(),
            'certificado_cargado_at' => now(),
            'ultima_validacion_at' => null,
            'ultima_validacion_ok' => null,
            'ultima_validacion_error' => null,
        ];
    }

    public function deEmpresa(Empresa $empresa): static
    {
        return $this->state(fn (): array => ['empresa_id' => $empresa->id]);
    }

    /**
     * Certificado vencido: debe bloquear toda emisión de la empresa.
     */
    public function vencido(): static
    {
        return $this->state(fn (): array => [
            'certificado_vence_en' => now()->subDay(),
            'ultima_validacion_ok' => false,
            'ultima_validacion_error' => 'El certificado digital está vencido.',
        ]);
    }

    /**
     * Vence en pocos días: dispara la alerta de §9.
     */
    public function porVencer(int $dias = 20): static
    {
        return $this->state(fn (): array => [
            'certificado_vence_en' => now()->addDays($dias),
        ]);
    }

    public function sinCertificado(): static
    {
        return $this->state(fn (): array => [
            'certificado_path' => null,
            'certificado_password' => null,
            'certificado_vence_en' => null,
        ]);
    }
}
