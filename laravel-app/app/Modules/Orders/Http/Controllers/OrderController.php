<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = $request->user()
            ->orders()
            ->with(['items.product'])
            ->withCount('items')
            ->latest()
            ->paginate(10);

        return OrderResource::collection($orders)->response();
    }

    public function show(Order $order): JsonResponse
    {
        // Customers may only view their own orders; admins may view all.
        Gate::authorize('view', $order);

        $order->load(['items.product', 'user']);

        return response()->json(['data' => (new OrderResource($order))->resolve()]);
    }
}
