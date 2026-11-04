<?php

namespace App\Modules\Products\Http\Requests;

use App\Modules\Products\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreCategoryRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:100', $this->uniqueName()],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            // Collapse repeated spaces so "Home  Garden" and "Home Garden"
            // count as the same name.
            $this->merge(['name' => preg_replace('/\s+/u', ' ', trim($this->input('name')))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'A category with this name already exists.',
        ];
    }

    protected function uniqueName(): Unique
    {
        return Rule::unique(Category::class, 'name');
    }
}
