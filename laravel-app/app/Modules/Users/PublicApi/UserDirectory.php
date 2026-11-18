<?php

namespace App\Modules\Users\PublicApi;

use App\Modules\Users\Http\Resources\UserResource;
use App\Modules\Users\Models\User;
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
        return User::query()->useWritePdo()->whereKey($id)->value('read_fence');
    }

    public function rememberWrite(int $id, string $lsn): void
    {
        DB::connection('primary_control')->update(
            "UPDATE users SET read_fence = GREATEST(COALESCE(read_fence, '0/0')::pg_lsn, ?::pg_lsn)::text WHERE id = ?", [$lsn, $id],
        );
    }

    /** @return array<int, array<string, mixed>> Profiles keyed by user ID. */
    public function profiles(array $ids): array
    {
        return User::query()->whereIn('id', array_unique($ids))->get()
            ->mapWithKeys(fn (User $user): array => [$user->id => (new UserResource($user))->resolve()])
            ->all();
    }

    public function notify(int $userId, Notification $notification): void
    {
        User::query()->useWritePdo()->findOrFail($userId)->notify($notification);
    }

    public function notifyAdministrators(Notification $notification): void
    {
        Delivery::send(User::query()->useWritePdo()->admins()->get(), $notification);
    }

    /** @return array{id:int,email:string,username:string}|null */
    public function recipient(int $id): ?array
    {
        $user = User::query()->useWritePdo()->find($id);

        return $user ? ['id' => (int) $user->id, 'email' => $user->email, 'username' => $user->username] : null;
    }

    public function isAdministrator(int $id): bool
    {
        return User::query()->useWritePdo()->whereKey($id)->admins()->exists();
    }

    /** @return list<int> */
    public function administratorIds(): array
    {
        return User::query()->useWritePdo()->admins()->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    public function notifyOnce(int $userId, Notification $notification, string $id): void
    {
        $user = User::query()->useWritePdo()->findOrFail($userId);
        DB::table('notifications')->insertOrIgnore([
            'id' => $id, 'type' => get_class($notification),
            'notifiable_type' => self::NOTIFIABLE_TYPE, 'notifiable_id' => $userId,
            'data' => json_encode($notification->toArray($user), JSON_THROW_ON_ERROR),
            'read_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
