<?php

namespace App\Nucleo\Concerns;

use App\Nucleo\Scopes\TenantScope;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);
    }
}
