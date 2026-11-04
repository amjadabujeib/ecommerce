<?php

namespace App\Modules\Products\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Products\Http\Requests\StoreProductRequest;
use App\Modules\Products\Http\Requests\UpdateProductRequest;
use App\Modules\Products\Http\Requests\UpdateStockRequest;
use App\Modules\Products\Http\Resources\CategoryResource;
use App\Modules\Products\Http\Resources\ProductResource;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\ProductService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

final class ProductController extends Controller
{
    public function index(ProductService $productService): JsonResponse
    {
        $products = Product::query()
            ->with('category')
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $productService->withStock($products->getCollection());

        return ProductResource::collection($products)->response();
    }

    public function create(): JsonResponse
    {
        return response()->json([
            'data' => [
                // Populates the category dropdown; new categories are picked
                // up automatically without touching the form.
                'categories' => CategoryResource::collection($this->categories())->resolve(),
            ],
        ]);
    }

    public function store(StoreProductRequest $request, ProductService $products): JsonResponse
    {
        $product = $products->create($request->validated());

        return response()->json([
            'message' => "Product \"{$product->title}\" was created.",
            'data' => (new ProductResource($product->load('category')))->resolve(),
        ], 201);
    }

    public function edit(Product $product, ProductService $productService): JsonResponse
    {
        $productService->withStock($product->load('category'));

        return response()->json([
            'data' => [
                'product' => (new ProductResource($product))->resolve(),
                'categories' => CategoryResource::collection($this->categories())->resolve(),
            ],
        ]);
    }

    public function update(
        UpdateProductRequest $request,
        Product $product,
        ProductService $products,
    ): JsonResponse {
        $product = $products->update($product, $request->validated());

        return response()->json([
            'message' => "Product \"{$product->title}\" was updated.",
            'data' => (new ProductResource($product->load('category')))->resolve(),
        ]);
    }

    public function updateStock(
        UpdateStockRequest $request,
        Product $product,
        ProductService $products,
    ): JsonResponse {
        $product = $products->updateStock($product, $request->integer('stock_quantity'));

        return response()->json([
            'message' => "Stock for \"{$product->title}\" is now {$product->availableStock} unit(s).",
            'data' => (new ProductResource($product->load('category')))->resolve(),
        ]);
    }

    public function destroy(Product $product, ProductService $products): JsonResponse
    {
        // Refused (409) while the product is in a pending order; see
        // ProductService::delete() for how finished orders are handled.
        $title = $product->title;
        $products->delete($product);

        return response()->json([
            'message' => "Product \"{$title}\" was deleted.",
        ]);
    }

    /**
     * @return Collection<int, Category>
     */
    private function categories(): Collection
    {
        return Category::query()->orderBy('name')->get();
    }
}
