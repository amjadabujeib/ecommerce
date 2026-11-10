<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Products\PublicApi\Catalog;
use App\Modules\Users\PublicApi\UserDirectory;

/** Enrich an entire page in batches; resources never query foreign models. */
final class OrderPresentation
{
    public function __construct(private readonly Catalog $catalog, private readonly UserDirectory $users) {}

    public function prepare(Order|iterable $orders, bool $customers = true): void
    {
        $list = $orders instanceof Order ? [$orders] : collect($orders)->values()->all();
        $ids = [];
        foreach ($list as $order) {
            if ($order->relationLoaded('items')) {
                foreach ($order->items as $item) {
                    $ids[] = $item->product_id;
                }
            }
        }
        $products = $ids === [] ? [] : $this->catalog->findMany($ids, includeDeleted: true);
        $profiles = $customers ? $this->users->profiles(array_map(fn (Order $order): int => $order->user_id, $list)) : [];
        foreach ($list as $order) {
            if ($customers) {
                $order->setRelation('customerProfile', $profiles[$order->user_id] ?? null);
            }
            if ($order->relationLoaded('items')) {
                foreach ($order->items as $item) {
                    $item->catalogTitle = $products[$item->product_id]->title ?? null;
                }
            }
        }
    }
}
