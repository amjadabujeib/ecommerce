<?php

namespace App\Modules\Products\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'price' => ['required', 'numeric', 'min:0.01', 'max:99999999.99', 'decimal:0,2'],
            'stock_quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge(['title' => trim($this->input('title'))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.max' => 'The product image may not be larger than 5 MB.',
            'image.mimes' => 'The product image must be a JPG, PNG or WebP file.',
            'price.decimal' => 'The price may have at most two decimal places.',
        ];
    }
}
