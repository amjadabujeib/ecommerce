<?php

namespace App\Modules\Orders\Services;

use App\Modules\Inventory\Contracts\InventoryGateway;
use App\Modules\Inventory\Data\ReservationResult;
use App\Modules\Inventory\Data\ReservationStatus;
use App\Modules\Inventory\Exceptions\InventoryUnavailableException;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Events\OrderPlaced;
use App\Modules\Orders\Exceptions\EmptyCartException;
use App\Modules\Orders\Models\Order;
use App\Modules\Products\Exceptions\InsufficientStockException;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\ProductService;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
        private readonly ProductService $products,
        private readonly InventoryGateway $inventory,
    ) {}

    public function placeOrder(User $user): Order
    {
        $cart = array_filter($this->cart->raw(), fn (int $quantity): bool => $quantity >= 1);

        if ($cart === []) {
            throw new EmptyCartException;
        }

        $products = $this->products->findMany(array_keys($cart));

        if ($products->count() !== count($cart)) {
            throw new EmptyCartException(
                'A product in your cart is no longer available. Please review your cart.',
            );
        }

        // Chosen here, not by the inventory, so that a call that timed out can
        // still be matched to (and released by) the same id.
        $reservationId = (string) Str::uuid();

        $this->reserve($reservationId, $cart, $products);

        try {
            $order = DB::transaction(fn (): Order => $this->createOrder($user, $cart, $products, $reservationId));
        } catch (Throwable $exception) {
            // The stock is held by the inventory but the order does not exist:
            // give the units back before reporting the failure.
            $this->releaseQuietly($reservationId, 'the order could not be saved');

            throw $exception;
        }

        $this->cart->clear();

        OrderPlaced::dispatch($order);

        return $order;
    }

    /**
     * @param  array<int, int>  $cart
     * @param  Collection<int, Product>  $products
     *
     * @throws InsufficientStockException
     * @throws InventoryUnavailableException
     */
    private function reserve(string $reservationId, array $cart, Collection $products): void
    {
        try {
            $result = $this->inventory->reserve($reservationId, $cart);
        } catch (InventoryUnavailableException $exception) {
            // A call that timed out may still have reserved the stock on the
            // other side. Releasing the same id is harmless if it did not, and
            // stops the late reservation from taking stock if it arrives later.
            $this->releaseQuietly($reservationId, 'the reservation did not complete');

            throw $exception;
        }

        if ($result->isReserved()) {
            return;
        }

        throw $this->rejection($result, $products);
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    private function rejection(ReservationResult $result, Collection $products): Throwable
    {
        if ($result->status === ReservationStatus::Released || $result->shortages === []) {
            return new InventoryUnavailableException('Reserve: the reservation was already released');
        }

        $shortage = $result->shortages[0];

        return new InsufficientStockException(
            $products->get($shortage->productId)?->title ?? "Product #{$shortage->productId}",
            $shortage->available,
            $shortage->requested,
        );
    }

    /**
     * @param  array<int, int>  $cart
     * @param  Collection<int, Product>  $products
     */
    private function createOrder(User $user, array $cart, Collection $products, string $reservationId): Order
    {
        $lines = [];
        $totalCents = 0;

        foreach ($cart as $productId => $quantity) {
            $product = $products->get($productId);
            $unitPriceCents = (int) round((float) $product->price * 100);
            $totalCents += $unitPriceCents * $quantity;

            $lines[] = [
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => number_format($unitPriceCents / 100, 2, '.', ''),
            ];
        }

        $order = Order::query()->create([
            'user_id' => $user->id,
            'total_amount' => number_format($totalCents / 100, 2, '.', ''),
            'status' => OrderStatus::Pending,
            'inventory_reservation_id' => $reservationId,
        ]);

        $order->items()->createMany($lines);

        return $order->load(['items.product', 'user']);
    }

    /**
     * Best effort: the customer already gets an error for the original
     * failure; a release that also fails is logged for follow-up.
     */
    private function releaseQuietly(string $reservationId, string $why): void
    {
        try {
            $this->inventory->release($reservationId);
        } catch (Throwable $exception) {
            Log::error('Inventory reservation could not be released', [
                'reservation_id' => $reservationId,
                'why' => $why,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
