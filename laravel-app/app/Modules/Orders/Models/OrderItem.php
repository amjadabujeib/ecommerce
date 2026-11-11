<?php

namespace App\Modules\Orders\Models;

use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'product_id', 'quantity', 'unit_price'])]
#[UseFactory(OrderItemFactory::class)]
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    /** Read-side catalog value; never persisted as an order_items column. */
    public ?string $catalogTitle = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Unit price snapshot multiplied by quantity, formatted to two decimals.
     */
    protected function lineTotal(): Attribute
    {
        return Attribute::get(
            fn (): string => number_format((float) $this->unit_price * $this->quantity, 2, '.', ''),
        );
    }
}
