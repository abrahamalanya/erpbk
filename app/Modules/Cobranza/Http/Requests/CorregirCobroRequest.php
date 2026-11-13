<?php

namespace App\Modules\Cobranza\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CorregirCobroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['administrador_general', 'sistemas']);
    }

    public function rules(): array
    {
        return [
            'monto_pagado' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'caja_ciclo_id' => ['required_without:mover_a_hoy', 'integer', 'min:1'],
            'mover_a_hoy' => ['sometimes', 'boolean', Rule::prohibitedIf($this->has('caja_ciclo_id'))],
            'motivo' => ['required', 'string', 'max:255'],
            'updated_at' => ['required', 'date'],
        ];
    }
}
