<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\RedisStockService;
use Illuminate\Console\Command;

class SyncStockToRedis extends Command
{
    protected $signature = 'app:sync-stock-to-redis';
    protected $description = 'Load product stock levels from the database into Redis for fast access';

    public function handle(RedisStockService $redisStock)
    {
        Product::chunk(100, function ($products) use ($redisStock) {
            foreach ($products as $product) {
                $redisStock->initializeStock($product->id, $product->stock);
                $this->info("Synced product {$product->id}: {$product->stock}");
            }
        });
    }
}
