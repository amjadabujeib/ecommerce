<?php

namespace App\Modules\Products\Services;

use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use Illuminate\Pagination\LengthAwarePaginator;

/** Cache catalog facts only. Quantities always come from the live gRPC service. */
final class CatalogReader
{
    public string $cacheStatus = 'disabled';

    public function __construct(private readonly CatalogCache $cache, private readonly ProductService $stock) {}

    public function listing(array $filters, int $page): array
    {
        $page = max(1, $page);
        $snapshot = $this->remember('list', ['q' => $filters['q'] ?? null, 'category' => $filters['category'] ?? null, 'page' => $page], function () use ($filters, $page): array {
            $products = Product::query()->with('category')->search($filters['q'] ?? null)
                ->inCategory($filters['category'] ?? null)->latest()->paginate(9, ['*'], 'page', $page);

            return [
                'rows' => $products->getCollection()->map(fn (Product $product): array => $this->snapshot($product))->all(),
                'total' => $products->total(),
                'categories' => Category::query()->orderBy('name')->get()->map(fn (Category $category): array => $category->getAttributes())->all(),
            ];
        });
        $models = collect($snapshot['rows'])->map(fn (array $row): Product => $this->hydrate($row));
        $this->stock->withStock($models);
        $paginator = new LengthAwarePaginator($models, $snapshot['total'], 9, $page, ['path' => request()->url()]);
        $paginator->withQueryString();

        return [$paginator, collect($snapshot['categories'])->map(fn (array $row): Category => (new Category)->newFromBuilder($row))];
    }

    public function detail(int $id): array
    {
        $snapshot = $this->remember('detail', ['id' => $id], function () use ($id): array {
            $product = Product::query()->with('category')->findOrFail($id);
            $related = Product::query()->with('category')->where('category_id', $product->category_id)
                ->where('id', '!=', $id)->latest()->limit(3)->get();

            return ['product' => $this->snapshot($product), 'related' => $related->map(fn (Product $row): array => $this->snapshot($row))->all()];
        });
        $product = $this->hydrate($snapshot['product']);
        $related = collect($snapshot['related'])->map(fn (array $row): Product => $this->hydrate($row));
        $this->stock->withStock([$product, ...$related]);

        return [$product, $related];
    }

    private function remember(string $kind, array $parameters, callable $load): array
    {
        try {
            return $this->cache->remember($kind, $parameters, $load);
        } finally {
            $this->cacheStatus = $this->cache->status;
        }
    }

    private function snapshot(Product $product): array
    {
        return [...$product->getAttributes(), '_category' => $product->category->getAttributes()];
    }

    private function hydrate(array $row): Product
    {
        $category = $row['_category'];
        unset($row['_category']);
        $product = (new Product)->newFromBuilder($row);
        $product->setRelation('category', (new Category)->newFromBuilder($category));

        return $product;
    }
}
