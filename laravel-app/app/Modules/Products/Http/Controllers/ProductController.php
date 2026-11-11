<?php

namespace App\Modules\Products\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Products\Http\Resources\CategoryResource;
use App\Modules\Products\Http\Resources\ProductResource;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\CatalogReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProductController extends Controller
{
    public function index(Request $request, CatalogReader $catalog): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer', 'exists:categories,id'],
        ]);
        [$products,$categories] = $catalog->listing($filters, max(1, (int) $request->query('page', 1)));
        $response = ProductResource::collection($products)->additional([
            'categories' => CategoryResource::collection($categories)->resolve(), 'currency' => config('store.currency'),
        ])->response();

        return $this->diagnostics($response, $catalog);
    }

    public function show(Request $request, Product $product, CatalogReader $catalog): JsonResponse
    {
        // Primary route binding still enforces existence before cached reads.
        [$product,$related] = $catalog->detail($product->id);
        $response = response()->json([
            'data' => (new ProductResource($product))->resolve(),
            'related' => ProductResource::collection($related)->resolve(),
            'currency' => config('store.currency'),
        ]);
        if (config('performance.enabled')) {
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-cache');
            $response->setEtag(hash('sha256', $response->getContent()));
            $response->isNotModified($request);
        }

        return $this->diagnostics($response, $catalog);
    }

    private function diagnostics(JsonResponse $response, CatalogReader $catalog): JsonResponse
    {
        if (app()->environment('testing') && config('performance.diagnostics')) {
            $response->headers->set('X-Catalog-Cache', $catalog->cacheStatus);
        }

        return $response;
    }
}
