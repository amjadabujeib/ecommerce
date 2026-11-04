<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Exceptions\EmptyCartException;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Services\CartService;
use App\Modules\Orders\Services\CheckoutService;
use App\Modules\Orders\Support\CartPayload;
use App\Modules\Users\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CheckoutController extends Controller
{
    public function index(Request $request, CartService $cart): JsonResponse
    {
        if ($cart->isEmpty()) {
            throw new EmptyCartException('Your cart is empty. Add a product before checking out.');
        }

        return response()->json([
            'data' => [
                ...CartPayload::from($cart),
                'customer' => (new UserResource($request->user()))->resolve(),
            ],
        ]);
    }

    public function store(Request $request, CheckoutService $checkout): JsonResponse
    {
        $order = $checkout->placeOrder($request->user());

        return response()->json([
            'message' => "Order #{$order->id} was placed successfully.",
            'data' => (new OrderResource($order))->resolve(),
        ], 201);
    }
}
