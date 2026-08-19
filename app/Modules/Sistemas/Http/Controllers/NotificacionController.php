<?php

namespace App\Modules\Sistemas\Http\Controllers;

use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Notifications\DatabaseNotification;

class NotificacionController extends Controller
{
    use ApiResponse;

    /**
     * Every notification belongs to the authenticated user only — there's no
     * cross-user visibility concept here, so no Policy needed beyond that.
     */
    public function index(): JsonResponse
    {
        $user = request()->user();

        return $this->successResponse([
            'notificaciones' => $user->notifications()->latest()->paginate(15),
            'no_leidas' => $user->unreadNotifications()->count(),
        ]);
    }

    public function marcarLeido(DatabaseNotification $notificacion): JsonResponse
    {
        abort_unless((int) $notificacion->notifiable_id === request()->user()->id, 404);

        $notificacion->markAsRead();

        return $this->successResponse($notificacion->fresh(), 'Notificación marcada como leída');
    }

    public function marcarTodasLeidas(): JsonResponse
    {
        request()->user()->unreadNotifications()->update(['read_at' => now()]);

        return $this->successResponse(null, 'Notificaciones marcadas como leídas');
    }
}
