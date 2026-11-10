<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Http\Resources\NotificationResource;
use App\Modules\Users\PublicApi\UserDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

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

        $notifications = $this->inbox($request)
            ->when($request->boolean('unread'), fn ($query) => $query->whereNull('read_at'))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return NotificationResource::collection($notifications)
            ->additional(['meta' => ['unread_count' => $this->inbox($request)->whereNull('read_at')->count()]])
            ->response();
    }

    public function markAsRead(Request $request, string $notification): JsonResponse
    {
        $notification = $this->inbox($request)->findOrFail($notification);
        $notification->markAsRead();

        return response()->json([
            'message' => 'Notification marked as read.',
            'data' => (new NotificationResource($notification))->resolve(),
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $updated = $this->inbox($request)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json([
            'message' => "{$updated} notification(s) marked as read.",
        ]);
    }

    private function inbox(Request $request): Builder
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', UserDirectory::NOTIFIABLE_TYPE)
            ->where('notifiable_id', $request->user()->getAuthIdentifier());
    }
}
