<?php

namespace App\Modules\Cobranza\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'mover_a_hoy' => ['required', 'boolean'],
            'motivo' => ['required', 'string', 'max:255'],
            'updated_at' => ['required', 'date'],
        ];
    }
}
