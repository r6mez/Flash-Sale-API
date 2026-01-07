<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Services\RedisStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class OrderFlowIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected RedisStockService $redisStockService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redisStockService = app(RedisStockService::class);
        Redis::flushall();
        config(['services.payment.secret' => 'test-secret']);
    }

    protected function tearDown(): void
    {
        Redis::flushall();
        parent::tearDown();
    }

    private function sendWebhook(string $orderReference, string $status)
    {
        $payload = [
            'order_reference' => $orderReference,
            'status' => $status,
        ];
        $signature = hash_hmac('sha256', json_encode($payload), config('services.payment.secret'));
        
        return $this->postJson('/api/payments/webhook', $payload, [
            'X-Payment-Signature' => $signature
        ]);
    }

    public function test_complete_successful_order_flow(): void
    {
        // Step 1: Create product
        $product = Product::create([
            'name' => 'Flash Sale iPhone',
            'price_cents' => 99900,
            'stock' => 10,
        ]);

        $this->redisStockService->initializeStock($product);

        // Step 2: View product
        $viewResponse = $this->getJson("/api/products/{$product->id}");
        $viewResponse->assertStatus(200)
            ->assertJson([
                'name' => 'Flash Sale iPhone',
                'stock' => 10,
            ]);

        // Step 3: Create order (synchronous queue for testing)
        $orderResponse = $this->postJson('/api/orders', [
            'product_id' => $product->id,
            'qty' => 2,
        ]);

        $orderResponse->assertStatus(202);
        $orderReference = $orderResponse->json('order_reference');

        // Verify Redis stock was decremented
        $this->assertEquals(8, $this->redisStockService->getStock($product->id));

        // Step 4: Order should be created in database (sync queue)
        $order = Order::where('order_reference', $orderReference)->first();
        $this->assertNotNull($order);
        $this->assertEquals('pending', $order->status);
        $this->assertEquals(2, $order->qty);
        // Amount is calculated as price * qty (price field in Product model)
        $this->assertNotNull($order->amount_cents);

        // Step 5: Payment webhook arrives with success
        $webhookResponse = $this->sendWebhook($orderReference, 'success');

        $webhookResponse->assertStatus(200)
            ->assertJson([
                'message' => 'Payment successful, order marked as paid',
            ]);

        // Verify final state
        $this->assertEquals('paid', $order->fresh()->status);
        $this->assertEquals(8, $product->fresh()->stock); // DB stock decremented
    }

    public function test_complete_failed_payment_flow(): void
    {
        $product = Product::create([
            'name' => 'Limited Edition Sneakers',
            'price_cents' => 25000,
            'stock' => 5,
        ]);

        $this->redisStockService->initializeStock($product);

        // Create order
        $orderResponse = $this->postJson('/api/orders', [
            'product_id' => $product->id,
            'qty' => 1,
        ]);

        $orderReference = $orderResponse->json('order_reference');

        // Verify stock was reserved
        $this->assertEquals(4, $this->redisStockService->getStock($product->id));

        // Payment fails
        $webhookResponse = $this->sendWebhook($orderReference, 'failure');

        $webhookResponse->assertStatus(200);

        // Verify order is cancelled
        $order = Order::where('order_reference', $orderReference)->first();
        $this->assertEquals('cancelled', $order->status);

        // Verify Redis stock was restored
        $this->assertEquals(5, $this->redisStockService->getStock($product->id));

        // DB stock should remain unchanged (never decremented for failed payments)
        $this->assertEquals(5, $product->fresh()->stock);
    }

    public function test_stock_exhaustion_scenario(): void
    {
        $product = Product::create([
            'name' => 'Very Limited Item',
            'price_cents' => 50000,
            'stock' => 3,
        ]);

        $this->redisStockService->initializeStock($product);

        // First 3 orders should succeed
        $successfulOrders = [];
        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson('/api/orders', [
                'product_id' => $product->id,
                'qty' => 1,
            ]);
            $response->assertStatus(202);
            $successfulOrders[] = $response->json('order_reference');
        }

        // 4th order should fail
        $failedResponse = $this->postJson('/api/orders', [
            'product_id' => $product->id,
            'qty' => 1,
        ]);

        $failedResponse->assertStatus(409)
            ->assertJson(['message' => 'Insufficient stock']);

        // Stock should be 0
        $this->assertEquals(0, $this->redisStockService->getStock($product->id));

        // Now cancel one order
        $this->sendWebhook($successfulOrders[0], 'failure');

        // Stock should be restored
        $this->assertEquals(1, $this->redisStockService->getStock($product->id));

        // Now another customer can order
        $newOrderResponse = $this->postJson('/api/orders', [
            'product_id' => $product->id,
            'qty' => 1,
        ]);

        $newOrderResponse->assertStatus(202);
        $this->assertEquals(0, $this->redisStockService->getStock($product->id));
    }
}
