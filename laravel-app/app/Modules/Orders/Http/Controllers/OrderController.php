<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderPresentation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class OrderController extends Controller
{
    public function index(Request $request, OrderPresentation $presentation): JsonResponse
    {
        $orders = Order::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->with('items')
            ->withCount('items')
            ->latest()
            ->paginate(10);

        $presentation->prepare($orders->getCollection(), customers: false);

        return OrderResource::collection($orders)->response();
    }

    public function show(Order $order, OrderPresentation $presentation): JsonResponse
    {
        // Customers may only view their own orders; admins may view all.
        Gate::authorize('view', $order);

        $order->load('items');
        $presentation->prepare($order);

        return response()->json(['data' => (new OrderResource($order))->resolve()]);
    }
}
