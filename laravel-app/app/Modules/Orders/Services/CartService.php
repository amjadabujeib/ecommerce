<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Cart;
use App\Modules\Orders\Models\CartItem;
use App\Modules\Products\PublicApi\Catalog;
use App\Modules\Products\PublicApi\CatalogProduct;
use App\Modules\Products\PublicApi\InsufficientStockException;
use App\Modules\Users\PublicApi\Actor;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in customer's shopping cart, stored in the database. Only product
 * ids and quantities are stored; prices are always read from the database and
 * stock from the inventory service (through the Products module).
 *
 * The cart check is a courtesy to the customer: nothing is held in stock until
 * checkout reserves it, which is where the inventory has the final word.
 */
final class CartService
{
    /** Existing inventory Reserve limit; a cart must fit one atomic RPC. */
    public const int MAX_LINES = 100;

    public function __construct(
        private readonly Request $request,
        private readonly Catalog $products,
    ) {}

    /**
     * Raw cart contents keyed by product id.
     *
     * @return array<int, int>
     */
    public function raw(): array
    {
        return $this->readDatabase($this->owner());
    }

    /**
     * Cart lines with fresh product data and calculated line totals.
     *
     * @return list<array{product: CatalogProduct, quantity: int, line_total: float}>
     */
    public function items(): array
    {
        return $this->locked(fn (): array => $this->readItems());
    }

    private function readItems(): array
    {
        $cart = $this->raw();

        if ($cart === []) {
            return [];
        }

        $products = $this->products->findMany(array_keys($cart), withStock: true);

        $items = [];
        $changed = false;

        foreach ($cart as $productId => $quantity) {
            $product = $products[$productId] ?? null;

            if ($product === null) {
                // The product was removed from the catalogue in the meantime.
                unset($cart[$productId]);
                $changed = true;

                continue;
            }

            $items[] = [
                'product' => $product,
                'quantity' => $quantity,
                'line_total' => round((float) $product->price * $quantity, 2),
            ];
        }

        if ($changed) {
            $this->store($cart);
        }

        return $items;
    }

    public function count(): int
    {
        return array_sum($this->raw());
    }

    public function subtotal(): float
    {
        return round(array_sum(array_column($this->items(), 'line_total')), 2);
    }

    public function isEmpty(): bool
    {
        return $this->raw() === [];
    }

    public function add(CatalogProduct $product, int $quantity): void
    {
        $this->locked(fn () => $this->addUnlocked($product, $quantity));
    }

    private function addUnlocked(CatalogProduct $product, int $quantity): void
    {
        $cart = $this->raw();
        $this->assertLineFits($cart, $product->id);
        $desired = ($cart[$product->id] ?? 0) + $quantity;
        $available = $this->products->availableUnits($product->id);

        if ($desired > $available) {
            throw new InsufficientStockException($product->title, $available, $desired);
        }

        $cart[$product->id] = $desired;
        $this->store($cart);
    }

    public function updateQuantity(CatalogProduct $product, int $quantity): void
    {
        $this->locked(fn () => $this->updateQuantityUnlocked($product, $quantity));
    }

    private function updateQuantityUnlocked(CatalogProduct $product, int $quantity): void
    {
        $cart = $this->raw();

        if ($quantity < 1) {
            unset($cart[$product->id]);
            $this->store($cart);

            return;
        }

        $this->assertLineFits($cart, $product->id);
        $available = $this->products->availableUnits($product->id);

        if ($quantity > $available) {
            throw new InsufficientStockException($product->title, $available, $quantity);
        }

        $cart[$product->id] = $quantity;
        $this->store($cart);
    }

    public function remove(int $productId): void
    {
        $this->locked(fn () => $this->removeUnlocked($productId));
    }

    private function removeUnlocked(int $productId): void
    {
        $cart = $this->raw();
        unset($cart[$productId]);
        $this->store($cart);
    }

    public function clear(): void
    {
        $this->locked(fn () => $this->clearUnlocked());
    }

    private function clearUnlocked(): void
    {
        $user = $this->owner();

        CartItem::query()
            ->whereHas('cart', fn ($query) => $query->where('user_id', (int) $user->getAuthIdentifier()))
            ->delete();
    }

    private function assertLineFits(array $cart, int $productId): void
    {
        if (! array_key_exists($productId, $cart) && count($cart) >= self::MAX_LINES) {
            throw ValidationException::withMessages(['cart' => 'A cart may contain at most 100 different products.']);
        }
    }

    /** Serialize all mutations and checkout consumption on the same persistent row. */
    public function locked(callable $work): mixed
    {
        $user = $this->owner();

        return DB::transaction(function () use ($user, $work): mixed {
            $cart = Cart::query()->firstOrCreate(['user_id' => (int) $user->getAuthIdentifier()]);
            Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();

            return $work();
        });
    }

    /**
     * The cart routes require authentication, so a missing user is a 401.
     */
    private function owner(): Actor
    {
        $user = $this->request->user();

        if (! $user instanceof Actor) {
            throw new AuthenticationException;
        }

        return $user;
    }

    /**
     * @param  array<int, int>  $cart
     */
    private function store(array $cart): void
    {
        if ($cart === []) {
            $this->clear();

            return;
        }

        $this->writeDatabase($this->owner(), $cart);
    }

    /**
     * @return array<int, int>
     */
    private function readDatabase(Actor $user): array
    {
        return $this->normalize(
            CartItem::query()
                ->whereHas('cart', fn ($query) => $query->where('user_id', (int) $user->getAuthIdentifier()))
                ->pluck('quantity', 'product_id')
                ->all(),
        );
    }

    /**
     * Replace the user's stored cart lines with the given contents.
     *
     * @param  array<int, int>  $cart
     */
    private function writeDatabase(Actor $user, array $cart): void
    {
        DB::transaction(function () use ($user, $cart): void {
            $storedCart = Cart::query()->firstOrCreate(['user_id' => (int) $user->getAuthIdentifier()]);

            $storedCart->items()
                ->whereNotIn('product_id', array_keys($cart))
                ->delete();

            if ($cart === []) {
                return;
            }

            CartItem::query()->upsert(
                array_map(
                    fn (int $productId, int $quantity): array => [
                        'cart_id' => $storedCart->id,
                        'product_id' => $productId,
                        'quantity' => $quantity,
                    ],
                    array_keys($cart),
                    $cart,
                ),
                ['cart_id', 'product_id'],
                ['quantity'],
            );
        });
    }

    /**
     * Drop malformed entries from stored cart data.
     *
     * @return array<int, int>
     */
    private function normalize(mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $cart = [];

        foreach ($stored as $productId => $quantity) {
            $productId = (int) $productId;
            $quantity = (int) $quantity;

            if ($productId > 0 && $quantity > 0) {
                $cart[$productId] = $quantity;
            }
        }

        return $cart;
    }
}
