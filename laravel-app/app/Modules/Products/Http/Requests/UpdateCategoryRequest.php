<?php

namespace App\Modules\Products\Http\Requests;

use Illuminate\Validation\Rules\Unique;

/**
 * Same rules as creating a category; the category being renamed may keep its
 * own name.
 */
final class UpdateCategoryRequest extends StoreCategoryRequest
{
    protected function uniqueName(): Unique
    {
        return parent::uniqueName()->ignore($this->route('category'));
    }
}
