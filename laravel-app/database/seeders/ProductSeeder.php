<?php

namespace Database\Seeders;

use App\Modules\Inventory\Contracts\InventoryGateway;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Products are stored here; their starting stock is sent to the inventory
     * service, which owns it. Stock is only set for products created by this
     * run, so restarting the container never resets real stock levels.
     */
    public function run(InventoryGateway $inventory): void
    {
        $electronics = Category::query()->where('slug', 'electronics')->firstOrFail();

        $products = [
            [
                'title' => 'Aurora 4K Monitor 27"',
                'description' => 'A 27-inch 4K IPS display with 99% sRGB coverage, USB-C power delivery and a height-adjustable stand.',
                'price' => '449.00',
                'stock_quantity' => 18,
            ],
            [
                'title' => 'Nimbus Wireless Headphones',
                'description' => 'Over-ear headphones with active noise cancellation, 40-hour battery life and a comfortable memory-foam headband.',
                'price' => '199.50',
                'stock_quantity' => 32,
            ],
            [
                'title' => 'Pulse Mechanical Keyboard',
                'description' => 'Hot-swappable 75% mechanical keyboard with PBT keycaps, RGB lighting and a detachable USB-C cable.',
                'price' => '129.99',
                'stock_quantity' => 45,
            ],
            [
                'title' => 'Vertex Pro Laptop 14"',
                'description' => 'A lightweight aluminium laptop with a 14-inch 2.8K display, 16 GB RAM and a 1 TB NVMe SSD.',
                'price' => '1299.00',
                'stock_quantity' => 9,
            ],
            [
                'title' => 'Echo Smart Speaker',
                'description' => 'Compact smart speaker with room-filling sound, multi-room audio and voice assistant support.',
                'price' => '89.00',
                'stock_quantity' => 60,
            ],
            [
                'title' => 'Orbit Wireless Mouse',
                'description' => 'Ergonomic wireless mouse with a 4000 DPI sensor, silent switches and USB-C fast charging.',
                'price' => '49.95',
                'stock_quantity' => 75,
            ],
            [
                'title' => 'Lumen Portable Projector',
                'description' => 'Full HD portable projector with 500 ANSI lumens, HDMI and wireless casting from phones and laptops.',
                'price' => '329.00',
                'stock_quantity' => 14,
            ],
            [
                'title' => 'Flux 65W GaN Charger',
                'description' => 'Three-port GaN wall charger that powers a laptop, tablet and phone at the same time.',
                'price' => '39.99',
                'stock_quantity' => 120,
            ],
        ];

        foreach ($products as $product) {
            $stock = $product['stock_quantity'];
            unset($product['stock_quantity']);

            $model = Product::query()->updateOrCreate(
                ['title' => $product['title']],
                [...$product, 'category_id' => $electronics->id],
            );

            if ($model->wasRecentlyCreated) {
                $inventory->setStock($model->id, $stock);
            }
        }
    }
}
