<?php

namespace App\Modules\Orders\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * CSS modifier used by the status badge component.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'badge--warning',
            self::Completed => 'badge--success',
            self::Cancelled => 'badge--danger',
        };
    }

    /**
     * The statuses this status may move to.
     *
     * Cancelled is terminal on purpose: it guarantees stock can never be
     * restored twice for the same order.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Completed, self::Cancelled],
            self::Completed => [self::Cancelled],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
