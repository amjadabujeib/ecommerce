<?php

namespace App\Modules\Products\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Products\Http\Resources\ProductResource;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProductController extends Controller
{
    public function index(Request $request, ProductService $productService): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer', 'exists:categories,id'],
        ]);

        $products = Product::query()
            ->with('category')
            ->search($filters['q'] ?? null)
            ->inCategory($filters['category'] ?? null)
            ->latest()
            ->paginate(9)
            ->withQueryString();

        // One gRPC call for the whole page.
        $productService->withStock($products->getCollection());

        return ProductResource::collection($products)->response();
    }

    public function show(Product $product, ProductService $productService): JsonResponse
    {
        $product->load('category');

        $related = Product::query()
            ->with('category')
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->latest()
            ->limit(3)
            ->get();

        // Ask the inventory service how many units are left, in one call for
        // the product and its related items.
        $productService->withStock([$product, ...$related]);

        return response()->json([
            'data' => (new ProductResource($product))->resolve(),
            'related' => ProductResource::collection($related)->resolve(),
        ]);
    }
}
