<?php

namespace App\Modules\Products\PublicApi;

use App\Modules\Products\Http\Resources\CategoryResource;
use App\Modules\Products\Http\Resources\ProductResource;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\ProductService;

/** Batch catalog reads for callers outside Products. Writes stay in Products. */
final class Catalog
{
    public function __construct(private readonly ProductService $products) {}

    public function find(int $id): CatalogProduct
    {
        return $this->snapshot(Product::query()->with('category')->findOrFail($id));
    }

    /** @return array<int, CatalogProduct> Active products by default; history may include removed ones. */
    public function findMany(array $ids, bool $withStock = false, bool $includeDeleted = false): array
    {
        $products = Product::query()->when($includeDeleted, fn ($query) => $query->withTrashed())
            ->with('category')->whereIn('id', array_unique($ids))->get();
        if ($withStock) {
            $this->products->withStock($products);
        }

        return $products->mapWithKeys(fn (Product $product): array => [$product->id => $this->snapshot($product)])->all();
    }

    public function availableUnits(int $id): int
    {
        return $this->products->availableUnits(Product::query()->findOrFail($id));
    }

    /** @return array{total_products: int, stock_units: int|null} */
    public function summary(): array
    {
        return $this->products->catalogSummary();
    }

    private function snapshot(Product $product): CatalogProduct
    {
        $data = (new ProductResource($product))->resolve();
        $data['category'] = (new CategoryResource($product->category))->resolve();

        return new CatalogProduct($product->id, $product->title, $product->price, $data);
    }
}
