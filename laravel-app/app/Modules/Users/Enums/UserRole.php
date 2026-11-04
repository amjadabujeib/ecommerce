<?php

namespace App\Modules\Users\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Customer = 'customer';

    /**
     * Human readable label used across the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Customer => 'Customer',
        };
    }

    /**
     * All role values, useful for migrations and validation rules.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
