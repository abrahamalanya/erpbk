<?php

namespace App\Nucleo\Rules;

use App\Nucleo\Support\DocumentoIdentidad;
use Closure;
use DomainException;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida un documento de identidad contra el catálogo y la longitud que le
 * corresponde, con el dígito verificador del RUC cuando aplica.
 *
 * Existe como regla y no solo en el service para que el error llegue al
 * formulario como validación de campo (`numero_documento`), con el mensaje
 * exacto que corresponde ("El RUC debe tener 11 dígitos"), en vez de como un
 * 422 genérico más adelante en el flujo.
 */
final class DocumentoDeIdentidadValido implements ValidationRule
{
    public function __construct(private readonly string $campoTipoDocumento = 'tipo_documento') {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $tipo = request()->input($this->campoTipoDocumento);

        if (! is_string($tipo) || ! is_string($value)) {
            return;
        }

        try {
            DocumentoIdentidad::validar($tipo, $value);
        } catch (DomainException $e) {
            $fail($e->getMessage());
        }
    }
}
