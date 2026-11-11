<?php

namespace App\Modules\Products\PublicApi;

/** Immutable catalog data; no model, relationship, or lazy query crosses modules. */
final readonly class CatalogProduct
{
    /** @param array<string, mixed> $representation */
    public function __construct(
        public int $id,
        public string $title,
        public string $price,
        public array $representation,
    ) {}
}
