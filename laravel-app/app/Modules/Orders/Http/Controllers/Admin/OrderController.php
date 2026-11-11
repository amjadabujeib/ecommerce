<?php

namespace App\Modules\Orders\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Http\Requests\UpdateOrderStatusRequest;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderPresentation;
use App\Modules\Orders\Services\OrderService;
use Illuminate\Http\JsonResponse;

final class OrderController extends Controller
{
    public function index(OrderPresentation $presentation): JsonResponse
    {
        $orders = Order::on(app(\App\Support\ReadRouter::class)->connection())
            ->with('items')
            ->withCount('items')
            ->latest()
            ->paginate(12);

        $presentation->prepare($orders->getCollection());

        return OrderResource::collection($orders)->response();
    }

    public function updateStatus(
        UpdateOrderStatusRequest $request,
        Order $order,
        OrderService $orders,
        OrderPresentation $presentation,
    ): JsonResponse {
        $newStatus = OrderStatus::from($request->validated('status'));
        $previousStatus = $order->status;

        $order = $orders->updateStatus($order, $newStatus);

        $order->load('items');
        $presentation->prepare($order);

        $message = match (true) {
            $previousStatus === $newStatus => "Order #{$order->id} is already {$newStatus->label()}.",
            $newStatus === OrderStatus::Cancelled => "Order #{$order->id} was cancelled and stock was restored.",
            default => "Order #{$order->id} is now {$newStatus->label()}.",
        };

        return response()->json([
            'message' => $message,
            'data' => (new OrderResource($order))->resolve(),
        ]);
    }
}
