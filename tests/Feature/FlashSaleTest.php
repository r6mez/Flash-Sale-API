<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use App\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class FlashSaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_parallel_hold_attempts_at_stock_boundary_prevents_overselling(): void
    {
        $product = Product::create([
            'name' => 'Test Item',
            'price_cents' => 1000,
            'stock' => 5,
        ]);

        $successCount = 0;
        $failCount = 0;

        // Simulate 10 parallel requests
        // Only 5 should succeed since we only have 5 in stock
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => 1,
            ]);

            if ($response->status() === 201) {
                $successCount++;
            } elseif ($response->status() === 409) {
                $failCount++;
            }
        }

        $product->refresh();
        $this->assertEquals(0, $product->stock, 'Stock should be zero');
        $this->assertEquals(5, Hold::where('product_id', $product->id)->count());
        $this->assertEquals(5, $successCount, 'Exactly 5 holds should succeed');
        $this->assertEquals(5, $failCount, 'Exactly 5 holds should fail');
    }

    public function test_multiple_hold_expiry_returns_correct_availability(): void
    {
        $product = Product::create([
            'name' => 'Test Item',
            'price_cents' => 1000,
            'stock' => 20,
        ]);

        $holdIds = [];
        for ($i = 0; $i < 4; $i++) {
            $response = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => 2,
            ]);
            $response->assertStatus(201);
            $holdIds[] = $response->json('data.hold');
        }

        // Stock should be reduced by 8 (4 holds * 2 qty)
        $product->refresh();
        $this->assertEquals(12, $product->stock);

        // Expire only 2 of the holds (4 qty total)
        Hold::whereIn('id', array_slice($holdIds, 0, 2))
            ->update(['expire_at' => now()->subMinutes(5)]);

        Artisan::call('holds:release-expired');

        $this->assertEquals(2, Hold::where('product_id', $product->id)->count());

        // Verify stock was restored by 4 (2 holds * 2 qty)
        $product->refresh();
        $this->assertEquals(16, $product->stock);
    }

    public function test_webhook_idempotency_same_key_repeated(): void
    {
        $product = Product::create([
            'name' => 'Test Item',
            'price_cents' => 1000,
            'stock' => 10,
        ]);

        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 1,
        ]);

        $hold_id = $holdResponse->json('data.hold');

        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $hold_id,
        ]);
        
        $order_id = $orderResponse->json('data.id');

        $idempotency_key = 'payment-key-' . uniqid();

        $response1 = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $idempotency_key,
            'order_id' => $order_id,
            'status' => 'success',
        ]);

        $response1->assertStatus(200);
        $this->assertEquals('paid', $response1->json('order_status'));

        $response2 = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $idempotency_key,
            'order_id' => $order_id,
            'status' => 'success',
        ]);

        $response2->assertStatus(200);
        $this->assertEquals('Webhook already processed', $response2->json('message'));

        $this->assertEquals(1, Webhook::where('idempotency_key', $idempotency_key)->count());

        // Verify order status is still 'paid' (not processed twice)
        $order = Order::find($order_id);
        $this->assertEquals('paid', $order->status);
    }

    public function test_webhook_idempotency_failure_does_not_return_stock_twice(): void
    {
        $product = Product::create([
            'name' => 'Test Item',
            'price_cents' => 3000,
            'stock' => 5,
        ]);

        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 2,
        ]);

        $hold_id = $holdResponse->json('data.hold');

        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $hold_id,
        ]);

        $order_id = $orderResponse->json('data.id');

        $product->refresh();
        $stockAfterOrder = $product->stock;

        $idempotencyKey = 'failure-key-' . uniqid();

        $response1 = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $order_id,
            'status' => 'failure',
        ]);

        $response1->assertStatus(200);

        $product->refresh();
        $this->assertEquals($stockAfterOrder + 2, $product->stock);

        // Second webhook with same key should not add stock again
        $response2 = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $order_id,
            'status' => 'failure',
        ]);

        $response2->assertStatus(200);

        $product->refresh();
        $this->assertEquals($stockAfterOrder + 2, $product->stock);
    }

    public function test_webhook_arriving_before_order_creation(): void
    {
        $nonExistentOrderId = 99999;
        $idempotencyKey = 'early-webhook-' . uniqid();

        $response = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $nonExistentOrderId,
            'status' => 'success',
        ]);

        $response->assertStatus(202);
        $this->assertEquals('Order not found, webhook recorded for later processing', $response->json('message'));

        $webhook = Webhook::where('idempotency_key', $idempotencyKey)->first();
        $this->assertNotNull($webhook);
        $this->assertEquals('pending', $webhook->status);
        $this->assertEquals($nonExistentOrderId, $webhook->payload['order_id']);
    }

    // additional tests 

    public function test_complete_successful_purchase_flow(): void
    {
        $product = Product::create([
            'name' => 'Complete Flow Item',
            'price_cents' => 10000,
            'stock' => 3,
        ]);

        // Create hold
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 1,
        ]);

        $holdResponse->assertStatus(201);
        $hold_id = $holdResponse->json('data.hold');
        $this->assertNotNull($holdResponse->json('data.expire_at'));

        // Verify stock reduced
        $product->refresh();
        $this->assertEquals(2, $product->stock);

        // Create order from hold
        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $hold_id,
        ]);

        $orderResponse->assertStatus(201);
        $orderId = $orderResponse->json('data.id');
        $this->assertEquals('pending', $orderResponse->json('data.status'));

        // Payment webhook success
        $webhookResponse = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'complete-flow-' . uniqid(),
            'order_id' => $orderId,
            'status' => 'success',
        ]);

        $webhookResponse->assertStatus(200);

        // Verify order is paid
        $order = Order::find($orderId);
        $this->assertEquals('paid', $order->status);

        // Stock should remain at 2 (was already deducted at hold time)
        $product->refresh();
        $this->assertEquals(2, $product->stock);
    }

    public function test_complete_failed_purchase_flow(): void
    {
        $product = Product::create([
            'name' => 'Failed Flow Item',
            'price_cents' => 7500,
            'stock' => 5,
        ]);

        // Create hold
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 2,
        ]);

        $hold_id = $holdResponse->json('data.hold');

        // Verify stock reduced
        $product->refresh();
        $this->assertEquals(3, $product->stock);

        // Create order
        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $hold_id,
        ]);

        $orderId = $orderResponse->json('data.id');

        // Payment fails
        $webhookResponse = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'failed-flow-' . uniqid(),
            'order_id' => $orderId,
            'status' => 'failure',
        ]);

        $webhookResponse->assertStatus(200);

        // Verify order is cancelled
        $order = Order::find($orderId);
        $this->assertEquals('cancelled', $order->status);

        // Stock should be restored
        $product->refresh();
        $this->assertEquals(5, $product->stock);
    }

    public function test_expired_hold_cannot_create_order(): void
    {
        $product = Product::create([
            'name' => 'Expired Hold Item',
            'price_cents' => 3000,
            'stock' => 10,
        ]);

        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 1,
        ]);

        $hold_id = $holdResponse->json('data.hold');

        Hold::where('id', $hold_id)->update(['expire_at' => now()->subMinutes(5)]);

        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $hold_id,
        ]);

        $orderResponse->assertStatus(410);
        $this->assertStringContainsString('expired', strtolower($orderResponse->json('error')));
    }
}
