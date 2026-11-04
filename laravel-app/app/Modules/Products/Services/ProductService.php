<?php

namespace App\Modules\Products\Services;

use App\Modules\Inventory\Contracts\InventoryGateway;
use App\Modules\Inventory\Exceptions\InventoryUnavailableException;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Products\Events\ProductRemoved;
use App\Modules\Products\Exceptions\ProductHasPendingOrdersException;
use App\Modules\Products\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * All product writes, including image lifecycle, live here so controllers stay
 * thin and the rules are applied consistently.
 *
 * Stock is not stored with the product: it belongs to the inventory service
 * and is read and changed here through the InventoryGateway (gRPC).
 */
final class ProductService
{
    public function __construct(private readonly InventoryGateway $inventory) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Product
    {
        $image = $attributes['image'] ?? null;
        $stock = (int) ($attributes['stock_quantity'] ?? 0);
        unset($attributes['image'], $attributes['stock_quantity']);

        if ($image instanceof UploadedFile) {
            $attributes['image_path'] = $this->storeImage($image);
        }

        try {
            $product = Product::create($attributes);
        } catch (Throwable $exception) {
            if (isset($attributes['image_path'])) {
                $this->deleteImage($attributes['image_path']);
            }

            throw $exception;
        }

        try {
            // The inventory needs the product id, so the product row comes first.
            $product->availableStock = $this->inventory->setStock($product->id, $stock);
        } catch (Throwable $exception) {
            // No product may exist without its stock: undo the creation.
            $this->delete($product);

            throw $exception;
        }

        return $product;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Product $product, array $attributes): Product
    {
        $image = $attributes['image'] ?? null;
        $oldImage = $product->image_path;
        $stock = array_key_exists('stock_quantity', $attributes) ? (int) $attributes['stock_quantity'] : null;
        unset($attributes['image'], $attributes['stock_quantity']);

        // Change the stock first: if the inventory service is down the request
        // fails before anything else is modified.
        $availableStock = $stock === null ? null : $this->inventory->setStock($product->id, $stock);

        if ($image instanceof UploadedFile) {
            $attributes['image_path'] = $this->storeImage($image);
        }

        try {
            $product->fill($attributes)->save();
        } catch (Throwable $exception) {
            if (isset($attributes['image_path'])) {
                $this->deleteImage($attributes['image_path']);
            }

            throw $exception;
        }

        if ($image instanceof UploadedFile && $oldImage !== null) {
            $this->deleteImage($oldImage);
        }

        $product->refresh();

        if ($availableStock !== null) {
            $product->availableStock = $availableStock;

            return $product;
        }

        return $this->withStock($product);
    }

    public function updateStock(Product $product, int $quantity): Product
    {
        $product->availableStock = $this->inventory->setStock($product->id, max(0, $quantity));

        return $product;
    }

    /**
     * Units available for one product, straight from the inventory service.
     *
     * @throws InventoryUnavailableException
     */
    public function availableUnits(Product $product): int
    {
        return $this->inventory->availability([$product->id])[$product->id] ?? 0;
    }

    /**
     * Fill in availableStock for display. Reading is best effort: when the
     * inventory service cannot answer, the products are still shown, with
     * their stock reported as unknown (null) instead of failing the page.
     *
     * @template T of Product|iterable<Product>
     *
     * @param  T  $products
     * @return T
     */
    public function withStock(Product|iterable $products): Product|iterable
    {
        $list = $products instanceof Product ? [$products] : $products;
        $ids = [];

        foreach ($list as $product) {
            $ids[] = $product->id;
        }

        try {
            $levels = $this->inventory->availability($ids);
        } catch (InventoryUnavailableException) {
            $levels = [];
        }

        foreach ($list as $product) {
            $product->availableStock = $levels[$product->id] ?? null;
        }

        return $products;
    }

    /**
     * @param  list<int>  $productIds
     * @return Collection<int, Product> keyed by product id
     */
    public function findMany(array $productIds): Collection
    {
        return Product::query()
            ->with('category')
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');
    }

    /**
     * Catalogue totals for the admin dashboard. stock_units is null when the
     * inventory service cannot be reached.
     *
     * @return array{total_products: int, stock_units: int|null}
     */
    public function catalogSummary(): array
    {
        $productIds = Product::query()->pluck('id')->all();

        try {
            $stockUnits = array_sum($this->inventory->availability($productIds));
        } catch (InventoryUnavailableException) {
            $stockUnits = null;
        }

        return [
            'total_products' => count($productIds),
            'stock_units' => $stockUnits,
        ];
    }

    /**
     * Remove a product from the catalogue.
     *
     *  - In a pending order: refused, the order still needs the product.
     *  - Only in finished orders (completed or cancelled): soft-deleted, so
     *    those orders keep their product row; the image stays for them too.
     *  - Never ordered: deleted for good, together with its image.
     *
     * @throws ProductHasPendingOrdersException
     */
    public function delete(Product $product): void
    {
        $imagePath = $product->image_path;

        $removedForGood = DB::transaction(function () use ($product): bool {
            // Lock the row so two administrators cannot delete it at once.
            $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->getKey());

            $pendingOrders = Order::query()
                ->where('status', OrderStatus::Pending)
                ->whereHas('items', fn ($query) => $query->where('product_id', $lockedProduct->id))
                ->count();

            if ($pendingOrders > 0) {
                throw new ProductHasPendingOrdersException($lockedProduct->title, $pendingOrders);
            }

            if ($lockedProduct->orderItems()->exists()) {
                $lockedProduct->delete();

                return false;
            }

            $lockedProduct->forceDelete();

            return true;
        });

        ProductRemoved::dispatch($product->id);

        if ($removedForGood && $imagePath !== null) {
            $this->deleteImage($imagePath);
        }
    }

    private function storeImage(UploadedFile $image): string
    {
        $extension = strtolower($image->extension() ?: $image->getClientOriginalExtension() ?: 'jpg');
        $filename = Str::uuid()->toString().'.'.$extension;

        $path = $image->storeAs('products', $filename, 'public');

        if ($path === false) {
            throw new RuntimeException('The product image could not be stored.');
        }

        return $path;
    }

    private function deleteImage(string $path): void
    {
        // Only ever remove files this application manages.
        if (str_starts_with($path, 'products/')) {
            Storage::disk('public')->delete($path);
        }
    }
}
