<?php

namespace App\Modules\Products\Http\Resources;

use App\Modules\Products\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
final class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price,
            // From the inventory service; null when it could not be reached.
            'stock_quantity' => $this->availableStock,
            'in_stock' => $this->isInStock(),
            'low_stock' => $this->isLowStock(),
            'stock_status' => $this->stockStatus(),
            'image_path' => $this->image_path,
            'image_url' => $this->image_url,
            'category' => CategoryResource::make($this->whenLoaded('category')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
