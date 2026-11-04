<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every query starts from the signed-in user's own notifications, so another
 * user's notification id simply resolves to a 404.
 */
final class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'unread' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();

        $notifications = ($request->boolean('unread')
            ? $user->unreadNotifications()
            : $user->notifications())
            ->paginate(15)
            ->withQueryString();

        return NotificationResource::collection($notifications)
            ->additional(['meta' => ['unread_count' => $user->unreadNotifications()->count()]])
            ->response();
    }

    public function markAsRead(Request $request, string $notification): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($notification);
        $notification->markAsRead();

        return response()->json([
            'message' => 'Notification marked as read.',
            'data' => (new NotificationResource($notification))->resolve(),
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $updated = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json([
            'message' => "{$updated} notification(s) marked as read.",
        ]);
    }
}
