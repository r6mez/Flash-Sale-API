<?php
namespace App\Services;

use Illuminate\Support\Facades\Redis;

class RedisStockService
{
    private const STOCK_KEY_PREFIX = 'stock:';

    private const DECREMENT_SCRIPT = <<<'LUA'
        local stock = tonumber(redis.call('get', KEYS[1]) or "0")
        local qty = tonumber(ARGV[1])
        
        if stock >= qty then
            redis.call('decrby', KEYS[1], qty)
            return 1
        else
            return 0
        end
    LUA;

    public function decrementStock(int $productId, int $quantity): bool
    {
        $key = self::STOCK_KEY_PREFIX . $productId;
        $result = Redis::eval(self::DECREMENT_SCRIPT, 1, $key, $quantity);
        return $result === 1;
    }

    public function incrementStock(int $productId, int $quantity): void
    {
        $key = self::STOCK_KEY_PREFIX . $productId;
        Redis::incrby($key, $quantity);
    }

    public function initializeStock(int $productId, int $quantity): void
    {
        $key = self::STOCK_KEY_PREFIX . $productId;
        Redis::set($key, $quantity);
    }

    public function getStock(int $productId): int
    {
        $key = self::STOCK_KEY_PREFIX . $productId;
        return (int) Redis::get($key) ?? 0;
    }
}