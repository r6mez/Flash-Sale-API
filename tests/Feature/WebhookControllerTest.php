<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Services\RedisStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class WebhookControllerTest extends TestCase
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

    public function test_successful_payment_marks_order_as_paid(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price_cents' => 1000,
            'stock' => 100,
        ]);

        $this->redisStockService->initializeStock($product);

        $order = Order::create([
            'order_reference' => 'test-order-ref-123',
            'product_id' => $product->id,
            'qty' => 2,
            'amount_cents' => 2000,
            'status' => 'pending',
        ]);

        $response = $this->sendWebhook('test-order-ref-123', 'success');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Payment successful, order marked as paid',
                'order_ref' => 'test-order-ref-123',
            ]);

        // Verify order status was updated
        $this->assertEquals('paid', $order->fresh()->status);

        // Verify product stock was decremented in database
        $this->assertEquals(98, $product->fresh()->stock);

        // Verify webhook was recorded
        $this->assertDatabaseHas('webhooks', [
            'order_reference' => 'test-order-ref-123',
            'status' => 'processed',
        ]);
    }

    public function test_failed_payment_cancels_order_and_restores_stock(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price_cents' => 1000,
            'stock' => 100,
        ]);

        $this->redisStockService->initializeStock($product);
        
        // Simulate that stock was already decremented when order was placed
        $this->redisStockService->decrementStock($product->id, 3);

        $order = Order::create([
            'order_reference' => 'test-order-ref-456',
            'product_id' => $product->id,
            'qty' => 3,
            'amount_cents' => 3000,
            'status' => 'pending',
        ]);

        $response = $this->sendWebhook('test-order-ref-456', 'failure');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Payment failed, order cancelled and stock released',
                'order_ref' => 'test-order-ref-456',
            ]);

        // Verify order status was updated to cancelled
        $this->assertEquals('cancelled', $order->fresh()->status);

        // Verify Redis stock was restored
        $this->assertEquals(100, $this->redisStockService->getStock($product->id));
    }

    public function test_webhook_for_nonexistent_order_is_recorded_for_later(): void
    {
        $response = $this->sendWebhook('nonexistent-order-ref', 'success');

        $response->assertStatus(202)
            ->assertJson([
                'message' => 'Order not found, webhook recorded for later processing',
                'order_reference' => 'nonexistent-order-ref',
            ]);

        // Verify webhook was recorded with pending status
        $this->assertDatabaseHas('webhooks', [
            'order_reference' => 'nonexistent-order-ref',
            'status' => 'pending',
        ]);
    }

    public function test_duplicate_webhook_is_handled_idempotently(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price_cents' => 1000,
            'stock' => 100,
        ]);

        $this->redisStockService->initializeStock($product);

        $order = Order::create([
            'order_reference' => 'duplicate-test-ref',
            'product_id' => $product->id,
            'qty' => 1,
            'amount_cents' => 1000,
            'status' => 'pending',
        ]);

        // First webhook
        $response1 = $this->sendWebhook('duplicate-test-ref', 'success');

        $response1->assertStatus(200);

        // Second webhook (duplicate)
        $response2 = $this->sendWebhook('duplicate-test-ref', 'success');

        $response2->assertStatus(200)
            ->assertJson([
                'message' => 'Webhook already processed',
                'order_reference' => 'duplicate-test-ref',
            ]);

        // Product stock should only be decremented once
        $this->assertEquals(99, $product->fresh()->stock);
    }
}
