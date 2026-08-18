<?php

namespace App\Nucleo\Services;

use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ConsultaDniService
{
    private const ENDPOINT = 'https://api.consultasperu.com/api/v1/query';

    /**
     * Queries a DNI against the ConsultasPerú API and normalizes the
     * result to umax's Cliente schema (single `nombre`/`apellido` fields,
     * not the API's separate given-name/surname split).
     *
     * @return array{numero_documento: string, nombre: string, apellido: string, direccion: ?string}
     */
    public function consultar(string $dni): array
    {
        if (! preg_match('/^\d{8}$/', $dni)) {
            throw new DomainException('El DNI debe tener 8 dígitos.');
        }

        $token = config('services.consultasperu.token');

        if (! $token) {
            throw new DomainException('CONSULTASPERU_TOKEN no está configurado.');
        }

        try {
            $response = Http::timeout(10)->post(self::ENDPOINT, [
                'token' => $token,
                'type_document' => 'dni',
                'document_number' => $dni,
            ]);
        } catch (ConnectionException) {
            throw new DomainException('No se pudo conectar con el servicio de consulta de DNI.');
        }

        $body = $response->json();

        if (! ($body['success'] ?? false)) {
            $message = match ($response->status()) {
                401 => 'Token de ConsultasPerú inválido. Revisa la configuración.',
                404 => 'No se encontró información para este DNI.',
                default => $body['message'] ?? 'No se pudo consultar el DNI.',
            };

            throw new DomainException($message);
        }

        $data = $body['data'] ?? [];

        $direccion = collect([
            $data['address'] ?? null,
            $data['district'] ?? null,
            $data['province'] ?? null,
            $data['department'] ?? null,
        ])->filter()->implode(', ');

        return [
            'numero_documento' => $data['number'] ?? $dni,
            'nombre' => $data['name'] ?? '',
            'apellido' => $data['surname'] ?? '',
            'direccion' => $direccion !== '' ? $direccion : null,
        ];
    }
}
