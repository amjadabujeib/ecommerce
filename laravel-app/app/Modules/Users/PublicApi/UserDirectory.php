<?php

namespace App\Modules\Users\PublicApi;

use App\Modules\Users\Http\Resources\UserResource;
use App\Modules\Users\Models\User;
use App\Support\ReadRouter;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as Delivery;

/** User profiles and notification delivery; never exports Eloquent models. */
final class UserDirectory
{
    public const string NOTIFIABLE_TYPE = 'user';

    /** Last committed WAL position acknowledged to this user. */
    public function readFence(int $id): ?string
    {
        return User::query()->whereKey($id)->value('read_fence');
    }

    public function rememberWrite(int $id, string $lsn): void
    {
        DB::update(
            "UPDATE users SET read_fence = GREATEST(COALESCE(read_fence, '0/0')::pg_lsn, ?::pg_lsn)::text WHERE id = ?", [$lsn, $id],
        );
    }

    /** @return array<int, array<string, mixed>> Profiles keyed by user ID. */
    public function profiles(array $ids): array
    {
        return User::on(app(ReadRouter::class)->connection())->whereIn('id', array_unique($ids))->get()
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
