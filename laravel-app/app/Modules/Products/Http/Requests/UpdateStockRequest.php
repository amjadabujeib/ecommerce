<?php

namespace App\Modules\Products\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'stock_quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}
