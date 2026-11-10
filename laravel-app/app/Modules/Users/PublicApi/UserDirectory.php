<?php

namespace App\Modules\Users\PublicApi;

use App\Modules\Users\Http\Resources\UserResource;
use App\Modules\Users\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Delivery;

/** User profiles and notification delivery; never exports Eloquent models. */
final class UserDirectory
{
    public const string NOTIFIABLE_TYPE = 'user';

    /** @return array<int, array<string, mixed>> Profiles keyed by user ID. */
    public function profiles(array $ids): array
    {
        return User::query()->whereIn('id', array_unique($ids))->get()
            ->mapWithKeys(fn (User $user): array => [$user->id => (new UserResource($user))->resolve()])
            ->all();
    }

    public function notify(int $userId, Notification $notification): void
    {
        User::query()->findOrFail($userId)->notify($notification);
    }

    public function notifyAdministrators(Notification $notification): void
    {
        Delivery::send(User::query()->admins()->get(), $notification);
    }
}
