<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\RedisStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ProductControllerTest extends TestCase
{
    use RefreshDatabase;

    protected RedisStockService $redisStockService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redisStockService = app(RedisStockService::class);
        Redis::flushall();
    }

    protected function tearDown(): void
    {
        Redis::flushall();
        parent::tearDown();
    }

    public function test_can_get_product_from_redis_cache(): void
    {
        $product = Product::create([
            'name' => 'Flash Sale Item',
            'price_cents' => 9999,
            'stock' => 100,
        ]);

        // Initialize product in Redis
        $this->redisStockService->initializeStock($product);

        $response = $this->getJson("/api/products/{$product->id}");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'id' => $product->id,
                'name' => 'Flash Sale Item',
                'stock' => 100,
            ]);
    }

    public function test_returns_404_for_nonexistent_product(): void
    {
        $response = $this->getJson('/api/products/999');

        $response->assertStatus(404)
            ->assertJson(['message' => 'Product Not found']);
    }

    public function test_product_stock_reflects_pending_orders_when_loaded_from_database(): void
    {
        $product = Product::create([
            'name' => 'Limited Item',
            'price_cents' => 1000,
            'stock' => 10,
        ]);

        \App\Models\Order::create([
            'order_reference' => 'test-ref-123',
            'product_id' => $product->id,
            'qty' => 3,
            'amount_cents' => 3000,
            'status' => 'pending',
        ]);

        $response = $this->getJson("/api/products/{$product->id}");

        $response->assertStatus(200);
        
        // Stock should be 10 - 3 = 7
        $this->assertEquals(7, $response->json('stock'));
    }
}
