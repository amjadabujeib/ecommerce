<?php

namespace App\Modules\Products\Services;

use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Support\ReadRouter;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Cache catalog facts only. Quantities always come from the live gRPC service. */
final class CatalogReader
{
    public string $cacheStatus = 'disabled';

    public function __construct(private readonly ReadRouter $reads, private readonly ProductService $stock) {}

    public function listing(array $filters, int $page): array
    {
        $page = max(1, $page);
        $snapshot = $this->remember('list', ['q' => $filters['q'] ?? null, 'category' => $filters['category'] ?? null, 'page' => $page], function (?string $connection) use ($filters, $page): array {
            $products = Product::on($connection)->with('category')->search($filters['q'] ?? null)
                ->inCategory($filters['category'] ?? null)->latest()->paginate(9, ['*'], 'page', $page);

            return [
                'rows' => $products->getCollection()->map(fn (Product $product): array => $this->snapshot($product))->all(),
                'total' => $products->total(),
                'categories' => Category::on($connection)->orderBy('name')->get()->map(fn (Category $category): array => $category->getAttributes())->all(),
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
        $snapshot = $this->remember('detail', ['id' => $id], function (?string $connection) use ($id): array {
            $product = Product::on($connection)->with('category')->findOrFail($id);
            $related = Product::on($connection)->with('category')->where('category_id', $product->category_id)
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
        if (! config('performance.catalog_cache')) {
            return $load($this->safeConnection());
        }
        // This primary-owned clock advances in the same transaction as each
        // product/category write. Old in-flight fills can never poison a new key.
        $version = (int) DB::table('catalog_versions')->where('id', 1)->value('version');
        $key = 'catalog:v'.$version.':'.$kind.':'.hash('sha256', json_encode($parameters));
        try {
            $store = Cache::store(config('performance.cache_store'));
            $cached = $store->get($key);
            if (is_array($cached)) {
                $this->cacheStatus = 'hit';

                return $cached;
            }
        } catch (Throwable) {
            $store = null;
            $this->cacheStatus = 'unavailable';
        }
        $value = $load($this->safeConnection($version));
        if ($store !== null) {
            try {
                $store->put($key, $value, max(1, (int) config('performance.catalog_ttl')));
                $this->cacheStatus = 'miss';
            } catch (Throwable) {
                $this->cacheStatus = 'unavailable';
            }
        }

        return $value;
    }

    private function safeConnection(?int $version = null): ?string
    {
        $connection = $this->reads->connection();
        if ($connection === 'replica') {
            try {
                $version ??= (int) DB::table('catalog_versions')->where('id', 1)->value('version');
                if ((int) DB::connection('replica')->table('catalog_versions')->where('id', 1)->value('version') < $version) {
                    $this->reads->usePrimary();

                    return null;
                }
            } catch (Throwable) {
                $this->reads->usePrimary();

                return null;
            }
        }

        return $connection;
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
