<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * GET /api/v1/notifications
     */
    public function index(Request $request): JsonResponse
    {
        $query = UserNotification::where('user_id', $request->user()->id);

        if ($request->has('read')) {
            $query->where('read', filter_var($request->read, FILTER_VALIDATE_BOOLEAN));
        }

        $query->orderBy('created_at', 'desc');

        $perPage = min((int) $request->get('size', 20), 100);
        $notifications = $query->paginate($perPage);

        return $this->paginatedResponse($notifications,
            fn ($n) => new NotificationResource($n)
        );
    }

    /**
     * PUT /api/v1/notifications/{notification_id}/read
     */
    public function markAsRead(Request $request, string $notificationId): JsonResponse
    {
        $notification = UserNotification::where('user_id', $request->user()->id)
            ->findOrFail($notificationId);
        $notification->update(['read' => true]);

        return response()->json(null, 204);
    }
}
