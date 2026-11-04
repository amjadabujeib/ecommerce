<?php

namespace App\Modules\Products\Models;

use App\Modules\Orders\Models\OrderItem;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'title',
    'description',
    'price',
    'category_id',
    'image_path',
])]
#[UseFactory(ProductFactory::class)]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /*
     * A deleted product that is still referenced by past orders stays in the
     * table (soft-deleted) so the order history keeps its title and link.
     * Products that were never ordered are removed for good.
     */
    use SoftDeletes;

    public const int LOW_STOCK_THRESHOLD = 5;

    /**
     * Units available, as reported by the inventory service. Stock is not a
     * column of this table: the inventory service owns it. Null until
     * ProductService::withStock() fills it in, or when the service is down.
     */
    public ?int $availableStock = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where('title', 'like', '%'.$term.'%');
    }

    #[Scope]
    protected function inCategory(Builder $query, int|string|null $categoryId): void
    {
        if ($categoryId === null || $categoryId === '') {
            return;
        }

        $query->where('category_id', $categoryId);
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(
            fn (): ?string => $this->image_path
                ? Storage::disk('public')->url($this->image_path)
                : null,
        );
    }

    /**
     * Null when the stock is unknown (not loaded, or the inventory service
     * could not be reached).
     */
    public function isInStock(): ?bool
    {
        return $this->availableStock === null ? null : $this->availableStock > 0;
    }

    public function isLowStock(): ?bool
    {
        return $this->availableStock === null
            ? null
            : $this->availableStock > 0 && $this->availableStock <= self::LOW_STOCK_THRESHOLD;
    }

    /**
     * @return 'in_stock'|'low_stock'|'out_of_stock'|'unknown'
     */
    public function stockStatus(): string
    {
        return match (true) {
            $this->availableStock === null => 'unknown',
            $this->availableStock === 0 => 'out_of_stock',
            $this->isLowStock() => 'low_stock',
            default => 'in_stock',
        };
    }
}
