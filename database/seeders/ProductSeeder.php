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
                'price_cents' => 20000,
                'stock' => 1000,
            ],
            [
                'name' => 'Flash Sale Item 2',
                'price_cents' => 100,
                'stock' => 500,
            ],
        ];

        foreach ($products as $product) {
            Product::create($product);
        }
    }
}
