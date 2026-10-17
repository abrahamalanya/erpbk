<?php

namespace App\Modules\Sistemas\Rules;

use App\Modules\Sistemas\Models\PerfilAcceso;
use App\Modules\Usuario\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PerfilAsignable implements ValidationRule
{
    public function __construct(private readonly User $actor, private readonly ?User $target = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->target?->perfil_acceso_id === (int) $value) {
            return;
        }

        if ($this->target !== null && ! $this->actor->hasRole('sistemas') && ($this->target->id === $this->actor->id || $this->target->hasRole('sistemas'))) {
            $fail('No puedes modificar tu propio perfil ni el de sistemas.');

            return;
        }

        if (! PerfilAcceso::query()->whereKey($value)->where('activo', true)->exists()) {
            $fail('Selecciona un perfil de acceso activo.');
        }
    }
}
