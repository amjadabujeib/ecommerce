<?php

namespace App\Modules\Orders\Policies;

use App\Modules\Orders\Models\Order;
use App\Modules\Users\PublicApi\Actor;

/**
 * Customers may only see their own orders. Administrators may see every order
 * (admin routes are additionally protected by the "admin" middleware).
 */
final class OrderPolicy
{
    public function view(Actor $user, Order $order): bool
    {
        return $user->isAdmin() || $order->user_id === (int) $user->getAuthIdentifier();
    }
}
