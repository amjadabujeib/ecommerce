<?php

namespace Tests;

use App\Modules\Inventory\Contracts\InventoryGateway;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;
use Tests\Fakes\FakeInventory;

abstract class TestCase extends BaseTestCase
{
    /**
     * A real 1x1 PNG. Using real bytes means the "mimes" rule is exercised
     * through MIME detection instead of relying on the GD extension.
     */
    private const string ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /**
     * Stands in for the inventory gRPC service in every test, so the suite
     * needs no running service. See tests/Fakes/FakeInventory.php.
     */
    protected FakeInventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventory = new FakeInventory;
        $this->app->instance(InventoryGateway::class, $this->inventory);
    }

    protected function fakePng(string $name = 'product.png', int $paddingBytes = 0): UploadedFile
    {
        $bytes = base64_decode(self::ONE_PIXEL_PNG, true);
        $content = ($bytes === false ? '' : $bytes).str_repeat("\0", $paddingBytes);

        return UploadedFile::fake()->createWithContent($name, $content);
    }
}
