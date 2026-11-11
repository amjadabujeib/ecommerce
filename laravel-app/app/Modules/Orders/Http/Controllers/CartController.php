<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\Requests\AddToCartRequest;
use App\Modules\Orders\Http\Requests\UpdateCartRequest;
use App\Modules\Orders\Services\CartService;
use App\Modules\Orders\Support\CartPayload;
use App\Modules\Products\PublicApi\Catalog;
use Illuminate\Http\JsonResponse;

final class CartController extends Controller
{
    public function index(CartService $cart): JsonResponse
    {
        return response()->json(['data' => CartPayload::from($cart)]);
    }

    public function store(
        AddToCartRequest $request,
        int $product,
        Catalog $catalog,
        CartService $cart,
    ): JsonResponse {
        $product = $catalog->find($product);
        $cart->add($product, $request->integer('quantity'));

        return response()->json([
            'message' => "\"{$product->title}\" was added to your cart.",
            'data' => CartPayload::from($cart),
        ]);
    }

    public function update(
        UpdateCartRequest $request,
        int $product,
        Catalog $catalog,
        CartService $cart,
    ): JsonResponse {
        $product = $catalog->find($product);
        $cart->updateQuantity($product, $request->integer('quantity'));

        return response()->json([
            'message' => 'Your cart was updated.',
            'data' => CartPayload::from($cart),
        ]);
    }

    public function destroy(int $product, Catalog $catalog, CartService $cart): JsonResponse
    {
        $product = $catalog->find($product);
        $cart->remove($product->id);

        return response()->json([
            'message' => "\"{$product->title}\" was removed from your cart.",
            'data' => CartPayload::from($cart),
        ]);
    }

    public function clear(CartService $cart): JsonResponse
    {
        $cart->clear();

        return response()->json([
            'message' => 'Your cart is empty.',
            'data' => CartPayload::from($cart),
        ]);
    }
}
