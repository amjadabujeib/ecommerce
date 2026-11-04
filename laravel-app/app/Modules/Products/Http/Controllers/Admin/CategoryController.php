<?php

namespace App\Modules\Products\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Products\Exceptions\CategoryInUseException;
use App\Modules\Products\Http\Requests\StoreCategoryRequest;
use App\Modules\Products\Http\Requests\UpdateCategoryRequest;
use App\Modules\Products\Http\Resources\CategoryResource;
use App\Modules\Products\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Category management for the admin dashboard. New categories show up in the
 * product form's dropdown straight away.
 */
final class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::query()
            ->withCount('products')
            ->orderBy('name')
            ->get();

        return CategoryResource::collection($categories)->response();
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = Category::query()->create($request->validated());

        return response()->json([
            'message' => "Category \"{$category->name}\" was created.",
            'data' => (new CategoryResource($category->loadCount('products')))->resolve(),
        ], 201);
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $category->update($request->validated());

        return response()->json([
            'message' => "Category \"{$category->name}\" was updated.",
            'data' => (new CategoryResource($category->loadCount('products')))->resolve(),
        ]);
    }

    public function destroy(Category $category): JsonResponse
    {
        DB::transaction(function () use ($category): void {
            $lockedCategory = Category::query()->lockForUpdate()->findOrFail($category->getKey());

            $activeProducts = $lockedCategory->products()->count();

            if ($activeProducts > 0) {
                throw CategoryInUseException::withProducts($lockedCategory->name, $activeProducts);
            }

            // Soft-deleted products still reference the category for the
            // order history.
            if ($lockedCategory->products()->onlyTrashed()->exists()) {
                throw CategoryInUseException::withOrderHistory($lockedCategory->name);
            }

            $lockedCategory->delete();
        });

        return response()->json([
            'message' => "Category \"{$category->name}\" was deleted.",
        ]);
    }
}
