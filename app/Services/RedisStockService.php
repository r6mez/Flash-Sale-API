<?php
namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class RedisStockService
{
    private const PRODUCT_KEY_PREFIX = 'product:';

    private const DECREMENT_SCRIPT = <<<'LUA'
        local stock = tonumber(redis.call('hget', KEYS[1], 'stock') or "0")
        local qty = tonumber(ARGV[1])
        
        if stock >= qty then
            redis.call('hincrby', KEYS[1], 'stock', -qty)
            return 1
        else
            return 0
        end
    LUA;

    public function getProduct(int $id): ?Product
    {
        $key = self::PRODUCT_KEY_PREFIX . $id;
        $data = Redis::hgetall($key); // gets all attributes of the product

        if (empty($data)) { // if redis crached or the keys were deleted (otherwise it should always be there)
            $product = DB::transaction(function () use ($id) {
                $product = Product::where('id', $id)->lockForUpdate()->first();
                
                if (!$product) return null;
                
                $pendingQty = (int) Order::where('product_id', $id)
                            ->where('status', 'pending')
                            ->sum('qty');

                $product->stock -= $pendingQty;
                
                return $product;
            });
            
            if($product) $this->initializeStock($product);
            
            return $product;
        }

        $product = new Product();
        $product->forceFill($data);

        // because redis stores everything as strings
        $product->id = (int) $product->id;
        $product->stock = (int) $product->stock;
        $product->price = (int) $product->price;

        return $product;
    }

    public function decrementStock(int $productId, int $quantity): bool
    {
        $key = self::PRODUCT_KEY_PREFIX . $productId;
        $result = Redis::eval(self::DECREMENT_SCRIPT, 1, $key, $quantity);
        return $result === 1;
    }

    public function incrementStock(int $productId, int $quantity): void
    {
        $key = self::PRODUCT_KEY_PREFIX . $productId;
        Redis::hincrby($key, 'stock', $quantity);
    }

    public function getStock(int $productId): int
    {
        $key = self::PRODUCT_KEY_PREFIX . $productId;
        return (int) (Redis::hget($key, 'stock') ?? 0);
    }

    public function initializeStock(Product $product): void
    {
        $key = self::PRODUCT_KEY_PREFIX . $product->id;
        Redis::hmset($key, $product->attributesToArray());
    }
}