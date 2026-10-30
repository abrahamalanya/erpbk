<?php

namespace App\Modules\Ubicacion\Services;

use App\Modules\Ubicacion\Models\DispositivoGps;
use App\Modules\Ubicacion\Models\UbicacionAsesor;
use App\Modules\Usuario\Models\User;
use Illuminate\Support\Facades\DB;

final class DispositivoGpsService
{
    public function vincular(User $user, string $identificador, int $tokenId): DispositivoGps
    {
        return DB::transaction(function () use ($user, $identificador, $tokenId) {
            DispositivoGps::query()->insertOrIgnore([
                'identificador' => $identificador, 'user_id' => $user->id,
                'token_id' => $tokenId, 'vinculado_en' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $device = DispositivoGps::where('identificador', $identificador)->lockForUpdate()->firstOrFail();
            // Once a real device is known, the pre-migration live position cannot
            // represent an additional phone. Historical points remain untouched.
            UbicacionAsesor::where('user_id', $user->id)->whereNull('dispositivo_id')->delete();
            if ($device->user_id !== $user->id || $device->token_id !== $tokenId) {
                if ($device->token_id !== null) {
                    DB::table('personal_access_tokens')->where('id', $device->token_id)->delete();
                }
                // Keep history, but never show the previous user's position as the new user's.
                if ($device->user_id !== $user->id) {
                    UbicacionAsesor::where('dispositivo_id', $device->id)->delete();
                }
                $device->update(['user_id' => $user->id, 'token_id' => $tokenId, 'vinculado_en' => $device->user_id === $user->id ? $device->vinculado_en : now()]);
            }

            return $device;
        });
    }
}
