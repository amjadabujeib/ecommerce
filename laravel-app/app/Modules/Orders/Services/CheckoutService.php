<?php

namespace App\Modules\Orders\Services;

use App\Modules\Inventory\Contracts\InventoryGateway;
use App\Modules\Inventory\Data\ReservationResult;
use App\Modules\Inventory\Data\ReservationStatus;
use App\Modules\Inventory\Exceptions\InventoryUnavailableException;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\EmptyCartException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\PublicApi\Events\OrderPlaced;
use App\Modules\Products\PublicApi\Catalog;
use App\Modules\Products\PublicApi\CatalogProduct;
use App\Modules\Products\PublicApi\InsufficientStockException;
use App\Modules\Users\PublicApi\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Turns the customer's cart into an order.
 *
 *   1. One gRPC Reserve call for every line of the cart. The inventory service
 *      takes the stock atomically: all lines or none.
 *   2. Approved: the order is saved. Rejected: no order, 409 with a message.
 *   3. If saving the order fails after the reservation, the reservation is
 *      released again (compensation), so no stock is lost.
 *
 * Prices and totals are recalculated from the database; nothing supplied by
 * the browser is trusted. Notifications are left to listeners of OrderPlaced.
 */
final class CheckoutService
{
    public function __construct(
        private readonly CartService $cart,
        private readonly Catalog $products,
        private readonly InventoryGateway $inventory,
        private readonly OrderPresentation $presentation,
        private readonly ReservationRecovery $recovery,
    ) {}

    public function placeOrder(Actor $user): Order
    {
        // Journal outside the cart/order transaction so process death cannot
        // roll back the only durable evidence of a remote reservation.
        $reservationId = (string) Str::uuid();
        $this->recovery->begin($reservationId);
        $reserveAttempted = false;
        try {
            $order = DB::transaction(function () use ($user, $reservationId, &$reserveAttempted): Order {
                $this->recovery->lock($reservationId);

                return $this->cart->locked(function () use ($user, $reservationId, &$reserveAttempted): Order {
                    $cart = array_filter($this->cart->raw(), fn (int $quantity): bool => $quantity >= 1);
                    if ($cart === []) {
                        throw new EmptyCartException;
                    }
                    if (count($cart) > CartService::MAX_LINES) {
                        throw ValidationException::withMessages(['cart' => 'An order may contain at most 100 different products.']);
                    }
                    $products = $this->products->lockForCheckout(array_keys($cart));
                    if (count($products) !== count($cart)) {
                        throw new EmptyCartException('A product in your cart is no longer available. Please review your cart.');
                    }
                    // Validate the aggregate before any inventory side effect.
                    $totalCents = 0;
                    foreach ($cart as $id => $quantity) {
                        $totalCents += (int) round((float) $products[$id]->price * 100) * $quantity;
                        if ($totalCents > 9_999_999_999) {
                            throw ValidationException::withMessages([
                                'total_amount' => 'The order total must not exceed 99,999,999.99.',
                            ]);
                        }
                    }
                    $reserveAttempted = true;
                    $this->reserve($reservationId, $cart, $products);
                    $order = $this->createOrder($user, $cart, $products, $reservationId);
                    $this->cart->clear();
                    $this->recovery->committed($reservationId);

                    return $order;
                });
            });
        } catch (Throwable $exception) {
            $this->recovery->release($reservationId, $reserveAttempted);
            throw $exception;
        }

        OrderPlaced::dispatch($order->summary());
        $this->presentation->prepare($order);

        return $order;
    }

    /**
     * @param  array<int, int>  $cart
     * @param  array<int, CatalogProduct>  $products
     *
     * @throws InsufficientStockException
     * @throws InventoryUnavailableException
     */
    private function reserve(string $reservationId, array $cart, array $products): void
    {
        $result = $this->inventory->reserve($reservationId, $cart);

        if ($result->isReserved()) {
            return;
        }

        throw $this->rejection($result, $products);
    }

    /**
     * @param  array<int, CatalogProduct>  $products
     */
    private function rejection(ReservationResult $result, array $products): Throwable
    {
        if ($result->status === ReservationStatus::Released || $result->shortages === []) {
            return new InventoryUnavailableException('Reserve: the reservation was already released');
        }

        $shortage = $result->shortages[0];

        return new InsufficientStockException(
            ($products[$shortage->productId] ?? null)?->title ?? "Product #{$shortage->productId}",
            $shortage->available,
            $shortage->requested,
        );
    }

    /**
     * @param  array<int, int>  $cart
     * @param  array<int, CatalogProduct>  $products
     */
    private function createOrder(Actor $user, array $cart, array $products, string $reservationId): Order
    {
        $lines = [];
        $totalCents = 0;

        foreach ($cart as $productId => $quantity) {
            $product = $products[$productId];
            $unitPriceCents = (int) round((float) $product->price * 100);
            $totalCents += $unitPriceCents * $quantity;

            $lines[] = [
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => number_format($unitPriceCents / 100, 2, '.', ''),
            ];
        }

        $order = Order::query()->create([
            'user_id' => (int) $user->getAuthIdentifier(),
            'total_amount' => number_format($totalCents / 100, 2, '.', ''),
            'status' => OrderStatus::Pending,
            'inventory_reservation_id' => $reservationId,
        ]);

        $order->items()->createMany($lines);

        return $order->load('items');
    }
}
