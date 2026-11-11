<?php

namespace App\Modules\Orders\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderPresentation;
use App\Modules\Products\PublicApi\Catalog;
use Illuminate\Http\JsonResponse;

final class DashboardController extends Controller
{
    public function __invoke(Catalog $products, OrderPresentation $presentation): JsonResponse
    {
        // Two aggregate queries instead of four round trips; catalogue
        // figures come from the Products module.
        $productStats = $products->summary();

        $orderStats = Order::query()
            ->selectRaw(
                'COUNT(*) AS total_orders, COALESCE(SUM(CASE WHEN status = ? THEN total_amount ELSE 0 END), 0) AS total_sales',
                [OrderStatus::Completed->value],
            )
            ->first();

        $recentOrders = Order::query()
            ->latest()
            ->limit(6)
            ->get();

        $presentation->prepare($recentOrders);

        return response()->json([
            'data' => [
                'total_products' => $productStats['total_products'],
                'total_orders' => (int) $orderStats->total_orders,
                // Sales only include completed orders.
                'total_sales' => number_format((float) $orderStats->total_sales, 2, '.', ''),
                'stock_units' => $productStats['stock_units'],
                'currency' => config('store.currency'),
            ],
            'recent_orders' => OrderResource::collection($recentOrders)->resolve(),
        ]);
    }
}
