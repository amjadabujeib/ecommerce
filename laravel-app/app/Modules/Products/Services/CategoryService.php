<?php

namespace App\Modules\Products\Services;

use App\Modules\Products\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Allocate category names and slugs together, including competing suffixes. */
final class CategoryService
{
    public function create(string $name): Category
    {
        return $this->inNamespace(function () use ($name): Category {
            $this->validateName($name);

            return Category::create(['name' => $name]);
        });
    }

    public function rename(Category $category, string $name): Category
    {
        return $this->inNamespace(function () use ($category, $name): Category {
            $locked = Category::query()->lockForUpdate()->findOrFail($category->id);
            $this->validateName($name, $locked->id);
            $locked->update(['name' => $name]);

            return $locked;
        });
    }

    private function inNamespace(callable $work): Category
    {
        return DB::transaction(function () use ($work): Category {
            if (DB::getDriverName() === 'pgsql') {
                // The whole namespace must share a lock: "C" can allocate
                // "c-2", which is also the base slug of a different name.
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?), 0)', ['novacart:category-slugs']);
            }

            return $work();
        });
    }

    private function validateName(string $name, ?int $ignoreId = null): void
    {
        if (Category::where('name', $name)->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            throw ValidationException::withMessages(['name' => 'A category with this name already exists.']);
        }
    }
}
