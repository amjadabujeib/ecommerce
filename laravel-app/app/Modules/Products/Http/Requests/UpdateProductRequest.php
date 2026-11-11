<?php

namespace App\Modules\Products\Http\Requests;

/** Metadata edits preserve warehouse stock unless an adjustment is explicit. */
final class UpdateProductRequest extends StoreProductRequest
{
    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'stock_quantity' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ]);
    }
}
