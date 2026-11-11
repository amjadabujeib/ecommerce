<?php

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Policies\OrderPolicy;
use App\Modules\Orders\PublicApi\OrderSummary;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'total_amount', 'status', 'inventory_reservation_id'])]
#[UsePolicy(OrderPolicy::class)]
#[UseFactory(OrderFactory::class)]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'status' => OrderStatus::class,
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function summary(): OrderSummary
    {
        return new OrderSummary($this->id, $this->user_id, $this->status->value, $this->status->label(), $this->total_amount);
    }

    public function isPending(): bool
    {
        return $this->status === OrderStatus::Pending;
    }

    public function isCancelled(): bool
    {
        return $this->status === OrderStatus::Cancelled;
    }
}
