<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $products = [
            [
                'name' => 'Flash Sale Item 1',
                'price_cents' => 9999,
                'stock' => 100,
            ],
            [
                'name' => 'Flash Sale Item 2',
                'price_cents' => 4999,
                'stock' => 50,
            ],
        ];

        foreach ($products as $product) {
            Product::create($product);
        }
    }
}
